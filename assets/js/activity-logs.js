var ofpAjaxUrl = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';
var ofpAjaxNonce = (window.ofpClientData && window.ofpClientData.nonce) ? window.ofpClientData.nonce : '';
var currentDrawerLogId = null;
var currentDrawerRecipient = '';

function ofpHandleViewLog(btn) {
    var isEmail = btn.getAttribute('data-is-email') === '1';
    if (isEmail) {
        ofpOpenEmailSlideDrawer(btn);
    } else {
        ofpOpenClientLogModal(btn);
    }
}

function ofpOpenEmailSlideDrawer(btn) {
    try {
        var raw = btn.getAttribute('data-log');
        if (!raw) return;
        var data = JSON.parse(raw);
        var details = data.details || {};

        currentDrawerLogId = data.id;
        currentDrawerRecipient = details.to || details.recipient || '';

        var drawer = document.getElementById('ofpEmailDrawer');
        var overlay = document.getElementById('ofpEmailDrawerOverlay');
        var toEl = document.getElementById('ofpDrawerTo');
        var subjEl = document.getElementById('ofpDrawerSubject');
        var dateEl = document.getElementById('ofpDrawerDate');
        var actorEl = document.getElementById('ofpDrawerActor');
        var statusBadge = document.getElementById('ofpDrawerStatusBadge');
        var iframe = document.getElementById('ofpEmailIframe');
        var resendStatus = document.getElementById('ofpResendStatus');
        var resendBtn = document.getElementById('ofpBtnResend');

        resendBtn.disabled = false;
        document.getElementById('ofpBtnResendText').textContent = 'Resend Email';
        resendStatus.textContent = '';
        resendStatus.style.color = '';

        toEl.textContent = currentDrawerRecipient || '—';
        subjEl.textContent = details.subject || data.action || 'No Subject';
        dateEl.textContent = (data.date_full || '') + ' at ' + (data.time_exact || '') + ' (' + (data.time_rel || '') + ')';
        actorEl.textContent = data.actor_label || 'Account Owner';

        statusBadge.className = 'ofp-act-pill ' + (data.act_class || 'ofp-act-pill-default');
        statusBadge.textContent = data.action || 'Email sent';

        // Placeholder while loading
        iframe.srcdoc = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#64748b;">Loading email template preview...</div>';

        // Fetch rendered email with template via AJAX
        var fd = new FormData();
        fd.append('action', 'ofp_get_email_preview');
        fd.append('nonce', ofpAjaxNonce);
        fd.append('log_id', data.id);

        fetch(ofpAjaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(function(res) { return res.json(); })
        .then(function(resp) {
            if (resp.success && resp.data && resp.data.html) {
                iframe.srcdoc = resp.data.html;
            } else {
                var fallbackMsg = details.message || details.body || data.description || 'Email was dispatched via SMTP.';
                iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;line-height:1.6;">' +
                                '<h3 style="margin-top:0;color:#0f172a;">' + ofpEscapeHtml(details.subject || data.action) + '</h3>' +
                                '<p style="white-space:pre-wrap;">' + ofpEscapeHtml(fallbackMsg) + '</p>' +
                                '</div>';
            }
        })
        .catch(function(err) {
            console.error('Failed to load email preview:', err);
            iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;"><p>' + ofpEscapeHtml(details.message || data.description || 'Email delivered via SMTP.') + '</p></div>';
        });

        // Open slide-over drawer
        overlay.classList.add('active');
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        console.error('Error opening email drawer:', e);
    }
}

function ofpCloseEmailDrawer() {
    var drawer = document.getElementById('ofpEmailDrawer');
    var overlay = document.getElementById('ofpEmailDrawerOverlay');
    if (drawer) drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function ofpTriggerResendEmail() {
    if (!currentDrawerLogId) return;

    var btn = document.getElementById('ofpBtnResend');
    var btnText = document.getElementById('ofpBtnResendText');
    var status = document.getElementById('ofpResendStatus');

    btn.disabled = true;
    btnText.textContent = 'Resending...';
    status.style.color = 'var(--text-muted, #64748b)';
    status.textContent = 'Connecting to SMTP server...';

    var fd = new FormData();
    fd.append('action', 'ofp_resend_activity_email');
    fd.append('nonce', ofpAjaxNonce);
    fd.append('log_id', currentDrawerLogId);

    fetch(ofpAjaxUrl, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if (resp.success) {
            btnText.textContent = 'Resent Successfully!';
            status.style.color = '#059669';
            status.textContent = '✅ ' + (resp.data.message || 'Email successfully resent via SMTP.');
            setTimeout(function() {
                btn.disabled = false;
                btnText.textContent = 'Resend Again';
            }, 3500);
        } else {
            btn.disabled = false;
            btnText.textContent = 'Resend Email';
            status.style.color = '#dc2626';
            status.textContent = '❌ ' + (resp.data || 'Failed to resend email.');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btnText.textContent = 'Resend Email';
        status.style.color = '#dc2626';
        status.textContent = '❌ Network error during resend.';
    });
}

function ofpOpenClientLogModal(btn) {
    try {
        var raw = btn.getAttribute('data-log');
        if (!raw) return;
        var data = JSON.parse(raw);

        var overlay = document.getElementById('ofpActModalOverlay');
        var pill = document.getElementById('ofpModalActionPill');
        var relBadge = document.getElementById('ofpModalTimeRelBadge');
        var dateFull = document.getElementById('ofpModalDateFull');
        var timeExact = document.getElementById('ofpModalTimeExact');
        var actorBox = document.getElementById('ofpModalActor');
        var narrative = document.getElementById('ofpModalNarrative');
        var paramsWrap = document.getElementById('ofpModalParamsContainer');
        var paramsGrid = document.getElementById('ofpModalParamsGrid');

        // Set action pill & time
        pill.className = 'ofp-act-pill ' + (data.act_class || 'ofp-act-pill-default');
        pill.textContent = data.action || 'Event';
        relBadge.textContent = data.time_rel || '';

        // Set date & time
        dateFull.textContent = data.date_full || '';
        timeExact.textContent = data.time_exact || '';

        // Set actor
        actorBox.innerHTML = '<span class="ofp-act-actor-badge" style="color:' + data.actor_color + '; background:' + data.actor_bg + '; font-size:13px; padding:4px 10px;">' + ofpEscapeHtml(data.actor_label) + '</span>';

        narrative.textContent = data.description || data.action || 'No additional details.';

        // Structured parameters
        var details = data.details || {};
        var skipKeys = ['actor_type', 'actor_name', 'actor_id', 'message', 'description', 'body', 'content'];
        var paramHtml = '';

        for (var key in details) {
            if (!details.hasOwnProperty(key)) continue;
            if (skipKeys.indexOf(key) !== -1) continue;

            var val = details[key];
            var displayVal = '';

            if (typeof val === 'object' && val !== null) {
                displayVal = JSON.stringify(val);
            } else if (typeof val === 'number') {
                if (key.indexOf('amount') !== -1 || key.indexOf('price') !== -1 || key.indexOf('cost') !== -1) {
                    displayVal = '₦' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2 });
                } else {
                    displayVal = String(val);
                }
            } else {
                displayVal = String(val);
            }

            var cleanLabel = key.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); });

            paramHtml += '<div class="ofp-act-box" style="padding:10px 12px;">' +
                         '<div class="ofp-act-box-label" style="font-size:10px;">' + ofpEscapeHtml(cleanLabel) + '</div>' +
                         '<div class="ofp-act-box-val" style="font-size:13px; font-weight:600;">' + ofpEscapeHtml(displayVal) + '</div>' +
                         '</div>';
        }

        if (paramHtml !== '') {
            paramsGrid.innerHTML = paramHtml;
            paramsWrap.style.display = 'block';
        } else {
            paramsGrid.innerHTML = '';
            paramsWrap.style.display = 'none';
        }

        // Show modal
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        console.error('Error opening activity log modal:', e);
    }
}

function ofpCloseClientLogModal() {
    var overlay = document.getElementById('ofpActModalOverlay');
    if (overlay) {
        overlay.classList.remove('active');
    }
    document.body.style.overflow = '';
}

function ofpHandleModalBackdropClick(e) {
    if (e.target && e.target.id === 'ofpActModalOverlay') {
        ofpCloseClientLogModal();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        ofpCloseClientLogModal();
        ofpCloseEmailDrawer();
    }
});

function ofpEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

function ofpToggleActFilterDropdown() {
    var menu = document.getElementById('ofpActFilterMenu');
    if (menu) {
        menu.classList.toggle('active');
    }
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('ofpActDropdownWrap');
    var menu = document.getElementById('ofpActFilterMenu');
    if (wrap && menu && !wrap.contains(e.target)) {
        menu.classList.remove('active');
    }
});
