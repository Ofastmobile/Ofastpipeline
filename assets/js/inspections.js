/**
 * OFast Pipeline - Property Inspections Manager (Table, AJAX Filtering & Slide-Over Drawer)
 */

var currentDrawerInquiry = null;

function ofpEscapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Open Slide-Over Drawer from a table row or action button
 */
function ofpOpenInspectionDrawer(el, event) {
    if (event) {
        // If the user clicked an interactive child element (like direct WhatsApp / Call link or property link), don't trigger drawer
        var target = event.target;
        if (target.closest('a') || target.closest('button.ofp-action-icon-btn') || target.closest('select') || target.closest('input')) {
            return;
        }
    }

    var row = el.closest('.ofp-inspection-row') || el;
    if (!row) return;

    var rawData = row.getAttribute('data-inquiry');
    if (!rawData) return;

    try {
        var data = JSON.parse(rawData);
        currentDrawerInquiry = data;

        var drawer  = document.getElementById('ofpInspectionDrawer');
        var overlay = document.getElementById('ofpInspectionDrawerOverlay');
        if (!drawer || !overlay) return;

        // Populate Drawer Header
        var idEl = document.getElementById('ofpDrawerInquiryId');
        if (idEl) idEl.textContent = '#' + data.id;

        var statusBadge = document.getElementById('ofpDrawerStatusBadge');
        if (statusBadge) {
            statusBadge.className = 'ofp-status-pill ofp-status-' + data.status;
            statusBadge.textContent = data.status_label || data.status;
        }

        // Prospect Info
        var avatarEl = document.getElementById('ofpDrawerAvatar');
        if (avatarEl) {
            var initial = (data.lead_name || 'P').trim().charAt(0).toUpperCase();
            avatarEl.textContent = initial || 'P';
        }

        var nameEl = document.getElementById('ofpDrawerLeadName');
        if (nameEl) nameEl.textContent = data.lead_name || 'Prospect';

        var phoneEl = document.getElementById('ofpDrawerLeadPhone');
        if (phoneEl) {
            phoneEl.textContent = data.lead_phone || 'No phone provided';
            phoneEl.href = data.lead_phone ? 'tel:' + data.lead_phone : '#';
        }

        var emailEl = document.getElementById('ofpDrawerLeadEmail');
        var emailWrap = document.getElementById('ofpDrawerLeadEmailWrap');
        if (emailEl && emailWrap) {
            if (data.lead_email) {
                emailEl.textContent = data.lead_email;
                emailEl.href = 'mailto:' + data.lead_email;
                emailWrap.style.display = 'inline-flex';
            } else {
                emailWrap.style.display = 'none';
            }
        }

        // Communication Buttons
        var waBtn = document.getElementById('ofpDrawerWaBtn');
        if (waBtn) {
            if (data.wa_link) {
                waBtn.href = data.wa_link;
                waBtn.style.display = 'inline-flex';
            } else {
                waBtn.style.display = 'none';
            }
        }

        var callBtn = document.getElementById('ofpDrawerCallBtn');
        if (callBtn) {
            if (data.lead_phone) {
                callBtn.href = 'tel:' + data.lead_phone;
                callBtn.style.display = 'inline-flex';
            } else {
                callBtn.style.display = 'none';
            }
        }

        // Property & Schedule Info
        var propTitleEl = document.getElementById('ofpDrawerPropTitle');
        if (propTitleEl) {
            propTitleEl.textContent = data.property_title || 'Property Listing';
            propTitleEl.href = data.property_url || '#';
        }

        var propLocEl = document.getElementById('ofpDrawerPropLocation');
        if (propLocEl) {
            if (data.property_location) {
                propLocEl.textContent = data.property_location;
                propLocEl.style.display = 'block';
            } else {
                propLocEl.style.display = 'none';
            }
        }

        var tourTypeEl = document.getElementById('ofpDrawerTourType');
        if (tourTypeEl) {
            if (data.is_video) {
                tourTypeEl.className = 'ofp-tour-badge ofp-tour-video';
                tourTypeEl.innerHTML = '<svg style="width:14px;height:14px;" fill="currentColor" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> WhatsApp Video Call';
            } else {
                tourTypeEl.className = 'ofp-tour-badge ofp-tour-physical';
                tourTypeEl.innerHTML = '<svg style="width:14px;height:14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6m6 0l-3-3m3 3l-3 3"/></svg> Physical Showing';
            }
        }

        var schedDateEl = document.getElementById('ofpDrawerScheduleDate');
        if (schedDateEl) {
            var schedText = data.preferred_date || 'Date not specified';
            if (data.preferred_time) {
                schedText += ' at ' + data.preferred_time;
            }
            schedDateEl.textContent = schedText;
        }

        var bookedEl = document.getElementById('ofpDrawerBookedDate');
        if (bookedEl) {
            bookedEl.textContent = 'Requested ' + (data.created_at_formatted || 'recently');
        }

        // Message Sent Section
        var msgEl = document.getElementById('ofpDrawerMessageText');
        if (msgEl) {
            if (data.message && data.message.trim().length > 0) {
                msgEl.textContent = data.message;
                msgEl.style.fontStyle = 'normal';
                msgEl.style.opacity = '1';
            } else {
                msgEl.textContent = 'No additional specifications or special instructions provided by prospect.';
                msgEl.style.fontStyle = 'italic';
                msgEl.style.opacity = '0.7';
            }
        }

        // Update Status Form inside Drawer
        var statusSelect = document.getElementById('ofpDrawerStatusSelect');
        if (statusSelect) {
            statusSelect.value = data.status || 'pending';
        }

        var statusLabels = {
            'pending': 'Pending Confirmation',
            'confirmed': 'Confirmed',
            'completed': 'Completed',
            'cancelled': 'Cancelled'
        };
        var dropupLabel = document.getElementById('ofpDropupLabel');
        if (dropupLabel) {
            dropupLabel.textContent = data.status_label || (statusLabels[data.status] || data.status);
        }

        var dropupMenu = document.getElementById('ofpDropupMenu');
        if (dropupMenu) {
            dropupMenu.classList.remove('active');
            var options = dropupMenu.querySelectorAll('.ofp-dropup-option');
            options.forEach(function(opt) {
                opt.classList.toggle('selected', opt.getAttribute('data-value') === (data.status || 'pending'));
            });
        }

        var inquiryIdInput = document.getElementById('ofpDrawerInquiryIdInput');
        if (inquiryIdInput) {
            inquiryIdInput.value = data.id;
        }

        var feedbackEl = document.getElementById('ofpDrawerStatusFeedback');
        if (feedbackEl) {
            feedbackEl.textContent = '';
            feedbackEl.style.display = 'none';
        }

        // Open Drawer
        overlay.classList.add('active');
        drawer.classList.add('active');
        document.body.classList.add('ofp-drawer-open');
    } catch (e) {
        console.error('Error opening inspection drawer:', e);
    }
}

/**
 * Toggle Status Dropup Menu
 */
function ofpToggleStatusDropup(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    var menu = document.getElementById('ofpDropupMenu');
    if (menu) menu.classList.toggle('active');
}

/**
 * Select Status from Dropup Menu
 */
function ofpSelectDropupStatus(val, label) {
    var statusSelect = document.getElementById('ofpDrawerStatusSelect');
    if (statusSelect) statusSelect.value = val;
    var dropupLabel = document.getElementById('ofpDropupLabel');
    if (dropupLabel) dropupLabel.textContent = label;
    var menu = document.getElementById('ofpDropupMenu');
    if (menu) {
        menu.classList.remove('active');
        var options = menu.querySelectorAll('.ofp-dropup-option');
        options.forEach(function(opt) {
            opt.classList.toggle('selected', opt.getAttribute('data-value') === val);
        });
    }
}

/**
 * Close Slide-Over Drawer
 */
function ofpCloseInspectionDrawer() {
    var drawer  = document.getElementById('ofpInspectionDrawer');
    var overlay = document.getElementById('ofpInspectionDrawerOverlay');
    var menu    = document.getElementById('ofpDropupMenu');
    if (menu) menu.classList.remove('active');
    if (drawer) drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.classList.remove('ofp-drawer-open');
}

/**
 * Handle Status Update submitted from the Drawer Form
 */
function handleDrawerStatusSubmit(e) {
    if (e) e.preventDefault();
    if (!currentDrawerInquiry) return false;

    var inquiryId    = currentDrawerInquiry.id;
    var statusSelect = document.getElementById('ofpDrawerStatusSelect');
    var newStatus    = statusSelect ? statusSelect.value : '';
    var saveBtn      = document.getElementById('ofpDrawerSaveStatusBtn');
    var feedbackEl   = document.getElementById('ofpDrawerStatusFeedback');
    var nonceEl      = document.querySelector('[name="ofp_inspection_nonce"]');
    var nonceVal     = nonceEl ? nonceEl.value : ((window.ofpClientData && window.ofpClientData.nonce) ? window.ofpClientData.nonce : '');

    if (!newStatus) return false;

    var originalBtnText = saveBtn ? saveBtn.textContent : 'Save';
    if (saveBtn) {
        saveBtn.textContent = 'Updating...';
        saveBtn.disabled = true;
    }

    var bodyData = new URLSearchParams();
    bodyData.append('action', 'ofp_update_inspection_status');
    bodyData.append('nonce', nonceVal);
    bodyData.append('inquiry_id', inquiryId);
    bodyData.append('status', newStatus);

    var ajaxUrl = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';

    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: bodyData.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (saveBtn) {
            saveBtn.textContent = originalBtnText;
            saveBtn.disabled = false;
        }

        var statusLabels = {
            'pending': 'Pending Confirmation',
            'confirmed': 'Confirmed',
            'completed': 'Completed',
            'cancelled': 'Cancelled'
        };
        var label = statusLabels[newStatus] || newStatus;

        if (data.success) {
            // 1. Update Drawer Status Badge
            var drawerBadge = document.getElementById('ofpDrawerStatusBadge');
            if (drawerBadge) {
                drawerBadge.className = 'ofp-status-pill ofp-status-' + newStatus;
                drawerBadge.textContent = label;
            }

            // 2. Update Table Row
            var row = document.getElementById('inspection-row-' + inquiryId);
            if (row) {
                row.setAttribute('data-status', newStatus);
                var rowPill = document.getElementById('status-pill-' + inquiryId);
                if (rowPill) {
                    rowPill.className = 'ofp-status-pill ofp-status-' + newStatus;
                    rowPill.textContent = label;
                }

                // Update row's cached JSON payload
                var rawPayload = row.getAttribute('data-inquiry');
                if (rawPayload) {
                    try {
                        var parsed = JSON.parse(rawPayload);
                        parsed.status = newStatus;
                        parsed.status_label = label;
                        row.setAttribute('data-inquiry', JSON.stringify(parsed));
                        currentDrawerInquiry = parsed;
                    } catch(err){}
                }
            }

            // 3. Show feedback in drawer
            if (feedbackEl) {
                feedbackEl.textContent = 'Status updated to ' + label;
                feedbackEl.style.color = '#10b981';
                feedbackEl.style.display = 'block';
                setTimeout(function() {
                    feedbackEl.style.display = 'none';
                }, 3000);
            }

            // 4. Toast notification
            if (window.OFP && typeof window.OFP.toast === 'function') {
                window.OFP.toast('Inspection status updated to ' + label, 'success');
            }

            // 5. Re-run filters to update counts & active tab visibility
            if (window.ofpFilterInspections) {
                window.ofpFilterInspections();
            }
        } else {
            var errMsg = (data.data && data.data.message) ? data.data.message : 'Failed to update inspection status.';
            if (feedbackEl) {
                feedbackEl.textContent = errMsg;
                feedbackEl.style.color = '#ef4444';
                feedbackEl.style.display = 'block';
            }
            if (window.OFP && typeof window.OFP.toast === 'function') {
                window.OFP.toast(errMsg, 'error');
            }
        }
    })
    .catch(function(err) {
        if (saveBtn) {
            saveBtn.textContent = originalBtnText;
            saveBtn.disabled = false;
        }
        if (feedbackEl) {
            feedbackEl.textContent = 'Network error. Please try again.';
            feedbackEl.style.color = '#ef4444';
            feedbackEl.style.display = 'block';
        }
    });

    return false;
}

// ── Interactive AJAX Tab & Filter Engine ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('ofp-inspection-search-input');
    var tourSelect  = document.getElementById('ofp-inspection-tour-select');
    var propSelect  = document.getElementById('ofp-inspection-prop-select');
    var clearBtn    = document.getElementById('ofp-inspection-clear-btn');
    var tabButtons  = document.querySelectorAll('.ofp-inspection-tab, .ofp-leads-pill');
    var rows        = document.querySelectorAll('.ofp-inspection-row');
    var countBadge  = document.getElementById('ofp-inspection-visible-count');
    var emptyNotice = document.getElementById('ofp-inspection-empty-notice');

    var activeStatus = '';

    // Initialize active status from active tab button or URL param
    var activeBtn = document.querySelector('.ofp-leads-pill.active, .ofp-inspection-tab.active');
    if (activeBtn) {
        activeStatus = activeBtn.getAttribute('data-status') || '';
    }

    function filterInspections() {
        var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var tour  = tourSelect ? tourSelect.value : '';
        var prop  = propSelect ? propSelect.value : '';

        var visibleCount = 0;
        var counts = { all: 0, pending: 0, confirmed: 0, completed: 0, cancelled: 0 };

        rows.forEach(function(row) {
            var rowStatus = row.getAttribute('data-status') || '';
            var rowTour   = row.getAttribute('data-tour') || '';
            var rowProp   = row.getAttribute('data-property') || '';
            var rowText   = row.getAttribute('data-search') || '';

            // Count for badge stats
            counts.all++;
            if (counts[rowStatus] !== undefined) {
                counts[rowStatus]++;
            }

            // Status match
            var statusMatch = !activeStatus || (rowStatus === activeStatus);

            // Tour match (normalize whatsapp_video and video)
            var tourMatch = true;
            if (tour) {
                if (tour === 'whatsapp_video') {
                    tourMatch = (rowTour === 'whatsapp_video' || rowTour === 'video');
                } else {
                    tourMatch = (rowTour === tour);
                }
            }

            // Property match
            var propMatch = !prop || (rowProp === prop);

            // Search match
            var searchMatch = !query || rowText.indexOf(query) !== -1;

            if (statusMatch && tourMatch && propMatch && searchMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (countBadge) {
            countBadge.textContent = visibleCount;
        }

        // Toggle empty state notice
        if (emptyNotice) {
            emptyNotice.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
        }

        // Toggle clear button
        if (clearBtn) {
            var hasFilters = Boolean(query || tour || prop || activeStatus);
            clearBtn.style.display = hasFilters ? 'inline-flex' : 'none';
        }

        // Update mobile active filter badge count
        var filterBadge = document.getElementById('ofp-filter-count-badge');
        if (filterBadge) {
            var activeFilterCount = 0;
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
        var allBadge = document.getElementById('tab-cnt-all');
        if (allBadge) allBadge.textContent = counts.all;
        ['pending', 'confirmed', 'completed', 'cancelled'].forEach(function(st) {
            var b = document.getElementById('tab-cnt-' + st);
            if (b) b.textContent = counts[st] || 0;
        });
    }

    // Mobile filter toggle (hide/unhide filter box)
    var filterToggleBtn = document.getElementById('ofp-inspection-filter-toggle');
    var filterBox       = document.getElementById('ofp-inspection-filters-box');
    if (filterToggleBtn && filterBox) {
        filterToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var isExpanded = filterBox.classList.toggle('ofp-filters-expanded');
            filterToggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            filterToggleBtn.classList.toggle('active', isExpanded);
        });
    }

    window.ofpFilterInspections = filterInspections;

    // Tab buttons click (AJAX tab switch)
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            tabButtons.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeStatus = this.getAttribute('data-status') || '';

            // Update URL query string with pushState without page reload
            if (window.history && window.history.pushState) {
                var url = new URL(window.location.href);
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
    var searchTimer = null;
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
            tabButtons.forEach(function(b) {
                if (!b.getAttribute('data-status')) b.classList.add('active');
                else b.classList.remove('active');
            });
            if (window.history && window.history.pushState) {
                var url = new URL(window.location.href);
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
        var params = new URLSearchParams(window.location.search);
        activeStatus = params.get('status') || '';
        tabButtons.forEach(function(b) {
            if (b.getAttribute('data-status') === activeStatus) b.classList.add('active');
            else b.classList.remove('active');
        });
        filterInspections();
    });

    // Close dropup menu when clicking outside
    document.addEventListener('click', function(e) {
        var container = document.getElementById('ofpDropupContainer');
        if (container && !container.contains(e.target)) {
            var menu = document.getElementById('ofpDropupMenu');
            if (menu) menu.classList.remove('active');
        }
    });

    // Close drawer when pressing Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            ofpCloseInspectionDrawer();
        }
    });

    // Run initial filter on load
    filterInspections();
});
