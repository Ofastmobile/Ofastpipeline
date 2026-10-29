/**
 * OFast Pipeline — Admin Activity Logs & Email Drawer JavaScript
 * Handles log detail modal popup, slide-over email drawer preview, and AJAX resend.
 */

var ofpCurrentLogData = null;
var currentDrawerLogId = null;
var currentDrawerRecipient = '';

function ofpOpenLogModal(buttonElement) {
    var isEmail = buttonElement.getAttribute('data-is-email') === '1';
    if (isEmail) {
        ofpOpenEmailSlideDrawer(buttonElement);
        return;
    }

    var raw = buttonElement.getAttribute('data-log');
    if (!raw) return;

    try {
        var log = JSON.parse(raw);
        ofpCurrentLogData = log;
        ofpPopulateAndShowModal(log);
    } catch (e) {
        console.error('Error parsing activity log data:', e);
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

        if (resendBtn) resendBtn.disabled = false;
        var btnTextEl = document.getElementById('ofpBtnResendText');
        if (btnTextEl) btnTextEl.textContent = 'Resend Email';
        if (resendStatus) {
            resendStatus.textContent = '';
            resendStatus.style.color = '';
        }

        if (toEl) toEl.textContent = currentDrawerRecipient || '—';
        if (subjEl) subjEl.textContent = details.subject || data.action || 'No Subject';
        if (dateEl) dateEl.textContent = (data.date_full || '') + ' at ' + (data.time_exact || '') + ' (' + (data.time_rel || '') + ')';
        if (actorEl) actorEl.textContent = data.actor_label || 'Platform System';
        if (statusBadge) statusBadge.textContent = data.action || 'Email sent';

        // Placeholder while loading
        if (iframe) iframe.srcdoc = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#64748b;">Loading email template preview...</div>';

        // Fetch rendered email with template via AJAX
        var ajaxUrl = (window.ofpAdmin && window.ofpAdmin.ajaxUrl) ? window.ofpAdmin.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
        var ajaxNonce = (window.ofpAdmin && window.ofpAdmin.nonce) ? window.ofpAdmin.nonce : '';

        var fd = new FormData();
        fd.append('action', 'ofp_admin_get_email_preview');
        fd.append('nonce', ajaxNonce);
        fd.append('log_id', data.id);

        fetch(ajaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(function(res) { return res.json(); })
        .then(function(resp) {
            if (iframe) {
                if (resp.success && resp.data && resp.data.html) {
                    iframe.srcdoc = resp.data.html;
                } else {
                    var fallbackMsg = details.message || details.body || data.action || 'Email was dispatched via SMTP.';
                    iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;line-height:1.6;">' +
                                    '<h3 style="margin-top:0;color:#0f172a;">' + escapeHtml(details.subject || data.action) + '</h3>' +
                                    '<p style="white-space:pre-wrap;">' + escapeHtml(fallbackMsg) + '</p>' +
                                    '</div>';
                }
            }
        })
        .catch(function(err) {
            console.error('Failed to load email preview:', err);
            if (iframe) {
                iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;"><p>' + escapeHtml(details.message || data.action || 'Email delivered via SMTP.') + '</p></div>';
            }
        });

        // Open slide-over drawer
        if (overlay) overlay.classList.add('active');
        if (drawer) drawer.classList.add('active');
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

function ofpTriggerAdminResendEmail() {
    if (!currentDrawerLogId) return;

    var btn = document.getElementById('ofpBtnResend');
    var btnText = document.getElementById('ofpBtnResendText');
    var status = document.getElementById('ofpResendStatus');

    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = 'Resending...';
    if (status) {
        status.style.color = '#64748b';
        status.textContent = 'Connecting to SMTP server...';
    }

    var ajaxUrl = (window.ofpAdmin && window.ofpAdmin.ajaxUrl) ? window.ofpAdmin.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    var ajaxNonce = (window.ofpAdmin && window.ofpAdmin.nonce) ? window.ofpAdmin.nonce : '';

    var fd = new FormData();
    fd.append('action', 'ofp_admin_resend_activity_email');
    fd.append('nonce', ajaxNonce);
    fd.append('log_id', currentDrawerLogId);

    fetch(ajaxUrl, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if (resp.success) {
            if (btnText) btnText.textContent = 'Resent Successfully!';
            if (status) {
                status.style.color = '#059669';
                status.textContent = '✅ ' + (resp.data.message || 'Email successfully resent via SMTP.');
            }
            setTimeout(function() {
                window.location.reload();
            }, 1500);
        } else {
            if (btn) btn.disabled = false;
            if (btnText) btnText.textContent = 'Resend Email';
            if (status) {
                status.style.color = '#dc2626';
                status.textContent = '❌ ' + (resp.data || 'Failed to resend email.') + ' Updating log table...';
            }
            setTimeout(function() {
                window.location.reload();
            }, 2200);
        }
    })
    .catch(function(err) {
        if (btn) btn.disabled = false;
        if (btnText) btnText.textContent = 'Resend Email';
        if (status) {
            status.style.color = '#dc2626';
            status.textContent = '❌ Network error during resend.';
        }
    });
}

function ofpPopulateAndShowModal(log) {
    // 1. Title & ID
    var idEl = document.getElementById('ofp-modal-log-id');
    var titleEl = document.getElementById('ofp-modal-title');
    if (idEl) idEl.textContent = '#' + (log.id || '0');
    if (titleEl) titleEl.textContent = log.action || 'Activity Event';

    // 2. Category Icon Theme
    var iconBox = document.getElementById('ofp-modal-cat-icon');
    var theme = log.action_theme || 'default';
    var iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    
    if (iconBox) {
        if (theme === 'success') {
            iconBox.style.background = 'rgba(16, 185, 129, 0.15)';
            iconBox.style.color = '#10b981';
            iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
        } else if (theme === 'danger') {
            iconBox.style.background = 'rgba(239, 68, 68, 0.15)';
            iconBox.style.color = '#ef4444';
            iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
        } else if (theme === 'warning') {
            iconBox.style.background = 'rgba(245, 158, 11, 0.15)';
            iconBox.style.color = '#f59e0b';
            iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
        } else if (theme === 'info') {
            iconBox.style.background = 'rgba(37, 99, 235, 0.15)';
            iconBox.style.color = '#2563eb';
            iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
        } else {
            iconBox.style.background = '#f1f5f9';
            iconBox.style.color = '#475569';
        }
        iconBox.innerHTML = iconSvg;
    }

    // 3. Full Date & Time
    var dateFullEl = document.getElementById('ofp-modal-full-date');
    var timeExactEl = document.getElementById('ofp-modal-exact-time');
    var timeRelEl = document.getElementById('ofp-modal-rel-time');
    if (dateFullEl) dateFullEl.textContent = log.date_full || '—';
    if (timeExactEl) timeExactEl.textContent = log.time_exact || '—';
    if (timeRelEl) timeRelEl.textContent = log.time_rel || 'Recently';

    // 4. Actor Info
    var actorWrap = document.getElementById('ofp-modal-actor-wrap');
    if (actorWrap) {
        actorWrap.innerHTML = '<span class="ofp-actor-pill" style="color:' + (log.actor_color || '#64748b') + '; background:' + (log.actor_bg || '#f1f5f9') + ';">' +
                              escapeHtml(log.actor_label || 'System') + '</span>';
    }

    // 5. Client Info
    var clientWrap = document.getElementById('ofp-modal-client-wrap');
    var clientBtnWrap = document.getElementById('ofp-modal-client-btn-wrap');
    if (clientWrap) {
        if (log.client_id && log.client_url) {
            clientWrap.innerHTML = '<a href="' + escapeHtml(log.client_url) + '" style="color:#10b981; font-weight:700; text-decoration:none;">' +
                                   escapeHtml(log.client_name) + ' (ID #' + log.client_id + ')</a>';
            if (clientBtnWrap) {
                clientBtnWrap.innerHTML = '<a href="' + escapeHtml(log.client_url) + '" class="ofp-btn-modal-client">' +
                                          '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' +
                                          'Open Client Profile</a>';
            }
        } else {
            clientWrap.innerHTML = '<span style="color:#64748b;">Global / Platform Action</span>';
            if (clientBtnWrap) clientBtnWrap.innerHTML = '';
        }
    }

    // 6. Complete Narrative / Message Formulation
    var msgContainer = document.getElementById('ofp-modal-complete-message');
    var details = log.details || {};
    var narrative = '';

    if (details.message) {
        narrative = '<p style="margin:0 0 6px 0; font-weight:600;">' + escapeHtml(details.message) + '</p>';
    } else if (details.note) {
        narrative = '<p style="margin:0 0 6px 0; font-weight:600;">' + escapeHtml(details.note) + '</p>';
    } else if (details.reason) {
        narrative = '<p style="margin:0 0 6px 0;"><strong>Reason:</strong> ' + escapeHtml(details.reason) + '</p>';
    } else if (details.subject) {
        narrative = '<p style="margin:0 0 6px 0;"><strong>Subject:</strong> ' + escapeHtml(details.subject) + '</p>';
    }

    var extraNarrative = '';
    if (log.action.indexOf('Plan changed') !== -1) {
        var prevPlan = details.previous_plan || 'free';
        var newPlan = details.new_plan || details.plan || 'active';
        extraNarrative = '<div style="margin-top:6px; display:flex; align-items:center; gap:8px;">' +
                         'Plan updated: <span class="ofp-badge-global" style="text-transform:uppercase;">' + escapeHtml(prevPlan) + '</span> &rarr; ' +
                         '<span class="ofp-badge-global" style="background:rgba(16,185,129,0.12); color:#059669; text-transform:uppercase;">' + escapeHtml(newPlan) + '</span>' +
                         '</div>';
    } else if (log.action.indexOf('Payment') !== -1 || log.action.indexOf('paid') !== -1) {
        var amt = details.amount ? (typeof details.amount === 'number' ? '₦' + Number(details.amount).toLocaleString() : details.amount) : '';
        var ref = details.reference || details.transaction_id || '';
        extraNarrative = '<p style="margin:4px 0 0 0;">' +
                         (amt ? '<strong>Amount:</strong> ' + escapeHtml(amt) + ' &bull; ' : '') +
                         (ref ? '<strong>Ref:</strong> <code>' + escapeHtml(ref) + '</code>' : '') +
                         '</p>';
    } else if (log.action.indexOf('Email') !== -1 || log.action.indexOf('SMS') !== -1) {
        var recipient = details.to || details.recipient || details.email || '';
        if (recipient) {
            extraNarrative = '<p style="margin:4px 0 0 0;"><strong>Recipient:</strong> ' + escapeHtml(recipient) + '</p>';
        }
    } else if (log.action.indexOf('status changed') !== -1 || details.previous_status) {
        var prevSt = details.previous_status || 'unknown';
        var newSt = details.new_status || 'updated';
        extraNarrative = '<div style="margin-top:6px; display:flex; align-items:center; gap:8px;">' +
                         'Status Transition: <span class="ofp-badge-global">' + escapeHtml(prevSt) + '</span> &rarr; ' +
                         '<span class="ofp-badge-global" style="background:rgba(16,185,129,0.12); color:#059669;">' + escapeHtml(newSt) + '</span>' +
                         '</div>';
    }

    if (!narrative && !extraNarrative) {
        narrative = '<p style="margin:0;">The event <strong>"' + escapeHtml(log.action) + '"</strong> was logged successfully on ' +
                    escapeHtml(log.date_full) + ' at ' + escapeHtml(log.time_exact) + ' by ' + escapeHtml(log.actor_label) + '.</p>';
    }

    if (msgContainer) msgContainer.innerHTML = narrative + extraNarrative;

    // 7. Structured Key-Value Grid
    var structuredSection = document.getElementById('ofp-modal-structured-section');
    var structuredGrid = document.getElementById('ofp-modal-structured-grid');
    if (structuredGrid) {
        structuredGrid.innerHTML = '';
        var skipKeys = ['actor_type', 'actor_name', 'actor_id'];
        var paramCount = 0;

        for (var key in details) {
            if (!details.hasOwnProperty(key)) continue;
            if (skipKeys.indexOf(key) !== -1) continue;

            var val = details[key];
            var formattedVal = '';

            if (val === null || val === undefined) {
                formattedVal = '<em>null</em>';
            } else if (typeof val === 'boolean') {
                formattedVal = val ? '<span style="color:#10b981;">Yes</span>' : '<span style="color:#ef4444;">No</span>';
            } else if (typeof val === 'object') {
                formattedVal = '<code style="font-size:11px;">' + escapeHtml(JSON.stringify(val)) + '</code>';
            } else {
                if ((key === 'amount' || key === 'expected' || key === 'paid' || key === 'balance') && !isNaN(val)) {
                    formattedVal = '₦' + Number(val).toLocaleString();
                } else {
                    formattedVal = escapeHtml(String(val));
                }
            }

            var friendlyKey = key.replace(/_/g, ' ');
            var tile = document.createElement('div');
            tile.className = 'ofp-param-tile';
            tile.innerHTML = '<div class="ofp-param-key">' + escapeHtml(friendlyKey) + '</div>' +
                             '<div class="ofp-param-value">' + formattedVal + '</div>';
            structuredGrid.appendChild(tile);
            paramCount++;
        }

        if (structuredSection) structuredSection.style.display = paramCount > 0 ? 'block' : 'none';
    }

    // 8. Raw JSON Display
    var jsonCode = document.getElementById('ofp-modal-raw-json');
    if (jsonCode) {
        var jsonPretty = JSON.stringify(details, null, 2);
        jsonCode.textContent = jsonPretty;
    }

    var rawBody = document.getElementById('ofp-raw-body');
    var rawChevron = document.getElementById('ofp-raw-chevron');
    var copyLabel = document.getElementById('ofp-copy-json-label');
    if (rawBody) rawBody.style.display = 'none';
    if (rawChevron) rawChevron.classList.remove('expanded');
    if (copyLabel) copyLabel.textContent = 'Copy JSON';

    // 9. Show Modal with animation & lock body scroll
    var overlay = document.getElementById('ofp-log-modal-overlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
    }
    document.body.style.overflow = 'hidden';
}

function ofpCloseLogModal() {
    var overlay = document.getElementById('ofp-log-modal-overlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
    }
    document.body.style.overflow = '';
}

function ofpCloseLogModalOnBackdrop(event) {
    if (event.target === document.getElementById('ofp-log-modal-overlay')) {
        ofpCloseLogModal();
    }
}

function ofpToggleRawJson() {
    var rawBody = document.getElementById('ofp-raw-body');
    var chevron = document.getElementById('ofp-raw-chevron');
    if (!rawBody || !chevron) return;
    if (rawBody.style.display === 'none') {
        rawBody.style.display = 'block';
        chevron.classList.add('expanded');
    } else {
        rawBody.style.display = 'none';
        chevron.classList.remove('expanded');
    }
}

function ofpCopyRawJson(event) {
    event.stopPropagation();
    if (!ofpCurrentLogData) return;
    
    var jsonString = JSON.stringify(ofpCurrentLogData.details || {}, null, 2);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(jsonString).then(function() {
            var label = document.getElementById('ofp-copy-json-label');
            if (label) label.textContent = '✓ Copied!';
            setTimeout(function() {
                if (label) label.textContent = 'Copy JSON';
            }, 2000);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = jsonString;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var label = document.getElementById('ofp-copy-json-label');
        if (label) label.textContent = '✓ Copied!';
        setTimeout(function() {
            if (label) label.textContent = 'Copy JSON';
        }, 2000);
    }
}

// Keyboard ESC to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        var overlay = document.getElementById('ofp-log-modal-overlay');
        if (overlay && overlay.classList.contains('active')) {
            ofpCloseLogModal();
        }
        var drawerOverlay = document.getElementById('ofpEmailDrawerOverlay');
        if (drawerOverlay && drawerOverlay.classList.contains('active')) {
            ofpCloseEmailDrawer();
        }
    }
});

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// Communications Log Drawer Handlers
// ─────────────────────────────────────────────────────────────────────────────
var currentCommDrawerId = null;

function ofpOpenCommEmailDrawer(commId, data) {
    currentCommDrawerId = commId;
    var drawer = document.getElementById('ofpCommDrawer');
    var overlay = document.getElementById('ofpCommDrawerOverlay');
    var toEl = document.getElementById('ofpCommDrawerTo');
    var subjEl = document.getElementById('ofpCommDrawerSubject');
    var dateEl = document.getElementById('ofpCommDrawerDate');
    var actorEl = document.getElementById('ofpCommDrawerActor');
    var iframe = document.getElementById('ofpCommEmailIframe');
    var resendBtn = document.getElementById('ofpCommBtnResend');
    var resendStatus = document.getElementById('ofpCommResendStatus');

    if (resendBtn) resendBtn.disabled = false;
    var resendText = document.getElementById('ofpCommBtnResendText');
    if (resendText) resendText.textContent = 'Resend Email';
    if (resendStatus) {
        resendStatus.textContent = '';
        resendStatus.style.color = '';
    }

    if (toEl) toEl.textContent = data.recipient || '—';
    if (subjEl) subjEl.textContent = 'Notification / Communication Notice';
    if (dateEl) dateEl.textContent = (data.sent_at || '') + ' (' + (data.time_rel || '') + ')';
    if (actorEl) actorEl.textContent = data.client || 'Super Admin';

    if (iframe) iframe.srcdoc = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#64748b;">Loading email template preview...</div>';

    var ajaxUrl = (window.ofpAdmin && window.ofpAdmin.ajaxUrl) ? window.ofpAdmin.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    var ajaxNonce = (window.ofpAdmin && window.ofpAdmin.nonce) ? window.ofpAdmin.nonce : '';

    var fd = new FormData();
    fd.append('action', 'ofp_admin_get_email_preview');
    fd.append('nonce', ajaxNonce);
    fd.append('comm_id', commId);

    fetch(ajaxUrl, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if (!iframe) return;
        if (resp.success && resp.data && resp.data.html) {
            iframe.srcdoc = resp.data.html;
        } else {
            iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;"><p>' + escapeHtml(data.message || 'Email delivered via SMTP.') + '</p></div>';
        }
    })
    .catch(function(err) {
        console.error('Failed to load email preview:', err);
        if (iframe) {
            iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;"><p>' + escapeHtml(data.message || 'Email delivered via SMTP.') + '</p></div>';
        }
    });

    if (overlay) overlay.classList.add('active');
    if (drawer) drawer.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function ofpCloseCommDrawer() {
    var drawer = document.getElementById('ofpCommDrawer');
    var overlay = document.getElementById('ofpCommDrawerOverlay');
    if (drawer) drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function ofpTriggerCommResend() {
    if (!currentCommDrawerId) return;

    var btn = document.getElementById('ofpCommBtnResend');
    var btnText = document.getElementById('ofpCommBtnResendText');
    var status = document.getElementById('ofpCommResendStatus');

    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = 'Resending...';
    if (status) {
        status.style.color = '#64748b';
        status.textContent = 'Connecting to SMTP server...';
    }

    var ajaxUrl = (window.ofpAdmin && window.ofpAdmin.ajaxUrl) ? window.ofpAdmin.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    var ajaxNonce = (window.ofpAdmin && window.ofpAdmin.nonce) ? window.ofpAdmin.nonce : '';

    var fd = new FormData();
    fd.append('action', 'ofp_admin_resend_activity_email');
    fd.append('nonce', ajaxNonce);
    fd.append('comm_id', currentCommDrawerId);

    fetch(ajaxUrl, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if (!btn || !btnText || !status) return;
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
        if (!btn || !btnText || !status) return;
        btn.disabled = false;
        btnText.textContent = 'Resend Email';
        status.style.color = '#dc2626';
        status.textContent = '❌ Network error during resend.';
    });
}

window.ofpOpenCommEmailDrawer = ofpOpenCommEmailDrawer;
window.ofpCloseCommDrawer = ofpCloseCommDrawer;
window.ofpTriggerCommResend = ofpTriggerCommResend;
