<?php
/**
 * Client Messaging — Send Message tab (Silver / Gold).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$plan_key = OFP_Subscription::client_plan( (int) $client->id );
$is_gold  = $plan_key === 'gold';

$leads = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, name, email, phone FROM {$p}ofp_leads WHERE client_id = %d ORDER BY created_at DESC LIMIT 200",
    $client->id
) );

$buyers = $wpdb->get_results( $wpdb->prepare(
    "SELECT pu.id, pu.buyer_name, pu.buyer_email, pu.buyer_phone, pr.title AS property_title
     FROM {$p}ofp_property_purchases pu
     LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
     WHERE pu.client_id = %d
     ORDER BY pu.id DESC
     LIMIT 200",
    $client->id
) );

$email_templates = array_values( array_filter( $templates, static function ( $t ) {
    return ( $t->type ?? 'email' ) === 'email';
} ) );

$default_wrapper = '';
foreach ( $email_templates as $t ) {
    if ( ! empty( $t->is_default ) ) {
        $default_wrapper = $t->body;
        break;
    }
}
if ( $default_wrapper === '' && ! empty( $email_templates ) ) {
    $default_wrapper = $email_templates[0]->body;
}
if ( $default_wrapper === '' ) {
    $default_wrapper = OFP_Comms::admin_wrapper();
}
?>

<div class="ofp-card" style="padding:28px;">
    <h3 style="margin-top:0;">Send Message</h3>
    <p class="ofp-hint" style="margin-bottom:20px;">Email is free. SMS costs ₦<?php echo esc_html( number_format( OFP_Comms::SMS_COST, 2 ) ); ?> each and uses your credit balance.</p>

    <div style="display:flex;gap:24px;align-items:stretch;flex-wrap:wrap;">
        <form id="ofp-send-form" style="flex:1;min-width:320px;">
            <div class="ofp-field" style="margin-bottom:14px;">
                <label>Channel</label>
                <select name="channel" id="ofp-send-channel">
                    <option value="email">Email</option>
                    <option value="sms">SMS</option>
                </select>
            </div>

            <div class="ofp-field" style="margin-bottom:14px;">
                <label>Recipients</label>
                <label style="display:block;font-weight:400;margin:6px 0;"><input type="radio" name="source" value="leads" checked> From Leads</label>
                <label style="display:block;font-weight:400;margin:6px 0;"><input type="radio" name="source" value="buyers"> From Buyers</label>
                <label style="display:block;font-weight:400;margin:6px 0;"><input type="radio" name="source" value="manual"> Manual entry</label>
            </div>

            <div id="ofp-source-leads" class="ofp-field" style="margin-bottom:14px;">
                <input type="search" id="ofp-lead-filter" placeholder="Search leads..." style="margin-bottom:8px;width:100%;">
                <div id="ofp-lead-list" style="max-height:180px;overflow:auto;border:1px solid var(--border-color);border-radius:8px;padding:8px;">
                    <?php if ( empty( $leads ) ) : ?>
                        <p class="ofp-hint">No leads yet.</p>
                    <?php else : ?>
                        <?php foreach ( $leads as $lead ) : ?>
                            <label class="ofp-pick" data-q="<?php echo esc_attr( strtolower( $lead->name . ' ' . $lead->email . ' ' . $lead->phone ) ); ?>" style="display:block;padding:4px 0;font-weight:400;">
                                <input type="checkbox" name="recipient_ids[]" value="<?php echo (int) $lead->id; ?>">
                                <?php echo esc_html( $lead->name ?: $lead->phone ?: $lead->email ); ?>
                                <span class="ofp-hint"><?php echo esc_html( $lead->email ?: $lead->phone ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div id="ofp-source-buyers" class="ofp-field" style="margin-bottom:14px;display:none;">
                <input type="search" id="ofp-buyer-filter" placeholder="Search buyers..." style="margin-bottom:8px;width:100%;">
                <div id="ofp-buyer-list" style="max-height:180px;overflow:auto;border:1px solid var(--border-color);border-radius:8px;padding:8px;">
                    <?php if ( empty( $buyers ) ) : ?>
                        <p class="ofp-hint">No buyers yet.</p>
                    <?php else : ?>
                        <?php foreach ( $buyers as $buyer ) : ?>
                            <label class="ofp-pick" data-q="<?php echo esc_attr( strtolower( $buyer->buyer_name . ' ' . $buyer->buyer_email . ' ' . $buyer->buyer_phone ) ); ?>" style="display:block;padding:4px 0;font-weight:400;">
                                <input type="checkbox" name="recipient_ids[]" value="<?php echo (int) $buyer->id; ?>">
                                <?php echo esc_html( $buyer->buyer_name ?: $buyer->buyer_email ); ?>
                                <span class="ofp-hint"><?php echo esc_html( $buyer->property_title ?: $buyer->buyer_phone ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div id="ofp-source-manual" class="ofp-field" style="margin-bottom:14px;display:none;">
                <textarea name="recipients" id="ofp-manual-recipients" rows="3" placeholder="Comma-separated emails or phone numbers"></textarea>
            </div>

            <?php if ( $is_gold ) : ?>
            <div class="ofp-field" id="ofp-tpl-wrap" style="margin-bottom:14px;">
                <label>Email template</label>
                <select name="template_id" id="ofp-send-template">
                    <option value="" data-body="<?php echo esc_attr( $default_wrapper ); ?>">Default</option>
                    <?php foreach ( $email_templates as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" data-body="<?php echo esc_attr( $t->body ); ?>">
                            <?php echo esc_html( $t->name ); ?><?php echo ! empty( $t->is_default ) ? ' (default)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="ofp-field" id="ofp-subject-wrap" style="margin-bottom:14px;">
                <label>Subject</label>
                <input type="text" name="subject" id="ofp-send-subject" placeholder="Email subject">
            </div>

            <div class="ofp-field" style="margin-bottom:14px;">
                <label>Message</label>
                <p class="ofp-hint" style="margin:4px 0 8px;">Placeholders: <code>{{name}}</code> <code>{{email}}</code> <code>{{phone}}</code> <code>{{property_title}}</code> <code>{{business_name}}</code></p>
                <textarea name="body" id="ofp-send-body" rows="8" required placeholder="Write your message..."></textarea>
            </div>

            <button type="submit" class="ofp-btn ofp-btn-primary" id="ofp-send-btn">Send Message</button>
        </form>

        <div style="flex:1;min-width:280px;display:flex;flex-direction:column;">
            <h4 style="margin:0 0 12px;">Live preview</h4>
            <iframe id="ofp-send-preview" sandbox srcdoc="" style="width:100%;min-height:520px;border:1px solid var(--border-color);border-radius:8px;background:#fff;"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    const SAMPLE = <?php echo wp_json_encode( OFP_Comms::SAMPLE_VARS ); ?>;
    const DEFAULT_WRAP = <?php echo wp_json_encode( $default_wrapper ); ?>;
    const SAMPLE_BODY = <?php echo wp_json_encode( OFP_Comms::sample_body_html() ); ?>;
    const channel = document.getElementById('ofp-send-channel');
    const bodyEl = document.getElementById('ofp-send-body');
    const subEl = document.getElementById('ofp-send-subject');
    const iframe = document.getElementById('ofp-send-preview');
    const tplSel = document.getElementById('ofp-send-template');
    const sources = document.querySelectorAll('input[name="source"]');

    function applyPlaceholders(text) {
        return String(text || '')
            .replaceAll('{{name}}', SAMPLE.name)
            .replaceAll('{{buyer_name}}', SAMPLE.name)
            .replaceAll('{{email}}', SAMPLE.email)
            .replaceAll('{{buyer_email}}', SAMPLE.email)
            .replaceAll('{{phone}}', SAMPLE.phone)
            .replaceAll('{{property_title}}', SAMPLE.property_title)
            .replaceAll('{{business_name}}', SAMPLE.business_name);
    }

    function currentWrapper() {
        if (tplSel && tplSel.selectedOptions[0]) {
            return tplSel.selectedOptions[0].getAttribute('data-body') || DEFAULT_WRAP;
        }
        return DEFAULT_WRAP;
    }

    function wrapEmail(inner) {
        const wrap = currentWrapper();
        if (wrap && (wrap.indexOf('{{content}}') !== -1 || wrap.indexOf('{email_body}') !== -1)) {
            return wrap.replace('{{content}}', inner).replace('{email_body}', inner);
        }
        return '<div style="font-family:sans-serif;padding:24px;background:#f1f5f9;"><div style="max-width:600px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;">' + inner + '</div></div>';
    }

    function preview() {
        const ch = channel.value;
        let inner = applyPlaceholders(bodyEl.value || SAMPLE_BODY).replace(/\n/g, '<br>');
        let html;
        if (ch === 'sms') {
            html = '<div style="min-height:100%;display:flex;align-items:center;justify-content:center;background:#e2e8f0;font-family:sans-serif;"><div style="width:280px;height:480px;background:#fff;border-radius:28px;border:8px solid #94a3b8;padding:48px 16px 16px;box-sizing:border-box;"><div style="background:#e2e8f0;border-radius:16px;padding:12px 14px;font-size:14px;line-height:1.5;">' + inner + '</div></div></div>';
        } else {
            html = wrapEmail(inner);
        }
        iframe.setAttribute('sandbox', '');
        iframe.srcdoc = html;
    }

    function showSource() {
        const src = (document.querySelector('input[name="source"]:checked') || {}).value;
        document.getElementById('ofp-source-leads').style.display = src === 'leads' ? 'block' : 'none';
        document.getElementById('ofp-source-buyers').style.display = src === 'buyers' ? 'block' : 'none';
        document.getElementById('ofp-source-manual').style.display = src === 'manual' ? 'block' : 'none';
        document.querySelectorAll('#ofp-lead-list input, #ofp-buyer-list input').forEach(function (el) { el.disabled = true; });
        if (src === 'leads') document.querySelectorAll('#ofp-lead-list input').forEach(function (el) { el.disabled = false; });
        if (src === 'buyers') document.querySelectorAll('#ofp-buyer-list input').forEach(function (el) { el.disabled = false; });
    }

    function filterList(inputId, listId) {
        const q = (document.getElementById(inputId).value || '').toLowerCase();
        document.querySelectorAll('#' + listId + ' .ofp-pick').forEach(function (el) {
            el.style.display = !q || (el.getAttribute('data-q') || '').indexOf(q) !== -1 ? 'block' : 'none';
        });
    }

    channel.addEventListener('change', function () {
        const email = channel.value === 'email';
        document.getElementById('ofp-subject-wrap').style.display = email ? 'block' : 'none';
        if (document.getElementById('ofp-tpl-wrap')) document.getElementById('ofp-tpl-wrap').style.display = email ? 'block' : 'none';
        preview();
    });
    sources.forEach(function (el) { el.addEventListener('change', showSource); });
    bodyEl.addEventListener('input', preview);
    if (tplSel) tplSel.addEventListener('change', preview);
    document.getElementById('ofp-lead-filter').addEventListener('input', function () { filterList('ofp-lead-filter', 'ofp-lead-list'); });
    document.getElementById('ofp-buyer-filter').addEventListener('input', function () { filterList('ofp-buyer-filter', 'ofp-buyer-list'); });

    document.getElementById('ofp-send-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = document.getElementById('ofp-send-btn');
        btn.disabled = true;
        btn.textContent = 'Sending...';
        const data = new FormData(this);
        data.append('action', 'ofp_send_client_broadcast');
        data.append('nonce', ofpClientData.nonce);
        fetch(ofpClientData.ajaxurl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                alert(res.data || (res.success ? 'Sent.' : 'Failed.'));
                if (res.success) { this.reset(); showSource(); preview(); }
            }.bind(this))
            .catch(function () { alert('Network error.'); })
            .finally(function () { btn.disabled = false; btn.textContent = 'Send Message'; });
    });

    showSource();
    preview();
})();
</script>
