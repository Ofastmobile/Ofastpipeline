/**
 * OFast Pipeline — Communications AJAX Log & Interactive Filters
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var form                = document.getElementById('comm-filter-form');
        var feedContainer       = document.getElementById('comm-feed-container');
        var paginationContainer = document.getElementById('comm-pagination-container');
        var countBadge          = document.getElementById('comm-count-badge');
        var clearBtn            = document.getElementById('comm-clear-btn');
        var searchInput         = document.getElementById('comm-search-input');
        var typeSelect          = document.getElementById('comm-type-select');
        var statusSelect        = document.getElementById('comm-status-select');

        if (!feedContainer) return;

        var searchTimer = null;
        var initialPage = feedContainer.getAttribute('data-current-page') || '1';
        var currentPage = parseInt(initialPage, 10) || 1;
        var ajaxUrl     = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';
        var nonce       = (window.ofpClientData && window.ofpClientData.nonce) ? window.ofpClientData.nonce : '';

        function fetchCommunications(page) {
            if (page !== undefined && page !== null) {
                currentPage = page;
            }

            var type   = typeSelect ? typeSelect.value : '';
            var status = statusSelect ? statusSelect.value : '';
            var s      = searchInput ? searchInput.value.trim() : '';

            // Visual loading state
            feedContainer.style.opacity = '0.4';
            feedContainer.style.pointerEvents = 'none';

            var formData = new FormData();
            formData.append('action', 'ofp_fetch_communications');
            formData.append('nonce', nonce);
            formData.append('type', type);
            formData.append('status', status);
            formData.append('s', s);
            formData.append('paged', currentPage);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                feedContainer.style.opacity = '1';
                feedContainer.style.pointerEvents = 'auto';

                if (res && res.success && res.data) {
                    feedContainer.innerHTML = res.data.html;
                    feedContainer.setAttribute('data-current-page', currentPage);
                    if (paginationContainer) {
                        paginationContainer.innerHTML = res.data.pagination;
                    }
                    if (countBadge) {
                        countBadge.innerHTML = res.data.count_text;
                    }
                    if (clearBtn) {
                        clearBtn.style.display = res.data.has_filter ? 'inline-flex' : 'none';
                    }

                    // Smooth URL update without browser reload
                    var url = new URL(window.location.href);
                    var currentTab = url.searchParams.get('tab');
                    if (currentTab) url.searchParams.set('tab', currentTab);
                    if (type) url.searchParams.set('type', type); else url.searchParams.delete('type');
                    if (status) url.searchParams.set('status', status); else url.searchParams.delete('status');
                    if (s) url.searchParams.set('s', s); else url.searchParams.delete('s');
                    if (currentPage > 1) url.searchParams.set('paged', currentPage); else url.searchParams.delete('paged');
                    window.history.replaceState({}, '', url.toString());
                }
            })
            .catch(function(err) {
                feedContainer.style.opacity = '1';
                feedContainer.style.pointerEvents = 'auto';
                console.error('AJAX communications fetch error:', err);
            });
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', function() {
                fetchCommunications(1);
            });
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                fetchCommunications(1);
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() {
                    fetchCommunications(1);
                }, 350);
            });
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(searchTimer);
                    fetchCommunications(1);
                }
            });
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                clearTimeout(searchTimer);
                fetchCommunications(1);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (searchInput) searchInput.value = '';
                if (typeSelect) typeSelect.value = '';
                if (statusSelect) statusSelect.value = '';
                fetchCommunications(1);
            });
        }

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.ofp-comm-page-btn');
            if (btn) {
                e.preventDefault();
                var page = parseInt(btn.getAttribute('data-page'), 10);
                if (page && page !== currentPage) {
                    fetchCommunications(page);
                    feedContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });

        // Dynamically position custom dropdown options with fixed coordinates and max z-index so options never clip
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.ofp-comm-filter-bar .ofp-custom-select-trigger');
            if (trigger) {
                var wrapper = trigger.closest('.ofp-custom-select-wrapper');
                if (wrapper) {
                    setTimeout(function() {
                        var options = wrapper.querySelector('.ofp-custom-select-options');
                        if (options && wrapper.classList.contains('open')) {
                            var rect = trigger.getBoundingClientRect();
                            options.style.position = 'fixed';
                            options.style.top = (rect.bottom + 4) + 'px';
                            options.style.left = rect.left + 'px';
                            options.style.minWidth = Math.max(rect.width, 160) + 'px';
                            options.style.zIndex = '999999';
                            options.style.display = 'block';
                            options.style.opacity = '1';
                            options.style.visibility = 'visible';
                        }
                    }, 10);
                }
            }
        });

        window.addEventListener('scroll', function() {
            document.querySelectorAll('.ofp-comm-filter-bar .ofp-custom-select-wrapper.open').forEach(function(el) {
                el.classList.remove('open');
            });
        }, { passive: true });
    });
})();
