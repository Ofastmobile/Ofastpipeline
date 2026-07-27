<?php
/**
 * Template: /pipeline-settings
 * Client customises their own pipeline messages and IVR configuration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

// Only CRM clients have pipeline settings.
if ( ! OFP_Subscription::has_active( 'crm', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$config = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
        $client->id
    )
);

$saved   = false;
$error   = '';

// ── Handle form submission ─────────────────────────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_pipeline_nonce'] ) ) {

    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['ofp_pipeline_nonce'] ) ),
        'ofp_save_pipeline_' . $client->id
    ) ) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            [
                'instant_sms_enabled'    => ! empty( $_POST['instant_sms_enabled'] ) ? 1 : 0,
                'instant_sms_message'    => sanitize_textarea_field( wp_unslash( $_POST['instant_sms_message']    ?? '' ) ),
                'followup_1_delay_hours' => absint( $_POST['followup_1_delay_hours'] ?? 1 ),
                'followup_1_type'        => sanitize_text_field( wp_unslash( $_POST['followup_1_type'] ?? 'sms' ) ),
                'followup_1_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_1_message']     ?? '' ) ),
                'followup_2_delay_hours' => absint( $_POST['followup_2_delay_hours'] ?? 24 ),
                'followup_2_type'        => sanitize_text_field( wp_unslash( $_POST['followup_2_type'] ?? 'voice' ) ),
                'followup_2_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_2_message']     ?? '' ) ),
                'followup_3_delay_hours' => absint( $_POST['followup_3_delay_hours'] ?? 72 ),
                'followup_3_type'        => sanitize_text_field( wp_unslash( $_POST['followup_3_type'] ?? 'sms' ) ),
                'followup_3_message'     => sanitize_textarea_field( wp_unslash( $_POST['followup_3_message']     ?? '' ) ),
                'transfer_phone'         => OFP_Security::sanitize_phone( wp_unslash( $_POST['transfer_phone']   ?? '' ) ),
                'whatsapp_link'          => sanitize_text_field( wp_unslash( $_POST['whatsapp_link']             ?? '' ) ),
                'updated_at'             => current_time( 'mysql' ),
            ],
            [ 'client_id' => $client->id ]
        );

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
    'voice' => 'Voice / IVR Call',
    'email' => 'Email',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pipeline Settings — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">

        <div class="ofp-page-header">
            <h1>Pipeline Settings</h1>
            <p>Customise the automated messages sent to your leads.</p>
        </div>

        <?php if ( $saved ) : ?>
            <div class="ofp-alert ofp-alert-success">✅ Pipeline settings saved successfully.</div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="ofp-alert ofp-alert-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <div class="ofp-pipeline-layout">
            <div class="ofp-pipeline-main">
                <div class="ofp-alert ofp-alert-info" style="margin-bottom: 24px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; font-size: 13px;">
                    <strong style="margin-right: 4px;">Available placeholders:</strong>
                    <span style="white-space: nowrap;"><code>{{name}}</code> &ndash; lead's name</span>
                    <span style="white-space: nowrap;"><code>{{phone}}</code> &ndash; lead's phone</span>
                    <span style="white-space: nowrap;"><code>{{business_name}}</code> &ndash; your business</span>
                </div>

                <form method="POST" action="" class="ofp-form ofp-pipeline-form">
            <?php wp_nonce_field( 'ofp_save_pipeline_' . $client->id, 'ofp_pipeline_nonce' ); ?>

            <div class="ofp-pipeline-timeline" id="ofpPipelineTimeline">
                <div class="ofp-timeline-progress" id="ofpTimelineProgress"></div>

                <!-- ── Instant SMS ──────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Instant SMS</h3>
                                <label class="ofp-toggle">
                                    <input type="checkbox" name="instant_sms_enabled" value="1"
                                        <?php checked( $config->instant_sms_enabled ?? 1, 1 ); ?>>
                                    <span class="ofp-toggle-slider"></span>
                                    <span class="ofp-toggle-label">Enabled</span>
                                </label>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:12px;">Sent within 5 minutes of a lead submitting your form.</p>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>Message</label>
                                <textarea name="instant_sms_message" rows="3" maxlength="320"
                                          placeholder="Hi {{name}}, thank you for your interest! We will be in touch shortly. - {{business_name}}"><?php
                                    echo esc_textarea( $config->instant_sms_message ?? '' );
                                ?></textarea>
                                <p class="ofp-hint" style="margin-top:6px;">Keep under 160 characters for a single SMS credit. Current:
                                    <span id="ofp-sms-count-0">0</span> characters.
                                </p>
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
                                    <input type="number" name="followup_1_delay_hours" min="1" max="168"
                                           value="<?php echo esc_attr( $config->followup_1_delay_hours ?? 1 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_1_type">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config->followup_1_type ?? 'sms', $val ); ?>>
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
                                    echo esc_textarea( $config->followup_1_message ?? '' );
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
                                <h3>Follow-up 2 (Voice Call / IVR)</h3>
                            </div>
                            <div class="ofp-form-row">
                                <div class="ofp-field">
                                    <label>Delay (hours after lead capture)</label>
                                    <input type="number" name="followup_2_delay_hours" min="1" max="168"
                                           value="<?php echo esc_attr( $config->followup_2_delay_hours ?? 24 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_2_type">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config->followup_2_type ?? 'voice', $val ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="ofp-field" style="margin-bottom:0;">
                                <label>IVR Script (read aloud during the call)</label>
                                <textarea name="followup_2_message" rows="3"
                                          placeholder="Hello, this is a message from {{business_name}}. Press 1 to speak with us now. Press 2 for our WhatsApp contact. Press 3 for a callback later."><?php
                                    echo esc_textarea( $config->followup_2_message ?? '' );
                                ?></textarea>
                                <p class="ofp-hint" style="margin-top:6px;">Write this as natural speech — it is read aloud by a text-to-speech engine.</p>
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
                                           value="<?php echo esc_attr( $config->followup_3_delay_hours ?? 72 ); ?>">
                                </div>
                                <div class="ofp-field">
                                    <label>Type</label>
                                    <select name="followup_3_type">
                                        <?php foreach ( $type_options as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $config->followup_3_type ?? 'sms', $val ); ?>>
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
                                    echo esc_textarea( $config->followup_3_message ?? '' );
                                ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── IVR Actions ─────────────────────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>IVR Response Settings</h3>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:16px;">Configure what happens when a lead presses a digit during the voice call.</p>
                            <div class="ofp-form-row">
                                <div class="ofp-field">
                                    <label>Transfer Phone (Digit 1)</label>
                                    <input type="tel" name="transfer_phone"
                                           value="<?php echo esc_attr( $config->transfer_phone ?? $client->business_phone ); ?>"
                                           placeholder="e.g. 08012345678">
                                    <p class="ofp-hint" style="margin-top:6px;">Lead is live-transferred to this number when they press 1.</p>
                                </div>
                                <div class="ofp-field">
                                    <label>WhatsApp Number (Digit 2)</label>
                                    <input type="tel" name="whatsapp_link"
                                           value="<?php echo esc_attr( $config->whatsapp_link ?? $client->whatsapp_number ); ?>"
                                           placeholder="e.g. 2348012345678 (international format, no +)">
                                    <p class="ofp-hint" style="margin-top:6px;">Lead receives an SMS with your WhatsApp link when they press 2.</p>
                                </div>
                            </div>
                            <p class="ofp-hint" style="margin-top:8px;"><strong>Digit 3:</strong> Lead is scheduled for a callback in 2 hours (automatic).</p>
                        </div>
                    </div>
                </div>

            </div>

            <div class="ofp-form-actions" style="margin-left: 60px;">
                <button type="submit" class="ofp-btn ofp-btn-primary">Save Pipeline Settings</button>
                <a href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>" class="ofp-btn ofp-btn-secondary">Cancel</a>
            </div>

                </form>
            </div>
            <div class="ofp-pipeline-sidebar">
                <div class="ofp-phone-mockup">
                    <div class="ofp-phone-notch"></div>
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
                            <div class="ofp-chat-bubble call-bubble" id="preview_followup_2_message">
                                <strong>📞 Voice Call Script</strong>
                                <p>Hello, this is a message from My Business. Press 1 to speak with us now. Press 2 for our WhatsApp contact. Press 3 for a callback later.</p>
                            </div>
                            <div class="ofp-chat-bubble" id="preview_followup_3_message">
                                Hi John, we have been trying to reach you. We would love to help. Call or message us anytime. - My Business
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
</div><!-- .ofp-shell -->

<script>
// Live character counter for instant SMS.
var smsField = document.querySelector('[name="instant_sms_message"]');
var counter  = document.getElementById('ofp-sms-count-0');
if (smsField && counter) {
    function updateCount() { counter.textContent = smsField.value.length; }
    smsField.addEventListener('input', updateCount);
    updateCount();
}

// Timeline Scroll Progress
var timeline = document.getElementById('ofpPipelineTimeline');
var progressLine = document.getElementById('ofpTimelineProgress');

if (timeline && progressLine) {
    function updateTimelineProgress() {
        var rect = timeline.getBoundingClientRect();
        var triggerPoint = window.innerHeight * 0.6; // Trigger when 60% down the screen
        
        var scrolled = triggerPoint - rect.top;
        var progress = 0;
        
        if (scrolled > 0) {
            progress = scrolled / rect.height;
            
            // Force 100% if we are at the absolute bottom of the page
            var scrollPos = window.scrollY || document.documentElement.scrollTop;
            var maxScroll = document.documentElement.scrollHeight - window.innerHeight;
            if (scrollPos >= maxScroll - 5) {
                progress = 1;
            }
            
            if (progress > 1) progress = 1;
        }
        
        progressLine.style.transform = 'scaleY(' + progress + ')';
        
        var steps = timeline.querySelectorAll('.ofp-timeline-step');
        steps.forEach(function(step) {
            var stepRect = step.getBoundingClientRect();
            // Activate badge if it crosses the trigger point
            if (stepRect.top + 20 < triggerPoint) {
                step.classList.add('active');
            } else {
                step.classList.remove('active');
            }
        });
    }
    
    window.addEventListener('scroll', updateTimelineProgress, { passive: true });
    // Initial check on load
    updateTimelineProgress();
}

// Live Preview Sync
var previewData = {
    'instant_sms_message': 'preview_instant_sms_message',
    'followup_1_message': 'preview_followup_1_message',
    'followup_2_message': 'preview_followup_2_message',
    'followup_3_message': 'preview_followup_3_message'
};

var businessName = "<?php echo esc_js($client->business_name ?? 'My Business'); ?>";

function formatPreviewText(text) {
    if (!text) return '...';
    // Replace placeholders with dummy data
    var formatted = text.replace(/\{\{name\}\}/gi, 'John')
                        .replace(/\{\{phone\}\}/gi, '07123456789')
                        .replace(/\{\{business_name\}\}/gi, businessName);
    
    // Convert newlines to <br> for HTML display
    return formatted.replace(/\n/g, '<br>');
}

Object.keys(previewData).forEach(function(inputName) {
    var input = document.querySelector('[name="' + inputName + '"]');
    var preview = document.getElementById(previewData[inputName]);
    var phoneBody = document.getElementById('ofpPhoneBody');
    
    if (input && preview) {
        // Sync on input
        input.addEventListener('input', function() {
            var rawText = this.value;
            var isVoice = inputName === 'followup_2_message'; // Follow-up 2 is voice usually
            
            if (isVoice) {
                preview.innerHTML = '<strong>📞 Voice Call Script</strong>' + formatPreviewText(rawText);
            } else {
                preview.innerHTML = formatPreviewText(rawText);
            }
        });
        
        // Highlight bubble on focus
        input.addEventListener('focus', function() {
            document.querySelectorAll('.ofp-chat-bubble').forEach(function(b) {
                b.classList.remove('active');
            });
            preview.classList.add('active');
            // Scroll preview to view
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        
        // Remove highlight on blur
        input.addEventListener('blur', function() {
            preview.classList.remove('active');
        });
        
        // Trigger initial formatting
        input.dispatchEvent(new Event('input'));
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
