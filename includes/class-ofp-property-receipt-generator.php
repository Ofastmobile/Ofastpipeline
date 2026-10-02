<?php
/**
 * Universal receipt generator.
 *
 * Renders the HTML receipt template (Ofast_Pipeline_Universal_Receipt.html)
 * for any transaction type — purchase installments, lease rent payments,
 * or future transaction types. Only the sections relevant to the specific
 * transaction are shown; all other contextual blocks are stripped.
 *
 * The brand header uses the client's business_name so every receipt
 * appears to come from the landlord / agent, not from the platform.
 *
 * Blueprint ref: §1.5 "each payment logged with its own receipt"
 *                §1.1 "the shared receipt PDF function — tenants call
 *                      the same function, not a second one"
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Receipt_Generator {

    /** Transaction type constants. */
    const TYPE_PURCHASE    = 'purchase';
    const TYPE_INSTALLMENT = 'installment';
    const TYPE_RENT        = 'rent';

    /** Sections that only apply to certain transaction types. */
    private static array $section_visibility = [
        'tenant-section'     => [ self::TYPE_RENT ],
        'investment-section' => [],           // future-proof, hidden for now
        'booking-section'    => [],           // future-proof, hidden for now
    ];

    /**
     * Auto-generate on lease payment success.
     * Fires on the `ofp_property_lease_payment_processed` action hook.
     */
    public static function init(): void {
        add_action( 'ofp_property_lease_payment_processed', [ __CLASS__, 'on_lease_payment' ], 10, 4 );
    }

    /**
     * Hook handler: generate a receipt for every successful lease payment.
     */
    public static function on_lease_payment( int $lease_id, int $payment_id, float $amount, string $method ): void {
        self::get_or_create_lease_receipt( $payment_id );
    }

    /**
     * Retrieves an existing lease receipt or generates a new one on demand.
     */
    public static function get_or_create_lease_receipt( int $payment_id ): ?array {
        global $wpdb;
        $p = $wpdb->prefix;

        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, l.rent_amount, l.legal_fee, l.agency_fee, l.caution_fee, l.service_charge,
                    l.total_initial_package, l.cycle_number, l.amount_paid, l.balance, l.rent_period, l.custom_days,
                    l.start_date, l.end_date, l.property_id AS lease_property_id,
                    t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone, t.id AS tenant_record_id,
                    pr.title AS property_title, pr.listing_type,
                    c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone,
                    c.business_phone AS client_business_phone
             FROM {$p}ofp_property_lease_payments p
             INNER JOIN {$p}ofp_property_leases l ON l.id = p.lease_id
             INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
             WHERE p.id = %d LIMIT 1",
            $payment_id
        ) );
        if ( ! $payment ) return null;

        $uploads = wp_upload_dir();
        if ( ! empty( $payment->receipt_path ) && file_exists( $uploads['basedir'] . '/' . $payment->receipt_path ) ) {
            return [
                'path' => $payment->receipt_path,
                'mime' => $payment->receipt_mime ?: 'text/html',
                'size' => (int) $payment->receipt_size ?: filesize( $uploads['basedir'] . '/' . $payment->receipt_path ),
            ];
        }

        $html = self::render_receipt( self::TYPE_RENT, self::build_rent_data( $payment, (int) $payment->lease_id, $payment_id ) );
        $saved = self::save_receipt_file( $html, 'rent', $payment_id );
        if ( ! $saved ) return null;

        $wpdb->update( "{$p}ofp_property_lease_payments", [
            'receipt_path' => $saved['path'],
            'receipt_mime' => 'text/html',
            'receipt_size' => $saved['size'],
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $payment_id ] );

        return $saved;
    }

    /**
     * Generate receipt HTML for any transaction type.
     *
     * @param string $type One of the TYPE_* constants.
     * @param array  $data Key-value pairs matching template placeholders.
     * @return string       Fully rendered HTML.
     */
    public static function render_receipt( string $type, array $data ): string {
        $template_path = OFP_PATH . 'Ofast_Pipeline_Universal_Receipt.html';
        if ( ! file_exists( $template_path ) ) {
            error_log( '[OFP_Receipt] Template not found: ' . $template_path );
            return '';
        }

        $html = file_get_contents( $template_path );

        // ── Brand header: replace hardcoded "Ofast Pipeline" with client business name ──
        $brand_name = ! empty( $data['business_name'] ) ? $data['business_name'] : 'Ofast Pipeline';
        $brand_initials = self::initials( $brand_name );
        $html = str_replace( '<div class="brand-name">Ofast Pipeline</div>', '<div class="brand-name">' . esc_html( $brand_name ) . '</div>', $html );
        $html = str_replace( '>OP</div>', '>' . esc_html( $brand_initials ) . '</div>', $html );

        // ── Hide sections not relevant to this transaction type ──
        foreach ( self::$section_visibility as $section_id => $allowed_types ) {
            if ( ! in_array( $type, $allowed_types, true ) ) {
                // Remove the entire <section> block by id
                $html = preg_replace(
                    '/<section[^>]*id="' . preg_quote( $section_id, '/' ) . '"[^>]*>.*?<\/section>/s',
                    '',
                    $html
                );
            }
        }

        // ── Replace all {{placeholder}} tokens ──
        foreach ( $data as $key => $value ) {
            $html = str_replace( '{{' . $key . '}}', esc_html( (string) $value ), $html );
        }

        // ── Clean up any unreplaced placeholders ──
        $html = preg_replace( '/\{\{[a-z_]+\}\}/', '—', $html );

        // ── Remove Print toolbar (server-generated receipts don't need it) ──
        $html = preg_replace( '/<div class="toolbar hide-print">.*?<\/div>/s', '', $html );

        return $html;
    }

    // ── Data builders per transaction type ────────────────────────────────────

    /**
     * Builds the placeholder data array for a rent payment receipt.
     */
    private static function build_rent_data( object $payment, int $lease_id, int $payment_id ): array {
        $receipt_number = 'RNT-' . str_pad( (string) $payment_id, 6, '0', STR_PAD_LEFT );
        $now = current_time( 'mysql' );

        $method_labels = [
            'cash'            => 'Cash',
            'manual'          => 'Manual / Bank Transfer',
            'checkout'        => 'Online Checkout (Paystack)',
            'virtual_account' => 'Dedicated Virtual Account',
        ];

        return [
            // Header / meta
            'receipt_number'       => $receipt_number,
            'transaction_id'      => $payment->gateway_reference ?: $receipt_number,
            'payment_date'        => wp_date( 'M j, Y', strtotime( $payment->created_at ) ),
            'payment_time'        => wp_date( 'g:i A', strtotime( $payment->created_at ) ),
            'payment_type'        => 'Rent Payment',
            'payment_status'      => ucfirst( str_replace( '_', ' ', $payment->status ) ),
            'currency'            => 'NGN',

            // Payer
            'payer_name'          => $payment->payer_name ?: $payment->tenant_name,
            'payer_email'         => $payment->tenant_email ?: '',
            'payer_phone'         => $payment->tenant_phone ?: '',
            'payer_address'       => '',

            // Recipient (landlord / client)
            'recipient_name'      => $payment->owner_name ?: $payment->business_name ?: 'Property Manager',
            'recipient_company'   => $payment->business_name ?: '',
            'recipient_email'     => $payment->client_email ?: '',
            'recipient_phone'     => $payment->client_business_phone ?: $payment->client_phone ?: '',

            // Property
            'property_name'       => $payment->property_title ?: 'Property',
            'property_id'         => $payment->lease_property_id ?: '',
            'property_type'       => ucfirst( $payment->listing_type ?: 'Rental' ),
            'transaction_type'    => 'Rent',
            'property_location'   => '',
            'unit_reference'      => '',
            'owner_name'          => $payment->owner_name ?: $payment->business_name ?: '',
            'agent_name'          => $payment->business_name ?: '',

            // Payment breakdown
            'payment_line_items'  => sprintf(
                '<tr><td>Rent payment — %s cycle</td><td>%s</td><td>NGN %s</td></tr>',
                esc_html( OFP_Property_Rent::period_label( $payment->rent_period, (int) $payment->custom_days ) ),
                esc_html( $payment->gateway_reference ?: $receipt_number ),
                esc_html( number_format( (float) $payment->amount, 2 ) )
            ),
            'subtotal'            => 'NGN ' . number_format( (float) $payment->amount, 2 ),
            'discount'            => 'NGN 0.00',
            'fees'                => 'NGN 0.00',
            'tax'                 => 'NGN 0.00',
            'amount_paid'         => 'NGN ' . number_format( (float) $payment->amount, 2 ),
            'outstanding_balance' => 'NGN ' . number_format( (float) $payment->balance, 2 ),

            // Payment method
            'payment_method'      => $method_labels[ $payment->payment_method ] ?? ucfirst( $payment->payment_method ),
            'payment_provider'    => $payment->gateway ?: 'N/A',
            'payment_reference'   => $payment->payer_reference ?: $payment->gateway_reference ?: 'N/A',
            'payment_channel'     => $payment->payment_method ?: 'N/A',

            // Payment allocation (installment-style — adapted for rent)
            'plan_name'           => OFP_Property_Rent::period_label( $payment->rent_period, (int) $payment->custom_days ) . ' Rent (Cycle #' . ( (int) ( $payment->cycle_number ?: 1 ) ) . ')',
            'installment_number'  => 'Cycle #' . ( (int) ( $payment->cycle_number ?: 1 ) ),
            'due_date'            => $payment->end_date ? wp_date( 'M j, Y', strtotime( $payment->end_date ) ) : 'N/A',
            'next_payment_date'   => 'As scheduled',

            // Tenancy details section
            'tenant_id'           => $payment->tenant_record_id ?: '',
            'lease_id'            => $lease_id,
            'rent_period'         => OFP_Property_Rent::period_label( $payment->rent_period, (int) $payment->custom_days ),
            'lease_start'         => $payment->start_date ? wp_date( 'M j, Y', strtotime( $payment->start_date ) ) : 'Pending activation',
            'lease_end'           => $payment->end_date ? wp_date( 'M j, Y', strtotime( $payment->end_date ) ) : 'Pending activation',
            'rent_balance'        => 'NGN ' . number_format( (float) $payment->balance, 2 ),
            'security_deposit'    => ( ! empty( $payment->caution_fee ) && (float) $payment->caution_fee > 0 ) ? 'NGN ' . number_format( (float) $payment->caution_fee, 2 ) : 'N/A',
            'utility_reference'   => 'N/A',

            // Footer
            'business_name'       => $payment->business_name ?: 'Ofast Pipeline',
            'business_address'    => '',
            'business_email'      => $payment->client_email ?: '',
            'business_phone'      => $payment->client_business_phone ?: $payment->client_phone ?: '',
            'generated_at'        => wp_date( 'M j, Y g:i A', strtotime( $now ) ),
            'verification_url'    => '',
            'receipt_hash'        => hash( 'sha256', $receipt_number . $payment_id . $lease_id ),

            // Banner
            'payment_message'     => 'Thank you for your rent payment.',
            'notes'               => $payment->note ?: 'No additional notes.',
        ];
    }

    /**
     * Builds the placeholder data array for a purchase/installment payment receipt.
     * Can be called by other classes for purchase payment receipts.
     */
    public static function build_purchase_data( object $payment, int $purchase_id, int $payment_id ): array {
        $receipt_number = 'PUR-' . str_pad( (string) $payment_id, 6, '0', STR_PAD_LEFT );
        $now = current_time( 'mysql' );

        $method_labels = [
            'cash'            => 'Cash',
            'manual'          => 'Manual / Bank Transfer',
            'checkout'        => 'Online Checkout (Paystack)',
            'virtual_account' => 'Dedicated Virtual Account',
            'bank_transfer'   => 'Bank Transfer',
            'bank_deposit'    => 'Bank Deposit',
            'pos'             => 'POS',
        ];

        return [
            'receipt_number'       => $receipt_number,
            'transaction_id'      => $payment->gateway_reference ?? $receipt_number,
            'payment_date'        => wp_date( 'M j, Y', strtotime( $payment->created_at ) ),
            'payment_time'        => wp_date( 'g:i A', strtotime( $payment->created_at ) ),
            'payment_type'        => 'Property Payment',
            'payment_status'      => ucfirst( str_replace( '_', ' ', $payment->status ?? 'successful' ) ),
            'currency'            => 'NGN',

            'payer_name'          => $payment->buyer_name ?? $payment->payer_name ?? '',
            'payer_email'         => $payment->buyer_email ?? '',
            'payer_phone'         => $payment->buyer_phone ?? '',
            'payer_address'       => '',

            'recipient_name'      => $payment->owner_name ?? $payment->business_name ?? 'Property Manager',
            'recipient_company'   => $payment->business_name ?? '',
            'recipient_email'     => $payment->client_email ?? '',
            'recipient_phone'     => $payment->client_business_phone ?? $payment->client_phone ?? '',

            'property_name'       => $payment->property_title ?? 'Property',
            'property_id'         => $payment->property_id ?? '',
            'property_type'       => ucfirst( $payment->listing_type ?? 'Sale' ),
            'transaction_type'    => 'Purchase',
            'property_location'   => '',
            'unit_reference'      => '',
            'owner_name'          => $payment->owner_name ?? '',
            'agent_name'          => $payment->business_name ?? '',

            'payment_line_items'  => sprintf(
                '<tr><td>Property payment</td><td>%s</td><td>NGN %s</td></tr>',
                esc_html( $payment->gateway_reference ?? $receipt_number ),
                esc_html( number_format( (float) ( $payment->amount ?? 0 ), 2 ) )
            ),
            'subtotal'            => 'NGN ' . number_format( (float) ( $payment->amount ?? 0 ), 2 ),
            'discount'            => 'NGN 0.00',
            'fees'                => 'NGN 0.00',
            'tax'                 => 'NGN 0.00',
            'amount_paid'         => 'NGN ' . number_format( (float) ( $payment->amount ?? 0 ), 2 ),
            'outstanding_balance' => 'NGN ' . number_format( (float) ( $payment->balance ?? 0 ), 2 ),

            'payment_method'      => $method_labels[ $payment->payment_method ?? '' ] ?? ucfirst( $payment->payment_method ?? 'N/A' ),
            'payment_provider'    => $payment->gateway ?? 'N/A',
            'payment_reference'   => $payment->payer_reference ?? $payment->gateway_reference ?? 'N/A',
            'payment_channel'     => $payment->payment_method ?? 'N/A',

            'plan_name'           => 'Property Purchase',
            'installment_number'  => '',
            'due_date'            => '',
            'next_payment_date'   => '',

            'business_name'       => $payment->business_name ?? 'Ofast Pipeline',
            'business_address'    => '',
            'business_email'      => $payment->client_email ?? '',
            'business_phone'      => $payment->client_business_phone ?? $payment->client_phone ?? '',
            'generated_at'        => wp_date( 'M j, Y g:i A', strtotime( $now ) ),
            'verification_url'    => '',
            'receipt_hash'        => hash( 'sha256', $receipt_number . $payment_id . $purchase_id ),

            'payment_message'     => 'Thank you for your property payment.',
            'notes'               => $payment->note ?? 'No additional notes.',
        ];
    }

    // ── File storage ─────────────────────────────────────────────────────────

    /**
     * Writes receipt HTML to wp-content/uploads/ofp-receipts/ and returns
     * the relative path and file size.
     */
    private static function save_receipt_file( string $html, string $type, int $payment_id ): ?array {
        if ( empty( $html ) ) return null;

        $uploads = wp_upload_dir();
        $dir = $uploads['basedir'] . '/ofp-receipts/' . $type;
        if ( ! wp_mkdir_p( $dir ) ) {
            error_log( '[OFP_Receipt] Cannot create directory: ' . $dir );
            return null;
        }

        // .htaccess protection against direct directory listing
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }

        $filename = $type . '-receipt-' . $payment_id . '-' . wp_generate_password( 8, false ) . '.html';
        $filepath = $dir . '/' . $filename;
        $bytes = file_put_contents( $filepath, $html );

        if ( false === $bytes ) {
            error_log( '[OFP_Receipt] Failed to write: ' . $filepath );
            return null;
        }

        // Path relative to uploads basedir (same convention as existing receipt_path)
        return [
            'path' => 'ofp-receipts/' . $type . '/' . $filename,
            'size' => $bytes,
        ];
    }

    /**
     * Extract initials from a business name for the logo box.
     */
    private static function initials( string $name ): string {
        $words = preg_split( '/\s+/', trim( $name ) );
        if ( count( $words ) >= 2 ) {
            return strtoupper( mb_substr( $words[0], 0, 1 ) . mb_substr( $words[1], 0, 1 ) );
        }
        return strtoupper( mb_substr( $name, 0, 2 ) );
    }
}
