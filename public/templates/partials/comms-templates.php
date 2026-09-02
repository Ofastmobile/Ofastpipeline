<?php
/**
 * Client Messaging — Email Template tab (Silver / Gold).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$plan_key = OFP_Subscription::client_plan( (int) $client->id );
$is_gold  = $plan_key === 'gold';
$email_templates = array_values( array_filter( $templates, static function ( $t ) {
    return ( $t->type ?? 'email' ) === 'email';
} ) );
$active = $email_templates[0] ?? null;
foreach ( $email_templates as $t ) {
    if ( ! empty( $t->is_default ) ) {
        $active = $t;
        break;
    }
}

$starter = '<!DOCTYPE html>
<html>
<body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0f172a;color:#ffffff;padding:24px 32px;font-size:20px;font-weight:700;">' . esc_html( $client->business_name ?: 'Your Brand' ) . '</td></tr>
<tr><td style="padding:32px;color:#334155;font-size:15px;line-height:1.6;">{{content}}</td></tr>
<tr><td style="padding:16px 32px;background:#f8fafc;color:#94a3b8;font-size:12px;text-align:center;">Sent by ' . esc_html( $client->business_name ?: 'us' ) . '</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';

$current_body = $active->body ?? $starter;
$sample       = OFP_Comms::sample_body_html();
?>

<div class="ofp-card" style="padding:28px;">
    <h3 style="margin-top:0;"><?php echo $is_gold ? 'Email templates' : 'Email template'; ?></h3>
    <p class="ofp-hint" style="margin-bottom:16px;">
        This wrapper is applied to emails you send to buyers and leads — not to your own system emails (welcome, billing).
        Put <code>{{content}}</code> where the message body should appear. If left empty, the platform default is used.
    </p>

    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:stretch;">
        <?php if ( $is_gold ) : ?>
        <div style="width:220px;">
            <h4 style="margin:0 0 10px;">Saved</h4>
            <div id="ofp-tpl-list" style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ( $email_templates as $t ) : ?>
                    <button type="button" class="ofp-btn ofp-btn-ghost ofp-tpl-item" data-id="<?php echo (int) $t->id; ?>" data-name="<?php echo esc_attr( $t->name ); ?>" data-body="<?php echo esc_attr( $t->body ); ?>" data-default="<?php echo ! empty( $t->is_default ) ? '1' : '0'; ?>" style="text-align:left;">
                        <?php echo esc_html( $t->name ); ?>
                        <?php if ( ! empty( $t->is_default ) ) : ?><span class="ofp-badge ofp-badge-green">Default</span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
                <?php if ( empty( $email_templates ) ) : ?>
                    <p class="ofp-hint">No templates yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <form id="ofp-tpl-form" style="flex:1;min-width:280px;">
            <input type="hidden" name="template_id" id="ofp-tpl-id" value="<?php echo (int) ( $active->id ?? 0 ); ?>">
            <div class="ofp-field" style="margin-bottom:12px;">
                <label>Template name</label>
                <input type="text" name="name" id="ofp-tpl-name" value="<?php echo esc_attr( $active->name ?? 'My email wrapper' ); ?>">
            </div>
            <div class="ofp-field" style="margin-bottom:12px;">
                <label>HTML wrapper</label>
                <textarea name="body" id="ofp-tpl-body" rows="18" style="font-family:monospace;font-size:12px;width:100%;"><?php echo esc_textarea( $current_body ); ?></textarea>
            </div>
            <?php if ( $is_gold ) : ?>
            <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="is_default" id="ofp-tpl-default" value="1" <?php checked( ! empty( $active->is_default ) ); ?>> Set as default for automated emails</label>
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" class="ofp-btn ofp-btn-primary" id="ofp-tpl-save">Save template</button>
                <?php if ( $is_gold ) : ?>
                <button type="button" class="ofp-btn ofp-btn-ghost" id="ofp-tpl-new">Save as new</button>
                <button type="button" class="ofp-btn ofp-btn-ghost ofp-btn-danger" id="ofp-tpl-del">Delete</button>
                <?php endif; ?>
                <button type="button" class="ofp-btn ofp-btn-ghost" id="ofp-tpl-test">Send test email</button>
            </div>
        </form>

        <div style="flex:1;min-width:280px;display:flex;flex-direction:column;">
            <h4 style="margin:0 0 12px;">Live preview</h4>
            <iframe id="ofp-tpl-preview" sandbox srcdoc="" style="width:100%;min-height:520px;border:1px solid var(--border-color);border-radius:8px;background:#fff;"></iframe>
        </div>
    </div>
</div>

<script>
(function () {
    const SAMPLE = <?php echo wp_json_encode( $sample ); ?>;
    const bodyEl = document.getElementById('ofp-tpl-body');
    const iframe = document.getElementById('ofp-tpl-preview');

    function preview() {
        let wrap = bodyEl.value || '';
        let html;
        if (!wrap.trim()) {
            html = '<div style="padding:40px;text-align:center;color:#64748b;font-family:sans-serif;">Platform default will be used.</div>';
        } else if (wrap.indexOf('{{content}}') !== -1 || wrap.indexOf('{email_body}') !== -1) {
            html = wrap.replace('{{content}}', SAMPLE).replace('{email_body}', SAMPLE);
        } else {
            html = wrap + SAMPLE;
        }
        iframe.setAttribute('sandbox', '');
        iframe.srcdoc = html;
    }

    bodyEl.addEventListener('input', preview);
    preview();

    document.querySelectorAll('.ofp-tpl-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('ofp-tpl-id').value = btn.getAttribute('data-id');
            document.getElementById('ofp-tpl-name').value = btn.getAttribute('data-name');
            bodyEl.value = btn.getAttribute('data-body');
            const def = document.getElementById('ofp-tpl-default');
            if (def) def.checked = btn.getAttribute('data-default') === '1';
            preview();
        });
    });

    document.getElementById('ofp-tpl-form').addEventListener('submit', function (e) {
        e.preventDefault();
        saveTemplate(false);
    });

    const newBtn = document.getElementById('ofp-tpl-new');
    if (newBtn) newBtn.addEventListener('click', function () { saveTemplate(true); });

    function saveTemplate(asNew) {
        const btn = document.getElementById('ofp-tpl-save');
        btn.disabled = true;
        const data = new FormData(document.getElementById('ofp-tpl-form'));
        data.append('action', 'ofp_save_template');
        data.append('nonce', ofpClientData.nonce);
        if (asNew) data.append('save_as_new', '1');
        fetch(ofpClientData.ajaxurl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) location.reload();
                else alert(res.data || 'Save failed.');
            })
            .catch(function () { alert('Network error.'); })
            .finally(function () { btn.disabled = false; });
    }

    const delBtn = document.getElementById('ofp-tpl-del');
    if (delBtn) delBtn.addEventListener('click', function () {
        const id = document.getElementById('ofp-tpl-id').value;
        if (!id || !confirm('Delete this template?')) return;
        const data = new FormData();
        data.append('action', 'ofp_delete_template');
        data.append('nonce', ofpClientData.nonce);
        data.append('template_id', id);
        fetch(ofpClientData.ajaxurl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) location.reload();
                else alert(res.data || 'Delete failed.');
            });
    });

    document.getElementById('ofp-tpl-test').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        const data = new FormData();
        data.append('action', 'ofp_send_test_email');
        data.append('nonce', ofpClientData.nonce);
        data.append('template_id', document.getElementById('ofp-tpl-id').value);
        fetch(ofpClientData.ajaxurl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) { alert(res.data || (res.success ? 'Sent.' : 'Failed.')); })
            .catch(function () { alert('Network error.'); })
            .finally(function () { btn.disabled = false; });
    });
})();
</script>
