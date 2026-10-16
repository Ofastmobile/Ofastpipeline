/**
 * OFast Pipeline — Modern Admin Portal JavaScript
 * Design #2 Interactions: Theme Toggle, Search Filter, In-Portal Routing, Media Upload, Mobile Menu
 */

document.addEventListener('DOMContentLoaded', function () {
    // ── 1. Theme Toggle (Dark / Light Mode) ──────────────────────────────────
    const themeBtn = document.getElementById('ofp-theme-toggle');
    const body = document.body;
    
    // Check saved preference or system preference
    const savedTheme = localStorage.getItem('ofp_admin_theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        body.classList.add('ofp-dark-mode');
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            body.classList.toggle('ofp-dark-mode');
            const isDark = body.classList.contains('ofp-dark-mode');
            localStorage.setItem('ofp_admin_theme', isDark ? 'dark' : 'light');
        });
    }

    // ── 2. Mobile Sidebar Toggle ───────────────────────────────────────────
    const collapseBtn = document.getElementById('ofp-sb-collapse');
    const mobileToggle = document.getElementById('ofp-mobile-menu-btn');
    const sidebar = document.getElementById('ofp-sidebar');

    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
        });
    }

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }

    // ── 3. Quick Search / Filter in Table ───────────────────────────────────
    const searchInput = document.getElementById('ofp-table-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function (e) {
            const query = e.target.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.ofp-data-table tbody tr');

            rows.forEach(function (row) {
                const text = row.innerText.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // ── 4. Global Keyboard Shortcut (⌘F / Ctrl+F) ───────────────────────────
    const sbSearch = document.getElementById('ofp-sb-search-input');
    window.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'f') {
            if (sbSearch) {
                e.preventDefault();
                sbSearch.focus();
                sbSearch.select();
            }
        }
    });

    if (sbSearch) {
        sbSearch.addEventListener('input', function (e) {
            const q = e.target.value.toLowerCase().trim();
            const navItems = document.querySelectorAll('.ofp-nav-item');
            navItems.forEach(function (item) {
                const label = item.innerText.toLowerCase();
                if (!q || label.includes(q)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // ── 5. In-Portal Link Interception (Never kick out to WP Admin) ──────────
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a');
        if (!link) return;

        // Skip explicit switch to WP Admin links
        if (link.classList.contains('ofp-wp-switch-btn') || link.classList.contains('ofp-btn-wp')) {
            return;
        }

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

        // Check if link points to WordPress admin page relating to this project
        const isWpAdminPage = href.includes('admin.php?page=ofp-') 
            || href.includes('edit.php?post_type=ofp_property')
            || href.includes('post-new.php?post_type=ofp_property')
            || href.includes('edit-tags.php?taxonomy=ofp_property')
            || href.startsWith('?post_type=ofp_property')
            || href.startsWith('?page=ofp-');

        if (isWpAdminPage) {
            e.preventDefault();

            try {
                const url = new URL(href, window.location.origin);
                const page = url.searchParams.get('page') || '';
                const postType = url.searchParams.get('post_type') || '';
                const taxonomy = url.searchParams.get('taxonomy') || '';
                const action = url.searchParams.get('action') || '';
                const id = url.searchParams.get('id') || url.searchParams.get('client_id') || url.searchParams.get('post') || '';

                let tab = 'overview';
                if (page) {
                    tab = page.replace(/^ofp-/, '');
                    if (tab === 'admins') tab = 'manage-admins';
                } else if (taxonomy === 'ofp_property_type') {
                    tab = 'property-types';
                } else if (taxonomy === 'ofp_property_location') {
                    tab = 'property-locations';
                } else if (postType === 'ofp_property' || href.includes('post_type=ofp_property')) {
                    if (action === 'add' || href.includes('post-new.php')) {
                        tab = 'property-add';
                    } else {
                        tab = 'properties';
                    }
                }

                // Construct portal URL
                const portalUrl = new URL('/admin-portal', window.location.origin);
                portalUrl.searchParams.set('tab', tab);
                if (action && action !== 'add') portalUrl.searchParams.set('action', action);
                if (id) portalUrl.searchParams.set('id', id);

                // Map any inner tab or subtab (e.g. &tab=send, &tab=templates, &tab=tenants)
                const innerTab = url.searchParams.get('tab') || '';
                const innerSubtab = url.searchParams.get('subtab') || '';
                const resolvedSubtab = innerSubtab || (innerTab && innerTab !== tab ? innerTab : '');
                if (resolvedSubtab) {
                    portalUrl.searchParams.set('subtab', resolvedSubtab);
                }

                // Preserve other query params
                url.searchParams.forEach((val, key) => {
                    if (!['page', 'post_type', 'taxonomy', 'tab', 'subtab'].includes(key)) {
                        portalUrl.searchParams.set(key, val);
                    }
                });

                window.location.href = portalUrl.toString();
            } catch (err) {
                console.warn('Navigation reroute error:', err);
                window.location.href = href;
            }
        }
    });

    // ── 6. WordPress Media Library Integration for Property Images ─────────
    const imgInput = document.getElementById('ofp_feat_image_url');
    if (imgInput && typeof wp !== 'undefined' && wp.media) {
        imgInput.addEventListener('click', function () {
            const frame = wp.media({
                title: 'Select or Upload Property Photo',
                button: { text: 'Use Photo' },
                multiple: false
            });

            frame.on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                imgInput.value = attachment.url;
            });

            frame.open();
        });
    }
});
