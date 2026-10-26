<?php
/**
 * Template: /pipeline-settings
 * Client customises their own pipeline messages and voice scripts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

global $wpdb;
$config = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
        $client->id
    )
);

// A client with no config row would see a blank page. Create a default one.
if ( ! $config ) {
    $wpdb->insert(
        $wpdb->prefix . 'ofp_pipeline_configs',
        [
            'client_id'              => (int) $client->id,
            'instant_sms_enabled'    => 1,
            'followup_1_type'        => 'sms',
            'followup_2_type'        => 'sms',
            'followup_3_type'        => 'sms',
            'updated_at'             => current_time( 'mysql' ),
        ]
    );
    $config = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
            $client->id
        )
    );
}

$saved   = false;
$error   = '';

// ── Handle Call Scripts save (standalone) ──────────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_voice_notice_nonce'] ) && ! isset( $_POST['ofp_pipeline_nonce'] ) ) {
    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['ofp_voice_notice_nonce'] ) ),
        'ofp_voice_notice_' . (int) $client->id
    ) ) {
        wp_die( 'Security check failed. Please refresh and try again.', 'Security Check', [ 'response' => 403 ] );
    }

    if ( class_exists( 'OFP_Voice_Notice' ) ) {
        OFP_Voice_Notice::save_settings( (int) $client->id, $_POST );
    }

    wp_safe_redirect( add_query_arg( [ 'saved_calls' => '1' ], home_url( '/pipeline-settings' ) ) . '#call-scripts' );
    exit;
}

// ── Handle form submission ─────────────────────────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_pipeline_nonce'] ) ) {

    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['ofp_pipeline_nonce'] ) ),
        'ofp_save_pipeline_' . $client->id
    ) ) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        // Only sms or email are allowed. Delay is 1 to 720 hours.
        $clean_type  = static function ( $value ): string {
            return OFP_Queue::clean_type( sanitize_key( wp_unslash( (string) $value ) ) );
        };
        $clean_delay = static function ( $value, int $fallback ): int {
            $hours = absint( $value );
            if ( $hours < 1 ) {
                $hours = $fallback;
            }
            return min( 720, $hours );
        };

        $config_data = [
                'instant_sms_enabled'    => ! empty( $_POST['instant_sms_enabled'] ) ? 1 : 0,
                'instant_sms_message'    => sanitize_textarea_field( wp_unslash( $_POST['instant_sms_message']    ?? '' ) ),
                'followup_1_delay_hours' => $clean_delay( $_POST['followup_1_delay_hours'] ?? 0, 1 ),
                'followup_1_type'        => $clean_type( $_POST['followup_1_type'] ?? 'sms' ),
                'followup_1_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_1_message']     ?? '' ) ),
                'followup_2_delay_hours' => $clean_delay( $_POST['followup_2_delay_hours'] ?? 0, 24 ),
                'followup_2_type'        => $clean_type( $_POST['followup_2_type'] ?? 'sms' ),
                'followup_2_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_2_message']     ?? '' ) ),
                'followup_3_delay_hours' => $clean_delay( $_POST['followup_3_delay_hours'] ?? 0, 72 ),
                'followup_3_type'        => $clean_type( $_POST['followup_3_type'] ?? 'sms' ),
                'followup_3_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_3_message']     ?? '' ) ),
                'updated_at'             => current_time( 'mysql' ),
        ];

        // The new fields exist once the plugin has added their columns.
        if ( OFP_Queue::columns_ready() ) {
            $config_data['instant_email_enabled'] = ! empty( $_POST['instant_email_enabled'] ) ? 1 : 0;
            $config_data['instant_email_subject'] = sanitize_text_field( wp_unslash( $_POST['instant_email_subject'] ?? '' ) );
            $config_data['instant_email_message'] = sanitize_textarea_field( wp_unslash( $_POST['instant_email_message'] ?? '' ) );
            $config_data['alert_sms_enabled']     = ! empty( $_POST['alert_sms_enabled'] ) ? 1 : 0;
        }

        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            $config_data,
            [ 'client_id' => $client->id ]
        );

        // Also save voice notice / call scripts if submitted with pipeline form.
        if ( class_exists( 'OFP_Voice_Notice' ) && isset( $_POST['ofp_voice_notice_nonce'] ) ) {
            if ( wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['ofp_voice_notice_nonce'] ) ),
                'ofp_voice_notice_' . (int) $client->id
            ) ) {
                OFP_Voice_Notice::save_settings( (int) $client->id, $_POST );
            }
        }

        // Reload updated config.
        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client->id
            )
        );
        $saved = true;
    }
}

$type_options = [
    'sms'   => 'SMS',
    'email' => 'Email',
];

// Warn about messages that are empty, because an empty message sends nothing.
$empty_notes = [];
if ( $config ) {
    if ( ! empty( $config->instant_sms_enabled ) && trim( (string) $config->instant_sms_message ) === '' ) {
        $empty_notes[] = 'Instant SMS is switched on but has no message, so nothing will be sent.';
    }
    foreach ( [ 1, 2, 3 ] as $n ) {
        if ( trim( (string) ( $config->{"followup_{$n}_message"} ?? '' ) ) === '' ) {
            $empty_notes[] = 'Follow-up ' . $n . ' has no message, so it will be skipped.';
        }
    }
}

// ── Data for Call Scripts ──────────────────────────────────────────────────
$call_settings = class_exists( 'OFP_Voice_Notice' ) ? OFP_Voice_Notice::settings( (int) $client->id ) : [];
$call_hours    = class_exists( 'OFP_Voice_Notice' ) ? OFP_Voice_Notice::hours() : [ 'enabled' => false, 'start' => 8, 'end' => 19 ];
$call_credits  = class_exists( 'OFP_Credit' ) ? OFP_Credit::get( (int) $client->id ) : null;
$call_balance  = (float) ( $call_credits->voice_remaining ?? 0 );
$call_cost     = defined( 'OFP_Queue::VOICE_COST' ) ? (float) OFP_Queue::VOICE_COST : 15.0;
$max_chars     = defined( 'OFP_Voice_Notice::MAX_CHARS' ) ? (int) OFP_Voice_Notice::MAX_CHARS : 400;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Pipeline Settings', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">

        <div class="ofp-pipeline-layout">
            <div class="ofp-pipeline-main">
                <?php if ( $saved ) : ?>
                    <div class="ofp-alert ofp-alert-success" style="margin-bottom: 20px;">✅ Pipeline settings saved successfully.</div>
                <?php endif; ?>
                <?php if ( ! empty( $_GET['saved_calls'] ) ) : ?>
                    <div class="ofp-alert ofp-alert-success" style="margin-bottom: 20px;">✅ Your call scripts were saved.</div>
                <?php endif; ?>
                <?php if ( $error ) : ?>
                    <div class="ofp-alert ofp-alert-error" style="margin-bottom: 20px;"><?php echo esc_html( $error ); ?></div>
                <?php endif; ?>

                <?php if ( $config ) : ?>

                <form method="POST" action="" class="ofp-form ofp-pipeline-form">
            <?php wp_nonce_field( 'ofp_save_pipeline_' . $client->id, 'ofp_pipeline_nonce' ); ?>
            <?php wp_nonce_field( 'ofp_voice_notice_' . (int) $client->id, 'ofp_voice_notice_nonce' ); ?>

            <?php if ( $empty_notes ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin-bottom: 16px;">
                    <?php foreach ( $empty_notes as $note ) : ?>
                        <div><?php echo esc_html( $note ); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="ofp-hint" style="margin-bottom: 16px;">You can use {{name}}, {{business_name}}, {{property}} and {{phone}} in any message below.</p>

            <div class="ofp-pipeline-timeline" id="ofpPipelineTimeline" data-business-name="<?php echo esc_attr( $client->business_name ?? 'My Business' ); ?>">
                <div class="ofp-timeline-progress" id="ofpTimelineProgress"></div>

                <!-- ── 0. New Lead Alert to You ──────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>New lead alert to you</h3>
                                <label class="ofp-toggle">
                                    <input type="checkbox" name="alert_sms_enabled" value="1"
                                        <?php checked( $config?->alert_sms_enabled ?? 1, 1 ); ?>>
                                    <span class="ofp-toggle-slider"></span>
                                </label>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:0;">The moment a lead comes in, you get a bell alert and an email, based on your notification settings. This switch also sends an SMS to your own phone number. That SMS uses one SMS credit.</p>
                        </div>
                    </div>
                </div>

                <!-- ── Instant SMS ──────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Instant SMS</h3>
                                <label class="ofp-toggle">
                                    <input type="checkbox" name="instant_sms_enabled" value="1"
                                        <?php checked( $config?->instant_sms_enabled ?? 1, 1 ); ?>>
                                    <span class="ofp-toggle-slider"></span>
                                </label>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:12px;">Sent to the lead straight after they submit your form. If it fails, it is tried again automatically.</p>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="instant_sms_message" rows="3" maxlength="320"
                                          placeholder="Hi {{name}}, thank you for your interest! We will be in touch shortly. - {{business_name}}"><?php
                                    echo esc_textarea( $config?->instant_sms_message ?? '' );
                                ?></textarea>
                                <p class="ofp-hint" style="margin-top:6px;">Keep under 160 characters for a single SMS credit. Current:
                                    <span id="ofp-sms-count-0">0</span> characters.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Instant Email ─────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><polyline points="3,7 12,13 21,7"></polyline></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Instant Email</h3>
                                <label class="ofp-toggle">
                                    <input type="checkbox" name="instant_email_enabled" value="1"
                                        <?php checked( $config?->instant_email_enabled ?? 1, 1 ); ?>>
                                    <span class="ofp-toggle-slider"></span>
                                </label>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:12px;">Sent at the same time as the Instant SMS, to leads who gave an email address. It uses your email template, replies come to your email, and it costs no credit.</p>
                            <div class="ofp-field">
                                <label>Subject</label>
                                <input type="text" name="instant_email_subject" maxlength="150"
                                       value="<?php echo esc_attr( $config?->instant_email_subject ?? '' ); ?>"
                                       placeholder="<?php echo esc_attr( OFP_Queue::default_instant_email_subject() ); ?>">
                            </div>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="instant_email_message" rows="4" maxlength="2000"
                                          placeholder="<?php echo esc_attr( OFP_Queue::default_instant_email_message() ); ?>"><?php
                                    echo esc_textarea( $config?->instant_email_message ?? '' );
                                ?></textarea>
                                <p class="ofp-hint" style="margin-top:6px;">Leave the message empty to use the default text shown above.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Follow-up 1 ─────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Follow-up 1</h3>
                            </div>
                            <div class="ofp-form-row">
                                <div class="ofp-field">
                                    <label>Delay (hours after lead capture)</label>
                                    <input type="number" name="followup_1_delay_hours" min="1" max="720"
                                           value="<?php echo esc_attr( $config?->followup_1_delay_hours ?? 1 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_1_type" class="ofp-select">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config?->followup_1_type ?? 'sms', $val ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="followup_1_message" rows="3"
                                          placeholder="Hi {{name}}, just checking in — did you get our earlier message? - {{business_name}}"><?php
                                    echo esc_textarea( $config?->followup_1_message ?? '' );
                                ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Follow-up 2 ─────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Follow-up 2</h3>
                            </div>
                            <div class="ofp-form-row">
                                <div class="ofp-field">
                                    <label>Delay (hours after lead capture)</label>
                                    <input type="number" name="followup_2_delay_hours" min="1" max="720"
                                           value="<?php echo esc_attr( $config?->followup_2_delay_hours ?? 24 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_2_type" class="ofp-select">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config?->followup_2_type ?? 'sms', $val ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="followup_2_message" rows="3"
                                          placeholder="Hi {{name}}, we would love to help you with {{property}}. Reply to this message or call us anytime. {{business_name}}"><?php
                                    echo esc_textarea( $config?->followup_2_message ?? '' );
                                ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Follow-up 3 ─────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Follow-up 3</h3>
                            </div>
                            <div class="ofp-form-row">
                                <div class="ofp-field">
                                    <label>Delay (hours after lead capture)</label>
                                    <input type="number" name="followup_3_delay_hours" min="1" max="720"
                                           value="<?php echo esc_attr( $config?->followup_3_delay_hours ?? 72 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_3_type" class="ofp-select">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config?->followup_3_type ?? 'sms', $val ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="followup_3_message" rows="3"
                                          placeholder="Hi {{name}}, we have been trying to reach you. We would love to help. Call or message us anytime. - {{business_name}}"><?php
                                    echo esc_textarea( $config?->followup_3_message ?? '' );
                                ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Call Scripts (below Follow-up 3) ─────────────────────── -->
                <div class="ofp-timeline-step" id="call-scripts" style="margin-bottom: 0;">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 10px;">
                                <h3 style="margin: 0;">Call Scripts</h3>
                                <div style="display: flex; align-items: center; gap: 10px; font-size: 13px;">
                                    <span class="ofp-badge ofp-badge-blue">Voice Credit: &#8358;<?php echo esc_html( number_format( $call_balance, 0 ) ); ?></span>
                                    <span class="ofp-badge ofp-badge-gray">&#8358;<?php echo esc_html( number_format( $call_cost, 0 ) ); ?> / call</span>
                                </div>
                            </div>

                            <?php if ( $call_hours['enabled'] ) : ?>
                                <p class="ofp-hint" style="margin-bottom: 12px;">
                                    Calls only go out between <?php echo esc_html( gmdate( 'g A', mktime( (int) $call_hours['start'], 0, 0, 1, 1, 2000 ) ) ); ?> and <?php echo esc_html( gmdate( 'g A', mktime( (int) $call_hours['end'] % 24, 0, 0, 1, 1, 2000 ) ) ); ?>. A call due outside those hours waits until the next morning.
                                </p>
                            <?php endif; ?>
                            <?php if ( $call_balance < $call_cost ) : ?>
                                <div style="margin-bottom: 16px; padding: 10px 14px; background: rgba(245, 158, 11, 0.1); border-left: 3px solid #f59e0b; border-radius: 6px; font-size: 13px; color: #b45309;">
                                    Your voice credit is too low to place a call. Top up on the Credits page.
                                </div>
                            <?php endif; ?>

                            <!-- Group A: Instant call to new leads -->
                            <div style="border-top: 1px solid var(--border-color); padding-top: 18px; margin-top: 8px; margin-bottom: 24px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                                    <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: var(--text-main);">Instant call to new leads</h4>
                                    <label class="ofp-toggle">
                                        <input type="checkbox" name="call_interest_enabled" value="1" <?php checked( ! empty( $call_settings['interest_enabled'] ) ); ?>>
                                        <span class="ofp-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="ofp-field" style="margin-bottom:0;">
                                    <label for="call_interest_message">What the call says</label>
                                    <textarea id="call_interest_message" name="call_interest_message" rows="3" maxlength="<?php echo (int) $max_chars; ?>"><?php echo esc_textarea( $call_settings['interest_message'] ?? '' ); ?></textarea>
                                    <p class="ofp-hint" style="margin-top:4px; font-size:11px; margin-bottom:0; white-space:nowrap;">{{name}} {{business_name}} {{property}} {{amount}} {{due_date}}. 400 characters max.</p>
                                </div>
                            </div>

                            <!-- Group B: Payment reminder calls -->
                            <div style="border-top: 1px solid var(--border-color); padding-top: 18px;">
                                <div style="margin-bottom: 14px;">
                                    <h4 style="margin: 0 0 4px; font-size: 15px; font-weight: 600; color: var(--text-main);">Payment reminder calls</h4>
                                    <p class="ofp-subtitle" style="margin: 0; font-size: 13px; color: var(--text-muted);">Automated voice reminders triggered on payment due dates.</p>
                                </div>

                                <!-- Installment Due Day Call -->
                                <div style="margin-bottom: 20px; padding-bottom: 18px; border-bottom: 1px dashed var(--border-color);">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                                        <span style="font-size: 14px; font-weight: 600; color: var(--text-main);">When a buyer's installment is due today</span>
                                        <label class="ofp-toggle">
                                            <input type="checkbox" name="call_installment_enabled" value="1" <?php checked( ! empty( $call_settings['installment_enabled'] ) ); ?>>
                                            <span class="ofp-toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="ofp-field" style="margin-bottom:0;">
                                        <label for="call_installment_message">What the call says</label>
                                        <textarea id="call_installment_message" name="call_installment_message" rows="3" maxlength="<?php echo (int) $max_chars; ?>"><?php echo esc_textarea( $call_settings['installment_message'] ?? '' ); ?></textarea>
                                        <p class="ofp-hint" style="margin-top:4px; font-size:11px; margin-bottom:0; white-space:nowrap;">{{name}} {{business_name}} {{property}} {{amount}} {{due_date}}. 400 characters max.</p>
                                    </div>
                                </div>

                                <!-- Rent Due Day Call -->
                                <div>
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                                        <span style="font-size: 14px; font-weight: 600; color: var(--text-main);">When a tenant's rent is due today</span>
                                        <label class="ofp-toggle">
                                            <input type="checkbox" name="call_rent_enabled" value="1" <?php checked( ! empty( $call_settings['rent_enabled'] ) ); ?>>
                                            <span class="ofp-toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="ofp-field" style="margin-bottom:0;">
                                        <label for="call_rent_message">What the call says</label>
                                        <textarea id="call_rent_message" name="call_rent_message" rows="3" maxlength="<?php echo (int) $max_chars; ?>"><?php echo esc_textarea( $call_settings['rent_message'] ?? '' ); ?></textarea>
                                        <p class="ofp-hint" style="margin-top:4px; font-size:11px; margin-bottom:0; white-space:nowrap;">{{name}} {{business_name}} {{property}} {{amount}} {{due_date}}. 400 characters max.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="ofp-form-actions" style="margin-left: 56px; margin-top: 14px; display: flex; align-items: center; gap: 12px;">
                <button type="submit" class="ofp-btn ofp-btn-primary ofp-btn-save">Save Settings</button>
                <a href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>" class="ofp-btn ofp-btn-secondary ofp-btn-reduced">Cancel</a>
            </div>

                </form>

                <?php endif; // $config ?>

            </div>
            <?php if ( $config ) : ?>
            <div class="ofp-pipeline-sidebar">
                <div class="ofp-phone-mockup">
                    <img class="ofp-phone-frame-overlay" src="<?php echo esc_url( ofp_image_url( 'Smartphone-Mockup.png' ) ); ?>" alt="Smartphone Mockup" loading="lazy">
                    <div class="ofp-phone-screen">
                        <div class="ofp-phone-header">
                            <div class="ofp-phone-contact">Message Preview</div>
                        </div>
                        <div class="ofp-phone-body" id="ofpPhoneBody">
                            <div class="ofp-chat-bubble" id="preview_instant_sms_message">
                                Hi John, thank you for your interest! We will be in touch shortly. - My Business
                            </div>
                            <div class="ofp-chat-bubble" id="preview_followup_1_message">
                                Hi John, just checking in — did you get our earlier message? - My Business
                            </div>
                            <div class="ofp-chat-bubble" id="preview_followup_2_message">
                                Hi John, we would love to help you with Sunset Villa. Reply to this message or call us anytime. My Business
                            </div>
                            <div class="ofp-chat-bubble" id="preview_followup_3_message">
                                Hi John, we have been trying to reach you. We would love to help. Call or message us anytime. - My Business
                            </div>
                            <div class="ofp-chat-bubble call-bubble" id="preview_call_interest_message" style="color: #0f172a !important;">
                                <strong style="color: #be185d !important;">📞 Instant Call: New Lead</strong>
                                <p style="margin:0; color: #0f172a !important;"><?php echo esc_html( $call_settings['interest_message'] ?? 'Hello John, this is My Business regarding your recent inquiry.' ); ?></p>
                            </div>
                            <div class="ofp-chat-bubble call-bubble" id="preview_call_installment_message" style="color: #0f172a !important;">
                                <strong style="color: #be185d !important;">📞 Reminder Call: Installment Due</strong>
                                <p style="margin:0; color: #0f172a !important;"><?php echo esc_html( $call_settings['installment_message'] ?? 'Hello John, this is My Business. Your installment of 250,000 naira for Sunset Villa is due today.' ); ?></p>
                            </div>
                            <div class="ofp-chat-bubble call-bubble" id="preview_call_rent_message" style="color: #0f172a !important;">
                                <strong style="color: #be185d !important;">📞 Reminder Call: Rent Due</strong>
                                <p style="margin:0; color: #0f172a !important;"><?php echo esc_html( $call_settings['rent_message'] ?? 'Hello John, this is My Business. Your rent payment for Sunset Villa is due today.' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
