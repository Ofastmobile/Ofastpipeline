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
 *  DVAs are used for BUYER property payments (bank transfer), not client
 *  subscription funding. One DVA is created per purchase, and can receive
 *  any number of installment deposits over the life of that purchase.
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

    /**
     * Asks Paystack directly about one payment (Verify Transaction).
     * This does not depend on Paystack reaching our webhook, so a blocked or
     * missing webhook can no longer leave a paid client without their plan.
     *
     * @return array|null [ 'status' => 'success'|'failed'|'abandoned'|..., 'amount' => naira, 'id' => string ]
     *                    or null when Paystack could not be reached or does not know the reference.
     */
    public function verify_transaction( string $reference ): ?array {
        if ( ! $this->secret_key || $reference === '' ) {
            return null;
        }
        $response = wp_remote_get(
            $this->base_url . '/transaction/verify/' . rawurlencode( $reference ),
            [ 'headers' => $this->get_headers(), 'timeout' => 20 ]
        );
        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Paystack verify request error: ' . $response->get_error_message() );
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->status ) || empty( $body->data ) ) {
            return null;
        }
        return [
            'status' => (string) ( $body->data->status ?? '' ),
            'amount' => ( (float) ( $body->data->amount ?? 0 ) ) / 100,
            'id'     => (string) ( $body->data->id ?? '' ),
        ];
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $payload   = $request->get_body();
        $signature = $request->get_header( 'x-paystack-signature' );

        // With no secret key saved, the signature check below would accept a
        // forged request that was signed with an empty key. Refuse instead.
        if ( ! $this->secret_key ) {
            return new WP_REST_Response( [ 'error' => 'Gateway not configured.' ], 401 );
        }

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

        return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
    }

    private function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->secret_key,
            'Content-Type'  => 'application/json',
        ];
    }
}
