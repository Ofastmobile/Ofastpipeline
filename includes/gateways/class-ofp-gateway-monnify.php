<?php
/**
 * OFP_Gateway_Monnify
 *
 * Monnify payment gateway adapter.
 * Implements OFP_Gateway_Interface.
 *
 * MONNIFY VIRTUAL ACCOUNTS:
 *  Each client gets one dedicated reserved account.
 *  Payments into that account trigger the SUCCESSFUL_TRANSACTION webhook.
 *  The account reference is "ofp_client_{id}" for easy lookup in the webhook.
 *
 * WEBHOOK VERIFICATION:
 *  Monnify signs webhooks with SHA512(secret_key|payload).
 *  We verify this before processing any payment.
 *
 * Docs: https://developers.monnify.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Gateway_Monnify implements OFP_Gateway_Interface {

    private string $base_url;
    private string $api_key;
    private string $secret_key;
    private string $contract_code;

    public function __construct() {
        $this->base_url      = get_option( 'ofp_monnify_base_url', 'https://api.monnify.com' );
        $this->api_key       = OFP_Security::decrypt( get_option( 'ofp_monnify_api_key', '' ) );
        $this->secret_key    = OFP_Security::decrypt( get_option( 'ofp_monnify_secret_key', '' ) );
        $this->contract_code = get_option( 'ofp_monnify_contract_code', '' );
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        return ! empty( $this->api_key )
            && ! empty( $this->secret_key )
            && ! empty( $this->contract_code );
    }

    /**
     * {@inheritdoc}
     */
    public function create_virtual_account( array $client_data, int $client_id ): ?object {
        $token = $this->get_access_token();
        if ( ! $token ) return null;

        $response = wp_remote_post(
            $this->base_url . '/api/v2/bank-transfer/reserved-accounts',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'accountReference'    => 'ofp_client_' . $client_id,
                    'accountName'         => $client_data['business_name'] . ' — OFast Pipeline',
                    'currencyCode'        => 'NGN',
                    'contractCode'        => $this->contract_code,
                    'customerEmail'       => $client_data['email'],
                    'customerName'        => $client_data['owner_name'],
                    'getAllAvailableBanks' => false,
                    'preferredBanks'      => [ '035' ], // Wema Bank
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[OFP_Monnify] create_virtual_account error: ' . $response->get_error_message() );
            return null;
        }

        $body    = json_decode( wp_remote_retrieve_body( $response ) );
        $account = $body->responseBody->accounts[0] ?? null;

        if ( ! $account ) {
            error_log( '[OFP_Monnify] No account in response: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        // Normalise to standard format.
        return (object) [
            'account_number' => $account->accountNumber ?? '',
            'bank_name'      => $account->bankName ?? '',
        ];
    }

    /**
     * Initiate a Monnify checkout transaction for credit top-up.
     *
     * @param array $args
     * @return string|null
     */
    public function initiate_transaction( array $args ): ?string {
        $token = $this->get_access_token();
        if ( ! $token ) {
            error_log( 'OFP Monnify initiate_transaction — failed to obtain access token' );
            return null;
        }

        $response = wp_remote_post( $this->base_url . '/api/v1/merchant/transactions/init-transaction', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'amount'             => $args['amount'],
                'customerName'       => $args['name'],
                'customerEmail'      => $args['email'],
                'paymentReference'   => $args['reference'],
                'paymentDescription' => $args['description'],
                'currencyCode'       => 'NGN',
                'contractCode'       => $this->contract_code,
                'redirectUrl'        => $args['redirect_url'],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Monnify initiate_transaction request error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body->requestSuccessful ) || empty( $body->responseBody->checkoutUrl ) ) {
            error_log( 'OFP Monnify initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        return $body->responseBody->checkoutUrl;
    }

    /**
     * {@inheritdoc}
     */
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {

        $payload   = $request->get_body();
        $signature = $request->get_header( 'monnify-signature' );

        // Verify SHA512 signature.
        $expected = hash( 'sha512', $this->secret_key . '|' . $payload );
        if ( ! hash_equals( $expected, (string) $signature ) ) {
            error_log( '[OFP_Monnify] Webhook signature mismatch.' );
            return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
        }

        $data = json_decode( $payload );

        // Only process successful transactions.
        if ( ( $data->eventType ?? '' ) !== 'SUCCESSFUL_TRANSACTION' ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $topup_reference = $data->eventData->paymentReference ?? '';

        if ( $topup_reference && OFP_Payment::is_credit_topup_reference( $topup_reference ) ) {
            $amount_paid = (float) ( $data->eventData->amountPaid ?? 0 );
            OFP_Payment::confirm_credit_topup(
                $topup_reference,
                $amount_paid,
                (string) ( $data->eventData->transactionReference ?? '' )
            );
            return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
        }

        $account_ref = $data->eventData->product->reference ?? '';
        $amount      = (float) ( $data->eventData->amountPaid ?? 0 );
        $payment_ref = sanitize_text_field( $data->eventData->transactionReference ?? '' );

        // Extract client ID from the account reference "ofp_client_{id}".
        preg_match( '/ofp_client_(\d+)/', $account_ref, $matches );
        $client_id = (int) ( $matches[1] ?? 0 );

        if ( ! $client_id || $amount <= 0 ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $this->process_payment( $client_id, $amount, $payment_ref );

        return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get a Monnify access token via Basic auth.
     *
     * @return string|null  Access token, or null on failure.
     */
    private function get_access_token(): ?string {
        $credentials = base64_encode( $this->api_key . ':' . $this->secret_key );

        $response = wp_remote_post(
            $this->base_url . '/api/v1/auth/login',
            [
                'headers' => [ 'Authorization' => 'Basic ' . $credentials ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[OFP_Monnify] Auth error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        return $body->responseBody->accessToken ?? null;
    }

    /**
     * Process a verified payment — match amount, record subscription, update client.
     *
     * @param  int    $client_id
     * @param  float  $amount
     * @param  string $payment_ref
     * @return void
     */
    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {

        $expected = OFP_Subscription::get_expected_monthly_total( $client_id );

        if ( $amount < $expected ) {
            error_log(
                "[OFP_Monnify] Underpayment for client {$client_id}: "
                . "expected NGN {$expected}, got NGN {$amount}"
            );
            // Still record but don't activate — flag for manual review.
            // TODO: notify admin of partial payment.
            return;
        }

        // Determine which subscription types to renew based on what client has.
        global $wpdb;
        $has_crm     = OFP_Subscription::has_active( 'crm', $client_id )
                       || $wpdb->get_var( $wpdb->prepare(
                           "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                            WHERE client_id = %d AND type = 'crm' LIMIT 1",
                           $client_id
                       ) );
        $has_listing = OFP_Subscription::has_active( 'listing', $client_id )
                       || $wpdb->get_var( $wpdb->prepare(
                           "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                            WHERE client_id = %d AND type = 'listing' LIMIT 1",
                           $client_id
                       ) );

        if ( $has_crm ) {
            OFP_Subscription::record_payment(
                $client_id, 'crm', $amount, $payment_ref, 'monnify_virtual_account'
            );
        }

        if ( $has_listing && ! $has_crm ) {
            OFP_Subscription::record_payment(
                $client_id, 'listing', $amount, $payment_ref, 'monnify_virtual_account'
            );
        }
    }
}
