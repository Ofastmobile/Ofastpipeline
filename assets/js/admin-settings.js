/**
 * OFast Pipeline - Admin Settings Page Interactive Scripts
 */

(function() {

    'use strict';



    // ── 1. Tab Switching & Hash Persistence ──────────────────────────────────

    function switchTab(tabId) {

        if ( ! tabId ) return;

        tabId = tabId.replace(/^#/, '');



        var targetPane = document.getElementById(tabId);

        if ( ! targetPane ) return;



        // Update nav items

        document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item').forEach(function(btn) {

            btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tabId);

        });



        // Update panes

        document.querySelectorAll('.ofp-tab-pane').forEach(function(pane) {

            pane.classList.remove('is-active');

        });

        targetPane.classList.add('is-active');



        // Update hidden field & URL hash

        var activeTabInput = document.getElementById('ofp_active_tab');

        if ( activeTabInput ) activeTabInput.value = tabId;



        if ( history.replaceState ) {

            history.replaceState(null, null, '#' + tabId);

        } else {

            location.hash = '#' + tabId;

        }



        // Scroll into view if requested from health card

        window.scrollTo({ top: 0, behavior: 'smooth' });

    }



    // Attach click to sidebar nav items

    document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item').forEach(function(btn) {

        btn.addEventListener('click', function(e) {

            e.preventDefault();

            switchTab(this.getAttribute('data-tab'));

        });

    });



    // Attach click to top health cards

    document.querySelectorAll('.ofp-nav-trigger').forEach(function(card) {

        card.addEventListener('click', function(e) {

            e.preventDefault();

            switchTab(this.getAttribute('data-target'));

        });

    });



    // Check initial hash on page load

    window.addEventListener('DOMContentLoaded', function() {

        var hash = location.hash ? location.hash.replace(/^#/, '') : '';

        if ( hash && document.getElementById(hash) ) {

            switchTab(hash);

        }

    });



    // ── 2. Password Eyeball Show/Hide Toggle ─────────────────────────────────

    document.querySelectorAll('.ofp-btn-toggle-pwd').forEach(function(btn) {

        btn.addEventListener('click', function(e) {

            e.preventDefault();

            var input = this.closest('.ofp-input-box').querySelector('input');

            if ( ! input ) return;



            var isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';



            this.innerHTML = isPassword

                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'

                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

        });

    });



    // ── 3. One-Click Copy to Clipboard ───────────────────────────────────────

    document.querySelectorAll('.ofp-copy-btn').forEach(function(btn) {

        btn.addEventListener('click', function(e) {

            e.preventDefault();

            var targetSelector = this.getAttribute('data-clipboard-target');

            var targetElem = document.querySelector(targetSelector);

            if ( ! targetElem ) return;



            var textToCopy = targetElem.innerText || targetElem.value;

            navigator.clipboard.writeText(textToCopy).then(function() {

                var originalHtml = btn.innerHTML;

                btn.classList.add('is-copied');

                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> <span>Copied!</span>';



                setTimeout(function() {

                    btn.classList.remove('is-copied');

                    btn.innerHTML = originalHtml;

                }, 2000);

            });

        });

    });



    // ── 4. Click-to-Insert Merge Tag Chips ───────────────────────────────────

    document.querySelectorAll('.ofp-chip').forEach(function(chip) {

        chip.addEventListener('click', function(e) {

            e.preventDefault();

            var tag = this.getAttribute('data-insert');

            var targetId = this.getAttribute('data-target');

            var textarea = document.getElementById(targetId);

            if ( ! textarea || ! tag ) return;



            var start = textarea.selectionStart;

            var end = textarea.selectionEnd;

            var text = textarea.value;



            textarea.value = text.substring(0, start) + tag + text.substring(end);

            textarea.selectionStart = textarea.selectionEnd = start + tag.length;

            textarea.focus();



            // Trigger character counter update

            textarea.dispatchEvent(new Event('input', { bubbles: true }));

        });

    });



    // ── 5. Live SMS Character Counter ────────────────────────────────────────

    function updateSmsCounters() {

        document.querySelectorAll('.ofp-sms-counter-input').forEach(function(ta) {

            var counterBar = ta.nextElementSibling;

            if ( ! counterBar || ! counterBar.classList.contains('ofp-char-counter-bar') ) return;

            var pill = counterBar.querySelector('.ofp-char-pill');

            if ( ! pill ) return;



            var count = ta.value.length;

            var segments = Math.ceil(count / 160) || 1;

            pill.textContent = count + ' chars (' + segments + ' SMS)';



            pill.classList.toggle('is-multisms', count > 160);

        });

    }



    document.querySelectorAll('.ofp-sms-counter-input').forEach(function(ta) {

        ta.addEventListener('input', updateSmsCounters);

    });

    updateSmsCounters();



    // ── 6. Live Bank Account Card Preview ────────────────────────────────────

    function syncBankPreview() {

        var bankNameInput   = document.getElementById('ofp_bank_name_input');

        var bankAccInput    = document.getElementById('ofp_bank_acc_input');

        var bankHolderInput = document.getElementById('ofp_bank_holder_input');



        var cardBankName   = document.getElementById('ofp-card-bank-name');

        var cardAccNo      = document.getElementById('ofp-card-acc-no');

        var cardHolderName = document.getElementById('ofp-card-holder-name');



        if ( bankNameInput && cardBankName ) {

            bankNameInput.addEventListener('input', function() {

                cardBankName.textContent = this.value.trim() ? this.value.toUpperCase() : 'BANK NAME';

            });

        }



        if ( bankAccInput && cardAccNo ) {

            bankAccInput.addEventListener('input', function() {

                cardAccNo.textContent = this.value.trim() ? this.value : '0000 0000 00';

            });

        }



        if ( bankHolderInput && cardHolderName ) {

            bankHolderInput.addEventListener('input', function() {

                cardHolderName.textContent = this.value.trim() ? this.value.toUpperCase() : 'OFFICIAL BENEFICIARY';

            });

        }

    }

    syncBankPreview();



    // ── 7. Mode Visual Cards Toggle (SMTP & Prembly) ─────────────────────────

    function syncRadioCards(name) {

        var radios = document.querySelectorAll('input[name="' + name + '"]');

        radios.forEach(function(radio) {

            var card = radio.closest('.ofp-mode-card');

            if ( card ) {

                if ( radio.checked ) {

                    card.classList.add('is-active');

                } else {

                    card.classList.remove('is-active');

                }

            }

        });

    }



    function syncSmtpCards() {

        syncRadioCards('ofp_smtp_mode');

        var selected = document.querySelector('input[name="ofp_smtp_mode"]:checked');

        var panel = document.getElementById('ofp-smtp-credentials-panel');

        if ( panel && selected ) {

            panel.style.display = selected.value === 'custom' ? '' : 'none';

        }

    }



    document.querySelectorAll('input[name="ofp_smtp_mode"]').forEach(function(radio) {

        radio.addEventListener('change', syncSmtpCards);

    });



    document.querySelectorAll('input[name="ofp_prembly_env"]').forEach(function(radio) {

        radio.addEventListener('change', function() {

            syncRadioCards('ofp_prembly_env');

        });

    });



    // ── 8. Pricing Tier Cards Features Accordion & FAQ Arrow Toggle ──────────

    var tierGrid = document.querySelector('.ofp-pricing-tier-grid');

    var tierToggles = document.querySelectorAll('.ofp-tier-accordion-toggle');

    var globalTierToggle = document.getElementById('ofp-admin-toggle-all-cards');



    function setTierExpansion(expand) {

        if ( ! tierGrid ) return;

        tierGrid.classList.toggle('is-expanded', expand);

        tierToggles.forEach(function(btn) {

            btn.setAttribute('aria-expanded', expand ? 'true' : 'false');

            var arrow = btn.querySelector('.ofp-faq-arrow');

            if ( arrow ) {

                arrow.classList.toggle('is-rotated', expand);

            }

        });

        if ( globalTierToggle ) {

            globalTierToggle.textContent = expand ? 'Collapse All Features' : 'Toggle All Features';

        }

    }



    if ( tierGrid && tierToggles.length ) {

        tierToggles.forEach(function(btn) {

            btn.addEventListener('click', function(e) {

                e.preventDefault();

                var willExpand = ! tierGrid.classList.contains('is-expanded');

                setTierExpansion(willExpand);

            });

        });



        if ( globalTierToggle ) {

            globalTierToggle.addEventListener('click', function(e) {

                e.preventDefault();

                var willExpand = ! tierGrid.classList.contains('is-expanded');

                setTierExpansion(willExpand);

            });

        }



        // Live update bullet count pill as user types in textarea

        document.querySelectorAll('.ofp-tier-accordion-body textarea').forEach(function(ta) {

            ta.addEventListener('input', function() {

                var lines = this.value.split('\n').filter(function(l) { return l.trim().length > 0; });

                var wrap = this.closest('.ofp-tier-accordion-wrap');

                if ( wrap ) {

                    var pill = wrap.querySelector('.ofp-tier-count-pill');

                    if ( pill ) {

                        pill.textContent = lines.length;

                    }

                }

            });

        });

    }



        // ── 8b. Auto-Calculate Plan Period Prices (3m 5%, 6m 10%, 1yr 15%) ──────────
    var planDiscounts = { 3: 0.05, 6: 0.10, 12: 0.15 };

    function getCalculatedPeriodPrice(monthly, months) {
        var disc = planDiscounts[months] || 0;
        return Math.round(monthly * months * (1 - disc));
    }

    function formatNumberNaira(n) {
        return Math.round(n).toLocaleString('en-NG');
    }

    function updatePeriodRow(planKey, months, forceAuto) {
        var monthlyInput = document.getElementById('listing_price_' + planKey);
        var periodInput = document.getElementById('period_price_' + planKey + '_' + months);
        var previewSpan = document.getElementById('period_mo_preview_' + planKey + '_' + months);
        if (!monthlyInput || !periodInput) return;

        var monthly = parseFloat(monthlyInput.value) || 0;
        var calculated = getCalculatedPeriodPrice(monthly, months);

        if (forceAuto || !periodInput.value || parseFloat(periodInput.value) === 0 || periodInput.dataset.autoSync === '1') {
            if (monthly > 0) {
                periodInput.value = calculated;
                periodInput.dataset.autoSync = '1';
            }
        }
        periodInput.placeholder = calculated > 0 ? calculated : '0.00';

        var currentVal = parseFloat(periodInput.value) || 0;
        if (previewSpan) {
            if (currentVal > 0) {
                var perMo = Math.round(currentVal / months);
                var fullPrice = monthly * months;
                var saved = fullPrice > currentVal ? Math.round(fullPrice - currentVal) : 0;
                var text = '₦' + formatNumberNaira(perMo) + '/mo';
                if (saved > 0) {
                    text += ' · save ₦' + formatNumberNaira(saved);
                }
                previewSpan.textContent = text;
            } else if (monthly > 0) {
                var perMo = Math.round(calculated / months);
                previewSpan.textContent = '₦' + formatNumberNaira(perMo) + '/mo (auto)';
            } else {
                previewSpan.textContent = '';
            }
        }
    }

    function updateAllPeriodsForPlan(planKey, forceAuto) {
        [3, 6, 12].forEach(function(m) {
            updatePeriodRow(planKey, m, forceAuto);
        });
    }

    ['silver', 'gold'].forEach(function(planKey) {
        var monthlyInput = document.getElementById('listing_price_' + planKey);
        if (monthlyInput) {
            monthlyInput.addEventListener('input', function() {
                updateAllPeriodsForPlan(planKey, false);
            });
            monthlyInput.addEventListener('change', function() {
                updateAllPeriodsForPlan(planKey, false);
            });
        }

        [3, 6, 12].forEach(function(m) {
            var periodInput = document.getElementById('period_price_' + planKey + '_' + m);
            if (periodInput) {
                periodInput.addEventListener('input', function() {
                    this.dataset.autoSync = '0';
                    updatePeriodRow(planKey, m, false);
                });
            }
        });

        updateAllPeriodsForPlan(planKey, false);
    });

    document.querySelectorAll('.ofp-btn-recalc-plan-discounts').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var planKey = this.getAttribute('data-plan');
            if (planKey) {
                updateAllPeriodsForPlan(planKey, true);
                var origText = this.textContent;
                this.textContent = '✓ Calculated!';
                var b = this;
                setTimeout(function() { b.textContent = origText; }, 1500);
            }
        });
    });

    // ── 9. Real-Time Settings Search & Filter across Tabs ────────────────────

    var searchInput = document.getElementById('ofp-settings-filter-input');

    var navButtons = document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item');

    var originalPills = {};



    navButtons.forEach(function(btn) {

        var tabKey = btn.getAttribute('data-tab');

        var pill = btn.querySelector('.ofp-nav-pill');

        if ( pill ) {

            originalPills[tabKey] = pill.textContent;

        }

    });



    if ( searchInput ) {

        searchInput.addEventListener('input', function() {

            var q = this.value.toLowerCase().trim();

            var firstTabWithMatch = null;



            navButtons.forEach(function(btn) {

                var tabId = btn.getAttribute('data-tab');

                var pane = document.getElementById(tabId);

                var pill = btn.querySelector('.ofp-nav-pill');

                if ( ! pane ) return;



                var cards = pane.querySelectorAll('.ofp-card');

                var matchCount = 0;



                cards.forEach(function(card) {

                    if ( ! q ) {

                        card.style.display = '';

                        return;

                    }

                    var text = (card.textContent || '').toLowerCase();

                    var isMatch = text.indexOf(q) !== -1;

                    card.style.display = isMatch ? '' : 'none';

                    if ( isMatch ) matchCount++;

                });



                if ( ! q ) {

                    if ( pill && originalPills[tabId] ) {

                        pill.textContent = originalPills[tabId];

                        pill.style.background = '';

                        pill.style.color = '';

                    }

                } else {

                    if ( pill ) {

                        pill.textContent = matchCount + ' found';

                        pill.style.background = matchCount > 0 ? '#dcfce7' : '#f1f5f9';

                        pill.style.color = matchCount > 0 ? '#15803d' : '#94a3b8';

                    }

                    if ( matchCount > 0 && ! firstTabWithMatch ) {

                        firstTabWithMatch = tabId;

                    }

                }

            });



            if ( q && firstTabWithMatch ) {

                var activeBtn = document.querySelector('.ofp-settings-tab-btn.is-active, .ofp-nav-item.is-active');

                var currentActiveTab = activeBtn ? activeBtn.getAttribute('data-tab') : '';

                var currentPane = document.getElementById(currentActiveTab);

                var currentHasMatch = currentPane ? currentPane.querySelectorAll('.ofp-card:not([style*="display: none"])').length > 0 : false;

                

                if ( ! currentHasMatch ) {

                    switchTab(firstTabWithMatch);

                }

            }

        });

    }



    // ── 10. Global Keyboard Shortcuts (⌘S to Save, ⌘K to Search) ─────────────

    document.addEventListener('keydown', function(e) {

        // ⌘S or Ctrl+S to save

        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 's' ) {

            e.preventDefault();

            var form = document.getElementById('ofp-master-settings-form');

            if ( form ) form.submit();

        }



        // ⌘K or Ctrl+K to focus search filter

        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {

            if ( searchInput ) {

                e.preventDefault();

                searchInput.focus();

                searchInput.select();

            }

        }

    });



})();

