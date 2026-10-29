/**
 * OFast Pipeline - Client Portal Feedback & Bug Report Modal Handler
 */
(function() {
    'use strict';

    var modalInitialized = false;
    var cachedModal = null;

    function getModal() {
        return cachedModal || (cachedModal = document.getElementById('ofp-client-feedback-modal'));
    }

    function initFeedbackModal() {
        var modal = getModal();
        if ( ! modal ) return false;
        if ( modalInitialized ) return true;
        modalInitialized = true;

        var closeBtn     = document.getElementById('ofp-cfb-close-btn');
        var cancelBtn    = document.getElementById('ofp-cfb-cancel-btn');
        var doneBtn      = document.getElementById('ofp-cfb-done-btn');
        var form         = document.getElementById('ofp-cfb-form');
        var successScr   = document.getElementById('ofp-cfb-success-screen');
        var tabFeature   = document.getElementById('ofp-cfb-tab-feature');
        var tabBug       = document.getElementById('ofp-cfb-tab-bug');
        var typeInput    = document.getElementById('ofp-cfb-type');
        var pageUrlInput = document.getElementById('ofp-cfb-page-url');
        var deviceInput  = document.getElementById('ofp-cfb-device-info');
        var titleLabel   = document.getElementById('ofp-cfb-title-label');
        var titleInput   = document.getElementById('ofp-cfb-title');
        var descLabel    = document.getElementById('ofp-cfb-desc-label');
        var descInput    = document.getElementById('ofp-cfb-description');
        var videoInput   = document.getElementById('ofp-cfb-video-url');
        var videoHint    = document.getElementById('ofp-cfb-video-hint');
        var videoPill    = document.getElementById('ofp-cfb-video-pill');
        var videoPillText= document.getElementById('ofp-cfb-video-pill-text');
        var diagBox      = document.getElementById('ofp-cfb-diag-box');
        var submitBtn    = document.getElementById('ofp-cfb-submit-btn');
        var btnText      = document.getElementById('ofp-cfb-btn-text');
        var btnSpinner   = document.getElementById('ofp-cfb-btn-spinner');
        var errorAlert   = document.getElementById('ofp-cfb-error-alert');
        var modalTitle   = document.getElementById('ofp-cfb-modal-title');
        var prioLabel    = document.getElementById('ofp-cfb-priority-label');

        // ── Custom Platform Glass Dropdown Handler ──
        function initCfbCustomSelects() {
            var wraps = modal.querySelectorAll('.ofp-cfb-custom-select-wrap');
            wraps.forEach(function(wrap) {
                var select  = wrap.querySelector('.ofp-cfb-native-select');
                var trigger = wrap.querySelector('.ofp-cfb-select-trigger');
                var valSpan = wrap.querySelector('.ofp-cfb-select-value');
                var menu    = wrap.querySelector('.ofp-cfb-select-menu');
                if ( ! select || ! trigger || ! valSpan || ! menu ) return;

                menu.innerHTML = '';
                Array.from(select.options).forEach(function(opt) {
                    var item = document.createElement('div');
                    item.className = 'ofp-cfb-select-item' + (opt.selected ? ' selected' : '');
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                    item.dataset.value = opt.value;

                    var labelSpan = document.createElement('span');
                    labelSpan.textContent = opt.text;
                    item.appendChild(labelSpan);

                    var checkSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    checkSvg.setAttribute('class', 'ofp-cfb-select-item-check');
                    checkSvg.setAttribute('viewBox', '0 0 24 24');
                    checkSvg.setAttribute('fill', 'none');
                    checkSvg.setAttribute('stroke', 'currentColor');
                    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    path.setAttribute('stroke-linecap', 'round');
                    path.setAttribute('stroke-linejoin', 'round');
                    path.setAttribute('stroke-width', '2.5');
                    path.setAttribute('d', 'M5 13l4 4L19 7');
                    checkSvg.appendChild(path);
                    item.appendChild(checkSvg);

                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        select.value = opt.value;
                        valSpan.textContent = opt.text;
                        menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                            el.classList.remove('selected');
                            el.setAttribute('aria-selected', 'false');
                        });
                        item.classList.add('selected');
                        item.setAttribute('aria-selected', 'true');
                        wrap.classList.remove('open');
                        trigger.setAttribute('aria-expanded', 'false');
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    menu.appendChild(item);
                });

                var currentOpt = select.options[select.selectedIndex];
                if ( currentOpt ) {
                    valSpan.textContent = currentOpt.text;
                }

                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isOpen = wrap.classList.contains('open');
                    modal.querySelectorAll('.ofp-cfb-custom-select-wrap.open').forEach(function(otherWrap) {
                        if ( otherWrap !== wrap ) {
                            otherWrap.classList.remove('open');
                            var otherTrg = otherWrap.querySelector('.ofp-cfb-select-trigger');
                            if ( otherTrg ) otherTrg.setAttribute('aria-expanded', 'false');
                        }
                    });
                    if ( isOpen ) {
                        wrap.classList.remove('open');
                        trigger.setAttribute('aria-expanded', 'false');
                    } else {
                        wrap.classList.add('open');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });

                select.addEventListener('change', function() {
                    var selectedOpt = select.options[select.selectedIndex];
                    if ( selectedOpt ) {
                        valSpan.textContent = selectedOpt.text;
                        menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                            var isSelected = el.dataset.value === selectedOpt.value;
                            el.classList.toggle('selected', isSelected);
                            el.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                        });
                    }
                });
            });
        }

        initCfbCustomSelects();

        // Close custom select dropdowns on outside click
        document.addEventListener('click', function(e) {
            if ( ! e.target.closest('.ofp-cfb-custom-select-wrap') ) {
                modal.querySelectorAll('.ofp-cfb-custom-select-wrap.open').forEach(function(wrap) {
                    wrap.classList.remove('open');
                    var trg = wrap.querySelector('.ofp-cfb-select-trigger');
                    if ( trg ) trg.setAttribute('aria-expanded', 'false');
                });
            }
        });

        // Sync dropdown labels when form is reset
        if ( form ) {
            form.addEventListener('reset', function() {
                setTimeout(function() {
                    var wraps = modal.querySelectorAll('.ofp-cfb-custom-select-wrap');
                    wraps.forEach(function(wrap) {
                        var select = wrap.querySelector('.ofp-cfb-native-select');
                        var valSpan = wrap.querySelector('.ofp-cfb-select-value');
                        var menu    = wrap.querySelector('.ofp-cfb-select-menu');
                        if ( select && valSpan ) {
                            var opt = select.options[select.selectedIndex];
                            if ( opt ) {
                                valSpan.textContent = opt.text;
                                if ( menu ) {
                                    menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                                        var isSel = el.dataset.value === opt.value;
                                        el.classList.toggle('selected', isSel);
                                        el.setAttribute('aria-selected', isSel ? 'true' : 'false');
                                    });
                                }
                            }
                        }
                    });
                }, 20);
            });
        }

        function closeModal() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        function setType(type) {
            if ( ! typeInput ) return;
            typeInput.value = type;
            if ( errorAlert ) errorAlert.style.display = 'none';

            var formEl = document.getElementById('ofp-cfb-form');
            if ( formEl ) {
                formEl.classList.add('ofp-form-crossfading');
            }

            setTimeout(function() {
                if ( type === 'bug_report' ) {
                    if ( tabBug ) tabBug.classList.add('active');
                    if ( tabFeature ) tabFeature.classList.remove('active');
                    if ( modalTitle ) modalTitle.textContent = 'Report a Bug or Issue';
                    if ( titleLabel ) titleLabel.innerHTML = 'What went wrong? <span class="req">*</span>';
                    if ( titleInput ) titleInput.placeholder = 'e.g. Save button did not respond on property page';
                    if ( descLabel ) descLabel.innerHTML = 'Steps to Reproduce & Expected Behavior <span class="req">*</span>';
                    if ( descInput ) descInput.placeholder = 'What were you doing when the bug occurred? What did you expect to happen versus what actually happened?';
                    if ( videoHint ) videoHint.textContent = 'Optional: Have a video (YouTube / Loom) or screenshot showing the glitch? Paste the link here.';
                    if ( prioLabel ) prioLabel.textContent = 'Issue Severity';
                    if ( diagBox ) diagBox.style.display = 'flex';
                    if ( btnText ) btnText.textContent = 'Submit Bug Report';
                } else {
                    if ( tabFeature ) tabFeature.classList.add('active');
                    if ( tabBug ) tabBug.classList.remove('active');
                    if ( modalTitle ) modalTitle.textContent = 'Request a Feature';
                    if ( titleLabel ) titleLabel.innerHTML = 'Feature Title <span class="req">*</span>';
                    if ( titleInput ) titleInput.placeholder = 'e.g. Bulk WhatsApp rent reminder broadcast';
                    if ( descLabel ) descLabel.innerHTML = 'Detailed Explanation <span class="req">*</span>';
                    if ( descInput ) descInput.placeholder = 'Please describe what you want to achieve, how it should work, and why it is valuable to your business...';
                    if ( videoHint ) videoHint.textContent = 'Optional: Have a YouTube or Loom video explaining your idea? Paste the link here so we can watch it!';
                    if ( prioLabel ) prioLabel.textContent = 'Business Impact / Priority';
                    if ( diagBox ) diagBox.style.display = 'none';
                    if ( btnText ) btnText.textContent = 'Submit Feature Request';
                }

                if ( formEl ) {
                    requestAnimationFrame(function() {
                        formEl.classList.remove('ofp-form-crossfading');
                    });
                }
            }, 50);
        }

        // Attach internal tab switching
        if ( tabFeature ) tabFeature.addEventListener('click', function() { setType('feature_request'); });
        if ( tabBug ) tabBug.addEventListener('click', function() { setType('bug_report'); });

        // Close triggers
        if ( closeBtn )  closeBtn.addEventListener('click', closeModal);
        if ( cancelBtn ) cancelBtn.addEventListener('click', closeModal);
        if ( doneBtn )   doneBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', function(e) {
            if ( e.target === modal ) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if ( e.key === 'Escape' && modal.style.display === 'flex' ) {
                closeModal();
            }
        });

        // Live Video URL detection
        if ( videoInput ) {
            videoInput.addEventListener('input', function() {
                var val = videoInput.value.trim();
                if ( ! val ) {
                    if ( videoPill ) videoPill.style.display = 'none';
                    return;
                }

                if ( val.indexOf('youtube.com') !== -1 || val.indexOf('youtu.be') !== -1 ) {
                    if ( videoPill ) videoPill.style.display = 'inline-flex';
                    if ( videoPillText ) videoPillText.textContent = '✓ YouTube video recognized';
                } else if ( val.indexOf('loom.com') !== -1 ) {
                    if ( videoPill ) videoPill.style.display = 'inline-flex';
                    if ( videoPillText ) videoPillText.textContent = '✓ Loom video recognized';
                } else if ( val.indexOf('vimeo.com') !== -1 ) {
                    if ( videoPill ) videoPill.style.display = 'inline-flex';
                    if ( videoPillText ) videoPillText.textContent = '✓ Vimeo video recognized';
                } else if ( val.startsWith('http://') || val.startsWith('https://') ) {
                    if ( videoPill ) videoPill.style.display = 'inline-flex';
                    if ( videoPillText ) videoPillText.textContent = '✓ Link detected';
                } else {
                    if ( videoPill ) videoPill.style.display = 'none';
                }
            });
        }

        // Form Submit Handler
        if ( form ) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if ( errorAlert ) errorAlert.style.display = 'none';

                var title = titleInput ? titleInput.value.trim() : '';
                var desc  = descInput ? descInput.value.trim() : '';

                if ( ! title || ! desc ) {
                    if ( errorAlert ) {
                        errorAlert.textContent = 'Please fill in both the title and description.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                if ( submitBtn ) submitBtn.disabled = true;
                if ( btnText ) btnText.style.display = 'none';
                if ( btnSpinner ) btnSpinner.style.display = 'inline-block';

                var fd = new FormData(form);
                fd.append('action', 'ofp_submit_feedback');
                fd.append('nonce', typeof ofpClientData !== 'undefined' ? ofpClientData.nonce : '');

                var ajaxUrl = typeof ofpClientData !== 'undefined' ? ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if ( submitBtn ) submitBtn.disabled = false;
                    if ( btnText ) btnText.style.display = 'inline';
                    if ( btnSpinner ) btnSpinner.style.display = 'none';

                    if ( data.success ) {
                        form.reset();
                        if ( videoPill ) videoPill.style.display = 'none';
                        form.style.display = 'none';
                        if ( successScr ) successScr.style.display = 'block';
                        
                        var successTitle = document.getElementById('ofp-cfb-success-title');
                        var successMsg   = document.getElementById('ofp-cfb-success-message');
                        if ( typeInput && typeInput.value === 'bug_report' ) {
                            if ( successTitle ) successTitle.textContent = 'Bug Report Logged!';
                            if ( successMsg ) successMsg.textContent = 'Thank you for helping us catch this! Our engineering team will investigate and address it.';
                        } else {
                            if ( successTitle ) successTitle.textContent = 'Feature Request Received!';
                            if ( successMsg ) successMsg.textContent = 'Thank you for your suggestion! We review all client requests when planning upcoming releases.';
                        }
                    } else {
                        if ( errorAlert ) {
                            errorAlert.textContent = (data.data && data.data.message) ? data.data.message : 'Unable to submit feedback. Please try again.';
                            errorAlert.style.display = 'block';
                        }
                    }
                })
                .catch(function(err) {
                    if ( submitBtn ) submitBtn.disabled = false;
                    if ( btnText ) btnText.style.display = 'inline';
                    if ( btnSpinner ) btnSpinner.style.display = 'none';
                    if ( errorAlert ) {
                        errorAlert.textContent = 'A connection error occurred. Please try again.';
                        errorAlert.style.display = 'block';
                    }
                });
            });
        }

        return true;
    }

    // Auto-capture device diagnostics
    function getDeviceInfo() {
        try {
            return JSON.stringify({
                userAgent: navigator.userAgent,
                screenWidth: window.innerWidth,
                screenHeight: window.innerHeight,
                language: navigator.language,
                platform: navigator.platform || 'Unknown'
            });
        } catch(e) {
            return '';
        }
    }

    // Global Modal Opener
    function openModal(defaultType) {
        initFeedbackModal();
        var modal = getModal();
        if ( ! modal ) {
            console.warn('[OFP] Feedback modal container not found in DOM.');
            return;
        }

        var form         = document.getElementById('ofp-cfb-form');
        var successScr   = document.getElementById('ofp-cfb-success-screen');
        var errorAlert   = document.getElementById('ofp-cfb-error-alert');
        var pageUrlInput = document.getElementById('ofp-cfb-page-url');
        var deviceInput  = document.getElementById('ofp-cfb-device-info');
        var titleInput   = document.getElementById('ofp-cfb-title');
        var typeInput    = document.getElementById('ofp-cfb-type');
        var tabFeature   = document.getElementById('ofp-cfb-tab-feature');
        var tabBug       = document.getElementById('ofp-cfb-tab-bug');
        var modalTitle   = document.getElementById('ofp-cfb-modal-title');
        var titleLabel   = document.getElementById('ofp-cfb-title-label');
        var descLabel    = document.getElementById('ofp-cfb-desc-label');
        var descInput    = document.getElementById('ofp-cfb-description');
        var videoHint    = document.getElementById('ofp-cfb-video-hint');
        var prioLabel    = document.getElementById('ofp-cfb-priority-label');
        var diagBox      = document.getElementById('ofp-cfb-diag-box');
        var btnText      = document.getElementById('ofp-cfb-btn-text');

        var type = defaultType || 'feature_request';
        if ( typeInput ) typeInput.value = type;

        if ( type === 'bug_report' ) {
            if ( tabBug ) tabBug.classList.add('active');
            if ( tabFeature ) tabFeature.classList.remove('active');
            if ( modalTitle ) modalTitle.textContent = 'Report a Bug or Issue';
            if ( titleLabel ) titleLabel.innerHTML = 'What went wrong? <span class="req">*</span>';
            if ( titleInput ) titleInput.placeholder = 'e.g. Save button did not respond on property page';
            if ( descLabel ) descLabel.innerHTML = 'Steps to Reproduce & Expected Behavior <span class="req">*</span>';
            if ( descInput ) descInput.placeholder = 'What were you doing when the bug occurred? What did you expect to happen versus what actually happened?';
            if ( videoHint ) videoHint.textContent = 'Optional: Have a video (YouTube / Loom) or screenshot showing the glitch? Paste the link here.';
            if ( prioLabel ) prioLabel.textContent = 'Issue Severity';
            if ( diagBox ) diagBox.style.display = 'flex';
            if ( btnText ) btnText.textContent = 'Submit Bug Report';
        } else {
            if ( tabFeature ) tabFeature.classList.add('active');
            if ( tabBug ) tabBug.classList.remove('active');
            if ( modalTitle ) modalTitle.textContent = 'Request a Feature';
            if ( titleLabel ) titleLabel.innerHTML = 'Feature Title <span class="req">*</span>';
            if ( titleInput ) titleInput.placeholder = 'e.g. Bulk WhatsApp rent reminder broadcast';
            if ( descLabel ) descLabel.innerHTML = 'Detailed Explanation <span class="req">*</span>';
            if ( descInput ) descInput.placeholder = 'Please describe what you want to achieve, how it should work, and why it is valuable to your business...';
            if ( videoHint ) videoHint.textContent = 'Optional: Have a YouTube or Loom video explaining your idea? Paste the link here so we can watch it!';
            if ( prioLabel ) prioLabel.textContent = 'Business Impact / Priority';
            if ( diagBox ) diagBox.style.display = 'none';
            if ( btnText ) btnText.textContent = 'Submit Feature Request';
        }

        if ( form ) form.style.display = 'block';
        if ( successScr ) successScr.style.display = 'none';
        if ( errorAlert ) errorAlert.style.display = 'none';
        if ( pageUrlInput ) pageUrlInput.value = window.location.href;
        if ( deviceInput ) deviceInput.value = getDeviceInfo();

        // Close all other dropdown menus in the UI so they don't stay open
        document.querySelectorAll('.ofp-user-menu.active, .ofp-topbar-user-menu.active, #ofp-topbar-profile-menu.active, #ofp-notif-menu.active').forEach(function(m) {
            m.classList.remove('active');
        });

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        setTimeout(function() {
            if ( titleInput ) titleInput.focus();
        }, 100);
    }

    // Expose open methods immediately
    window.ofpOpenFeedbackModal = openModal;
    window.ofpOpenFeedback = openModal;

    // Document-level delegated click listener (always bound, works everywhere)
    document.addEventListener('click', function(e) {
        var trg = e.target.closest ? e.target.closest('.ofp-open-feedback-trigger, [data-open-feedback]') : null;
        if (trg) {
            e.preventDefault();
            e.stopPropagation();
            var reqType = trg.getAttribute('data-type') || 'feature_request';
            openModal(reqType);
        }
    }, true);

    // Initial DOM ready binding
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFeedbackModal);
    } else {
        initFeedbackModal();
    }
})();
