<?php
/**
 * OFP_Gateway_Flutterwave
 *
 * Flutterwave Virtual Account Numbers (VAN) adapter.
 * Implements OFP_Gateway_Interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Gateway_Flutterwave implements OFP_Gateway_Interface {

    private string $secret_key;
    private string $secret_hash;
    private string $base_url = 'https://api.flutterwave.com/v3';

    public function __construct() {
        $this->secret_key  = OFP_Security::decrypt( get_option( 'ofp_flutterwave_secret_key', '' ) );
        $this->secret_hash = OFP_Security::decrypt( get_option( 'ofp_flutterwave_secret_hash', '' ) );
    }

    public function is_configured(): bool {
        return ! empty( $this->secret_key ) && ! empty( $this->secret_hash );
    }

    public function create_virtual_account( array $client_data, int $client_id ): ?object {
        $response = wp_remote_post(
            $this->base_url . '/virtual-account-numbers',
            [
                'headers' => $this->get_headers(),
                'body'    => wp_json_encode( [
                    'email'        => $client_data['email'],
                    'is_permanent' => true,
                    'bvn'          => '',
                    'tx_ref'       => 'ofp_client_' . $client_id,
                    'phonenumber'  => '',
                    'firstname'    => explode( ' ', $client_data['owner_name'] )[0] ?? '',
                    'lastname'     => $client_data['business_name'],
                    'narration'    => $client_data['business_name'] . ' — OFast Pipeline',
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[OFP_Flutterwave] create_virtual_account error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ( $body->status ?? '' ) !== 'success' || empty( $body->data->account_number ) ) {
            error_log( '[OFP_Flutterwave] VAN creation failed: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        return (object) [
            'account_number' => $body->data->account_number,
            'bank_name'      => $body->data->bank_name ?? 'Flutterwave',
        ];
    }

    public function initiate_transaction( array $args ): ?string {
        if ( ! $this->secret_key ) {
            error_log( 'OFP Flutterwave initiate_transaction — missing secret key' );
            return null;
        }

        $response = wp_remote_post( 'https://api.flutterwave.com/v3/payments', [
            'headers' => $this->get_headers(),
            'body' => wp_json_encode( [
                'tx_ref'       => $args['reference'],
                'amount'       => $args['amount'],
                'currency'     => 'NGN',
                'redirect_url' => $args['redirect_url'],
                'customer'     => [
                    'email'       => $args['email'],
                    'name'        => $args['name'],
                    'phonenumber' => $args['phone'],
                ],
                'customizations' => [
                    'title' => $args['description'],
                ],
                'meta' => [
                    'client_id' => $args['client_id'],
                ],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Flutterwave initiate_transaction request error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->status ) || $body->status !== 'success' || empty( $body->data->link ) ) {
            error_log( 'OFP Flutterwave initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        return $body->data->link;
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $signature = $request->get_header( 'verif-hash' );
        if ( $signature !== $this->secret_hash ) {
            error_log( '[OFP_Flutterwave] Webhook hash mismatch.' );
            return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
        }

        $data  = json_decode( $request->get_body() );
        $event = $data->event ?? '';

        if ( $event !== 'VIRTUAL_ACCOUNT_CREDIT' ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $tx_ref = $data->data->tx_ref ?? '';

        // Property commerce gets its own handler and never falls through to
        // the CRM/subscription payment processor.
        if ( $tx_ref && class_exists( 'OFP_Property_Payment_Context' ) && OFP_Property_Payment_Context::is_reference( $tx_ref ) ) {
            $amount_paid = (float) ( $data->data->amount ?? 0 );
            $processed = OFP_Property_Payment_Context::process_verified_payment(
                $tx_ref,
                $amount_paid,
                'flutterwave',
                (string) ( $data->data->id ?? $data->data->flw_ref ?? $tx_ref )
            );
            return new WP_REST_Response( [ 'status' => $processed ? 'property_payment_processed' : 'property_payment_rejected' ], $processed ? 200 : 422 );
        }

        if ( $tx_ref && OFP_Payment::is_credit_topup_reference( $tx_ref ) ) {
            $amount_paid = (float) ( $data->data->amount ?? 0 );
            OFP_Payment::confirm_credit_topup( $tx_ref, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
        }

        if ( $tx_ref && OFP_Payment::is_subscription_checkout_reference( $tx_ref ) ) {
            $amount_paid = (float) ( $data->data->amount ?? 0 );
            OFP_Payment::confirm_subscription_checkout( $tx_ref, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'subscription_checkout_processed' ], 200 );
        }

        // Legacy client virtual-account payments continue through the existing
        // subscription handler. Unknown references are ignored rather than guessed.
        preg_match( '/ofp_client_(\d+)/', $tx_ref, $matches );
        $client_id = (int) ( $matches[1] ?? 0 );
        $amount    = (float) ( $data->data->amount ?? 0 );
        $flw_ref   = sanitize_text_field( $data->data->flw_ref ?? '' );

        if ( ! $client_id || $amount <= 0 ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $this->process_payment( $client_id, $amount, $flw_ref );
        return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
    }

    private function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Content-Type'  => 'application/json',
        ];
    }

    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {
        OFP_Subscription::process_gateway_payment( $client_id, $amount, $payment_ref, 'flutterwave_virtual_account' );
    }
}
