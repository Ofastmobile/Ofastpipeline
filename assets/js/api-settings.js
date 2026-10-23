/**
 * OFast Pipeline — Integrations & API Settings Script
 * Handles vertical tab navigation and tactile copy feedback.
 */
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ── Tab Switching ────────────────────────────────────────────────────────
    var tabBtns   = document.querySelectorAll('.ofp-tab-btn');
    var tabPanels = document.querySelectorAll('.ofp-tab-panel');

    function switchTab(targetId) {
        if (!targetId) return;

        tabBtns.forEach(function(btn) {
            var isMatch = btn.getAttribute('data-target') === targetId;
            btn.classList.toggle('is-active', isMatch);
        });

        tabPanels.forEach(function(panel) {
            var isMatch = panel.id === targetId;
            panel.classList.toggle('is-active', isMatch);
        });

        if (history.replaceState) {
            history.replaceState(null, null, '#' + targetId.replace('panel-', ''));
        }
    }

    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = this.getAttribute('data-target');
            switchTab(targetId);
        });
    });

    var hash = (window.location.hash || '').replace('#', '');
    if (hash) {
        var mappedId = 'panel-' + hash;
        if (document.getElementById(mappedId)) {
            switchTab(mappedId);
        }
    }

    // ── Interactive Copy Feedback (Modern SaaS Pattern) ────────────────────
    var copySvg  = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    var checkSvg = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

    document.querySelectorAll('.ofp-btn-copy').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var textToCopy = '';
            var directData = this.getAttribute('data-copy');
            var targetSelector = this.getAttribute('data-copy-target');

            if (directData) {
                textToCopy = directData;
            } else if (targetSelector) {
                var targetEl = document.querySelector(targetSelector);
                if (targetEl) textToCopy = targetEl.innerText || targetEl.textContent;
            }

            if (!textToCopy) return;

            navigator.clipboard.writeText(textToCopy).then(function() {
                btn.classList.add('copied');
                btn.innerHTML = checkSvg + ' <span>Copied!</span>';

                setTimeout(function() {
                    btn.classList.remove('copied');
                    var originalLabel = directData && directData.indexOf('http') === 0 ? 'Copy URL' : (directData ? 'Copy ID' : 'Copy Code');
                    btn.innerHTML = copySvg + ' <span>' + originalLabel + '</span>';
                }, 2000);
            }).catch(function(err) {
                console.warn('Clipboard write failed:', err);
            });
        });
    });
});
