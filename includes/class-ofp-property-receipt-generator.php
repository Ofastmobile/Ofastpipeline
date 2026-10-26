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
     * Auto-generate on payment success.
     * Fires on lease and purchase payment action hooks.
     */
    public static function init(): void {
        add_action( 'ofp_property_lease_payment_processed', [ __CLASS__, 'on_lease_payment' ], 10, 4 );
        add_action( 'ofp_property_payment_processed', [ __CLASS__, 'on_purchase_payment' ], 10, 6 );
    }

    /**
     * Hook handler: generate a receipt for every successful lease payment.
     */
    public static function on_lease_payment( int $lease_id, int $payment_id, float $amount, string $method ): void {
        self::get_or_create_lease_receipt( $payment_id );
    }

    /**
     * Hook handler: generate a receipt for every successful purchase payment.
     */
    public static function on_purchase_payment( int $purchase_id, int $installment_id, float $amount, $allocation = null, string $gateway = '', string $reference = '' ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $payment_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}ofp_property_payments WHERE purchase_id = %d AND status = 'successful' ORDER BY id DESC LIMIT 1",
            $purchase_id
        ) );
        if ( $payment_id > 0 ) {
            self::get_or_create_purchase_receipt( $payment_id );
        }
    }

    /**
     * Formats internal machine references into clean, professional customer-facing references.
     * e.g. ofp_property_installment_1_dYCQQJB9QW -> TXN-DYCQQJB9QW
     *      ofpl-12-abc12345                     -> TXN-ABC12345
     */
    public static function format_display_reference( ?string $reference, string $fallback = '' ): string {
        $ref = trim( (string) $reference );
        if ( $ref === '' ) {
            return $fallback;
        }

        // Internal property installment pattern: ofp_property_installment_{id}_{random}
        if ( preg_match( '/^ofp_property_installment_\d+_(.+)$/i', $ref, $m ) ) {
            return 'TXN-' . strtoupper( $m[1] );
        }

        // Internal lease pattern: ofpl-{lease_id}-{random}
        if ( preg_match( '/^ofpl-\d+-(.+)$/i', $ref, $m ) ) {
            return 'TXN-' . strtoupper( $m[1] );
        }

        // Generic scoped internal reference: ofp_{scope}_{random}
        if ( preg_match( '/^ofp_[a-z0-9_]+_([a-z0-9]{6,})$/i', $ref, $m ) ) {
            return 'TXN-' . strtoupper( $m[1] );
        }

        return $ref;
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
                    pr.title AS property_title, pr.listing_type, pr.location_text,
                    c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone,
                    c.business_phone AS client_business_phone, l.client_id AS receipt_client_id
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
            $saved_html = file_get_contents( $uploads['basedir'] . '/' . $payment->receipt_path );
            // Auto-heal previously saved receipts that suffered from HTML-escaped table tags
            if ( false === strpos( $saved_html, '&lt;tr&gt;' ) ) {
                return [
                    'path' => $payment->receipt_path,
                    'mime' => $payment->receipt_mime ?: 'text/html',
                    'size' => (int) $payment->receipt_size ?: filesize( $uploads['basedir'] . '/' . $payment->receipt_path ),
                ];
            }
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
     * Retrieves an existing purchase receipt or generates a new one on demand.
     */
    public static function get_or_create_purchase_receipt( int $payment_id ): ?array {
        global $wpdb;
        $p = $wpdb->prefix;

        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT py.*, pu.buyer_name, pu.buyer_email, pu.buyer_phone, pu.balance, pu.property_id, pu.client_id,
                    pr.title AS property_title, pr.listing_type, pr.location_text,
                    c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone,
                    c.business_phone AS client_business_phone
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE py.id = %d LIMIT 1",
            $payment_id
        ) );
        if ( ! $payment ) return null;

        $uploads = wp_upload_dir();
        if ( ! empty( $payment->receipt_path ) && file_exists( $uploads['basedir'] . '/' . $payment->receipt_path ) ) {
            $saved_html = file_get_contents( $uploads['basedir'] . '/' . $payment->receipt_path );
            // Auto-heal previously saved receipts with escaped <tr> or raw internal prefix
            if ( false === strpos( $saved_html, '&lt;tr&gt;' ) && false === strpos( $saved_html, 'ofp_property_installment_' ) ) {
                return [
                    'path' => $payment->receipt_path,
                    'mime' => $payment->receipt_mime ?: 'text/html',
                    'size' => (int) $payment->receipt_size ?: filesize( $uploads['basedir'] . '/' . $payment->receipt_path ),
                ];
            }
        }

        $html = self::render_receipt( self::TYPE_PURCHASE, self::build_purchase_data( $payment, (int) $payment->purchase_id, $payment_id ) );
        $saved = self::save_receipt_file( $html, 'purchase', $payment_id );
        if ( ! $saved ) return null;

        $wpdb->update( "{$p}ofp_property_payments", [
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
        $brand_name = ! empty( $data['business_name'] ) ? $data['business_name'] : OFP_Brand::name();
        $data['brand_name'] = $brand_name;
        $data['app_name']   = OFP_Brand::name();
        $brand_initials = self::initials( $brand_name );
        $html = str_replace( '<div class="brand-name">Ofast Pipeline</div>', '<div class="brand-name">' . esc_html( $brand_name ) . '</div>', $html );
        $html = str_replace( '>OP</div>', '>' . esc_html( $brand_initials ) . '</div>', $html );
        $html = str_ireplace( 'Ofast Pipeline', esc_html( $brand_name ), $html );

        // ── Client branding: same logo and email color as their emails ──
        $brand_client = ! empty( $data['client_id'] ) ? OFP_Client::get( (int) $data['client_id'] ) : null;
        if ( $brand_client ) {
            if ( ! empty( $brand_client->logo_url ) ) {
                $logo_html = '<div class="logo" style="background:none;overflow:hidden;"><img src="' . esc_url( $brand_client->logo_url ) . '" alt="" style="max-width:100%;max-height:100%;object-fit:contain;"></div>';
                $html = preg_replace( '/<div class="logo">[^<]*<\/div>/', $logo_html, $html, 1 );
            }
            if ( OFP_Subscription::allows_email_templates( (int) $brand_client->id ) ) {
                $rc = ! empty( $brand_client->email_brand_color ) ? sanitize_hex_color( $brand_client->email_brand_color ) : '';
                if ( $rc ) {
                    $html = str_replace( '--primary:#5b35d5;', '--primary:' . $rc . ';', $html );
                    $html = str_replace( '--primary-dark:#4020a7;', '--primary-dark:' . OFP_Brand::darken( $rc, 0.2 ) . ';', $html );
                }
            }
            if ( ! empty( $brand_client->is_white_label ) ) {
                $html = str_replace( '<div class="brand-sub">Real Estate Management & Transaction Platform</div>', '', $html );
            }
        }

        // ── Official Verification Stamp: Client custom stamp or platform fallback ──
        $default_stamp_path = OFP_PATH . 'assets/images/receipt-verified-stamp.png';
        if ( file_exists( $default_stamp_path ) ) {
            $default_stamp = 'data:image/png;base64,' . base64_encode( file_get_contents( $default_stamp_path ) );
        } else {
            $default_stamp = ofp_image_url( 'receipt-verified-stamp.png' );
        }

        $stamp_image_url = ( $brand_client && ! empty( $brand_client->stamp_url ) )
            ? esc_url( $brand_client->stamp_url )
            : $default_stamp;

        $html = str_replace( '{{stamp_image_url}}', $stamp_image_url, $html );

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
        $html_allowed_keys = [ 'payment_line_items' ];
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $html_allowed_keys, true ) ) {
                $replacement = wp_kses( (string) $value, [
                    'tr'     => [ 'class' => true, 'style' => true ],
                    'td'     => [ 'class' => true, 'style' => true, 'colspan' => true ],
                    'th'     => [ 'class' => true, 'style' => true, 'colspan' => true ],
                    'span'   => [ 'class' => true, 'style' => true ],
                    'strong' => [],
                    'em'     => [],
                    'small'  => [],
                ] );
            } else {
                $replacement = esc_html( (string) $value );
            }
            $html = str_replace( '{{' . $key . '}}', $replacement, $html );
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

        $display_ref = self::format_display_reference( $payment->gateway_reference ?: $payment->payer_reference ?: $receipt_number, $receipt_number );

        $method_labels = [
            'cash'            => 'Cash',
            'manual'          => 'Manual / Bank Transfer',
            'checkout'        => 'Online Checkout (Paystack)',
            'virtual_account' => 'Dedicated Virtual Account',
        ];

        return [
            // Header / meta
            'receipt_number'       => $receipt_number,
            'transaction_id'      => $display_ref,
            'payment_date'        => wp_date( 'M j, Y', strtotime( $payment->created_at ) ),
            'payment_time'        => wp_date( 'g:i A', strtotime( $payment->created_at ) ),
            'payment_type'        => 'Rent Payment',
            'payment_status'      => ucfirst( str_replace( '_', ' ', $payment->status ) ),

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
            'property_location'   => $payment->location_text ?: '',
            'unit_reference'      => '',
            'owner_name'          => $payment->owner_name ?: $payment->business_name ?: '',
            'agent_name'          => $payment->business_name ?: '',

            // Payment breakdown
            'payment_line_items'  => sprintf(
                '<tr><td>Rent payment — %s cycle</td><td>%s</td><td>NGN %s</td></tr>',
                esc_html( OFP_Property_Rent::period_label( $payment->rent_period, (int) $payment->custom_days ) ),
                esc_html( $display_ref ),
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
            'payment_reference'   => $display_ref,
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
            'business_name'       => $payment->business_name ?: OFP_Brand::name(),
            'client_id'           => (int) ( $payment->receipt_client_id ?? 0 ),
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
        global $wpdb;
        $p = $wpdb ? $wpdb->prefix : '';

        $receipt_number = 'PUR-' . str_pad( (string) $payment_id, 6, '0', STR_PAD_LEFT );
        $now = current_time( 'mysql' );

        // 1. Resolve human-friendly transaction reference (cleans internal ofp_ prefixes)
        $raw_ref     = (string) ( $payment->gateway_reference ?? $payment->payer_reference ?? '' );
        $display_ref = self::format_display_reference( $raw_ref, $receipt_number );

        // 2. Query purchase & property context if missing or incomplete
        $purchase_meta = ( $wpdb && $purchase_id > 0 ) ? $wpdb->get_row( $wpdb->prepare(
            "SELECT pu.total_price, pu.balance, pu.initial_payment, pu.installment_amount,
                    pu.frequency, pu.installment_count, pu.first_due_date,
                    pr.title AS property_title, pr.location_text
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             WHERE pu.id = %d LIMIT 1",
            $purchase_id
        ) ) : null;

        $prop_title    = ! empty( $payment->property_title )
            ? $payment->property_title
            : ( ! empty( $purchase_meta->property_title ) ? $purchase_meta->property_title : 'Property' );
        $prop_location = ! empty( $payment->property_location )
            ? $payment->property_location
            : ( ! empty( $purchase_meta->location_text ) ? $purchase_meta->location_text : '' );

        $total_installments = (int) ( $purchase_meta->installment_count ?? 0 );
        $frequency          = ucfirst( (string) ( $purchase_meta->frequency ?? 'Monthly' ) );

        // 3. Resolve installment allocations for this payment
        $allocations = ( $wpdb && $payment_id > 0 ) ? (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.amount, i.id AS installment_id, i.installment_no, i.due_date, i.amount_due
             FROM {$p}ofp_property_payment_allocations a
             INNER JOIN {$p}ofp_property_installments i ON i.id = a.installment_id
             WHERE a.payment_id = %d
             ORDER BY i.installment_no ASC",
            $payment_id
        ) ) : [];

        // Fallback: If not in allocations table, inspect raw reference for installment id
        if ( empty( $allocations ) && preg_match( '/^ofp_property_installment_(\d+)_/i', $raw_ref, $m ) ) {
            $parsed_inst_id = (int) $m[1];
            $inst_row = ( $wpdb && $parsed_inst_id > 0 ) ? $wpdb->get_row( $wpdb->prepare(
                "SELECT id AS installment_id, installment_no, due_date, amount_due
                 FROM {$p}ofp_property_installments
                 WHERE id = %d LIMIT 1",
                $parsed_inst_id
            ) ) : null;
            if ( $inst_row ) {
                $allocations[] = (object) [
                    'amount'         => (float) ( $payment->amount ?? 0 ),
                    'installment_id' => (int) $inst_row->installment_id,
                    'installment_no' => (int) $inst_row->installment_no,
                    'due_date'       => $inst_row->due_date,
                    'amount_due'     => (float) $inst_row->amount_due,
                ];
            } else {
                // If DB row not available, infer installment from reference id
                $allocations[] = (object) [
                    'amount'         => (float) ( $payment->amount ?? 0 ),
                    'installment_id' => $parsed_inst_id,
                    'installment_no' => $parsed_inst_id,
                    'due_date'       => '',
                    'amount_due'     => (float) ( $payment->amount ?? 0 ),
                ];
            }
        }

        // 4. Construct descriptive line items HTML
        $line_items_html = '';
        $current_inst_no = null;
        $inst_due_date   = null;

        if ( ! empty( $allocations ) ) {
            foreach ( $allocations as $alloc ) {
                $inst_no = (int) $alloc->installment_no;
                if ( null === $current_inst_no ) {
                    $current_inst_no = $inst_no;
                    $inst_due_date   = $alloc->due_date;
                }

                if ( $total_installments > 0 ) {
                    $item_desc = sprintf( 'Installment #%d of %d — %s', $inst_no, $total_installments, $prop_title );
                } else {
                    $item_desc = sprintf( 'Installment #%d — %s', $inst_no, $prop_title );
                }

                $line_items_html .= sprintf(
                    '<tr><td>%s</td><td>%s</td><td>NGN %s</td></tr>',
                    esc_html( $item_desc ),
                    esc_html( $display_ref ),
                    esc_html( number_format( (float) $alloc->amount, 2 ) )
                );
            }
        } else {
            // Determine if initial deposit or outright
            $init_pay = (float) ( $purchase_meta->initial_payment ?? 0 );
            $amount   = (float) ( $payment->amount ?? 0 );
            if ( $init_pay > 0 && abs( $amount - $init_pay ) < 0.01 ) {
                $item_desc = sprintf( 'Initial Deposit — %s', $prop_title );
            } elseif ( $total_installments === 0 ) {
                $item_desc = sprintf( 'Property Purchase — %s', $prop_title );
            } else {
                $item_desc = sprintf( 'Property Installment Payment — %s', $prop_title );
            }

            $line_items_html = sprintf(
                '<tr><td>%s</td><td>%s</td><td>NGN %s</td></tr>',
                esc_html( $item_desc ),
                esc_html( $display_ref ),
                esc_html( number_format( $amount, 2 ) )
            );
        }

        // 5. Payment allocation card details
        if ( $total_installments > 0 ) {
            $plan_name = sprintf( '%d-Payment Installment Plan (%s)', $total_installments, $frequency );
        } elseif ( $purchase_meta && (float) $purchase_meta->balance <= 0 ) {
            $plan_name = 'Outright Purchase';
        } else {
            $plan_name = 'Property Purchase';
        }

        if ( $current_inst_no !== null ) {
            $inst_number_str = $total_installments > 0
                ? sprintf( 'Installment #%d of %d', $current_inst_no, $total_installments )
                : sprintf( 'Installment #%d', $current_inst_no );
        } else {
            $inst_number_str = 'N/A';
        }

        $due_date_str = ( $inst_due_date && $inst_due_date !== '0000-00-00' )
            ? wp_date( 'M j, Y', strtotime( $inst_due_date ) )
            : 'N/A';

        // 6. Next payment date
        $next_due = ( $wpdb && $purchase_id > 0 ) ? $wpdb->get_var( $wpdb->prepare(
            "SELECT due_date FROM {$p}ofp_property_installments
             WHERE purchase_id = %d AND status NOT IN ('paid')
             ORDER BY installment_no ASC LIMIT 1",
            $purchase_id
        ) ) : null;
        $remaining_bal = (float) ( $payment->balance ?? $purchase_meta->balance ?? 0 );
        if ( $next_due && $next_due !== '0000-00-00' ) {
            $next_payment_date_str = wp_date( 'M j, Y', strtotime( $next_due ) );
        } elseif ( $remaining_bal <= 0 ) {
            $next_payment_date_str = 'Completed (Fully Paid)';
        } else {
            $next_payment_date_str = 'As scheduled';
        }

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
            'transaction_id'      => $display_ref,
            'payment_date'        => wp_date( 'M j, Y', strtotime( $payment->created_at ) ),
            'payment_time'        => wp_date( 'g:i A', strtotime( $payment->created_at ) ),
            'payment_type'        => 'Property Payment',
            'payment_status'      => ucfirst( str_replace( '_', ' ', $payment->status ?? 'successful' ) ),

            'payer_name'          => $payment->buyer_name ?? $payment->payer_name ?? '',
            'payer_email'         => $payment->buyer_email ?? '',
            'payer_phone'         => $payment->buyer_phone ?? '',
            'payer_address'       => '',

            'recipient_name'      => $payment->owner_name ?? $payment->business_name ?? 'Property Manager',
            'recipient_company'   => $payment->business_name ?? '',
            'recipient_email'     => $payment->client_email ?? '',
            'recipient_phone'     => $payment->client_business_phone ?? $payment->client_phone ?? '',

            'property_name'       => $prop_title,
            'property_id'         => $payment->property_id ?? '',
            'property_type'       => ucfirst( $payment->listing_type ?? 'Sale' ),
            'transaction_type'    => 'Purchase',
            'property_location'   => $prop_location,
            'unit_reference'      => '',
            'owner_name'          => $payment->owner_name ?? '',
            'agent_name'          => $payment->business_name ?? '',

            'payment_line_items'  => $line_items_html,
            'subtotal'            => 'NGN ' . number_format( (float) ( $payment->amount ?? 0 ), 2 ),
            'discount'            => 'NGN 0.00',
            'fees'                => 'NGN 0.00',
            'tax'                 => 'NGN 0.00',
            'amount_paid'         => 'NGN ' . number_format( (float) ( $payment->amount ?? 0 ), 2 ),
            'outstanding_balance' => 'NGN ' . number_format( (float) $remaining_bal, 2 ),

            'payment_method'      => $method_labels[ $payment->payment_method ?? '' ] ?? ucfirst( $payment->payment_method ?? 'N/A' ),
            'payment_provider'    => $payment->gateway ?? 'N/A',
            'payment_reference'   => $display_ref,
            'payment_channel'     => $payment->payment_method ?? 'N/A',

            'plan_name'           => $plan_name,
            'installment_number'  => $inst_number_str,
            'due_date'            => $due_date_str,
            'next_payment_date'   => $next_payment_date_str,

            'business_name'       => $payment->business_name ?? OFP_Brand::name(),
            'client_id'           => (int) ( $payment->client_id ?? 0 ),
            'business_address'    => '',
            'business_email'      => $payment->client_email ?? '',
            'business_phone'      => $payment->client_business_phone ?? $payment->client_phone ?? '',
            'generated_at'        => wp_date( 'M j, Y g:i A', strtotime( $now ) ),
            'verification_url'    => '',
            'receipt_hash'        => hash( 'sha256', $receipt_number . $payment_id . $purchase_id ),

            'payment_message'     => $current_inst_no !== null
                ? sprintf( 'Thank you for your installment payment towards %s.', $prop_title )
                : sprintf( 'Thank you for your property payment towards %s.', $prop_title ),
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
