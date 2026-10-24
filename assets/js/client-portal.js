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
    var isThemeToggling = false;

    function togglePortalTheme() {
        if ( isThemeToggling ) return;
        isThemeToggling = true;
        setTimeout(function () { isThemeToggling = false; }, 200);

        var PORTAL_KEY = 'ofp_portal_theme';
        var html = document.documentElement;
        var currentlyDark = html.classList.contains('dark') || html.getAttribute('data-theme') === 'dark';
        var makeDark = !currentlyDark;

        if ( makeDark ) {
            html.classList.add('dark');
            html.classList.remove('light');
            html.setAttribute('data-theme', 'dark');
            try { localStorage.setItem(PORTAL_KEY, 'dark'); } catch (e) {}
        } else {
            html.classList.remove('dark');
            html.classList.add('light');
            html.setAttribute('data-theme', 'light');
            try { localStorage.setItem(PORTAL_KEY, 'light'); } catch (e) {}
        }

        window.dispatchEvent(new CustomEvent('ofp-theme-changed', {
            detail: { dark: makeDark, theme: makeDark ? 'dark' : 'light' }
        }));
    }

    window.ofpToggleTheme = togglePortalTheme;
    window.toggleDark = togglePortalTheme;

    function initThemeToggle() {
        var btn = document.getElementById('ofp-theme-toggle');
        if ( ! btn ) return;

        if ( btn._ofpThemeBound ) return;
        btn._ofpThemeBound = true;

        if ( btn.hasAttribute('onclick') ) {
            btn.removeAttribute('onclick');
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            togglePortalTheme();
        });
    }

    // ── User & Notification Dropdown Toggle ─────────────────────────────────
    function initUserDropdown() {
        var userMenu = document.getElementById('ofp-user-menu');
        var avatar   = document.getElementById('ofp-user-avatar');
        var notifMenu    = document.getElementById('ofp-notif-menu');
        var notifTrigger = document.getElementById('ofp-notif-trigger');

        if ( avatar && userMenu ) {
            avatar.addEventListener('click', function (e) {
                e.stopPropagation();
                if ( notifMenu ) notifMenu.classList.remove('active');
                userMenu.classList.toggle('active');
            });
        }

        if ( notifTrigger && notifMenu ) {
            notifTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                if ( userMenu ) userMenu.classList.remove('active');
                notifMenu.classList.toggle('active');
            });
        }

        document.addEventListener('click', function (e) {
            if ( userMenu && ! userMenu.contains(e.target) ) {
                userMenu.classList.remove('active');
            }
            if ( notifMenu && ! notifMenu.contains(e.target) ) {
                notifMenu.classList.remove('active');
            }
        });
    }

    // ── Custom Dropdown Component ────────────────────────────────────────────
    function initOfpCustomSelects() {
        var selects = document.querySelectorAll('.ofp-select');
        selects.forEach(function(select) {
            if (select.closest('.ofp-custom-select-wrapper') || select.closest('.ofp-modal') || select.closest('.ofp-modal-backdrop') || select.classList.contains('ofp-native-select')) return;

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

    window.initOfpCustomSelects = initOfpCustomSelects;

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

    // ── Global OFP Namespace: Top Progress Bar & Modern Toasts ───────────────
    var progressEl = null;
    var progressTimer = null;
    var progressVal = 0;

    function getProgressBar() {
        if (!progressEl) {
            progressEl = document.getElementById('ofp-top-progress');
            if (!progressEl) {
                progressEl = document.createElement('div');
                progressEl.id = 'ofp-top-progress';
                document.body.appendChild(progressEl);
            }
        }
        return progressEl;
    }

    var ProgressEngine = {
        start: function () {
            var bar = getProgressBar();
            clearInterval(progressTimer);
            progressVal = 20;
            bar.classList.add('is-active');
            bar.style.width = progressVal + '%';

            progressTimer = setInterval(function () {
                if (progressVal < 85) {
                    progressVal += Math.random() * 8 + 3;
                    if (progressVal > 85) progressVal = 85;
                    bar.style.width = progressVal + '%';
                }
            }, 250);
        },
        set: function (pct) {
            var bar = getProgressBar();
            bar.classList.add('is-active');
            bar.style.width = Math.min(100, Math.max(0, pct)) + '%';
        },
        done: function () {
            var bar = getProgressBar();
            clearInterval(progressTimer);
            progressVal = 100;
            bar.style.width = '100%';
            setTimeout(function () {
                bar.classList.remove('is-active');
                setTimeout(function () {
                    bar.style.width = '0%';
                }, 250);
            }, 200);
        }
    };

    var ToastEngine = {
        container: null,
        getContainer: function () {
            if (!this.container) {
                this.container = document.querySelector('.ofp-toast-container');
                if (!this.container) {
                    this.container = document.createElement('div');
                    this.container.className = 'ofp-toast-container';
                    document.body.appendChild(this.container);
                }
            }
            return this.container;
        },
        show: function (titleOrMsg, type, duration, detailMsg) {
            type = type || 'success';
            duration = duration !== undefined ? duration : 3500;

            var title = detailMsg ? titleOrMsg : (type === 'success' ? 'Saved' : (type === 'error' ? 'Notice' : 'Information'));
            var message = detailMsg ? detailMsg : titleOrMsg;

            var container = this.getContainer();
            var toast = document.createElement('div');
            toast.className = 'ofp-toast ofp-toast-' + type;

            var iconSvg = '';
            if (type === 'success') {
                iconSvg = '<svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>';
            } else if (type === 'error') {
                iconSvg = '<svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>';
            } else if (type === 'warning') {
                iconSvg = '<svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>';
            } else {
                iconSvg = '<svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>';
            }

            toast.innerHTML = 
                '<div class="ofp-toast-icon">' + iconSvg + '</div>' +
                '<div class="ofp-toast-content">' +
                    '<div class="ofp-toast-title">' + title + '</div>' +
                    '<div class="ofp-toast-msg">' + message + '</div>' +
                '</div>' +
                '<button type="button" class="ofp-toast-close" aria-label="Close">' +
                    '<svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>' +
                '</button>' +
                '<div class="ofp-toast-bar"></div>';

            container.appendChild(toast);

            var bar = toast.querySelector('.ofp-toast-bar');
            if (bar && duration > 0) {
                bar.style.transition = 'width ' + duration + 'ms linear';
                requestAnimationFrame(function () {
                    bar.style.width = '0%';
                });
            }

            var dismissTimeout = null;
            function dismiss() {
                clearTimeout(dismissTimeout);
                toast.classList.add('ofp-toast-hiding');
                setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 300);
            }

            if (duration > 0) {
                dismissTimeout = setTimeout(dismiss, duration);
            }

            var closeBtn = toast.querySelector('.ofp-toast-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', dismiss);
            }

            // Pause dismiss on hover
            toast.addEventListener('mouseenter', function () {
                clearTimeout(dismissTimeout);
                if (bar) bar.style.transition = 'none';
            });
            toast.addEventListener('mouseleave', function () {
                if (duration > 0) {
                    dismissTimeout = setTimeout(dismiss, 1500);
                }
            });

            return toast;
        }
    };

    // Expose global OFP API
    window.OFP = window.OFP || {};
    window.OFP.progress = ProgressEngine;
    window.OFP.toast = function (titleOrMsg, type, duration, detailMsg) {
        return ToastEngine.show(titleOrMsg, type, duration, detailMsg);
    };

    // Global fetch interceptor to drive top preloader automatically
    var origFetch = window.fetch;
    if (origFetch) {
        var activeRequests = 0;
        window.fetch = function () {
            activeRequests++;
            ProgressEngine.start();
            return origFetch.apply(this, arguments).finally(function () {
                activeRequests--;
                if (activeRequests <= 0) {
                    activeRequests = 0;
                    ProgressEngine.done();
                }
            });
        };
    }

    // ── Init all ─────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initSidebarToggle();
        initCharCounters();
        initFormLoadingState();
        initAutoDismissAlerts();
        initSignupPlanToggle();
        initLeadStatusConfirm();
        initPasswordStrength();
        initThemeToggle();
        initUserDropdown();
        initOfpCustomSelects();
        initAjaxLeads();

        // Convert any existing page flash alert to modern toast if present
        var legacySuccess = document.querySelector('.ofp-alert-success');
        if (legacySuccess && legacySuccess.textContent.trim()) {
            window.OFP.toast(legacySuccess.textContent.trim(), 'success');
            legacySuccess.style.display = 'none';
        }
        var legacyError = document.querySelector('.ofp-alert-error, .ofp-alert-danger');
        if (legacyError && legacyError.textContent.trim()) {
            window.OFP.toast(legacyError.textContent.trim(), 'error');
            legacyError.style.display = 'none';
        }
    });

}());

