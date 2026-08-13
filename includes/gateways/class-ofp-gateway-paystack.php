<?php
/**
 * OFP_Gateway_Paystack
 *
 * Paystack Dedicated Virtual Accounts adapter.
 * Implements OFP_Gateway_Interface.
 *
 * PAYSTACK VIRTUAL ACCOUNTS:
 *  Paystack calls them "Dedicated Virtual Accounts" (DVA).
 *  Each customer gets a dedicated account from Paystack's bank partners.
 *  Payments trigger the charge.success webhook event.
 *
 * WEBHOOK VERIFICATION:
 *  Paystack signs webhooks with HMAC SHA512 using your secret key.
 *  The signature is in the x-paystack-signature header.
 *
 * Docs: https://paystack.com/docs/payments/dedicated-virtual-accounts/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Gateway_Paystack implements OFP_Gateway_Interface {

    private string $secret_key;
    private string $base_url = 'https://api.paystack.co';

    public function __construct() {
        $this->secret_key = OFP_Security::decrypt( get_option( 'ofp_paystack_secret_key', '' ) );
    }

    public function is_configured(): bool {
        return ! empty( $this->secret_key );
    }

    public function create_virtual_account( array $client_data, int $client_id ): ?object {
        $customer_code = $this->create_customer( $client_data, $client_id );
        if ( ! $customer_code ) return null;

        $response = wp_remote_post(
            $this->base_url . '/dedicated_account',
            [
                'headers' => $this->get_headers(),
                'body'    => wp_json_encode( [
                    'customer'       => $customer_code,
                    'preferred_bank' => 'wema-bank',
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[OFP_Paystack] create_virtual_account error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! ( $body->status ?? false ) || empty( $body->data->account_number ) ) {
            error_log( '[OFP_Paystack] DVA creation failed: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        return (object) [
            'account_number' => $body->data->account_number,
            'bank_name'      => $body->data->bank->name ?? 'Paystack',
        ];
    }

    public function initiate_transaction( array $args ): ?string {
        if ( ! $this->secret_key ) {
            error_log( 'OFP Paystack initiate_transaction — missing secret key' );
            return null;
        }

        $amount_kobo = (int) round( (float) $args['amount'] * 100 );

        $response = wp_remote_post( 'https://api.paystack.co/transaction/initialize', [
            'headers' => $this->get_headers(),
            'body' => wp_json_encode( [
                'email'        => $args['email'],
                'amount'       => $amount_kobo,
                'currency'     => 'NGN',
                'reference'    => $args['reference'],
                'callback_url' => $args['redirect_url'],
                'metadata'     => [
                    'client_id'   => $args['client_id'],
                    'description' => $args['description'],
                ],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Paystack initiate_transaction request error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->status ) || empty( $body->data->authorization_url ) ) {
            error_log( 'OFP Paystack initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        return $body->data->authorization_url;
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $payload   = $request->get_body();
        $signature = $request->get_header( 'x-paystack-signature' );

        $expected = hash_hmac( 'sha512', $payload, $this->secret_key );
        if ( ! hash_equals( $expected, (string) $signature ) ) {
            error_log( '[OFP_Paystack] Webhook signature mismatch.' );
            return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
        }

        $data  = json_decode( $payload );
        $event = $data->event ?? '';

        if ( $event !== 'charge.success' ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $reference = $data->data->reference ?? '';

        // Property commerce gets its own handler and never falls through to
        // the CRM/subscription payment processor.
        if ( $reference && class_exists( 'OFP_Property_Payment_Context' ) && OFP_Property_Payment_Context::is_reference( $reference ) ) {
            $amount_paid = ( (float) ( $data->data->amount ?? 0 ) ) / 100;
            $processed = OFP_Property_Payment_Context::process_verified_payment(
                $reference,
                $amount_paid,
                'paystack',
                (string) ( $data->data->id ?? $reference )
            );
            return new WP_REST_Response( [ 'status' => $processed ? 'property_payment_processed' : 'property_payment_rejected' ], $processed ? 200 : 422 );
        }

        if ( $reference && OFP_Payment::is_credit_topup_reference( $reference ) ) {
            $amount_paid = ( (float) ( $data->data->amount ?? 0 ) ) / 100;
            OFP_Payment::confirm_credit_topup( $reference, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
        }

        if ( $reference && OFP_Payment::is_subscription_checkout_reference( $reference ) ) {
            $amount_paid = ( (float) ( $data->data->amount ?? 0 ) ) / 100;
            OFP_Payment::confirm_subscription_checkout( $reference, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'subscription_checkout_processed' ], 200 );
        }

        // Legacy client virtual-account payments continue through the existing
        // subscription handler. Unknown references are ignored rather than
        // guessed into the subscription business.
        $client_id   = (int) ( $data->data->metadata->ofp_client_id ?? 0 );
        $amount      = (float) ( $data->data->amount ?? 0 ) / 100;
        $payment_ref = sanitize_text_field( $data->data->reference ?? '' );

        if ( ! $client_id || $amount <= 0 ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $this->process_payment( $client_id, $amount, $payment_ref );
        return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
    }

    private function create_customer( array $client_data, int $client_id ): ?string {
        $name_parts = explode( ' ', $client_data['owner_name'], 2 );

        $response = wp_remote_post(
            $this->base_url . '/customer',
            [
                'headers' => $this->get_headers(),
                'body'    => wp_json_encode( [
                    'email'      => $client_data['email'],
                    'first_name' => $name_parts[0] ?? $client_data['owner_name'],
                    'last_name'  => $name_parts[1] ?? $client_data['business_name'],
                    'metadata'   => [ 'ofp_client_id' => $client_id ],
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        return $body->data->customer_code ?? null;
    }

    private function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Content-Type'  => 'application/json',
        ];
    }

    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {
        OFP_Subscription::process_gateway_payment( $client_id, $amount, $payment_ref, 'paystack_virtual_account' );
    }
}
