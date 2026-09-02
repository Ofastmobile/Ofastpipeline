<?php
/**
 * OFP_Comms
 *
 * Shared helpers for the communication module:
 * placeholders, wrappers, logging, template limits.
 *
 * Pipeline A (system emails TO the client) lives on OFP_Mailer::send().
 * Pipeline B (emails TO buyers/leads) uses OFP_Mailer::send_client_email().
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Comms {

    const SMS_COST = 6.99;

    const SAMPLE_VARS = [
        'name'           => 'John Doe',
        'email'          => 'john@example.com',
        'phone'          => '08012345678',
        'property_title' => 'Luxury Villa',
        'business_name'  => 'PrimeEstate',
    ];

    public static function sample_body_html(): string {
        return '<h2>Your Payment Was Successful</h2>
<p>Hi John, your payment of ₦500,000 for Luxury Villa has been confirmed.</p>
<p>Your next installment of ₦250,000 is due on March 15, 2026.</p>
<p><a href="#">View Transaction</a></p>';
    }

    public static function apply_placeholders( string $text, array $vars ): string {
        $map = [
            '{{name}}'           => (string) ( $vars['name'] ?? '' ),
            '{{email}}'          => (string) ( $vars['email'] ?? '' ),
            '{{phone}}'          => (string) ( $vars['phone'] ?? '' ),
            '{{property_title}}' => (string) ( $vars['property_title'] ?? '' ),
            '{{business_name}}'  => (string) ( $vars['business_name'] ?? '' ),
            '{{buyer_name}}'     => (string) ( $vars['name'] ?? '' ),
            '{{buyer_email}}'    => (string) ( $vars['email'] ?? '' ),
        ];
        return strtr( $text, $map );
    }

    /**
     * Inject body into a wrapper. Supports {{content}} and legacy {email_body}.
     */
    public static function apply_wrapper( string $wrapper, string $content ): string {
        $wrapper = (string) $wrapper;
        if ( $wrapper === '' ) {
            return $content;
        }
        if ( str_contains( $wrapper, '{{content}}' ) ) {
            return str_replace( '{{content}}', $content, $wrapper );
        }
        if ( str_contains( $wrapper, '{email_body}' ) ) {
            return str_replace( '{email_body}', $content, $wrapper );
        }
        return $wrapper . $content;
    }

    public static function admin_wrapper(): string {
        return (string) get_option( 'ofp_universal_email_template', '' );
    }

    /**
     * Client email wrapper HTML for Pipeline B.
     */
    public static function client_wrapper( int $client_id, ?int $template_id = null ): string {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = null;
        if ( $template_id ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT body FROM {$p}ofp_client_templates WHERE id = %d AND client_id = %d AND type = 'email' LIMIT 1",
                $template_id,
                $client_id
            ) );
        }
        if ( ! $row ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT body FROM {$p}ofp_client_templates WHERE client_id = %d AND type = 'email' AND is_default = 1 LIMIT 1",
                $client_id
            ) );
        }
        if ( ! $row ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT body FROM {$p}ofp_client_templates WHERE client_id = %d AND type = 'email' ORDER BY id ASC LIMIT 1",
                $client_id
            ) );
        }

        if ( $row && ! empty( $row->body ) ) {
            return (string) $row->body;
        }

        return self::admin_wrapper();
    }

    /**
     * 0 = Free (none), 1 = Silver, -1 = Gold unlimited.
     */
    public static function template_limit( int $client_id ): int {
        $plan = OFP_Subscription::client_plan( $client_id );
        return match ( $plan ) {
            'gold'   => -1,
            'silver' => 1,
            default  => 0,
        };
    }

    public static function can_edit_templates( int $client_id ): bool {
        return OFP_Subscription::allows_email_templates( $client_id );
    }

    public static function log(
        int $client_id,
        int $lead_id,
        string $type,
        string $message,
        string $status,
        float $cost = 0.0,
        string $provider = '',
        string $provider_ref = ''
    ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ofp_communications_log',
            [
                'client_id'    => $client_id,
                'lead_id'      => $lead_id,
                'type'         => $type,
                'direction'    => 'outbound',
                'message'      => $message,
                'status'       => $status,
                'provider'     => $provider,
                'provider_ref' => $provider_ref,
                'cost'         => $cost,
                'sent_at'      => current_time( 'mysql' ),
            ]
        );
    }
}
