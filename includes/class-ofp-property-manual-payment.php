<?php
/**
 * Property manual payment flow.
 * Buyer-facing receipt submission only. Verification (admin + client) is
 * handled canonically by OFP_Property_Payment_Records — see that class.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Manual_Payment {

    const MAX_RECEIPT_SIZE = 5242880; // 5 MB
    const TOKEN_TTL = 2592000; // 30 days

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_public_route' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-pay/?$', 'index.php?ofp_manual_payment=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_manual_payment';
        return $vars;
    }

    public static function payment_link( int $purchase_id ): string {
        $token = self::make_token( $purchase_id );
        return add_query_arg( 'token', rawurlencode( $token ), home_url( '/property-pay/' ) );
    }

    private static function make_token( int $purchase_id ): string {
        $expires = time() + self::TOKEN_TTL;
        $payload = $purchase_id . '.' . $expires;
        $sig = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return $payload . '.' . $sig;
    }

    private static function verify_token( string $token ): ?int {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return null;
        $purchase_id = absint( $parts[0] );
        $expires = absint( $parts[1] );
        $sig = sanitize_text_field( $parts[2] );
        if ( ! $purchase_id || ! $expires || $expires < time() || ! hash_equals( hash_hmac( 'sha256', $purchase_id . '.' . $expires, wp_salt( 'auth' ) ), $sig ) ) return null;
        return $purchase_id;
    }

    public static function handle_public_route(): void {
        if ( get_query_var( 'ofp_manual_payment' ) ) {
            self::render_public_form();
            exit;
        }
    }

    private static function render_public_form(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $purchase_id = self::verify_token( $token );
        $purchase = $purchase_id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT pu.*, pr.title AS property_title, pr.location_text, pr.featured_image, pr.property_type, pr.listing_type, pr.wp_post_id,
                    c.business_name, c.owner_name, c.phone AS client_phone, c.email AS client_email,
                    c.business_phone AS client_business_phone, c.whatsapp_number AS client_whatsapp,
                    c.logo_url AS client_logo, c.brand_color AS client_brand_color
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = COALESCE(NULLIF(pu.client_id, 0), pr.client_id)
             WHERE pu.id = %d LIMIT 1",
            $purchase_id
        ) ) : null;
        $error = '';
        $success = '';

        if ( ! $purchase ) {
            $error = 'This payment link is invalid or has expired.';
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_manual_payment_submit'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ofp_manual_payment_nonce'] ?? '', 'ofp_manual_payment_' . $purchase_id ) ) {
                $error = 'Security check failed. Please try again.';
            } else {
                $amount = max( 0.0, (float) ( $_POST['amount'] ?? 0 ) );
                $payer_name = sanitize_text_field( wp_unslash( $_POST['payer_name'] ?? '' ) );
                $payer_reference = sanitize_text_field( wp_unslash( $_POST['payer_reference'] ?? '' ) );
                $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

                $dup = ( $payer_reference !== '' ) ? (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_payments WHERE purchase_id = %d AND payer_reference = %s AND status IN ('pending_verification','successful')",
                    $purchase_id,
                    $payer_reference
                ) ) : 0;
                $open = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_payments WHERE purchase_id = %d AND status = 'pending_verification'",
                    $purchase_id
                ) );

                if ( (float) $purchase->balance <= 0 ) {
                    $error = 'This purchase is already fully paid.';
                } elseif ( $amount <= 0 ) {
                    $error = 'Enter a valid payment amount.';
                } elseif ( $amount > (float) $purchase->balance + 0.01 ) {
                    $error = 'The amount is more than your remaining balance of NGN ' . number_format( (float) $purchase->balance, 2 ) . '.';
                } elseif ( $dup ) {
                    $error = 'You already sent a payment notice with that reference.';
                } elseif ( $open >= 5 ) {
                    $error = 'You have several payments waiting for approval. Please wait for them to be confirmed.';
                } elseif ( empty( $_FILES['receipt']['name'] ) || ! empty( $_FILES['receipt']['error'] ) ) {
                    $error = 'A payment receipt is required.';
                } else {
                    $receipt = self::store_receipt( $_FILES['receipt'] );
                    if ( is_wp_error( $receipt ) ) {
                        $error = $receipt->get_error_message();
                    } else {
                        $payment_id = OFP_Property_Payment_Record::create([
                            'purchase_id' => $purchase_id,
                            'payment_method' => 'manual',
                            'amount' => $amount,
                            'status' => 'pending_verification',
                            'payer_name' => $payer_name ?: $purchase->buyer_name,
                            'payer_reference' => $payer_reference,
                            'note' => $note,
                        ]);

                        if ( is_wp_error( $payment_id ) ) {
                            self::delete_receipt( $receipt['path'] );
                            $error = $payment_id->get_error_message();
                        } else {
                            $wpdb->update(
                                "{$p}ofp_property_payments",
                                [
                                    'receipt_path' => $receipt['path'],
                                    'receipt_mime' => $receipt['mime'],
                                    'receipt_size' => $receipt['size'],
                                    'updated_at' => current_time( 'mysql' ),
                                ],
                                [ 'id' => (int) $payment_id ]
                            );
                            do_action( 'ofp_property_manual_payment_submitted', (int) $payment_id, $purchase_id );
                            $success = 'Payment notice and proof submitted successfully! The property owner will verify and confirm your payment.';
                            $purchase = $wpdb->get_row( $wpdb->prepare(
                                "SELECT pu.*, pr.title AS property_title, pr.location_text, pr.featured_image, pr.property_type, pr.listing_type, pr.wp_post_id,
                                        c.business_name, c.owner_name, c.phone AS client_phone, c.email AS client_email,
                                        c.business_phone AS client_business_phone, c.whatsapp_number AS client_whatsapp,
                                        c.logo_url AS client_logo, c.brand_color AS client_brand_color
                                 FROM {$p}ofp_property_purchases pu
                                 LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                                 LEFT JOIN {$p}ofp_clients c ON c.id = COALESCE(NULLIF(pu.client_id, 0), pr.client_id)
                                 WHERE pu.id = %d LIMIT 1",
                                $purchase_id
                            ) );
                        }
                    }
                }
            }
        }

        // Installment & financial calculations
        $ofp_bank = $purchase ? OFP_Bank_Account::for_purchase( $purchase ) : null;
        $ofp_ref  = $purchase_id ? OFP_Bank_Account::purchase_reference( (int) $purchase_id ) : '';
        $ofp_ip   = $wpdb->prefix;
        $ofp_next = $purchase_id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$ofp_ip}ofp_property_installments WHERE purchase_id = %d AND status IN ('scheduled','due','partially_paid','overdue') AND amount_paid < amount_due ORDER BY installment_no ASC LIMIT 1",
            (int) $purchase_id
        ) ) : null;
        $ofp_count   = $purchase_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ofp_ip}ofp_property_installments WHERE purchase_id = %d", (int) $purchase_id ) ) : 0;
        $ofp_balance = $purchase ? max( 0.0, (float) $purchase->balance ) : 0.0;
        $ofp_total   = $purchase ? max( 0.0, (float) $purchase->total_price ) : 0.0;
        $ofp_paid    = $purchase ? max( 0.0, (float) $purchase->amount_paid ) : 0.0;
        $ofp_pct     = $ofp_total > 0 ? min( 100, round( ( $ofp_paid / $ofp_total ) * 100 ) ) : 0;
        $ofp_due     = $ofp_next ? min( $ofp_balance, max( 0.0, (float) $ofp_next->amount_due - (float) $ofp_next->amount_paid ) ) : 0.0;
        $ofp_default = $ofp_due > 0 ? $ofp_due : $ofp_balance;

        if ( $ofp_next && $purchase ) {
            $ofp_is_initial = ( (float) $purchase->initial_payment > 0 && (int) $ofp_next->installment_no === 1 );
            $ofp_label      = $ofp_is_initial ? 'Initial Payment' : 'Installment ' . (int) $ofp_next->installment_no . ' of ' . $ofp_count;
            $ofp_overdue    = ( $ofp_next->status === 'overdue' ) || ( strtotime( $ofp_next->due_date ) < strtotime( current_time( 'Y-m-d' ) ) );
        }

        // Business Identity (No owner personal name)
        $agency_name   = $purchase ? trim( (string) ( $purchase->business_name ?? '' ) ) : '';
        $client_phone  = $purchase ? trim( (string) ( $purchase->client_whatsapp ?: ( $purchase->client_business_phone ?: ( $purchase->client_phone ?? '' ) ) ) ) : '';
        $client_email  = $purchase ? trim( (string) ( $purchase->client_email ?? '' ) ) : '';
        $client_logo   = $purchase ? trim( (string) ( $purchase->client_logo ?? '' ) ) : '';
        $property_url  = ( $purchase && ! empty( $purchase->wp_post_id ) ) ? get_permalink( (int) $purchase->wp_post_id ) : '';

        // Initials Avatar fallback from business name
        $avatar_seed = $agency_name ?: 'OF';
        $words = preg_split( '/\s+/', trim( $avatar_seed ) );
        $initials = '';
        foreach ( array_slice( $words, 0, 2 ) as $w ) {
            $initials .= strtoupper( mb_substr( $w, 0, 1 ) );
        }
        if ( ! $initials ) $initials = 'OF';

        $online_enabled = OFP_Bank_Account::online_payments_enabled();
        $online_checkout_url = ( $online_enabled && $token ) ? add_query_arg( [ 'token' => rawurlencode( $token ) ], home_url( '/property-checkout/' ) ) : '';
        $my_payments_url = class_exists( 'OFP_Property_My_Payments' ) ? OFP_Property_My_Payments::url() : home_url( '/my-payments/' );
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html( ( $purchase ? $purchase->property_title : 'Property' ) . ' — Bank Transfer &amp; Payment' ); ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <?php wp_head(); ?>
            <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/property-checkout.css?v=' . OFP_VERSION ); ?>">
        </head>
        <body>
            <div class="ofp-pay-wrap">
                <div class="ofp-pay-card">
                    <!-- 1. TOP LANDLORD / BUSINESS IDENTIFIER -->
                    <div class="ofp-landlord-header">
                        <div class="ofp-landlord-top-row">
                            <div class="ofp-verified-badge">
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Verified Business / Agency</span>
                            </div>
                            <div class="ofp-secure-pill">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <span>Direct Bank Payment</span>
                            </div>
                        </div>

                        <div class="ofp-landlord-profile">
                            <div class="ofp-landlord-avatar-wrap">
                                <?php if ( $client_logo ) : ?>
                                    <img class="ofp-landlord-avatar" src="<?php echo esc_url( $client_logo ); ?>" alt="<?php echo esc_attr( $agency_name ); ?>">
                                <?php else : ?>
                                    <div class="ofp-landlord-initials"><?php echo esc_html( $initials ); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="ofp-landlord-details">
                                <div class="ofp-landlord-label">Business / Agency</div>
                                <h2 class="ofp-landlord-name">
                                    <?php echo esc_html( $agency_name ?: 'Verified Agency' ); ?>
                                </h2>

                                <?php if ( $client_phone || $client_email ) : ?>
                                    <div class="ofp-landlord-contact">
                                        <?php if ( $client_phone ) : 
                                            $clean_phone = preg_replace( '/[^\d+]/', '', $client_phone );
                                        ?>
                                            <div class="ofp-contact-toggle">
                                                <button type="button" class="ofp-contact-chip ofp-reveal-btn" onclick="toggleContact(this, true)" title="Click to view phone number">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                                    <span>View Phone</span>
                                                </button>
                                                <div class="ofp-contact-revealed" style="display:none;">
                                                    <a href="tel:<?php echo esc_attr( $clean_phone ); ?>" class="ofp-contact-chip active-revealed" title="Call or WhatsApp">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                                        <span><?php echo esc_html( $client_phone ); ?></span>
                                                    </a>
                                                    <button type="button" class="ofp-contact-hide-btn" onclick="toggleContact(this, false)" title="Hide Phone Number">&times;</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( $client_email ) : ?>
                                            <div class="ofp-contact-toggle">
                                                <button type="button" class="ofp-contact-chip ofp-reveal-btn" onclick="toggleContact(this, true)" title="Click to view email address">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                                    <span>View Email</span>
                                                </button>
                                                <div class="ofp-contact-revealed" style="display:none;">
                                                    <a href="mailto:<?php echo esc_attr( $client_email ); ?>" class="ofp-contact-chip active-revealed" title="Send Email">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                                        <span><?php echo esc_html( $client_email ); ?></span>
                                                    </a>
                                                    <button type="button" class="ofp-contact-hide-btn" onclick="toggleContact(this, false)" title="Hide Email Address">&times;</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- PROPERTY, LOCATION, BUYER IN A STRAIGHT LINE -->
                                <div class="ofp-property-inline-bar">
                                    <span class="ofp-inline-prop">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                        <?php if ( $property_url ) : ?>
                                            <a href="<?php echo esc_url( $property_url ); ?>" target="_blank" class="ofp-prop-title-link" title="Open single property listing in new tab">
                                                <span><?php echo esc_html( $purchase->property_title ?: 'Property Details' ); ?></span>
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                            </a>
                                        <?php else : ?>
                                            <span class="ofp-prop-title-plain"><?php echo esc_html( $purchase->property_title ?: 'Property' ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ( ! empty( $purchase->location_text ) ) : ?>
                                        <span class="ofp-inline-sep">•</span>
                                        <span class="ofp-inline-loc">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <span><?php echo esc_html( $purchase->location_text ); ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <span class="ofp-inline-sep">•</span>
                                    <span class="ofp-inline-buyer">
                                        Buyer: <strong><?php echo esc_html( $purchase->buyer_name ); ?></strong>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. MAIN BODY -->
                    <div class="ofp-card-body">
                        <?php if ( $error ) : ?>
                            <div class="ofp-alert ofp-alert-err">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                <div><?php echo esc_html( $error ); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $success ) : ?>
                            <div class="ofp-alert ofp-alert-ok">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                <div><?php echo esc_html( $success ); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $purchase ) : ?>
                            <!-- ONLINE OPTION PROMO (if enabled) -->
                            <?php if ( $online_enabled && $online_checkout_url ) : ?>
                                <div class="ofp-online-banner">
                                    <p>💳 Prefer instant checkout with Debit Card, USSD or Apple Pay?</p>
                                    <a href="<?php echo esc_url( $online_checkout_url ); ?>">Pay Online via Paystack &rarr;</a>
                                </div>
                            <?php endif; ?>

                            <!-- FINANCIAL STATS -->
                            <div class="ofp-fin-grid">
                                <div class="ofp-fin-box">
                                    <span class="ofp-fin-label">Total Price</span>
                                    <span class="ofp-fin-val">NGN <?php echo esc_html( number_format( $ofp_total, 2 ) ); ?></span>
                                </div>
                                <div class="ofp-fin-box">
                                    <span class="ofp-fin-label">Total Paid (<?php echo (int) $ofp_pct; ?>%)</span>
                                    <span class="ofp-fin-val" style="color:var(--ofp-emerald);">NGN <?php echo esc_html( number_format( $ofp_paid, 2 ) ); ?></span>
                                </div>
                                <div class="ofp-fin-box">
                                    <span class="ofp-fin-label">Remaining Balance</span>
                                    <span class="ofp-fin-val highlight">NGN <?php echo esc_html( number_format( $ofp_balance, 2 ) ); ?></span>
                                </div>
                            </div>

                            <?php if ( $ofp_balance <= 0 ) : ?>
                                <div class="ofp-alert ofp-alert-ok" style="padding: 24px; text-align: center; display: block;">
                                    <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
                                    <h3 style="margin: 0 0 6px; font-size: 18px; color: var(--ofp-emerald);">This Property is Fully Paid!</h3>
                                    <p style="margin: 0 0 16px; color: var(--ofp-slate-600); font-size: 14px;">All scheduled installments have been settled in full. Thank you!</p>
                                    <a href="<?php echo esc_url( $my_payments_url ); ?>" class="ofp-submit-btn" style="display: inline-flex; width: auto; text-decoration: none; padding: 12px 24px;">View Payment Receipts &amp; Status</a>
                                </div>
                            <?php else : ?>
                                <!-- 2-STEP PROGRESS STEPPER: 1 ---------- 2 -->
                                <div class="ofp-stepper-wrap">
                                    <div class="ofp-stepper">
                                        <button type="button" class="ofp-step active" id="manual-step-node-1" onclick="goToManualStep(1)">
                                            <span class="ofp-step-circle">
                                                <span class="ofp-step-num">1</span>
                                                <svg class="ofp-step-check" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                                </svg>
                                            </span>
                                            <span class="ofp-step-label">1. Transfer Funds</span>
                                        </button>
                                        <div class="ofp-step-line" id="manual-stepper-line">
                                            <div class="ofp-step-line-fill" id="manual-stepper-line-fill"></div>
                                        </div>
                                        <button type="button" class="ofp-step" id="manual-step-node-2" onclick="goToManualStep(2)">
                                            <span class="ofp-step-circle">
                                                <span class="ofp-step-num">2</span>
                                            </span>
                                            <span class="ofp-step-label">2. Upload Proof</span>
                                        </button>
                                    </div>
                                </div>

                                <form method="post" enctype="multipart/form-data" id="ofp-manual-form">
                                    <?php wp_nonce_field( 'ofp_manual_payment_' . $purchase_id, 'ofp_manual_payment_nonce' ); ?>
                                    <input type="hidden" name="ofp_manual_payment_submit" value="1">

                                    <!-- ================= STEP 1: TRANSFER DETAILS & AMOUNT ================= -->
                                    <div id="ofp-manual-step-1" class="ofp-step-pane">
                                        <div class="ofp-step-card">
                                            <span class="ofp-step-badge">Step 1 of 2</span>
                                            <h3 class="ofp-step-title">Verified Bank Account Details</h3>
                                            <?php if ( $ofp_bank ) : ?>
                                                <div class="ofp-bank-box">
                                                    <div>Bank: <strong style="font-size:15px; color:var(--ofp-slate-900);"><?php echo esc_html( $ofp_bank->bank_name ); ?></strong></div>
                                                    <div class="ofp-acct-number-row">
                                                        <span>Account Number:</span>
                                                        <strong class="ofp-acct-num"><?php echo esc_html( $ofp_bank->account_number ); ?></strong>
                                                        <button type="button" class="ofp-copy-btn" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc_js( $ofp_bank->account_number ); ?>');this.textContent='Copied ✓';">Copy</button>
                                                    </div>
                                                    <div>Account Name: <strong style="color:var(--ofp-slate-900);"><?php echo esc_html( $ofp_bank->account_name ); ?></strong></div>
                                                    <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #cbd5e1;">
                                                        Transfer Reference Note: <strong style="color:var(--ofp-primary);"><?php echo esc_html( $ofp_ref ); ?></strong>
                                                        <button type="button" class="ofp-copy-btn" style="margin-left:6px; padding:2px 8px; font-size:11px;" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc_js( $ofp_ref ); ?>');this.textContent='Copied ✓';">Copy</button>
                                                    </div>
                                                </div>
                                                <p style="margin: 10px 0 0; font-size: 12px; color: var(--ofp-emerald); font-weight: 600;">
                                                    🔒 Verified account of <?php echo esc_html( $agency_name ?: 'Verified Agency' ); ?>. Make your transfer using any mobile banking app or USSD.
                                                </p>
                                            <?php else : ?>
                                                <div class="ofp-bank-empty-notice">
                                                    The property owner has not set up bank details yet. Please contact them directly before making payment.
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="ofp-form-group">
                                            <label for="ofp-pay-amount">Amount Sent / Transfer Amount (NGN)</label>
                                            <div class="ofp-quick-pills">
                                                <?php if ( $ofp_next && $ofp_due > 0 && $ofp_due < $ofp_balance - 0.01 ) : ?>
                                                    <button type="button" class="ofp-quick-pill" onclick="selectManualAmount('<?php echo esc_js( number_format( $ofp_due, 2, '.', '' ) ); ?>', this);">
                                                        Pay Amount Due (₦<?php echo esc_html( number_format( $ofp_due, 2 ) ); ?>)
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="ofp-quick-pill active" onclick="selectManualAmount('<?php echo esc_js( number_format( $ofp_balance, 2, '.', '' ) ); ?>', this);">
                                                    Pay Full Balance (₦<?php echo esc_html( number_format( $ofp_balance, 2 ) ); ?>)
                                                </button>
                                            </div>
                                            <div class="ofp-input-wrap">
                                                <span class="ofp-input-currency">₦</span>
                                                <input type="number" id="ofp-pay-amount" name="amount" min="0.01" step="0.01" max="<?php echo esc_attr( number_format( $ofp_balance, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( $ofp_default, 2, '.', '' ) ); ?>" required class="ofp-amount-input" oninput="onManualAmountChanged(this.value)">
                                            </div>
                                            <p class="ofp-field-hint">Enter the exact amount you sent to the bank account above.</p>
                                        </div>

                                        <button type="button" class="ofp-submit-btn" onclick="goToManualStep(2)">
                                            <span>I've Made The Transfer</span>
                                        </button>
                                    </div>

                                    <!-- ================= STEP 2: NOTIFY PAYMENT & UPLOAD PROOF ================= -->
                                    <div id="ofp-manual-step-2" class="ofp-step-pane" style="display:none;">
                                        <!-- TRANSFER RECAP -->
                                        <div class="ofp-recap-box">
                                            <div class="ofp-recap-header">
                                                <span class="ofp-recap-label">Transfer Summary</span>
                                                <button type="button" class="ofp-edit-amount-btn" onclick="goToManualStep(1)">Edit Amount / Bank Details ✎</button>
                                            </div>
                                            <div class="ofp-recap-amount-row">
                                                <div>
                                                    <span style="font-size:11.5px; color:var(--ofp-slate-500); font-weight:700; text-transform:uppercase;">Amount Sent</span>
                                                    <div class="ofp-recap-amount">NGN <span id="manual-recap-amount"><?php echo esc_html( number_format( $ofp_default, 2 ) ); ?></span></div>
                                                </div>
                                                <div style="text-align:right;">
                                                    <span class="ofp-verified-badge" style="font-size:11px; padding:3px 10px;">
                                                        <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                        <span>Beneficiary Verified</span>
                                                    </span>
                                                    <div style="font-size:12.5px; color:var(--ofp-slate-700); margin-top:4px; font-weight:700;">
                                                        <?php echo esc_html( $agency_name ?: 'Verified Agency' ); ?>
                                                    </div>
                                                    <?php if ( $ofp_bank ) : ?>
                                                        <div style="font-size:11.5px; color:var(--ofp-slate-500);">
                                                            <?php echo esc_html( $ofp_bank->bank_name . ' • ' . $ofp_bank->account_number ); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="ofp-step-card" style="margin-bottom:20px;">
                                            <span class="ofp-step-badge">Step 2 of 2</span>
                                            <h3 class="ofp-step-title">Upload Transfer Receipt &amp; Sender Details</h3>

                                            <div class="ofp-form-group">
                                                <label for="payer_name">Sender / Bank Account Name *</label>
                                                <input type="text" id="payer_name" name="payer_name" value="<?php echo esc_attr( $purchase->buyer_name ); ?>" required placeholder="Full name on the sender account">
                                            </div>

                                            <div class="ofp-form-group">
                                                <label for="payer_reference">Bank Transaction Reference (Session ID / Ref)</label>
                                                <input type="text" id="payer_reference" name="payer_reference" placeholder="e.g. 100004240918123456789">
                                                <p class="ofp-field-hint">From your bank debit alert or transfer receipt.</p>
                                            </div>

                                            <div class="ofp-form-group">
                                                <label for="receipt_file">Payment Receipt / Screenshot *</label>
                                                <input type="file" id="receipt_file" name="receipt" accept="image/jpeg,image/png,application/pdf" required class="ofp-file-input-compact">
                                                <small class="ofp-file-hint-compact">Accepted formats: JPG, PNG, or PDF (Max 5 MB).</small>
                                            </div>

                                            <div class="ofp-form-group" style="margin-bottom:0;">
                                                <label for="note">Additional Note (Optional)</label>
                                                <textarea id="note" name="note" rows="3" placeholder="Any extra details about your transfer..."></textarea>
                                            </div>
                                        </div>

                                        <div class="ofp-step-actions">
                                            <button type="button" class="ofp-btn-back" onclick="goToManualStep(1)">
                                                &larr; Back to Step 1
                                            </button>
                                            <button class="ofp-submit-btn" type="submit" id="ofp-manual-btn" style="flex:1;">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                                <span>Submit Payment Notice &amp; Proof</span>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="ofp-footer-links">
                            <a href="<?php echo esc_url( $my_payments_url ); ?>">View all my payments and receipts &rarr;</a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function toggleContact(el, reveal) {
                    var wrap = el.closest('.ofp-contact-toggle');
                    if (!wrap) return;
                    var btn = wrap.querySelector('.ofp-reveal-btn');
                    var rev = wrap.querySelector('.ofp-contact-revealed');
                    if (reveal) {
                        if (btn) btn.style.display = 'none';
                        if (rev) rev.style.display = 'inline-flex';
                    } else {
                        if (rev) rev.style.display = 'none';
                        if (btn) btn.style.display = 'inline-flex';
                    }
                }

                function formatMoney(num) {
                    var n = parseFloat(num);
                    if (isNaN(n)) return '0.00';
                    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                function selectManualAmount(val, el) {
                    var input = document.getElementById('ofp-pay-amount');
                    if (input) {
                        input.value = val;
                        onManualAmountChanged(val);
                    }
                    var pills = document.querySelectorAll('.ofp-quick-pill');
                    pills.forEach(function(p) { p.classList.remove('active'); });
                    if (el) el.classList.add('active');
                }

                function onManualAmountChanged(val) {
                    var formatted = formatMoney(val);
                    var recap = document.getElementById('manual-recap-amount');
                    if (recap) recap.textContent = formatted;
                }

                function goToManualStep(step) {
                    var step1 = document.getElementById('ofp-manual-step-1');
                    var step2 = document.getElementById('ofp-manual-step-2');
                    var node1 = document.getElementById('manual-step-node-1');
                    var node2 = document.getElementById('manual-step-node-2');
                    var line = document.getElementById('manual-stepper-line');
                    var amountInput = document.getElementById('ofp-pay-amount');

                    if (!step1 || !step2) return;

                    if (step === 2) {
                        var val = parseFloat(amountInput.value);
                        if (isNaN(val) || val <= 0) {
                            alert('Please enter a valid transfer amount greater than zero.');
                            amountInput.focus();
                            return;
                        }
                        var maxVal = parseFloat(amountInput.getAttribute('max'));
                        if (!isNaN(maxVal) && val > maxVal + 0.01) {
                            alert('Amount cannot exceed the remaining balance of NGN ' + formatMoney(maxVal));
                            amountInput.focus();
                            return;
                        }

                        var formatted = formatMoney(val);
                        var recap = document.getElementById('manual-recap-amount');
                        if (recap) recap.textContent = formatted;

                        step1.style.display = 'none';
                        step2.style.display = 'block';

                        if (node1) {
                            node1.classList.remove('active');
                            node1.classList.add('completed');
                        }
                        if (node2) {
                            node2.classList.add('active');
                        }
                        if (line) {
                            line.classList.add('step-2-active');
                        }
                    } else {
                        step2.style.display = 'none';
                        step1.style.display = 'block';

                        if (node1) {
                            node1.classList.add('active');
                            node1.classList.remove('completed');
                        }
                        if (node2) {
                            node2.classList.remove('active');
                        }
                        if (line) {
                            line.classList.remove('step-2-active');
                        }
                    }
                }

                var mAmountInput = document.getElementById('ofp-pay-amount');
                if (mAmountInput) {
                    mAmountInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            goToManualStep(2);
                        }
                    });
                }

                var mform = document.getElementById('ofp-manual-form');
                if (mform) {
                    mform.addEventListener('submit', function() {
                        var btn = document.getElementById('ofp-manual-btn');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerHTML = 'Submitting Proof...';
                        }
                    });
                }

                <?php if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $error ) : ?>
                    goToManualStep(2);
                <?php endif; ?>
            </script>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    public static function store_receipt( array $file ) {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) return new WP_Error( 'receipt_invalid', 'Invalid receipt upload.' );
        if ( (int) $file['size'] > self::MAX_RECEIPT_SIZE ) return new WP_Error( 'receipt_too_large', 'Receipt must be 5 MB or smaller.' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' ] );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], [ 'image/jpeg', 'image/png', 'application/pdf' ], true ) ) return new WP_Error( 'receipt_type', 'Only JPG, PNG or PDF receipts are allowed.' );
        $uploads = wp_upload_dir();
        $dir = trailingslashit( $uploads['basedir'] ) . 'ofp-private-receipts';
        if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'receipt_dir', 'Could not create secure receipt storage.' );
        self::protect_receipt_dir( $dir );
        $name = wp_unique_filename( $dir, wp_generate_uuid4() . '.' . strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) );
        $destination = trailingslashit( $dir ) . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) return new WP_Error( 'receipt_store', 'Could not store the receipt.' );
        return [ 'path' => $destination, 'mime' => $check['type'], 'size' => (int) $file['size'] ];
    }

    public static function delete_receipt( string $path ): void {
        if ( $path && is_file( $path ) ) @unlink( $path );
    }

    public static function protect_receipt_dir( string $dir ): void {
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "# Deny all direct web access to private receipts\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n" );
        }
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\nhttp_response_code(403);\nexit;\n" );
        }
    }
}

OFP_Property_Manual_Payment::init();
