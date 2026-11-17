<?php
/**
 * OFP_Credit
 *
 * Manages SMS credit balances for each client.
 *
 * CREDIT FLOW:
 *  - Admin tops up credit via wp-admin (manual for now, automated via payment in Phase 6)
 *  - Every SMS page deducts OFP_Credit::sms_price() (setting ofp_sms_price, default NGN 10.00)
 *  - When remaining balance drops below 20% → low warning email (once per cycle)
 *  - When remaining balance hits zero → paused = 1 → queue stops firing
 *  - Top-up resets paused = 0 and low_warned = 0 automatically
 *
 * Depends on: ofp_credits, ofp_credit_transactions, OFP_Mailer, OFP_Client.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Credit {

    /** Credit channels that exist. Column names are built only from this list (S2-02). */
    const CHANNELS = [ 'sms' ];

    /**
     * The ONE place the price of one SMS page comes from (G8.01).
     * Setting: ofp_sms_price. Default NGN 10.00.
     */
    public static function sms_price(): float {
        $price = (float) get_option( 'ofp_sms_price', 10.00 );
        return $price > 0 ? $price : 10.00;
    }

    /**
     * What one SMS message costs: the price of one page times the pages the text uses (D-19).
     */
    public static function sms_cost( string $message ): float {
        return round( self::sms_price() * OFP_SMS::segments( $message ), 2 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BALANCE CHECKS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if a client has sufficient balance for a given channel and amount.
     *
     * @param  int    $client_id  Client ID.
     * @param  string $channel    'sms'.
     * @param  float  $amount     Required amount in NGN.
     * @return bool
     */
    public static function has_balance( int $client_id, string $channel, float $amount ): bool {
        global $wpdb;

        // The platform's own account is never limited by credits.
        if ( OFP_Platform::is_platform( $client_id ) ) {
            return true;
        }

        if ( ! in_array( $channel, self::CHANNELS, true ) ) {
            return false;
        }

        $col       = $channel . '_remaining';
        $remaining = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT {$col} FROM {$wpdb->prefix}ofp_credits WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        return $remaining >= $amount;
    }

    /**
     * Get the full credit row for a client.
     *
     * @param  int         $client_id
     * @return object|null
     */
    public static function get( int $client_id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_credits WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEDUCT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Deduct credit from a client's balance after a successful send.
     *
     * Steps:
     *  1. One UPDATE that checks the balance and changes it (no check-then-write)
     *  2. Stop if no row changed
     *  3. Insert transaction record
     *  4. Check if balance dropped below 20% → send warning
     *  5. Check if balance hit zero → set paused = 1
     *
     * @param  int    $client_id
     * @param  string $channel    'sms'
     * @param  float  $amount
     * @return bool               False if insufficient balance.
     */
    public static function deduct( int $client_id, string $channel, float $amount ): bool {
        global $wpdb;

        // Nothing is charged on the platform's own account.
        if ( OFP_Platform::is_platform( $client_id ) ) {
            return true;
        }
        if ( ! in_array( $channel, self::CHANNELS, true ) ) {
            return false;
        }
        $p   = $wpdb->prefix;
        $col = $channel;

        // One UPDATE that checks the balance itself. If two sends run together,
        // only the ones that still fit get a changed row (S2-01).
        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_credits
                 SET {$col}_used      = {$col}_used + %f,
                     {$col}_remaining = {$col}_remaining - %f,
                     updated_at       = NOW()
                 WHERE client_id = %d AND {$col}_remaining >= %f",
                $amount, $amount, $client_id, $amount
            )
        );

        if ( 1 !== (int) $changed ) {
            return false;
        }

        $updated = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$col}_loaded, {$col}_remaining, low_warned
                 FROM {$p}ofp_credits WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        // Log transaction.
        $wpdb->insert(
            $p . 'ofp_credit_transactions',
            [
                'client_id'     => $client_id,
                'channel'       => $channel,
                'type'          => 'deduction',
                'amount'        => $amount,
                'balance_after' => $updated->{$col . '_remaining'},
                'created_at'    => current_time( 'mysql' ),
            ]
        );

        $loaded    = (float) $updated->{$col . '_loaded'};
        $remaining = (float) $updated->{$col . '_remaining'};

        // Low balance warning — only send once per cycle.
        if ( $loaded > 0 && ( $remaining / $loaded ) < 0.20 && ! $updated->low_warned ) {
            self::trigger_low_warning( $client_id, $channel, $remaining );
        }

        // Pause the pipeline when SMS credit runs out.
        if ( 'sms' === $channel && $remaining <= 0 ) {
            $wpdb->update(
                $p . 'ofp_credits',
                [ 'paused' => 1 ],
                [ 'client_id' => $client_id ]
            );
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOP UP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add credit to a client's balance.
     * Resets paused and low_warned flags automatically.
     *
     * @param  int    $client_id
     * @param  string $channel    'sms'
     * @param  float  $amount     Amount in NGN to add.
     * @param  string $reference  Payment reference for the transaction log.
     * @return bool               False when the channel is not allowed, or the reference was
     *                            already credited (G4.01).
     */
    public static function topup(
        int $client_id,
        string $channel,
        float $amount,
        string $reference = ''
    ): bool {
        global $wpdb;
        if ( ! in_array( $channel, self::CHANNELS, true ) ) {
            return false;
        }
        $p   = $wpdb->prefix;
        $col = $channel;

        // G4.01: with a reference, write the transaction row FIRST. The table has a
        // UNIQUE KEY (reference, type), so only one request can win. The loser
        // gets false and adds no credit.
        $tx_id = 0;
        if ( '' !== $reference ) {
            $claimed = $wpdb->insert(
                $p . 'ofp_credit_transactions',
                [
                    'client_id'     => $client_id,
                    'channel'       => $channel,
                    'type'          => 'topup',
                    'amount'        => $amount,
                    'balance_after' => 0,
                    'reference'     => sanitize_text_field( $reference ),
                    'created_at'    => current_time( 'mysql' ),
                ]
            );
            if ( ! $claimed ) {
                return false;
            }
            $tx_id = (int) $wpdb->insert_id;
        }

        $credited = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_credits
                 SET {$col}_loaded    = {$col}_loaded + %f,
                     {$col}_remaining = {$col}_remaining + %f,
                     paused           = CASE WHEN sms_remaining <= 0 THEN 1 ELSE 0 END,
                     low_warned       = 0,
                     updated_at       = NOW()
                 WHERE client_id = %d",
                $amount, $amount, $client_id
            )
        );

        // No credit row to update: undo the claim so nothing is recorded without money moving.
        if ( 1 !== (int) $credited ) {
            if ( $tx_id ) {
                $wpdb->delete( $p . 'ofp_credit_transactions', [ 'id' => $tx_id ] );
            }
            return false;
        }

        $updated = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$col}_remaining FROM {$p}ofp_credits WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        if ( $tx_id ) {
            $wpdb->update(
                $p . 'ofp_credit_transactions',
                [ 'balance_after' => $updated->{$col . '_remaining'} ],
                [ 'id' => $tx_id ]
            );
        } else {
            $wpdb->insert(
                $p . 'ofp_credit_transactions',
                [
                    'client_id'     => $client_id,
                    'channel'       => $channel,
                    'type'          => 'topup',
                    'amount'        => $amount,
                    'balance_after' => $updated->{$col . '_remaining'},
                    'reference'     => null,
                    'created_at'    => current_time( 'mysql' ),
                ]
            );
        }

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( ucfirst( $channel ) . ' credit topped up', $client_id, [
                'channel'   => $channel,
                'amount'    => $amount,
                'reference' => $reference,
            ] );
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DAILY CHECK (WP-Cron)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Daily credit balance check for all active clients.
     * Sends low-credit warnings where not already warned.
     * Called by OFP_Cron_Handler on 'ofp_daily_credit_check' hook.
     *
     * @return void
     */
    public static function run_daily_check(): void {
        global $wpdb;

        $clients = $wpdb->get_results(
            "SELECT cr.client_id,
                    cr.sms_loaded, cr.sms_remaining,
                    cr.low_warned
             FROM {$wpdb->prefix}ofp_credits cr
             JOIN {$wpdb->prefix}ofp_clients c ON c.id = cr.client_id
             WHERE c.status = 'active' AND cr.low_warned = 0"
        );

        foreach ( $clients as $c ) {
            if ( $c->sms_loaded > 0 &&
                 ( (float) $c->sms_remaining / (float) $c->sms_loaded ) < 0.20 ) {
                self::trigger_low_warning( $c->client_id, 'sms', (float) $c->sms_remaining );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a low credit warning email and set the low_warned flag.
     *
     * @param  int    $client_id
     * @param  string $channel
     * @param  float  $remaining
     * @return void
     */
    private static function trigger_low_warning(
        int $client_id,
        string $channel,
        float $remaining
    ): void {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        if ( ! $client || OFP_Platform::is_platform( $client ) ) return;

        OFP_Mailer::send_low_credit_warning( $client, $channel, $remaining );

        $wpdb->update(
            $wpdb->prefix . 'ofp_credits',
            [ 'low_warned' => 1 ],
            [ 'client_id'  => $client_id ]
        );
    }
}
