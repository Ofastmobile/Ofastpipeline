/**
 * OFast Pipeline — Guided Dashboard Onboarding Tour
 * Lightweight, accessible, interactive walkthrough for real estate agents.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'ofp_dashboard_tour_seen';

    var tourSteps = [
        {
            id: 'welcome',
            isModal: true,
            title: 'Welcome to Your Agency Dashboard 🚀',
            description: 'This is your central command center. Let’s take a quick 60-second guided tour to discover your financial metrics, listing tools, agency branding, and lead automations.',
            stepLabel: 'Intro',
            nextBtnText: 'Start Guided Tour →',
            skipBtnText: 'Maybe Later'
        },
        {
            id: 'kpis',
            target: '.ofp-zentra-kpi-grid',
            title: 'Live Portfolio & Financial Overview',
            description: 'Track your total asset valuation, active listings, occupancy percentage, and monthly rental income in real time. Numbers update automatically as new listings and leases are recorded.',
            stepLabel: 'Step 1 of 5',
            position: 'bottom'
        },
        {
            id: 'cashflow',
            target: '.ofp-zentra-analytics-grid',
            title: 'Cash Flow Trajectory & Performance',
            description: 'Visualize your net inflows (property sales + rent collected minus communications & SMS costs). Switch dynamically between Year, 6 Months, and 30 Days views.',
            stepLabel: 'Step 2 of 5',
            position: 'bottom'
        },
        {
            id: 'properties',
            target: '[data-slug="properties"], .ofp-hero-btn-add',
            title: 'Listings & Property Commerce',
            description: 'Publish verified properties for sale or rent, generate customized installment payment plans for buyers, issue invoices, and manage tenant agreements.',
            stepLabel: 'Step 3 of 5',
            position: 'right'
        },
        {
            id: 'profile_branding',
            target: '#ofp-topbar-profile-menu, #ofp-user-avatar',
            title: 'Branding, Bank Account & Public Profile',
            description: 'Customize your agency branding, upload your logo, link your bank account for client settlements, and share your public showcase landing page with buyers.',
            stepLabel: 'Step 4 of 5',
            position: 'bottom'
        },
        {
            id: 'leads_inspections',
            target: '[data-slug="inspections"], [data-slug="leads"]',
            title: 'Leads, WhatsApp Tours & Automations',
            description: 'Monitor prospective buyer inquiries, schedule physical or WhatsApp video call inspection tours, and keep prospective buyers engaged with automated SMS pipelines.',
            stepLabel: 'Step 5 of 5',
            position: 'right'
        },
        {
            id: 'complete',
            isModal: true,
            title: 'You’re All Set! 🎉',
            description: 'You’re ready to start closing deals. You can re-launch this tour anytime from your profile dropdown menu or the dashboard header button.',
            stepLabel: 'Done',
            nextBtnText: 'Explore Dashboard →',
            isFinish: true
        }
    ];

    var state = {
        active: false,
        currentStep: 0,
        elements: {}
    };

    function createTourElements() {
        if (state.elements.overlay) return;

        // Container
        var overlay = document.createElement('div');
        overlay.className = 'ofp-tour-overlay';
        overlay.id = 'ofp-tour-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        // Backdrop
        var backdrop = document.createElement('div');
        backdrop.className = 'ofp-tour-backdrop';
        overlay.appendChild(backdrop);

        // Target highlight
        var highlight = document.createElement('div');
        highlight.className = 'ofp-tour-highlight';
        highlight.style.display = 'none';
        overlay.appendChild(highlight);

        // Tooltip Popover Card
        var card = document.createElement('div');
        card.className = 'ofp-tour-card';
        card.innerHTML = [
            '<div class="ofp-tour-header">',
            '  <span class="ofp-tour-step-pill" id="ofp-tour-pill">Step 1 of 5</span>',
            '  <button type="button" class="ofp-tour-close-btn" id="ofp-tour-close" aria-label="Exit tour" title="Exit tour">',
            '    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"/></svg>',
            '  </button>',
            '</div>',
            '<h3 class="ofp-tour-title" id="ofp-tour-title"></h3>',
            '<p class="ofp-tour-desc" id="ofp-tour-desc"></p>',
            '<div class="ofp-tour-footer">',
            '  <div class="ofp-tour-dots" id="ofp-tour-dots"></div>',
            '  <div class="ofp-tour-actions">',
            '    <button type="button" class="ofp-tour-btn-skip" id="ofp-tour-skip">Skip</button>',
            '    <button type="button" class="ofp-tour-btn-prev" id="ofp-tour-prev">Back</button>',
            '    <button type="button" class="ofp-tour-btn-next" id="ofp-tour-next">Next →</button>',
            '  </div>',
            '</div>'
        ].join('');
        overlay.appendChild(card);

        document.body.appendChild(overlay);

        state.elements = {
            overlay: overlay,
            backdrop: backdrop,
            highlight: highlight,
            card: card,
            pill: card.querySelector('#ofp-tour-pill'),
            closeBtn: card.querySelector('#ofp-tour-close'),
            title: card.querySelector('#ofp-tour-title'),
            desc: card.querySelector('#ofp-tour-desc'),
            dots: card.querySelector('#ofp-tour-dots'),
            skipBtn: card.querySelector('#ofp-tour-skip'),
            prevBtn: card.querySelector('#ofp-tour-prev'),
            nextBtn: card.querySelector('#ofp-tour-next')
        };

        // Event listeners
        state.elements.closeBtn.addEventListener('click', endTour);
        state.elements.skipBtn.addEventListener('click', endTour);
        state.elements.prevBtn.addEventListener('click', prevStep);
        state.elements.nextBtn.addEventListener('click', nextStep);

        // Keyboard navigation
        document.addEventListener('keydown', handleKeydown);
        window.addEventListener('resize', handleResize);
    }

    function handleKeydown(e) {
        if (!state.active) return;
        if (e.key === 'Escape') {
            endTour();
        } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
            nextStep();
        } else if (e.key === 'ArrowLeft') {
            prevStep();
        }
    }

    function handleResize() {
        if (!state.active) return;
        positionStep(tourSteps[state.currentStep]);
    }

    function startTour() {
        createTourElements();
        state.active = true;
        state.currentStep = 0;
        state.elements.overlay.classList.add('is-active');
        document.body.style.overflow = 'hidden';
        renderStep(state.currentStep);
    }

    function endTour() {
        if (!state.active) return;
        state.active = false;
        state.elements.overlay.classList.remove('is-active');
        state.elements.card.classList.remove('is-visible');
        state.elements.highlight.style.display = 'none';
        document.body.style.overflow = '';

        // Save completion flag in localStorage
        try {
            localStorage.setItem(STORAGE_KEY, '1');
        } catch (e) {}

        // Persist to backend user profile if ajax data is present
        persistTourCompletion();
    }

    function persistTourCompletion() {
        if (typeof ofpClientData === 'undefined' || !ofpClientData.ajaxurl) return;
        try {
            var data = new URLSearchParams();
            data.append('action', 'ofp_save_tour_status');
            data.append('nonce', ofpClientData.nonce);
            data.append('completed', '1');
            fetch(ofpClientData.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).catch(function () {});
        } catch (e) {}
    }

    function prevStep() {
        if (state.currentStep > 0) {
            state.currentStep--;
            renderStep(state.currentStep);
        }
    }

    function nextStep() {
        if (state.currentStep < tourSteps.length - 1) {
            state.currentStep++;
            renderStep(state.currentStep);
        } else {
            endTour();
        }
    }

    function renderStep(idx) {
        var step = tourSteps[idx];
        if (!step) return;

        var el = state.elements;
        el.title.textContent = step.title;
        el.desc.textContent = step.description;
        el.pill.textContent = step.stepLabel || ('Step ' + (idx + 1));

        // Prev button visibility
        el.prevBtn.style.display = idx === 0 ? 'none' : 'inline-block';

        // Skip button
        if (step.skipBtnText) {
            el.skipBtn.textContent = step.skipBtnText;
        } else {
            el.skipBtn.textContent = 'Skip';
        }
        el.skipBtn.style.display = step.isFinish ? 'none' : 'inline-block';

        // Next button styling
        if (step.nextBtnText) {
            el.nextBtn.innerHTML = step.nextBtnText;
        } else {
            el.nextBtn.innerHTML = 'Next →';
        }

        if (step.isFinish) {
            el.nextBtn.classList.add('ofp-tour-btn-finish');
        } else {
            el.nextBtn.classList.remove('ofp-tour-btn-finish');
        }

        // Render Dots
        el.dots.innerHTML = '';
        tourSteps.forEach(function (_, i) {
            var dot = document.createElement('div');
            dot.className = 'ofp-tour-dot' + (i === idx ? ' is-active' : '');
            el.dots.appendChild(dot);
        });

        // Position Card & Highlight
        positionStep(step);
    }

    function positionStep(step) {
        var el = state.elements;
        var card = el.card;
        var highlight = el.highlight;

        if (step.isModal || !step.target) {
            card.classList.add('is-modal');
            highlight.style.display = 'none';
            card.style.top = '';
            card.style.left = '';
            card.style.right = '';
            card.style.bottom = '';
            card.classList.add('is-visible');
            return;
        }

        card.classList.remove('is-modal');

        // Locate target element
        var targetEl = document.querySelector(step.target);
        if (!targetEl) {
            // Target not found on page, fallback to modal style
            card.classList.add('is-modal');
            highlight.style.display = 'none';
            card.classList.add('is-visible');
            return;
        }

        // Scroll target into view
        targetEl.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });

        // Update target highlight box after small delay to let scroll finish
        setTimeout(function () {
            var rect = targetEl.getBoundingClientRect();
            var padding = 8;

            highlight.style.display = 'block';
            highlight.style.top = Math.max(0, rect.top - padding) + 'px';
            highlight.style.left = Math.max(0, rect.left - padding) + 'px';
            highlight.style.width = (rect.width + padding * 2) + 'px';
            highlight.style.height = (rect.height + padding * 2) + 'px';

            // Position card relative to highlight (on desktop > 768px)
            if (window.innerWidth > 768) {
                var cardW = 360;
                var cardH = card.offsetHeight || 220;
                var pos = step.position || 'bottom';

                var top = 0;
                var left = 0;

                if (pos === 'bottom') {
                    top = rect.bottom + padding + 12;
                    left = rect.left + (rect.width / 2) - (cardW / 2);
                } else if (pos === 'top') {
                    top = rect.top - cardH - padding - 12;
                    left = rect.left + (rect.width / 2) - (cardW / 2);
                } else if (pos === 'right') {
                    top = rect.top + (rect.height / 2) - (cardH / 2);
                    left = rect.right + padding + 16;
                } else if (pos === 'left') {
                    top = rect.top + (rect.height / 2) - (cardH / 2);
                    left = rect.left - cardW - padding - 16;
                }

                // Prevent overflowing viewport
                if (left < 16) left = 16;
                if (left + cardW > window.innerWidth - 16) {
                    left = window.innerWidth - cardW - 16;
                }

                if (top < 16) top = 16;
                if (top + cardH > window.innerHeight - 16) {
                    // Flip to top if bottom overflows
                    top = Math.max(16, rect.top - cardH - padding - 12);
                }

                card.style.top = top + 'px';
                card.style.left = left + 'px';
                card.style.right = 'auto';
                card.style.bottom = 'auto';
            }

            card.classList.add('is-visible');
        }, 120);
    }

    // Auto-launch on first visit if not seen yet
    function checkAutoStart() {
        try {
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tour') === '1') {
                setTimeout(startTour, 400);
                return;
            }

            var seen = localStorage.getItem(STORAGE_KEY);
            if (!seen) {
                // Wait slightly for dashboard elements to fully render
                setTimeout(function () {
                    startTour();
                }, 800);
            }
        } catch (e) {}
    }

    // Expose global function
    window.ofpStartDashboardTour = function () {
        startTour();
    };

    document.addEventListener('DOMContentLoaded', checkAutoStart);

})();

