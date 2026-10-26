<?php
/**
 * Property buyer checkout flow.
 * Buyers do not need an OFast Pipeline account.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Checkout {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-checkout/?$', 'index.php?ofp_property_checkout=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_property_checkout';
        return $vars;
    }

    public static function handle_route(): void {
        if ( ! get_query_var( 'ofp_property_checkout' ) ) return;

        // Old checkout links go to the bank transfer page while online payments are off.
        if ( ! OFP_Bank_Account::online_payments_enabled() ) {
            $old_token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
            wp_safe_redirect( add_query_arg( 'token', rawurlencode( $old_token ), home_url( '/property-pay/' ) ) );
            exit;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $purchase_id = self::verify_purchase_token( $token );
        if ( ! $purchase_id ) {
            wp_die( esc_html__( 'This payment link is invalid or has expired.', 'ofast-pipeline' ) );
        }

        global $wpdb;
        $p = $wpdb->prefix;
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
        if ( ! $purchase ) wp_die( esc_html__( 'Purchase not found.', 'ofast-pipeline' ) );

        $installment_id = absint( $_GET['installment'] ?? 0 );
        $installment = null;
        if ( $installment_id ) {
            $installment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_installments WHERE id = %d AND purchase_id = %d LIMIT 1",
                $installment_id,
                $purchase_id
            ) );
        }

        if ( ! $installment ) {
            $installment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d AND status IN ('scheduled','due','partially_paid','overdue') AND amount_paid < amount_due ORDER BY installment_no ASC LIMIT 1",
                $purchase_id
            ) );
        }

        $installment_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_installments WHERE purchase_id = %d",
            $purchase_id
        ) );

        $balance = max( 0.0, (float) $purchase->balance );
        $total_price = max( 0.0, (float) $purchase->total_price );
        $amount_paid = max( 0.0, (float) $purchase->amount_paid );
        $progress_pct = $total_price > 0 ? min( 100, round( ( $amount_paid / $total_price ) * 100 ) ) : 0;

        if ( ! $installment && $balance > 0 ) {
            wp_die( esc_html__( 'No outstanding installment is available for payment.', 'ofast-pipeline' ) );
        }

        $configured = self::configured_gateways();
        $requested_gateway = sanitize_key( $_GET['gateway'] ?? '' );
        $gateway_slug = in_array( $requested_gateway, $configured, true ) ? $requested_gateway : ( in_array( 'paystack', $configured, true ) ? 'paystack' : ( $configured[0] ?? 'paystack' ) );

        $checkout_return = sanitize_key( $_GET['checkout'] ?? '' ) === 'return';
        $error = '';
        $notice = '';

        if ( $checkout_return ) {
            $notice = 'Your checkout has been returned to this page. Final payment confirmation comes from the gateway notification, so your payment may still be processing.';
        }

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && $installment ) {
            if ( ! wp_verify_nonce( $_POST['checkout_nonce'] ?? '', 'ofp_property_checkout_' . $installment->id ) ) {
                $error = 'Security check failed. Please try again.';
            } elseif ( empty( $configured ) ) {
                $error = 'Online checkout is not currently available. Please use Manual Payment or contact the property owner.';
            } else {
                $amount = max( 0.0, (float) ( $_POST['amount'] ?? 0 ) );
                $requested = sanitize_key( $_POST['gateway'] ?? $gateway_slug );
                $gateway_slug = in_array( $requested, $configured, true ) ? $requested : ( in_array( 'paystack', $configured, true ) ? 'paystack' : $configured[0] );
                $outstanding = max( 0.0, (float) $installment->amount_due - (float) $installment->amount_paid );

                if ( $amount <= 0 || $amount > (float) $purchase->balance + 0.00001 ) {
                    $error = 'Enter an amount up to the total remaining purchase balance.';
                } elseif ( empty( $purchase->buyer_email ) || ! is_email( $purchase->buyer_email ) ) {
                    $error = 'A valid buyer email is required for online checkout.';
                } else {
                    $reference = OFP_Property_Payment_Context::generate_reference( (int) $installment->id );
                    $payment_id = OFP_Property_Payment_Record::create([
                        'purchase_id'       => (int) $purchase_id,
                        'payment_method'    => 'checkout',
                        'gateway'           => $gateway_slug,
                        'gateway_reference' => $reference,
                        'amount'            => $amount,
                        'status'            => 'pending_verification',
                        'payer_name'        => $purchase->buyer_name,
                        'payer_reference'   => $reference,
                    ]);

                    if ( is_wp_error( $payment_id ) ) {
                        $error = $payment_id->get_error_message();
                    } else {
                        $gateway = self::gateway( $gateway_slug );
                        $url = $gateway ? $gateway->initiate_transaction([
                            'client_id'    => (int) $purchase->client_id,
                            'amount'       => $amount,
                            'reference'    => $reference,
                            'email'        => $purchase->buyer_email,
                            'name'         => $purchase->buyer_name,
                            'phone'        => $purchase->buyer_phone,
                            'description'  => 'Property installment — ' . ( $purchase->property_title ?: 'Property Purchase' ),
                            'redirect_url' => add_query_arg([
                                'token'       => rawurlencode( $token ),
                                'installment' => (int) $installment->id,
                                'gateway'     => $gateway_slug,
                                'checkout'    => 'return',
                            ], home_url( '/property-checkout/' ) ),
                        ]) : null;

                        if ( $url ) {
                            $gw_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                            $gw_https = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) === 'https';
                            $trusted  = $gw_https && ( $gw_host === 'paystack.com' || str_ends_with( $gw_host, '.paystack.com' ) );
                            if ( $trusted ) {
                                wp_redirect( esc_url_raw( $url ) );
                                exit;
                            }
                            error_log( 'OFP checkout: refused an unexpected gateway address: ' . $gw_host );
                        }

                        $wpdb->update(
                            "{$p}ofp_property_payments",
                            [
                                'status'     => 'failed',
                                'note'       => 'Gateway initialization failed.',
                                'updated_at' => current_time( 'mysql' ),
                            ],
                            [ 'id' => (int) $payment_id ]
                        );
                        $error = 'Unable to start checkout. Please try again or use Manual Payment.';
                    }
                }
            }
        }

        $outstanding = $installment ? max( 0.0, (float) $installment->amount_due - (float) $installment->amount_paid ) : 0.0;
        $default_amount = $outstanding > 0 ? $outstanding : $balance;

        // Business Identity (No owner personal name)
        $agency_name   = trim( (string) ( $purchase->business_name ?? '' ) );
        $client_phone  = trim( (string) ( $purchase->client_whatsapp ?: ( $purchase->client_business_phone ?: ( $purchase->client_phone ?? '' ) ) ) );
        $client_email  = trim( (string) ( $purchase->client_email ?? '' ) );
        $client_logo   = trim( (string) ( $purchase->client_logo ?? '' ) );
        $property_url  = ! empty( $purchase->wp_post_id ) ? get_permalink( (int) $purchase->wp_post_id ) : '';

        // Initials Avatar fallback from business name
        $avatar_seed = $agency_name ?: 'OF';
        $words = preg_split( '/\s+/', trim( $avatar_seed ) );
        $initials = '';
        foreach ( array_slice( $words, 0, 2 ) as $w ) {
            $initials .= strtoupper( mb_substr( $w, 0, 1 ) );
        }
        if ( ! $initials ) $initials = 'OF';

        $manual_pay_url = class_exists( 'OFP_Property_Manual_Payment' ) ? OFP_Property_Manual_Payment::payment_link( (int) $purchase_id ) : home_url( '/property-pay/?token=' . rawurlencode( $token ) );
        $my_payments_url = class_exists( 'OFP_Property_My_Payments' ) ? OFP_Property_My_Payments::url() : home_url( '/my-payments/' );
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html( ( $purchase->property_title ?: 'Property' ) . ' — Secure Checkout' ); ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <?php wp_head(); ?>
            <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/property-checkout.css?v=' . OFP_VERSION ); ?>">
        </head>
        <body>
            <div class="ofp-checkout-wrap">
                <div class="ofp-checkout-card">
                    <!-- 1. TOP BUSINESS / AGENCY IDENTIFIER -->
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
                                <span>256-Bit SSL Checkout</span>
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

                    <!-- 2. MAIN CARD BODY -->
                    <div class="ofp-card-body">
                        <?php if ( $error ) : ?>
                            <div class="ofp-alert ofp-alert-err">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                <div><?php echo esc_html( $error ); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ( $notice ) : ?>
                            <div class="ofp-alert ofp-alert-info">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0; margin-top:1px;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                <div><?php echo esc_html( $notice ); ?></div>
                            </div>
                        <?php endif; ?>



                        <!-- FINANCIAL SNAPSHOT -->
                        <div class="ofp-fin-grid">
                            <div class="ofp-fin-box">
                                <span class="ofp-fin-label">Total Price</span>
                                <span class="ofp-fin-val">NGN <?php echo esc_html( number_format( $total_price, 2 ) ); ?></span>
                            </div>
                            <div class="ofp-fin-box">
                                <span class="ofp-fin-label">Total Paid (<?php echo (int) $progress_pct; ?>%)</span>
                                <span class="ofp-fin-val" style="color:var(--ofp-emerald);">NGN <?php echo esc_html( number_format( $amount_paid, 2 ) ); ?></span>
                            </div>
                            <div class="ofp-fin-box">
                                <span class="ofp-fin-label">Remaining Balance</span>
                                <span class="ofp-fin-val highlight">NGN <?php echo esc_html( number_format( $balance, 2 ) ); ?></span>
                            </div>
                        </div>

                        <div class="ofp-progress-wrap">
                            <div class="ofp-progress-bar-bg">
                                <div class="ofp-progress-bar-fill" style="width: <?php echo esc_attr( $progress_pct ); ?>%;"></div>
                            </div>
                            <div class="ofp-progress-label">
                                <span>Payment Progress</span>
                                <span><?php echo (int) $progress_pct; ?>% Completed</span>
                            </div>
                        </div>

                        <?php if ( $balance <= 0 ) : ?>
                            <!-- FULLY PAID -->
                            <div class="ofp-alert ofp-alert-ok" style="padding: 24px; text-align: center; display: block;">
                                <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
                                <h3 style="margin: 0 0 6px; font-size: 18px; color: var(--ofp-emerald);">This Property is Fully Paid!</h3>
                                <p style="margin: 0 0 16px; color: var(--ofp-slate-600); font-size: 14px;">All scheduled installments have been settled in full. Thank you!</p>
                                <a href="<?php echo esc_url( $my_payments_url ); ?>" class="ofp-submit-btn" style="display: inline-flex; width: auto; text-decoration: none; padding: 12px 24px;">View Payment Receipts &amp; Status</a>
                            </div>
                        <?php elseif ( empty( $configured ) ) : ?>
                            <div class="ofp-alert ofp-alert-info">
                                <p style="margin:0;">Online payment gateway is temporarily unavailable. Please use the direct Bank Transfer payment option or contact the property owner.</p>
                            </div>
                            <div style="text-align:center; margin-top: 16px;">
                                <a href="<?php echo esc_url( $manual_pay_url ); ?>" class="ofp-submit-btn" style="display:inline-flex; width:auto; text-decoration:none;">Pay via Direct Bank Deposit</a>
                            </div>
                        <?php else : ?>
                            <!-- 2-STEP PROGRESS STEPPER: 1 ---------- 2 -->
                            <div class="ofp-stepper-wrap">
                                <div class="ofp-stepper">
                                    <button type="button" class="ofp-step active" id="step-node-1" onclick="goToStep(1)">
                                        <span class="ofp-step-circle">
                                            <span class="ofp-step-num">1</span>
                                            <svg class="ofp-step-check" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </span>
                                        <span class="ofp-step-label">1. Choose Amount</span>
                                    </button>
                                    <div class="ofp-step-line" id="stepper-line">
                                        <div class="ofp-step-line-fill" id="stepper-line-fill"></div>
                                    </div>
                                    <button type="button" class="ofp-step" id="step-node-2" onclick="goToStep(2)">
                                        <span class="ofp-step-circle">
                                            <span class="ofp-step-num">2</span>
                                        </span>
                                        <span class="ofp-step-label">2. Confirm &amp; Pay</span>
                                    </button>
                                </div>
                            </div>

                            <!-- CHECKOUT FORM WITH 2 STEPS -->
                            <form method="post" id="ofp-checkout-form">
                                <?php if ( $installment ) : ?>
                                    <?php wp_nonce_field( 'ofp_property_checkout_' . $installment->id, 'checkout_nonce' ); ?>
                                <?php endif; ?>

                                <!-- ================= STEP 1: CHOOSE AMOUNT ================= -->
                                <div id="ofp-step-1" class="ofp-step-pane">
                                    <!-- INSTALLMENT DUE HIGHLIGHT -->
                                    <?php if ( $installment ) : 
                                        $is_overdue = ( $installment->status === 'overdue' ) || ( strtotime( $installment->due_date ) < strtotime( current_time( 'Y-m-d' ) ) );
                                    ?>
                                        <div class="ofp-due-banner">
                                            <div class="ofp-due-left">
                                                <h3>
                                                    Installment #<?php echo esc_html( $installment->installment_no ); ?>
                                                    <?php if ( $installment_count > 0 ) : ?>
                                                        of <?php echo esc_html( $installment_count ); ?>
                                                    <?php endif; ?>
                                                    <?php if ( $is_overdue ) : ?>
                                                        <span style="color:#dc2626; font-size:12px; margin-left:6px; background:#fee2e2; padding:2px 8px; border-radius:4px;">Overdue</span>
                                                    <?php endif; ?>
                                                </h3>
                                                <p>
                                                    Due <?php echo esc_html( wp_date( 'M j, Y', strtotime( $installment->due_date ) ) ); ?>
                                                </p>
                                            </div>
                                            <div class="ofp-due-right">
                                                <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--ofp-slate-500); margin-bottom:2px;">Amount Due</div>
                                                <div class="ofp-due-amount">NGN <?php echo esc_html( number_format( $outstanding, 2 ) ); ?></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ofp-form-group">
                                        <label for="pay_amount">Choose Payment Amount (NGN)</label>
                                        <div class="ofp-quick-pills">
                                            <?php if ( $outstanding > 0 ) : ?>
                                                <button type="button" class="ofp-quick-pill active" id="pill-installment" onclick="selectAmount('<?php echo esc_js( number_format( $outstanding, 2, '.', '' ) ); ?>', this);">
                                                    Pay Next Installment (₦<?php echo esc_html( number_format( $outstanding, 2 ) ); ?>)
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="ofp-quick-pill <?php echo ( $outstanding <= 0 ) ? 'active' : ''; ?>" id="pill-full" onclick="selectAmount('<?php echo esc_js( number_format( $balance, 2, '.', '' ) ); ?>', this);">
                                                Pay Full Balance (₦<?php echo esc_html( number_format( $balance, 2 ) ); ?>)
                                            </button>
                                        </div>
                                        <div class="ofp-input-wrap">
                                            <span class="ofp-input-currency">₦</span>
                                            <input type="number" id="pay_amount" name="amount" min="0.01" step="0.01" max="<?php echo esc_attr( number_format( $balance, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( $default_amount, 2, '.', '' ) ); ?>" required class="ofp-amount-input" oninput="onAmountChanged(this.value)">
                                        </div>
                                        <p class="ofp-field-hint">Any payment exceeding the current installment will automatically reduce future balances.</p>
                                    </div>

                                    <button type="button" class="ofp-submit-btn" onclick="goToStep(2)">
                                        <span>Proceed to Payment Method (₦<strong id="btn-next-text"><?php echo esc_html( number_format( $default_amount, 2 ) ); ?></strong>) &rarr;</span>
                                    </button>
                                </div>

                                <!-- ================= STEP 2: CONFIRM & PAY ================= -->
                                <div id="ofp-step-2" class="ofp-step-pane" style="display:none;">
                                    <!-- PAYMENT RECAP CARD -->
                                    <div class="ofp-recap-box">
                                        <div class="ofp-recap-header">
                                            <span class="ofp-recap-label">Review Payment Summary</span>
                                            <button type="button" class="ofp-edit-amount-btn" onclick="goToStep(1)">Edit Amount ✎</button>
                                        </div>
                                        <div class="ofp-recap-amount-row">
                                            <div>
                                                <span style="font-size:11.5px; color:var(--ofp-slate-500); font-weight:700; text-transform:uppercase;">Amount to be Charged</span>
                                                <div class="ofp-recap-amount">NGN <span id="recap-amount-display"><?php echo esc_html( number_format( $default_amount, 2 ) ); ?></span></div>
                                            </div>
                                            <div style="text-align:right;">
                                                <span class="ofp-verified-badge" style="font-size:11px; padding:3px 10px;">
                                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                    <span>Verified Recipient</span>
                                                </span>
                                                <div style="font-size:12.5px; color:var(--ofp-slate-700); margin-top:4px; font-weight:700;">
                                                    <?php echo esc_html( $agency_name ?: 'Verified Agency' ); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- GATEWAY SELECTOR (PAYSTACK) -->
                                    <div class="ofp-form-group">
                                        <label>Payment Gateway</label>
                                        <div class="ofp-gateway-card">
                                            <div class="ofp-gateway-info">
                                                <div class="ofp-gateway-badge">P</div>
                                                <div>
                                                    <h4 class="ofp-gateway-title">Paystack Instant Checkout</h4>
                                                    <p class="ofp-gateway-desc">Pay with Debit Card, Bank Transfer, USSD, or Apple Pay</p>
                                                </div>
                                            </div>
                                            <div class="ofp-gateway-verified">
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                                <span>Secure</span>
                                            </div>
                                        </div>
                                        <input type="hidden" name="gateway" value="paystack">
                                    </div>

                                    <!-- STEP 2 ACTIONS: BACK & PAY -->
                                    <div class="ofp-step-actions">
                                        <button type="button" class="ofp-btn-back" onclick="goToStep(1)">
                                            &larr; Back
                                        </button>
                                        <button class="ofp-submit-btn" type="submit" id="ofp-pay-btn" style="flex:1;">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                            <span>Confirm &amp; Pay NGN <strong id="btn-amount-text"><?php echo esc_html( number_format( $default_amount, 2 ) ); ?></strong></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>

                        <!-- FOOTER ACTIONS & TRUST -->
                        <div class="ofp-checkout-footer">
                            <a href="<?php echo esc_url( $manual_pay_url ); ?>" class="ofp-alt-link">
                                <span>Prefer to pay by direct bank transfer?</span>
                                <strong style="color:var(--ofp-primary);">Pay via Bank Deposit &rarr;</strong>
                            </a>
                            <a href="<?php echo esc_url( $my_payments_url ); ?>" class="ofp-alt-link" style="color:var(--ofp-slate-500); font-size:12px;">
                                View my payment history and receipts
                            </a>
                            <div class="ofp-trust-badges">
                                <span>🔒 256-Bit Bank Grade Encryption</span>
                                <span>•</span>
                                <span>Direct Verified Settlement</span>
                                <span>•</span>
                                <span>Instant Receipt</span>
                            </div>
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

                function selectAmount(val, el) {
                    var input = document.getElementById('pay_amount');
                    if (input) {
                        input.value = val;
                        onAmountChanged(val);
                    }
                    var pills = document.querySelectorAll('.ofp-quick-pill');
                    pills.forEach(function(p) { p.classList.remove('active'); });
                    if (el) el.classList.add('active');
                }

                function onAmountChanged(val) {
                    var formatted = formatMoney(val);
                    var btnNext = document.getElementById('btn-next-text');
                    if (btnNext) btnNext.textContent = formatted;
                    var btnText = document.getElementById('btn-amount-text');
                    if (btnText) btnText.textContent = formatted;
                    var recap = document.getElementById('recap-amount-display');
                    if (recap) recap.textContent = formatted;
                }

                function goToStep(step) {
                    var step1 = document.getElementById('ofp-step-1');
                    var step2 = document.getElementById('ofp-step-2');
                    var node1 = document.getElementById('step-node-1');
                    var node2 = document.getElementById('step-node-2');
                    var line = document.getElementById('stepper-line');
                    var payInput = document.getElementById('pay_amount');

                    if (!step1 || !step2) return;

                    if (step === 2) {
                        var val = parseFloat(payInput.value);
                        if (isNaN(val) || val <= 0) {
                            alert('Please enter a valid payment amount greater than zero.');
                            payInput.focus();
                            return;
                        }
                        var maxVal = parseFloat(payInput.getAttribute('max'));
                        if (!isNaN(maxVal) && val > maxVal + 0.01) {
                            alert('Amount cannot exceed the remaining balance of NGN ' + formatMoney(maxVal));
                            payInput.focus();
                            return;
                        }

                        // Update Step 2 recap & button
                        var formatted = formatMoney(val);
                        var recap = document.getElementById('recap-amount-display');
                        if (recap) recap.textContent = formatted;
                        var btnText = document.getElementById('btn-amount-text');
                        if (btnText) btnText.textContent = formatted;

                        // Switch views
                        step1.style.display = 'none';
                        step2.style.display = 'block';

                        // Update Stepper
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
                        // Go back to Step 1
                        step2.style.display = 'none';
                        step1.style.display = 'block';

                        // Update Stepper
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

                var amountInput = document.getElementById('pay_amount');
                if (amountInput) {
                    amountInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            goToStep(2);
                        }
                    });
                }

                var form = document.getElementById('ofp-checkout-form');
                if (form) {
                    form.addEventListener('submit', function() {
                        var btn = document.getElementById('ofp-pay-btn');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerHTML = 'Connecting to Secure Gateway...';
                        }
                    });
                }
            </script>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php exit;
    }

    private static function configured_gateways(): array {
        $configured = [];
        foreach ( [ 'paystack' ] as $slug ) {
            $class = 'OFP_Gateway_' . ucfirst( $slug );
            if ( ! class_exists( $class ) ) continue;
            $gateway = new $class();
            if ( method_exists( $gateway, 'is_configured' ) && $gateway->is_configured() && method_exists( $gateway, 'initiate_transaction' ) ) {
                $configured[] = $slug;
            }
        }
        return $configured;
    }

    /**
     * Builds a secure, buyer-facing checkout link for a purchase.
     * Same signed-token scheme verify_purchase_token() below expects.
     * Shared so nobody duplicates this HMAC pattern inline again.
     */
    public static function payment_link( int $purchase_id, int $ttl = 2592000 ): string {
        // Bank transfer is the default. Every link goes to the transfer page.
        if ( ! OFP_Bank_Account::online_payments_enabled() && class_exists( 'OFP_Property_Manual_Payment' ) ) {
            return OFP_Property_Manual_Payment::payment_link( $purchase_id );
        }
        $expires = time() + $ttl;
        $payload = $purchase_id . '.' . $expires;
        $sig = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        $token = $payload . '.' . $sig;
        return add_query_arg( 'token', rawurlencode( $token ), home_url( '/property-checkout/' ) );
    }

    private static function verify_purchase_token( string $token ): ?int {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return null;
        $purchase_id = absint( $parts[0] );
        $expires = absint( $parts[1] );
        $signature = sanitize_text_field( $parts[2] );
        $expected = hash_hmac( 'sha256', $purchase_id . '.' . $expires, wp_salt( 'auth' ) );
        if ( ! $purchase_id || ! $expires || $expires < time() || ! hash_equals( $expected, $signature ) ) return null;
        return $purchase_id;
    }

    private static function gateway( string $slug ): ?object {
        $class = 'OFP_Gateway_' . ucfirst( $slug );
        if ( ! class_exists( $class ) ) return null;
        $gateway = new $class();
        return method_exists( $gateway, 'initiate_transaction' ) ? $gateway : null;
    }
}
