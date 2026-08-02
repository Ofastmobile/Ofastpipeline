<?php
/**
 * OFP_Client
 *
 * Handles all client record operations — create, read, update, delete.
 *
 * THE ONBOARDING CHAIN (what happens when create() is called):
 *  1.  Validate required fields
 *  2.  Generate a temporary password (plaintext, emailed once, never stored plain)
 *  3.  Insert the client row into wp_ofp_clients
 *  4.  Create the credit record in wp_ofp_credits (starts at zero balance)
 *  5.  Create subscriptions based on what was requested (crm / listing / both)
 *      — OFP_Subscription::create() handles this, including pipeline_config for CRM
 *  6.  Create a Monnify virtual account for subscription payments
 *  7.  Send the welcome email with login credentials + virtual account details
 *
 * TWO ONBOARDING PATHS (v2.1):
 *  - Manual  : Admin creates client via wp-admin form. Status goes straight to 'active'.
 *  - Self-serve: Client signs up via /signup. Status starts as 'pending_review'.
 *                Admin must approve before the account activates (fraud gate).
 *
 * Depends on: OFP_Security, OFP_Subscription, OFP_Monnify, OFP_Mailer, OFP_Credit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Client {

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new client and run the full onboarding chain.
     *
     * @param array $data {
     *     Required keys:
     *     @type string   $business_name      Business trading name.
     *     @type string   $owner_name         Full name of the business owner.
     *     @type string   $email              Contact email (must be unique).
     *     @type string   $phone              Primary phone number.
     *
     *     Optional keys:
     *     @type string   $business_phone     Phone for IVR call transfer (defaults to $phone).
     *     @type string   $whatsapp_number    WhatsApp number (defaults to $phone).
     *     @type string   $subdomain          Requested subdomain slug.
     *     @type string   $plan               CRM plan: 'starter'|'growth'|'pro'. Default 'starter'.
     *     @type string   $business_category  e.g. 'property', 'food', 'fashion'.
     *     @type string   $onboarding_source  'manual' (default) or 'self_serve'.
     *     @type string   $status             Override default status (rarely needed).
     *     @type array    $subscriptions      Which types to create: ['crm'], ['listing'], or both.
     *                                        Defaults to ['crm'] for backward compatibility.
     * }
     *
     * @return int|false  The new client ID on success, false on failure.
     */
    public static function create( array $data ): int|false {
        global $wpdb;

        // ── 1. Validate required fields ───────────────────────────────────────
        $required = [ 'business_name', 'owner_name', 'email', 'phone' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                error_log( "[OFP_Client::create] Missing required field: {$field}" );
                return false;
            }
        }

        $email = sanitize_email( $data['email'] );
        if ( ! is_email( $email ) ) {
            error_log( "[OFP_Client::create] Invalid email: {$data['email']}" );
            return false;
        }

        // Check for duplicate email.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                $email
            )
        );
        if ( $exists ) {
            error_log( "[OFP_Client::create] Duplicate email: {$email}" );
            return false;
        }

        // ── 2. Prepare data ───────────────────────────────────────────────────
        $temp_password      = self::generate_temp_password();
        $onboarding_source  = $data['onboarding_source'] ?? 'manual';
        $plan               = sanitize_text_field( $data['plan'] ?? 'starter' );
        $subscriptions      = $data['subscriptions'] ?? [ 'crm' ]; // default to CRM only
        $phone              = OFP_Security::sanitize_phone( $data['phone'] );
        $business_phone     = OFP_Security::sanitize_phone( $data['business_phone'] ?? $data['phone'] );
        $whatsapp_number    = OFP_Security::sanitize_phone( $data['whatsapp_number'] ?? $data['phone'] );

        // Self-serve signups start as pending_review until manually approved.
        // Manual onboarding (admin-created) goes straight to active.
        if ( isset( $data['status'] ) ) {
            $status = sanitize_text_field( $data['status'] );
        } else {
            $status = ( $onboarding_source === 'self_serve' ) ? 'pending_review' : 'active';
        }

        // ── 3. Insert client row ──────────────────────────────────────────────
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ofp_clients',
            [
                'business_name'        => sanitize_text_field( $data['business_name'] ),
                'owner_name'           => sanitize_text_field( $data['owner_name'] ),
                'email'                => $email,
                'phone'                => $phone,
                'password'             => password_hash( $temp_password, PASSWORD_BCRYPT ),
                'subdomain'            => sanitize_title( $data['subdomain'] ?? '' ),
                'plan'                 => $plan,
                'status'               => $status,
                'onboarding_source'    => $onboarding_source,
                'business_category'    => sanitize_text_field( $data['business_category'] ?? '' ),
                'business_phone'       => $business_phone,
                'whatsapp_number'      => $whatsapp_number,
                'subscription_expires' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
                'created_at'           => current_time( 'mysql' ),
            ]
        );

        if ( ! $inserted ) {
            error_log( '[OFP_Client::create] DB insert failed: ' . $wpdb->last_error );
            return false;
        }

        $client_id = (int) $wpdb->insert_id;

        // ── 4. Create credit record ───────────────────────────────────────────
        // Starts at zero balance. Client loads credit separately.
        $wpdb->insert(
            $wpdb->prefix . 'ofp_credits',
            [
                'client_id'  => $client_id,
                'updated_at' => current_time( 'mysql' ),
            ]
        );

        // ── 5. Create subscriptions ───────────────────────────────────────────
        // OFP_Subscription::create() also handles pipeline_config creation
        // for CRM subscriptions. Listing-only clients don't get pipeline_configs.
        $listing_plan = isset( $data['listing_plan'] ) ? sanitize_text_field( $data['listing_plan'] ) : 'bronze';

        foreach ( $subscriptions as $sub_type ) {
            $sub_type = sanitize_text_field( $sub_type );
            if ( in_array( $sub_type, [ 'crm', 'listing' ], true ) ) {
                $sub_plan = null;
                if ( $sub_type === 'crm' ) {
                    $sub_plan = $plan;
                } elseif ( $sub_type === 'listing' ) {
                    $sub_plan = $listing_plan;
                }

                OFP_Subscription::create(
                    $client_id,
                    $sub_type,
                    $sub_plan
                );
            }
        }

        // ── 6. Create virtual account via configured payment gateway ─────────
        // OFP_Payment is a provider-agnostic interface built in Phase 6.
        // It supports any Nigerian gateway that offers dedicated virtual accounts
        // (Monnify, Paystack, Flutterwave, Providus, etc.).
        // The active provider is configured in wp-admin → OFast Pipeline → Settings.
        //
        // Guard: if OFP_Payment is not built yet (Phase 6), skip gracefully.
        // The virtual account will be created when Phase 6 is deployed.
        if ( class_exists( 'OFP_Payment' ) ) {
            $account = OFP_Payment::create_virtual_account(
                [
                    'business_name' => $data['business_name'],
                    'owner_name'    => $data['owner_name'],
                    'email'         => $email,
                ],
                $client_id
            );

            if ( $account ) {
                $wpdb->update(
                    $wpdb->prefix . 'ofp_clients',
                    [
                        'virtual_account_number' => sanitize_text_field( $account->account_number ?? '' ),
                        'virtual_bank_name'      => sanitize_text_field( $account->bank_name ?? '' ),
                    ],
                    [ 'id' => $client_id ]
                );
            }
        }

        // ── 7. Send welcome email ─────────────────────────────────────────────
        // Pass the plaintext temp password — it's only used here to email the
        // client. The hash is already stored in the DB above.
        OFP_Mailer::send_welcome_email( $client_id, $temp_password );

        // Store the temp password in a short-lived transient so the admin can
        // see it immediately on the client detail page, in case SMTP is not
        // yet configured (e.g. local development) and the email never arrives.
        // Expires in 1 hour — purely a convenience, not a permanent record.
        set_transient( 'ofp_temp_password_' . $client_id, $temp_password, HOUR_IN_SECONDS );

        return $client_id;
    }

    /**
     * Retrieve a just-created client's temporary password, if still available.
     * Only valid for 1 hour after creation — purely a local-dev / no-SMTP
     * convenience so the admin isn't locked out of testing login.
     *
     * @param  int          $client_id
     * @return string|false            The temp password, or false if expired/unavailable.
     */
    public static function get_temp_password( int $client_id ): string|false {
        return get_transient( 'ofp_temp_password_' . $client_id );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch a single client by ID.
     *
     * @param  int         $id  Client ID.
     * @return object|null      Full ofp_clients row, or null if not found.
     */
    public static function get( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $id
            )
        );
    }

    /**
     * Fetch a single client by email address.
     *
     * @param  string      $email  Email address.
     * @return object|null         Full ofp_clients row, or null if not found.
     */
    public static function get_by_email( string $email ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                sanitize_email( $email )
            )
        );
    }

    /**
     * Fetch all clients, ordered by most recently created.
     * Trashed clients are EXCLUDED by default — pass status='trash' explicitly
     * to retrieve them, or use get_trashed() for clarity.
     *
     * @param  string|null $status  Optional status filter ('active', 'suspended', etc.).
     * @return array                Array of ofp_clients rows.
     */
    public static function all( ?string $status = null ): array {
        global $wpdb;

        if ( $status ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ofp_clients
                     WHERE status = %s
                     ORDER BY created_at DESC",
                    $status
                )
            );
        }

        // Default: exclude trash from the general list.
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ofp_clients
             WHERE status != 'trash'
             ORDER BY created_at DESC"
        );
    }

    /**
     * Count clients, optionally filtered by status.
     *
     * @param  string|null $status  Optional status filter.
     * @return int                  Client count.
     */
    public static function count( ?string $status = null ): int {
        global $wpdb;

        if ( $status ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_clients WHERE status = %s",
                    $status
                )
            );
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_clients"
        );
    }

    /**
     * Fetch clients pending admin review (self-serve signups awaiting approval).
     *
     * @return array  Array of ofp_clients rows with status = 'pending_review'.
     */
    public static function get_pending_review(): array {
        return self::all( 'pending_review' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update one or more fields on a client record.
     *
     * @param  int   $id    Client ID.
     * @param  array $data  Associative array of column => value pairs to update.
     * @return bool         True on success.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        // Always stamp updated_at.
        $data['updated_at'] = current_time( 'mysql' );

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            $data,
            [ 'id' => $id ]
        );
    }

    /**
     * Update a client's status field only.
     * Thin wrapper around update() for the common status-change operation.
     *
     * @param  int    $id      Client ID.
     * @param  string $status  New status value.
     * @return bool
     */
    public static function update_status( int $id, string $status ): bool {
        return self::update( $id, [ 'status' => sanitize_text_field( $status ) ] );
    }

    /**
     * Approve a pending_review client (self-serve signup gate).
     *
     * Sets status to active, sends an approval notification email,
     * and stamps the subscription start date from today.
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function approve( int $id ): bool {
        global $wpdb;

        $client = self::get( $id );
        if ( ! $client || $client->status !== 'pending_review' ) {
            return false;
        }

        $updated = self::update( $id, [
            'status'               => 'active',
            'subscription_expires' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
        ] );

        if ( $updated ) {
            OFP_Mailer::send_approval_notification( $client );
        }

        return $updated;
    }

    /**
     * Reset a client's password by sending them a secure reset link via email.
     *
     * Delegates to OFP_Auth::request_password_reset() which:
     *  - Generates a cryptographically secure token
     *  - Stores its hash + expiry in the DB
     *  - Sends a properly-formed /reset-password?email=...&token=... link
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function reset_password( int $id ): bool {
        $client = self::get( $id );
        if ( ! $client ) {
            return false;
        }

        return OFP_Auth::request_password_reset( $client->email );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRASH SYSTEM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Move a client to trash.
     *
     * Mirrors WordPress's own post trash pattern:
     *  - Client is hidden from all normal admin lists
     *  - Client cannot log in (require_client_login + status check blocks them)
     *  - All pending automation triggers are cancelled
     *  - Data is fully preserved and recoverable
     *  - Auto-purged permanently after 30 days (OFP_Cron_Handler)
     *
     * Primarily intended for removing demo/test clients cleanly on a live site
     * without losing the ability to review what was tested, and as a safer
     * alternative to permanent deletion for any client.
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function trash( int $id ): bool {
        global $wpdb;

        $updated = (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [
                'status'     => 'trash',
                'trashed_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );

        if ( $updated && class_exists( 'OFP_Queue' ) ) {
            // Stop any automation from firing for a trashed client.
            OFP_Queue::cancel_for_client( $id );
        }

        return $updated;
    }

    /**
     * Restore a trashed client back to active status.
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function restore( int $id ): bool {
        global $wpdb;

        $client = self::get( $id );
        if ( ! $client || $client->status !== 'trash' ) {
            return false;
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [
                'status'     => 'active',
                'trashed_at' => null,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );
    }

    /**
     * Fetch all clients currently in trash.
     *
     * @return array  Array of ofp_clients rows with status = 'trash'.
     */
    public static function get_trashed(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ofp_clients
             WHERE status = 'trash'
             ORDER BY trashed_at DESC"
        );
    }

    /**
     * Soft-delete a client by setting their status to 'cancelled'.
     * This is distinct from trash() — cancelled means subscription ended
     * but the client is still visible in normal lists. trash() hides them.
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function delete( int $id ): bool {
        return self::update_status( $id, 'cancelled' );
    }

    /**
     * Permanently delete a client and all related records.
     *
     * ⚠️  This is irreversible. Only available to super_admin, and only
     * callable from the Trash tab (clients must be trashed first).
     * Deletes: client row, leads, trigger queue, communications log,
     * IVR responses, credits, credit transactions, subscriptions,
     * pipeline config, archives, sessions, properties.
     *
     * @param  int  $id  Client ID.
     * @return bool      True on success.
     */
    public static function hard_delete( int $id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        $related_tables = [
            "DELETE FROM {$p}ofp_leads WHERE client_id = %d",
            "DELETE FROM {$p}ofp_trigger_queue WHERE client_id = %d",
            "DELETE FROM {$p}ofp_communications_log WHERE client_id = %d",
            "DELETE FROM {$p}ofp_ivr_responses WHERE client_id = %d",
            "DELETE FROM {$p}ofp_credits WHERE client_id = %d",
            "DELETE FROM {$p}ofp_credit_transactions WHERE client_id = %d",
            "DELETE FROM {$p}ofp_subscriptions WHERE client_id = %d",
            "DELETE FROM {$p}ofp_pipeline_configs WHERE client_id = %d",
            "DELETE FROM {$p}ofp_archives WHERE client_id = %d",
            "DELETE FROM {$p}ofp_client_sessions WHERE client_id = %d",
            "DELETE FROM {$p}ofp_properties WHERE client_id = %d",
            "DELETE FROM {$p}ofp_property_inquiries WHERE client_id = %d",
        ];

        foreach ( $related_tables as $sql ) {
            $wpdb->query( $wpdb->prepare( $sql, $id ) );
        }

        return (bool) $wpdb->delete( $wpdb->prefix . 'ofp_clients', [ 'id' => $id ] );
    }

    /**
     * Permanently purge all clients that have been in trash for 30+ days.
     * Called by the monthly cron job (OFP_Cron_Handler).
     *
     * @return int  Number of clients purged.
     */
    public static function purge_old_trash(): int {
        global $wpdb;

        $old_trashed = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}ofp_clients
             WHERE status = 'trash'
               AND trashed_at < DATE_SUB( NOW(), INTERVAL 30 DAY )"
        );

        foreach ( $old_trashed as $id ) {
            self::hard_delete( (int) $id );
        }

        return count( $old_trashed );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a secure temporary password.
     * 10 characters, mixed alphanumeric, no ambiguous characters (0, O, l, 1).
     *
     * @return string
     */
    private static function generate_temp_password(): string {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        $max      = strlen( $chars ) - 1;

        for ( $i = 0; $i < 10; $i++ ) {
            $password .= $chars[ random_int( 0, $max ) ];
        }

        return $password;
    }

    /**
     * Check whether a given email address is already registered as a client.
     *
     * @param  string $email  Email to check.
     * @return bool           True if the email exists in ofp_clients.
     */
    public static function email_exists( string $email ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                sanitize_email( $email )
            )
        );
    }

    /**
     * Return a summary stats array for a given client.
     * Used on the admin client-detail page and the client's own dashboard.
     *
     * @param  int   $id  Client ID.
     * @return array      Associative array of stats.
     */
    public static function get_stats( int $id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        return [
            'total_leads'     => (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_leads WHERE client_id = %d", $id )
            ),
            'leads_today'     => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_leads
                     WHERE client_id = %d AND DATE(created_at) = CURDATE()",
                    $id
                )
            ),
            'leads_converted' => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_leads WHERE client_id = %d AND status = 'converted'",
                    $id
                )
            ),
            'sms_sent'        => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_communications_log
                     WHERE client_id = %d AND type = 'sms'",
                    $id
                )
            ),
            'calls_made'      => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_communications_log
                     WHERE client_id = %d AND type = 'voice'",
                    $id
                )
            ),
            'pending_triggers' => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_trigger_queue
                     WHERE client_id = %d AND status = 'pending'",
                    $id
                )
            ),
        ];
    }
}
