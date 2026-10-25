/**
 * OFast Pipeline - Property Inspections Manager & AJAX Tab Switcher
 */

function handleStatusSubmit(e, inquiryId) {
    if (!window.fetch) return true;
    e.preventDefault();

    const form = e.target;
    const select = document.getElementById('status-select-' + inquiryId);
    const newStatus = select.value;
    const card = document.getElementById('inspection-card-' + inquiryId);
    const pill = document.getElementById('status-pill-' + inquiryId);
    const nonce = form.querySelector('[name="ofp_inspection_nonce"]').value;
    const button = form.querySelector('button[type="submit"]');

    const originalBtnText = button.textContent;
    button.textContent = 'Saving...';
    button.disabled = true;

    const bodyData = new URLSearchParams();
    bodyData.append('action', 'ofp_update_inspection_status');
    bodyData.append('nonce', nonce);
    bodyData.append('inquiry_id', inquiryId);
    bodyData.append('status', newStatus);

    const ajaxUrl = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';

    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: bodyData.toString()
    })
    .then(res => res.json())
    .then(data => {
        button.textContent = originalBtnText;
        button.disabled = false;
        if (data.success) {
            const statusLabels = {
                'pending': 'Pending Confirmation',
                'confirmed': 'Confirmed',
                'completed': 'Completed',
                'cancelled': 'Cancelled'
            };
            if (pill) {
                pill.className = 'ofp-status-pill ofp-status-' + newStatus;
                pill.textContent = statusLabels[newStatus] || newStatus;
            }
            if (card) {
                card.setAttribute('data-status', newStatus);
            }

            button.textContent = '✓ Saved';
            setTimeout(() => { button.textContent = originalBtnText; }, 2000);

            // Re-run filter and update badge counts
            if (window.ofpFilterInspections) {
                window.ofpFilterInspections();
            }

            if (window.OFP && typeof window.OFP.toast === 'function') {
                window.OFP.toast('Inspection status updated to ' + (statusLabels[newStatus] || newStatus), 'success');
            }
        } else {
            var errMsg = (data.data && data.data.message) ? data.data.message : 'Failed to update status.';
            if (window.OFP && typeof window.OFP.toast === 'function') {
                window.OFP.toast(errMsg, 'error');
            } else {
                alert(errMsg);
            }
        }
    })
    .catch(() => {
        button.textContent = originalBtnText;
        button.disabled = false;
        if (window.OFP && typeof window.OFP.toast === 'function') {
            window.OFP.toast('Network issue. Refreshing...', 'warning');
        }
        form.submit();
    });

    return false;
}

// ── Interactive AJAX Tab & Filter Engine ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('ofp-inspection-search-input');
    const tourSelect  = document.getElementById('ofp-inspection-tour-select');
    const propSelect  = document.getElementById('ofp-inspection-prop-select');
    const clearBtn    = document.getElementById('ofp-inspection-clear-btn');
    const tabButtons  = document.querySelectorAll('.ofp-inspection-tab');
    const cards       = document.querySelectorAll('.ofp-inspection-card');
    const countBadge  = document.getElementById('ofp-inspection-visible-count');
    const emptyNotice = document.getElementById('ofp-inspection-empty-notice');

    let activeStatus = '';

    // Initialize active status from active tab button or URL param
    const activeBtn = document.querySelector('.ofp-inspection-tab.active');
    if (activeBtn) {
        activeStatus = activeBtn.getAttribute('data-status') || '';
    }

    function filterInspections() {
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const tour = tourSelect ? tourSelect.value : '';
        const prop = propSelect ? propSelect.value : '';

        let visibleCount = 0;
        const counts = { all: 0, pending: 0, confirmed: 0, completed: 0, cancelled: 0 };

        cards.forEach(function(card) {
            const cardStatus = card.getAttribute('data-status') || '';
            const cardTour = card.getAttribute('data-tour') || '';
            const cardProp = card.getAttribute('data-property') || '';
            const cardText = card.getAttribute('data-search') || '';

            // Count for badge stats
            counts.all++;
            if (counts[cardStatus] !== undefined) {
                counts[cardStatus]++;
            }

            // Status match
            const statusMatch = !activeStatus || (cardStatus === activeStatus);

            // Tour match (normalize whatsapp_video and video)
            let tourMatch = true;
            if (tour) {
                if (tour === 'whatsapp_video') {
                    tourMatch = (cardTour === 'whatsapp_video' || cardTour === 'video');
                } else {
                    tourMatch = (cardTour === tour);
                }
            }

            // Property match
            const propMatch = !prop || (cardProp === prop);

            // Search match
            const searchMatch = !query || cardText.indexOf(query) !== -1;

            if (statusMatch && tourMatch && propMatch && searchMatch) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (countBadge) {
            countBadge.textContent = visibleCount;
        }

        // Toggle empty state notice
        if (emptyNotice) {
            emptyNotice.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
        }

        // Toggle clear button
        if (clearBtn) {
            const hasFilters = Boolean(query || tour || prop || activeStatus);
            clearBtn.style.display = hasFilters ? 'inline-flex' : 'none';
        }

        // Update mobile active filter badge count
        const filterBadge = document.getElementById('ofp-filter-count-badge');
        if (filterBadge) {
            let activeFilterCount = 0;
            if (tour) activeFilterCount++;
            if (prop) activeFilterCount++;
            if (activeFilterCount > 0) {
                filterBadge.textContent = activeFilterCount;
                filterBadge.style.display = 'inline-flex';
            } else {
                filterBadge.style.display = 'none';
            }
        }

        // Update tab badge counts
        const allBadge = document.getElementById('tab-cnt-all');
        if (allBadge) allBadge.textContent = counts.all;
        ['pending', 'confirmed', 'completed', 'cancelled'].forEach(function(st) {
            const b = document.getElementById('tab-cnt-' + st);
            if (b) b.textContent = counts[st] || 0;
        });
    }

    // Mobile filter toggle (hide/unhide filter box)
    const filterToggleBtn = document.getElementById('ofp-inspection-filter-toggle');
    const filterBox       = document.getElementById('ofp-inspection-filters-box');
    if (filterToggleBtn && filterBox) {
        filterToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isExpanded = filterBox.classList.toggle('ofp-filters-expanded');
            filterToggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            filterToggleBtn.classList.toggle('active', isExpanded);
        });
    }

    window.ofpFilterInspections = filterInspections;

    // Tab buttons click (AJAX tab switch)
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            tabButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            activeStatus = this.getAttribute('data-status') || '';

            // Update URL query string with pushState without page reload
            if (window.history && window.history.pushState) {
                const url = new URL(window.location.href);
                if (activeStatus) {
                    url.searchParams.set('status', activeStatus);
                } else {
                    url.searchParams.delete('status');
                }
                window.history.pushState({ status: activeStatus }, '', url.toString());
            }

            filterInspections();
        });
    });

    // Real-time search with debounce
    let searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(filterInspections, 150);
        });
    }

    if (tourSelect) {
        tourSelect.addEventListener('change', filterInspections);
    }

    if (propSelect) {
        propSelect.addEventListener('change', filterInspections);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (tourSelect) tourSelect.value = '';
            if (propSelect) propSelect.value = '';
            activeStatus = '';
            tabButtons.forEach(b => {
                if (!b.getAttribute('data-status')) b.classList.add('active');
                else b.classList.remove('active');
            });
            if (window.history && window.history.pushState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('status');
                url.searchParams.delete('tour_type');
                url.searchParams.delete('property_id');
                window.history.pushState({}, '', url.toString());
            }
            filterInspections();
        });
    }

    // Handle browser popstate
    window.addEventListener('popstate', function() {
        const params = new URLSearchParams(window.location.search);
        activeStatus = params.get('status') || '';
        tabButtons.forEach(b => {
            if (b.getAttribute('data-status') === activeStatus) b.classList.add('active');
            else b.classList.remove('active');
        });
        filterInspections();
    });

    // Run initial filter on load
    filterInspections();
});
