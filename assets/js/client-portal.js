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
        var toggle      = document.getElementById('ofp-sidebar-toggle');
        var collapseBtn = document.getElementById('ofp-sidebar-collapse-btn');
        var railToggle  = document.getElementById('ofp-sidebar-rail-toggle');
        var closeBtn    = document.getElementById('ofp-sidebar-close-btn');
        var backdrop    = document.getElementById('ofp-sidebar-backdrop');
        var body        = document.body;

        var DESKTOP_BREAKPOINT = 1025;
        var STORAGE_KEY = 'ofp_sidebar_closed';

        function isDesktop() {
            return window.innerWidth >= DESKTOP_BREAKPOINT;
        }

        // Apply saved desktop preference on load
        try {
            if ( isDesktop() && window.localStorage && window.localStorage.getItem(STORAGE_KEY) === '1' ) {
                body.classList.add('ofp-sidebar-closed');
                if ( toggle ) toggle.setAttribute('aria-expanded', 'false');
            }
        } catch (e) {}

        function handleToggle(e) {
            if ( e ) {
                e.preventDefault();
                e.stopPropagation();
            }
            if ( isDesktop() ) {
                var nowClosed = body.classList.toggle('ofp-sidebar-closed');
                try {
                    window.localStorage.setItem(STORAGE_KEY, nowClosed ? '1' : '0');
                } catch (err) {}
                if ( toggle ) toggle.setAttribute('aria-expanded', nowClosed ? 'false' : 'true');
            } else {
                var nowOpen = body.classList.toggle('ofp-sidebar-open');
                if ( toggle ) toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            }

            // Dispatch resize so Chart.js canvases smoothly recalculate without shrinking columns
            var triggerResize = function() {
                try {
                    window.dispatchEvent(new Event('resize'));
                    if (window.ofpCashFlowChart && typeof window.ofpCashFlowChart.resize === 'function') {
                        window.ofpCashFlowChart.resize();
                    }
                } catch(err) {}
            };
            triggerResize();
            setTimeout(triggerResize, 80);
            setTimeout(triggerResize, 280);
            setTimeout(triggerResize, 350);
        }

        window.ofpToggleSidebar = handleToggle;

        if ( collapseBtn && !collapseBtn._ofpBound ) {
            collapseBtn._ofpBound = true;
            collapseBtn.addEventListener('click', handleToggle);
        }

        if ( railToggle && !railToggle._ofpBound ) {
            railToggle._ofpBound = true;
            railToggle.addEventListener('click', handleToggle);
        }

        if ( closeBtn && !closeBtn._ofpBound ) {
            closeBtn._ofpBound = true;
            closeBtn.addEventListener('click', function(e) {
                if ( e ) e.preventDefault();
                body.classList.remove('ofp-sidebar-open');
                if ( toggle ) toggle.setAttribute('aria-expanded', 'false');
            });
        }

        if ( toggle && !toggle._ofpBound ) {
            toggle._ofpBound = true;
            toggle.addEventListener('click', handleToggle);
        }

        var brandLink = sidebar ? sidebar.querySelector('.ofp-nav-brand') : null;
        if ( brandLink && !brandLink._ofpBound ) {
            brandLink._ofpBound = true;
            brandLink.addEventListener('click', function(e) {
                if ( isDesktop() && body.classList.contains('ofp-sidebar-closed') ) {
                    e.preventDefault();
                    handleToggle();
                }
            });
        }

        // Tapping the backdrop closes the sidebar on mobile.
        if ( backdrop && !backdrop._ofpBound ) {
            backdrop._ofpBound = true;
            var dismissSidebar = function (e) {
                if ( e ) e.preventDefault();
                body.classList.remove('ofp-sidebar-open');
                if ( toggle ) toggle.setAttribute('aria-expanded', 'false');
            };
            backdrop.addEventListener('click', dismissSidebar);
            backdrop.addEventListener('touchend', dismissSidebar);
        }

        // Closing the mobile sidebar automatically when resizing up to desktop,
        // so it doesn't stay stuck open as an overlay at a wider viewport.
        if ( !window._ofpResizeBound ) {
            window._ofpResizeBound = true;
            window.addEventListener('resize', function () {
                if ( isDesktop() ) {
                    body.classList.remove('ofp-sidebar-open');
                }
            });
        }
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
                var self = this;
                var newStatus = self.value;
                if ( newStatus === 'converted' ) {
                    if ( ! window.confirm('Mark this lead as converted? This will cancel any remaining automated follow-ups.') ) {
                        for ( var i = 0; i < self.options.length; i++ ) {
                            if ( self.options[i].defaultSelected ) {
                                self.value = self.options[i].value;
                                break;
                            }
                        }
                        return;
                    }
                }

                var form = self.closest('form');
                if (!form) return;

                var row = self.closest('tr');
                var statusCell = row ? row.querySelector('.ofp-lead-status-cell') : null;
                self.disabled = true;

                var formData = new FormData(form);
                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    self.disabled = false;
                    if (res && res.success) {
                        if (row) {
                            row.setAttribute('data-status', newStatus);
                        }
                        if (statusCell && res.data && res.data.badge_html) {
                            statusCell.innerHTML = res.data.badge_html;
                        }
                        if (newStatus === 'converted' && form.parentNode) {
                            form.parentNode.innerHTML = '<span style="font-size:12px;color:#9ca3af;">Closed</span>';
                        }
                        if (window.OFP && typeof window.OFP.toast === 'function') {
                            window.OFP.toast((res.data && res.data.message) ? res.data.message : ('Lead status updated to ' + newStatus), 'success');
                        }
                    } else {
                        if (window.OFP && typeof window.OFP.toast === 'function') {
                            window.OFP.toast((res && res.data) ? res.data : 'Failed to update status', 'error');
                        }
                    }
                })
                .catch(function(err) {
                    self.disabled = false;
                    form.submit();
                });
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

        var touchMoved = false;
        btn.addEventListener('touchstart', function () {
            touchMoved = false;
        }, { passive: true });

        btn.addEventListener('touchmove', function () {
            touchMoved = true;
        }, { passive: true });

        btn.addEventListener('touchend', function (e) {
            if ( ! touchMoved ) {
                e.preventDefault();
                togglePortalTheme();
            }
        });

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            togglePortalTheme();
        });
    }

    // ── User & Notification Dropdown Toggle ─────────────────────────────────
    function initUserDropdown() {
        var userMenu         = document.getElementById('ofp-user-menu');
        var avatar           = document.getElementById('ofp-user-avatar');
        var notifMenu        = document.getElementById('ofp-notif-menu');
        var notifTrigger     = document.getElementById('ofp-notif-trigger');
        var topbarUserMenu   = document.getElementById('ofp-topbar-profile-menu');
        var topbarAvatar     = document.getElementById('ofp-topbar-user-avatar');

        if ( avatar && userMenu ) {
            avatar.addEventListener('click', function (e) {
                e.stopPropagation();
                if ( notifMenu ) notifMenu.classList.remove('active');
                if ( topbarUserMenu ) topbarUserMenu.classList.remove('active');
                userMenu.classList.toggle('active');
            });
        }

        if ( topbarAvatar && topbarUserMenu ) {
            topbarAvatar.addEventListener('click', function (e) {
                e.stopPropagation();
                if ( notifMenu ) notifMenu.classList.remove('active');
                if ( userMenu ) userMenu.classList.remove('active');
                topbarUserMenu.classList.toggle('active');
            });
        }

        if ( notifTrigger && notifMenu ) {
            notifTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                if ( userMenu ) userMenu.classList.remove('active');
                if ( topbarUserMenu ) topbarUserMenu.classList.remove('active');
                notifMenu.classList.toggle('active');
            });
        }

        var dismissMenus = function (e) {
            if ( userMenu && ! userMenu.contains(e.target) ) {
                userMenu.classList.remove('active');
            }
            if ( topbarUserMenu && ! topbarUserMenu.contains(e.target) ) {
                topbarUserMenu.classList.remove('active');
            }
            if ( notifMenu && ! notifMenu.contains(e.target) ) {
                notifMenu.classList.remove('active');
            }
        };

        document.addEventListener('click', dismissMenus);
        document.addEventListener('touchend', function(e) {
            // Dismiss if tap outside
            if ( (userMenu && userMenu.classList.contains('active') && !userMenu.contains(e.target)) ||
                 (topbarUserMenu && topbarUserMenu.classList.contains('active') && !topbarUserMenu.contains(e.target)) ||
                 (notifMenu && notifMenu.classList.contains('active') && !notifMenu.contains(e.target)) ) {
                dismissMenus(e);
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

    // ── Global OFP Namespace: Platform Frosted Glass Preloader & Modern Toasts ──
    var progressTrackEl = null;
    var progressEl = null;
    var glassIslandEl = null;
    var progressTimer = null;
    var progressVal = 0;

    function getPreloaderElements() {
        var target = document.body || document.documentElement;
        if (!progressTrackEl) {
            progressTrackEl = document.getElementById('ofp-top-progress-track');
            if (!progressTrackEl && target) {
                progressTrackEl = document.createElement('div');
                progressTrackEl.id = 'ofp-top-progress-track';
                target.appendChild(progressTrackEl);
            }
        }
        if (!progressEl) {
            progressEl = document.getElementById('ofp-top-progress');
            if (!progressEl && progressTrackEl) {
                progressEl = document.createElement('div');
                progressEl.id = 'ofp-top-progress';
                progressTrackEl.appendChild(progressEl);
            }
        }
        if (!glassIslandEl) {
            glassIslandEl = document.getElementById('ofp-glass-preloader');
            if (!glassIslandEl && target) {
                glassIslandEl = document.createElement('div');
                glassIslandEl.id = 'ofp-glass-preloader';
                glassIslandEl.innerHTML = '<div class="ofp-glass-spinner"></div><span class="ofp-glass-preloader-text">Loading...</span>';
                target.appendChild(glassIslandEl);
            }
        }
        return { track: progressTrackEl, bar: progressEl, island: glassIslandEl };
    }

    var ProgressEngine = {
        start: function (label) {
            var els = getPreloaderElements();
            if (!els.track || !els.bar || !els.island) return;
            clearInterval(progressTimer);

            if (!progressVal || progressVal < 25) {
                progressVal = 25;
            }

            els.track.classList.add('is-active');
            els.island.classList.add('is-active');
            els.bar.style.width = progressVal + '%';

            var textEl = els.island.querySelector('.ofp-glass-preloader-text');
            if (textEl && label) {
                textEl.textContent = label;
            }

            progressTimer = setInterval(function () {
                if (progressVal < 88) {
                    progressVal += Math.random() * 6 + 3;
                    if (progressVal > 88) progressVal = 88;
                    els.bar.style.width = progressVal + '%';
                }
            }, 180);
        },
        set: function (pct) {
            var els = getPreloaderElements();
            if (!els.track || !els.bar || !els.island) return;
            els.track.classList.add('is-active');
            els.island.classList.add('is-active');
            progressVal = Math.min(100, Math.max(0, pct));
            els.bar.style.width = progressVal + '%';
        },
        done: function () {
            var els = getPreloaderElements();
            if (!els.track || !els.bar || !els.island) return;
            clearInterval(progressTimer);
            progressVal = 100;
            els.bar.style.transition = 'width 0.12s ease-out';
            els.bar.style.width = '100%';

            setTimeout(function () {
                els.track.classList.remove('is-active');
                els.island.classList.remove('is-active');
                setTimeout(function () {
                    progressVal = 0;
                    els.bar.style.width = '0%';
                }, 120);
            }, 80);
        }
    };

    var ToastEngine = {
        container: null,
        getContainer: function () {
            if (!this.container) {
                this.container = document.querySelector('.ofp-toast-container');
                if (!this.container) {
                    var target = document.body || document.documentElement;
                    this.container = document.createElement('div');
                    this.container.className = 'ofp-toast-container';
                    if (target) target.appendChild(this.container);
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

    // Universal AJAX save helper for portal forms (in-place save + toast, neglecting full reload)
    window.OFP.ajaxSave = function (form, options) {
        options = options || {};
        if (typeof form === 'string') form = document.querySelector(form);
        if (!form) return Promise.reject(new Error('Form not found'));

        var submitBtn = form.querySelector('button[type="submit"]') || form.querySelector('input[type="submit"]');
        var origText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            if (options.loadingText) submitBtn.textContent = options.loadingText;
        }

        var formData = new FormData(form);
        if (options.extraData) {
            Object.keys(options.extraData).forEach(function (k) {
                formData.append(k, options.extraData[k]);
            });
        }

        var url = options.url || form.action || window.location.href;

        return fetch(url, {
            method: form.method || 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) {
            var ct = res.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return res.json();
            }
            return res.text().then(function (t) { return { success: res.ok, data: t }; });
        })
        .then(function (data) {
            if (data && data.success) {
                var msg = (data.data && data.data.message) || data.message || options.successMessage || 'Saved successfully';
                window.OFP.toast(msg, 'success');
                if (typeof options.onSuccess === 'function') options.onSuccess(data);
            } else {
                var errMsg = (data && data.data) || data.message || options.errorMessage || 'Failed to save';
                window.OFP.toast(errMsg, 'error');
                if (typeof options.onError === 'function') options.onError(data);
            }
            return data;
        })
        .catch(function (err) {
            window.OFP.toast(options.networkError || 'Network error. Please try again.', 'error');
            if (typeof options.onError === 'function') options.onError(err);
            throw err;
        })
        .finally(function () {
            if (submitBtn) {
                submitBtn.disabled = false;
                if (origText) submitBtn.textContent = origText;
            }
        });
    };

    // Alert Toast Bridge: seamlessly upgrades any standard alert() into modern floating toasts
    var origAlert = window.alert;
    window.alert = function (msg) {
        if (window.OFP && typeof window.OFP.toast === 'function') {
            var str = String(msg || '');
            var isSuccess = /success|copied|saved|updated|done|confirmed|created/i.test(str);
            var isError = /error|failed|invalid|required|denied|wrong/i.test(str);
            var isWarning = /warning|caution|alert|too large|attention/i.test(str);
            var type = isSuccess ? 'success' : (isError ? 'error' : (isWarning ? 'warning' : 'info'));
            window.OFP.toast(str, type);
        } else {
            origAlert(msg);
        }
    };

    // Global fetch interceptor to drive top preloader automatically
    var origFetch = window.fetch;
    if (origFetch) {
        var activeRequests = 0;
        var fetchTimeout = null;
        window.fetch = function () {
            activeRequests++;
            ProgressEngine.start('Syncing...');

            clearTimeout(fetchTimeout);
            fetchTimeout = setTimeout(function () {
                if (activeRequests > 0) {
                    activeRequests = 0;
                    ProgressEngine.done();
                }
            }, 8000);

            return origFetch.apply(this, arguments).finally(function () {
                activeRequests--;
                if (activeRequests <= 0) {
                    activeRequests = 0;
                    clearTimeout(fetchTimeout);
                    ProgressEngine.done();
                }
            });
        };
    }

    // ── Navigation & Page Preloader Interceptors ──────────────────────────────
    function initNavigationPreloader() {
        // Intercept all menu & page link clicks across the portal
        document.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            var link = e.target.closest('a');
            if (!link) return;

            var href = link.getAttribute('href');
            if (!href) return;

            // Ignore hash anchors, javascript, tel, mailto, target="_blank", or download
            if (href.indexOf('#') === 0 || href.indexOf('javascript:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('mailto:') === 0) return;
            if (link.getAttribute('target') === '_blank' || link.hasAttribute('download')) return;

            // Ignore modal triggers or buttons styled as links
            if (link.getAttribute('role') === 'button' || link.classList.contains('ofp-modal-trigger') || link.hasAttribute('data-dismiss')) return;

            // Check if same origin or relative
            try {
                var targetUrl = new URL(link.href, window.location.href);
                if (targetUrl.origin !== window.location.origin) return;
                if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search && targetUrl.hash) return;
            } catch (err) {
                return;
            }

            // Slight deferral so if an in-page tab switcher calls e.preventDefault(), we don't trigger full navigation preloader
            setTimeout(function () {
                if (!e.defaultPrevented) {
                    var label = 'Loading...';

                    // Extract page title cleanly from link
                    var labelEl = link.querySelector('.ofp-nav-label, .ofp-dropdown-item-title, .ofp-bottom-nav-label');
                    var rawText = '';
                    if (labelEl && labelEl.textContent.trim()) {
                        rawText = labelEl.textContent.trim();
                    } else if (link.getAttribute('title') && link.getAttribute('title').trim()) {
                        rawText = link.getAttribute('title').trim();
                    } else if (link.getAttribute('aria-label') && link.getAttribute('aria-label').trim()) {
                        rawText = link.getAttribute('aria-label').trim();
                    } else if (link.getAttribute('data-slug')) {
                        var slug = link.getAttribute('data-slug').replace(/[-_]/g, ' ');
                        rawText = slug.charAt(0).toUpperCase() + slug.slice(1);
                    } else {
                        var clone = link.cloneNode(true);
                        var unwanted = clone.querySelectorAll('.ofp-nav-badge, svg, .ofp-nav-icon, .badge');
                        for (var i = 0; i < unwanted.length; i++) {
                            if (unwanted[i].parentNode) unwanted[i].parentNode.removeChild(unwanted[i]);
                        }
                        rawText = clone.textContent.trim();
                    }

                    // Clean out numbers, symbols, excessive whitespace
                    var cleanName = rawText.replace(/\s+/g, ' ').replace(/[0-9]+$/, '').trim();
                    if (cleanName && cleanName.length > 1 && cleanName.length < 35) {
                        label = cleanName + '...';
                    }

                    // Persist to sessionStorage for the incoming page
                    try {
                        sessionStorage.setItem('ofp_nav_label', label);
                    } catch (err) {}

                    ProgressEngine.start(label);
                }
            }, 30);
        });

        // Intercept standard form submissions
        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            var form = e.target;
            if (form && form.getAttribute('target') === '_blank') return;
            setTimeout(function () {
                if (!e.defaultPrevented) {
                    try {
                        sessionStorage.setItem('ofp_nav_label', 'Saving...');
                    } catch (err) {}
                    ProgressEngine.start('Saving...');
                }
            }, 30);
        });

        // Browser beforeunload hook: preserve active label, only fallback to Loading... if inactive
        window.addEventListener('beforeunload', function () {
            var els = getPreloaderElements();
            if (!els.island || !els.island.classList.contains('is-active')) {
                ProgressEngine.start('Loading...');
            }
        });

        // Browser pageshow hook (back/forward cache restoration)
        window.addEventListener('pageshow', function () {
            try { sessionStorage.removeItem('ofp_nav_label'); } catch(e) {}
            ProgressEngine.done();
        });

        // Fresh page load completion: finish initial preloader instantly once DOM is ready
        function finishInitialPageLoad() {
            ProgressEngine.done();
        }

        if (document.readyState === 'interactive' || document.readyState === 'complete') {
            finishInitialPageLoad();
        } else {
            document.addEventListener('DOMContentLoaded', finishInitialPageLoad, { once: true });
        }

        // Failsafe timer (max 350ms)
        setTimeout(finishInitialPageLoad, 350);
    }

    // ── Persistent Sidebar Navigation Scroll Restoration ────────────────────
    function initSidebarScrollRestoration() {
        var sidebarNav = document.querySelector('.ofp-sidebar-nav');
        if (!sidebarNav) return;

        var SCROLL_KEY = 'ofp_sidebar_scroll';
        var CLICKED_KEY = 'ofp_sidebar_last_clicked_slug';

        function getActiveItem() {
            var active = sidebarNav.querySelector('.ofp-nav-item.active');
            if (!active) {
                // Match current URL pathname against nav link items
                var currentPath = window.location.pathname.replace(/\/+$/, '');
                var links = sidebarNav.querySelectorAll('a.ofp-nav-item[href]');
                for (var i = 0; i < links.length; i++) {
                    try {
                        var linkUrl = new URL(links[i].href, window.location.origin);
                        var linkPath = linkUrl.pathname.replace(/\/+$/, '');
                        if (linkPath && currentPath === linkPath) {
                            active = links[i];
                            break;
                        }
                    } catch (e) {}
                }
            }
            if (!active) {
                // Fallback to last clicked slug
                var lastSlug = null;
                try { lastSlug = sessionStorage.getItem(CLICKED_KEY); } catch (e) {}
                if (lastSlug) {
                    active = sidebarNav.querySelector('a.ofp-nav-item[data-slug="' + lastSlug + '"]');
                }
            }
            return active;
        }

        function ensureActiveItemVisible(smooth) {
            var active = getActiveItem();
            if (!active) return;

            // Ensure active highlight is present
            if (!active.classList.contains('active')) {
                sidebarNav.querySelectorAll('.ofp-nav-item.active').forEach(function (el) {
                    el.classList.remove('active');
                });
                active.classList.add('active');
            }

            var navRect = sidebarNav.getBoundingClientRect();
            var actRect = active.getBoundingClientRect();

            // Check if active item is outside of the visible viewport
            var isOutside = (actRect.top < (navRect.top + 16)) || (actRect.bottom > (navRect.bottom - 16));
            if (isOutside) {
                active.scrollIntoView({
                    block: 'center',
                    inline: 'nearest',
                    behavior: smooth ? 'smooth' : 'auto'
                });
                try {
                    sessionStorage.setItem(SCROLL_KEY, sidebarNav.scrollTop);
                } catch (e) {}
            }
        }

        function restoreScroll() {
            var saved = null;
            try { saved = sessionStorage.getItem(SCROLL_KEY); } catch (e) {}
            if (saved !== null && !isNaN(parseInt(saved, 10))) {
                sidebarNav.scrollTop = parseInt(saved, 10);
            }
            ensureActiveItemVisible(false);
        }

        // Restore immediately
        restoreScroll();

        // Re-check after layout calculation
        setTimeout(function () {
            ensureActiveItemVisible(false);
        }, 80);

        // Record clicks on nav items
        sidebarNav.addEventListener('click', function (e) {
            var link = e.target.closest('a.ofp-nav-item');
            if (!link || link.classList.contains('locked')) return;

            var slug = link.getAttribute('data-slug') || '';
            try {
                sessionStorage.setItem(SCROLL_KEY, sidebarNav.scrollTop);
                if (slug) {
                    sessionStorage.setItem(CLICKED_KEY, slug);
                }
            } catch (err) {}
        });

        // Debounced manual scroll listener
        var scrollTimer = null;
        sidebarNav.addEventListener('scroll', function () {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function () {
                try {
                    sessionStorage.setItem(SCROLL_KEY, sidebarNav.scrollTop);
                } catch (e) {}
            }, 80);
        }, { passive: true });

        // Save right before unload/reload
        window.addEventListener('beforeunload', function () {
            try {
                sessionStorage.setItem(SCROLL_KEY, sidebarNav.scrollTop);
            } catch (e) {}
        });

        // Restore on pageshow (e.g. back/forward navigation)
        window.addEventListener('pageshow', function () {
            restoreScroll();
        });
    }

    // ── Init all ─────────────────────────────────────────────────────────────
    // Run preloader initialization immediately so click & unload handlers are ready without waiting
    initNavigationPreloader();

    document.addEventListener('DOMContentLoaded', function () {
        var safeInit = function (fn, name) {
            try {
                fn();
            } catch (err) {
                console.warn('OFP Portal: init ' + name + ' failed:', err);
            }
        };

        safeInit(initSidebarToggle, 'initSidebarToggle');
        safeInit(initSidebarScrollRestoration, 'initSidebarScrollRestoration');
        safeInit(initCharCounters, 'initCharCounters');
        safeInit(initFormLoadingState, 'initFormLoadingState');
        safeInit(initAutoDismissAlerts, 'initAutoDismissAlerts');
        safeInit(initSignupPlanToggle, 'initSignupPlanToggle');
        safeInit(initLeadStatusConfirm, 'initLeadStatusConfirm');
        safeInit(initPasswordStrength, 'initPasswordStrength');
        safeInit(initThemeToggle, 'initThemeToggle');
        safeInit(initUserDropdown, 'initUserDropdown');
        safeInit(initOfpCustomSelects, 'initOfpCustomSelects');
        safeInit(initAjaxLeads, 'initAjaxLeads');

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

