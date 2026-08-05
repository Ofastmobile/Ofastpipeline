/**
 * OFast Pipeline — Client Portal JavaScript
 *
 * Handles:
 *  - Mobile nav toggle
 *  - SMS character counter on pipeline-settings
 *  - Form submission UX (loading state on buttons)
 *  - Auto-dismiss success alerts
 *  - Confirm dialogs for destructive actions
 */
(function () {
    'use strict';

    // ── Sidebar toggle (Phase 10) ────────────────────────────────────────────
    // Desktop (>1024px): sidebar defaults open. Toggling adds/removes
    // 'ofp-sidebar-closed' on <body>. Preference persists via localStorage.
    // Mobile (≤1024px): sidebar defaults closed (off-canvas). Toggling
    // adds/removes 'ofp-sidebar-open' on <body>. Does NOT persist — always
    // starts closed on a fresh page load to avoid an unexpected full-screen
    // overlay on first paint.
    function initSidebarToggle() {
        var toggle   = document.getElementById('ofp-sidebar-toggle');
        var backdrop = document.getElementById('ofp-sidebar-backdrop');
        var body     = document.body;

        if ( ! toggle ) return;

        var DESKTOP_BREAKPOINT = 1025;
        var STORAGE_KEY = 'ofp_sidebar_closed';

        function isDesktop() {
            return window.innerWidth >= DESKTOP_BREAKPOINT;
        }

        // Apply the saved desktop preference on load.
        if ( isDesktop() && window.localStorage.getItem(STORAGE_KEY) === '1' ) {
            body.classList.add('ofp-sidebar-closed');
            toggle.setAttribute('aria-expanded', 'false');
        }

        function toggleSidebar() {
            if ( isDesktop() ) {
                var nowClosed = body.classList.toggle('ofp-sidebar-closed');
                window.localStorage.setItem(STORAGE_KEY, nowClosed ? '1' : '0');
                toggle.setAttribute('aria-expanded', nowClosed ? 'false' : 'true');
            } else {
                var nowOpen = body.classList.toggle('ofp-sidebar-open');
                toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            }
        }

        toggle.addEventListener('click', toggleSidebar);

        // Tapping the backdrop closes the sidebar on mobile.
        if ( backdrop ) {
            backdrop.addEventListener('click', function () {
                body.classList.remove('ofp-sidebar-open');
                toggle.setAttribute('aria-expanded', 'false');
            });
        }

        // Closing the mobile sidebar automatically when resizing up to desktop,
        // so it doesn't stay stuck open as an overlay at a wider viewport.
        window.addEventListener('resize', function () {
            if ( isDesktop() ) {
                body.classList.remove('ofp-sidebar-open');
            }
        });
    }

    // ── SMS character counter ────────────────────────────────────────────────
    function initCharCounters() {
        var textareas = document.querySelectorAll('[data-char-counter]');
        textareas.forEach(function (ta) {
            var counterId = ta.getAttribute('data-char-counter');
            var counter   = document.getElementById(counterId);
            if ( ! counter ) return;

            function update() {
                var len   = ta.value.length;
                var max   = parseInt( ta.getAttribute('maxlength') || '320', 10 );
                var parts = Math.ceil( len / 160 );
                counter.textContent = len + ' / ' + max + ' chars';
                if ( len > 160 ) {
                    counter.textContent += ' (' + parts + ' SMS)';
                }
                counter.style.color = len > max * 0.9 ? '#ef4444' : '#9ca3af';
            }

            ta.addEventListener('input', update);
            update();
        });
    }

    // ── Loading state on form buttons ────────────────────────────────────────
    function initFormLoadingState() {
        var forms = document.querySelectorAll('.ofp-form, .ofp-funding-pane form');
        forms.forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('button[type="submit"]');
                if ( btn ) {
                    // If the button has a name, we must create a hidden input for it 
                    // because disabling it removes its value from the POST data.
                    if ( btn.name ) {
                        var hidden = document.createElement('input');
                        hidden.type  = 'hidden';
                        hidden.name  = btn.name;
                        hidden.value = btn.value || '1';
                        form.appendChild(hidden);
                    }
                    
                    btn.disabled = true;
                    btn.textContent = 'Saving…';
                }
            });
        });
    }

    // ── Auto-dismiss success alerts ──────────────────────────────────────────
    function initAutoDismissAlerts() {
        var alerts = document.querySelectorAll('.ofp-alert-success');
        alerts.forEach(function (alert) {
            setTimeout(function () {
                alert.style.transition = 'opacity 0.4s ease';
                alert.style.opacity    = '0';
                setTimeout(function () {
                    if ( alert.parentNode ) alert.parentNode.removeChild(alert);
                }, 400);
            }, 5000);
        });
    }

    // ── Plan selector toggle on signup page ──────────────────────────────────
    function initSignupPlanToggle() {
        var crmBox      = document.querySelector('[name="want_crm"]');
        var planSection = document.getElementById('ofp-plan-section');
        if ( crmBox && planSection ) {
            function toggle() {
                planSection.style.display = crmBox.checked ? '' : 'none';
            }
            crmBox.addEventListener('change', toggle);
            toggle();
        }

        var listingBox  = document.querySelector('[name="want_listing"]');
        var listingPlanSection = document.getElementById('ofp-listing-plan-section');
        if ( listingBox && listingPlanSection ) {
            function toggleListing() {
                listingPlanSection.style.display = listingBox.checked ? '' : 'none';
            }
            listingBox.addEventListener('change', toggleListing);
            toggleListing();
        }
        
        var catSelect = document.querySelector('[name="business_category"]');
        if ( catSelect && listingBox ) {
            catSelect.addEventListener('change', function() {
                if ( this.value === 'property' ) {
                    listingBox.checked = true;
                    if ( listingPlanSection ) {
                        listingPlanSection.style.display = '';
                    }
                }
            });
        }
    }

    // ── IVR digit action labels ───────────────────────────────────────────────
    // Shows a hint below the voice message field reminding the client
    // what each digit maps to, so they can write the script accordingly.
    function initIVRHints() {
        var voiceTypeSelect = document.querySelector('[name="followup_2_type"]');
        var voiceMessageField = document.querySelector('[name="followup_2_message"]');
        if ( ! voiceTypeSelect || ! voiceMessageField ) return;

        var hintEl = document.createElement('p');
        hintEl.className = 'ofp-hint';
        hintEl.style.marginTop = '4px';
        voiceMessageField.parentNode.appendChild(hintEl);

        function updateHint() {
            if ( voiceTypeSelect.value === 'voice' ) {
                hintEl.textContent = 'Digit 1 = live transfer | Digit 2 = WhatsApp SMS | Digit 3 = callback in 2h';
                hintEl.style.display = '';
            } else {
                hintEl.style.display = 'none';
            }
        }

        voiceTypeSelect.addEventListener('change', updateHint);
        updateHint();
    }

    // ── Status change confirm on leads page ──────────────────────────────────
    function initLeadStatusConfirm() {
        var selects = document.querySelectorAll('[name="new_status"]');
        selects.forEach(function (select) {
            select.addEventListener('change', function () {
                if ( this.value === 'converted' ) {
                    if ( ! window.confirm('Mark this lead as converted? This will cancel any remaining automated follow-ups.') ) {
                        // Reset to previous value — find the original selected option.
                        for ( var i = 0; i < this.options.length; i++ ) {
                            if ( this.options[i].defaultSelected ) {
                                this.value = this.options[i].value;
                                break;
                            }
                        }
                        return;
                    }
                }
                // Submit the parent form.
                this.closest('form').submit();
            });

            // Prevent the auto-submit from firing on page load.
            select.removeAttribute('onchange');
        });
    }

    // ── Password strength indicator ───────────────────────────────────────────
    function initPasswordStrength() {
        var pwField    = document.getElementById('new_password');
        var confirmPw  = document.getElementById('confirm_password');
        if ( ! pwField ) return;

        var indicator = document.createElement('div');
        indicator.style.cssText = 'height:4px;border-radius:100px;margin-top:6px;transition:background 0.3s,width 0.3s;width:0;';
        pwField.parentNode.appendChild(indicator);

        pwField.addEventListener('input', function () {
            var val      = this.value;
            var strength = 0;
            if ( val.length >= 8  ) strength++;
            if ( val.length >= 12 ) strength++;
            if ( /[A-Z]/.test(val) ) strength++;
            if ( /[0-9]/.test(val) ) strength++;
            if ( /[^A-Za-z0-9]/.test(val) ) strength++;

            var colors = [ '#ef4444', '#f59e0b', '#f59e0b', '#22c55e', '#22c55e' ];
            var widths = [ '20%', '40%', '60%', '80%', '100%' ];
            indicator.style.background = colors[ strength - 1 ] || '#e5e7eb';
            indicator.style.width      = widths[ strength - 1 ] || '0';
        });

        // Match indicator.
        if ( confirmPw ) {
            confirmPw.addEventListener('input', function () {
                this.style.borderColor = this.value === pwField.value
                    ? '#22c55e'
                    : ( this.value.length > 0 ? '#ef4444' : '' );
            });
        }
    }

    // ── Theme Toggle ─────────────────────────────────────────────────────────
    function initThemeToggle() {
        var btn = document.getElementById('ofp-theme-toggle');
        if ( ! btn ) return;
        
        var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
        if ( currentTheme === 'light' ) {
            document.documentElement.setAttribute('data-theme', 'light');
        }

        btn.addEventListener('click', function () {
            var isLight = document.documentElement.getAttribute('data-theme') === 'light';
            if ( isLight ) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('ofp_theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
                localStorage.setItem('ofp_theme', 'light');
            }
        });
    }

    // ── User Dropdown Toggle ─────────────────────────────────────────────────
    function initUserDropdown() {
        var menu = document.getElementById('ofp-user-menu');
        var avatar = document.getElementById('ofp-user-avatar');
        if ( ! menu || ! avatar ) return;

        avatar.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
            if ( ! menu.contains(e.target) ) {
                menu.classList.remove('active');
            }
        });
    }

    // ── Custom Dropdown Component ────────────────────────────────────────────
    function initOfpCustomSelects() {
        var selects = document.querySelectorAll('.ofp-select');
        selects.forEach(function(select) {
            if (select.closest('.ofp-custom-select-wrapper')) return;

            var wrapper = document.createElement('div');
            wrapper.className = 'ofp-custom-select-wrapper';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);

            var trigger = document.createElement('div');
            trigger.className = 'ofp-custom-select-trigger';
            var selectedOption = select.options[select.selectedIndex];
            trigger.textContent = selectedOption ? selectedOption.text : 'Select an option';
            wrapper.appendChild(trigger);

            var optionsContainer = document.createElement('div');
            optionsContainer.className = 'ofp-custom-select-options';
            wrapper.appendChild(optionsContainer);

            Array.from(select.options).forEach(function(option, index) {
                if (option.hidden) return;
                var optDiv = document.createElement('div');
                optDiv.className = 'ofp-custom-select-option';
                if (option.selected) optDiv.classList.add('selected');
                optDiv.textContent = option.text;
                optDiv.dataset.value = option.value;
                optDiv.dataset.index = index;

                optDiv.addEventListener('click', function(e) {
                    e.stopPropagation();
                    select.selectedIndex = this.dataset.index;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    trigger.textContent = this.textContent;
                    optionsContainer.querySelectorAll('.ofp-custom-select-option').forEach(function(el) {
                        el.classList.remove('selected');
                    });
                    this.classList.add('selected');
                    wrapper.classList.remove('open');
                });
                optionsContainer.appendChild(optDiv);
            });

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                document.querySelectorAll('.ofp-custom-select-wrapper.open').forEach(function(el) {
                    if (el !== wrapper) el.classList.remove('open');
                });
                wrapper.classList.toggle('open');
            });
            
            select.addEventListener('change', function() {
                var newSelected = select.options[select.selectedIndex];
                if (newSelected) {
                    trigger.textContent = newSelected.text;
                    optionsContainer.querySelectorAll('.ofp-custom-select-option').forEach(function(el) {
                        el.classList.toggle('selected', el.dataset.index == select.selectedIndex);
                    });
                }
            });
        });

        document.addEventListener('click', function() {
            document.querySelectorAll('.ofp-custom-select-wrapper.open').forEach(function(el) {
                el.classList.remove('open');
            });
        });
    }

    // ── AJAX Leads Filtering ─────────────────────────────────────────────────
    function initAjaxLeads() {
        var tabs = document.querySelectorAll('.ofp-ajax-tab');
        if (!tabs.length || typeof ofpClientData === 'undefined') return;

        var tbody = document.getElementById('ofp-leads-tbody');
        if (!tbody) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();

                // Visual updates instantly
                tabs.forEach(function (t) {
                    t.style.borderBottomColor = 'transparent';
                    t.style.color = 'var(--text-muted)';
                });
                tab.style.borderBottomColor = 'var(--accent-blue)';
                tab.style.color = 'var(--accent-blue)';
                
                // Show a brief loading state
                tbody.style.opacity = '0.5';

                var status = tab.getAttribute('data-status');
                
                var data = new URLSearchParams();
                data.append('action', 'ofp_fetch_leads');
                data.append('nonce', ofpClientData.nonce);
                data.append('status', status);

                fetch(ofpClientData.ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(res => {
                    tbody.style.opacity = '1';
                    if (res.success && res.data.html) {
                        tbody.innerHTML = res.data.html;
                        // Re-initialize custom selects for the new DOM elements
                        initOfpCustomSelects();
                    }
                })
                .catch(err => {
                    tbody.style.opacity = '1';
                    console.error('AJAX leads fetch failed:', err);
                });
            });
        });
    }

    // ── Init all ─────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initSidebarToggle();
        initCharCounters();
        initFormLoadingState();
        initAutoDismissAlerts();
        initSignupPlanToggle();
        initIVRHints();
        initLeadStatusConfirm();
        initPasswordStrength();
        initThemeToggle();
        initUserDropdown();
        initOfpCustomSelects();
        initAjaxLeads();
    });

}());
