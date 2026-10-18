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

    // ── 7. Property Studio Interactive Handlers ─────────────────────────────
    // Number to Words Verbalizer
    function numToNaira(num) {
        num = Math.floor(num);
        if (num <= 0) return '';
        if (num > 1000000000000) return 'Over 1 Trillion Naira';
        var a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        var b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        function c(n) {
            if (n === 0) return '';
            if (n < 20) return a[n] + ' ';
            if (n < 100) return b[Math.floor(n / 10)] + (n % 10 !== 0 ? '-' + a[n % 10] : '') + ' ';
            if (n < 1000) return a[Math.floor(n / 100)] + ' Hundred ' + (n % 100 !== 0 ? 'and ' + c(n % 100) : '');
            if (n < 1000000) return c(Math.floor(n / 1000)) + 'Thousand ' + (n % 1000 !== 0 ? c(n % 1000) : '');
            if (n < 1000000000) return c(Math.floor(n / 1000000)) + 'Million ' + (n % 1000000 !== 0 ? c(n % 1000000) : '');
            return c(Math.floor(n / 1000000000)) + 'Billion ' + (n % 1000000000 !== 0 ? c(n % 1000000000) : '');
        }
        return '₦ ' + num.toLocaleString() + ' — ' + c(num).trim() + ' Naira';
    }

    var priceInput = document.getElementById('ofp_price_input');
    var verbalizer = document.getElementById('ofp_price_verbalizer');
    function updateVerbalizer() {
        if (!priceInput || !verbalizer) return;
        var val = parseFloat(priceInput.value);
        if (!isNaN(val) && val > 0) {
            verbalizer.textContent = numToNaira(val);
            verbalizer.classList.add('is-active');
        } else {
            verbalizer.textContent = '';
            verbalizer.classList.remove('is-active');
        }
    }
    if (priceInput) {
        priceInput.addEventListener('input', updateVerbalizer);
        updateVerbalizer();
    }

    // Title Character Counter
    var titleInput = document.getElementById('ofp_title_input');
    var titleLen = document.getElementById('ofp_title_len');
    function updateTitleLen() {
        if (titleInput && titleLen) {
            titleLen.textContent = titleInput.value.length + ' / 120 chars';
        }
    }
    if (titleInput) {
        titleInput.addEventListener('input', updateTitleLen);
        updateTitleLen();
    }

    // Description Word Counter & Templates
    var contentInput = document.getElementById('ofp_content_input');
    var descWords = document.getElementById('ofp_desc_words');
    function updateWordCount() {
        if (contentInput && descWords) {
            var text = contentInput.value.trim();
            var words = text ? text.split(/\s+/).length : 0;
            descWords.textContent = words + (words === 1 ? ' word' : ' words');
        }
    }
    if (contentInput) {
        contentInput.addEventListener('input', updateWordCount);
        updateWordCount();
    }

    var btnAddBullet = document.getElementById('ofp_btn_add_bullet');
    if (btnAddBullet && contentInput) {
        btnAddBullet.addEventListener('click', function() {
            var start = contentInput.selectionStart || 0;
            var end = contentInput.selectionEnd || 0;
            var text = contentInput.value;
            var ins = (start > 0 && text.charAt(start - 1) !== '\n' ? '\n• ' : '• ');
            contentInput.value = text.substring(0, start) + ins + text.substring(end);
            contentInput.selectionStart = contentInput.selectionEnd = start + ins.length;
            contentInput.focus();
            updateWordCount();
        });
    }

    var btnAddHighlights = document.getElementById('ofp_btn_add_highlights');
    if (btnAddHighlights && contentInput) {
        btnAddHighlights.addEventListener('click', function() {
            var template = "\n\nKey Architectural Highlights:\n• Grand master bedroom suite with walk-in closet & ensuite bathroom\n• Fully-fitted chef kitchen with custom cabinetry & premium appliances\n• 24/7 uninterrupted power supply with dual generator backup\n• High security estate perimeter with biometric gate access clearance\n• High capital growth corridor and strong annual rental yield\n";
            contentInput.value += template;
            contentInput.focus();
            updateWordCount();
        });
    }

    // Listing Type vs Land Mode Toggle
    var listTypeSel = document.getElementById('ofp_listing_type_sel');
    var propTypeSel = document.getElementById('ofp_property_type_sel');
    var landBox = document.getElementById('ofp_land_box');
    var buildingBox = document.getElementById('ofp_building_box');
    var periodSel = document.getElementById('ofp_price_period_sel');

    function syncModes() {
        var lt = listTypeSel ? listTypeSel.value : 'sale';
        var pt = propTypeSel ? propTypeSel.value : '';
        var isLand = (lt === 'land' || pt === 'land');

        if (isLand) {
            if (landBox) landBox.style.display = 'block';
            if (buildingBox) buildingBox.style.display = 'none';
            if (periodSel) periodSel.value = 'sales';
        } else {
            if (landBox) landBox.style.display = 'none';
            if (buildingBox) buildingBox.style.display = 'block';
        }

        var pkgBox = document.getElementById('ofp_total_package_box');
        if (pkgBox) {
            pkgBox.style.display = (lt === 'rent' || lt === 'lease') ? 'block' : 'none';
        }
    }
    if (listTypeSel) listTypeSel.addEventListener('change', syncModes);
    if (propTypeSel) propTypeSel.addEventListener('change', syncModes);
    syncModes();

    // Preset Locations Chips
    document.querySelectorAll('.ofp-preset-loc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var loc = this.getAttribute('data-loc');
            var inp = document.getElementById('ofp_location_input');
            if (inp) {
                inp.value = loc;
                inp.focus();
            }
        });
    });

    // SQM Plot Presets
    var areaSqmInput = document.getElementById('ofp_area_sqm_input');
    var plotsInput = document.getElementById('ofp_plots_input');
    var sqmBadge = document.getElementById('ofp_sqm_live_badge');

    function updatePlotDescription(sqm) {
        if (!sqmBadge) return;
        if (!sqm || sqm <= 0) {
            sqmBadge.textContent = 'Standard Lagos Plot = 600 SQM';
            return;
        }
        if (sqm >= 280 && sqm <= 325) {
            sqmBadge.textContent = '≈ Half Plot (300 SQM / 50ft × 60ft)';
        } else if (sqm >= 580 && sqm <= 650) {
            sqmBadge.textContent = '≈ 1 Standard Lagos Plot (600 SQM / 60ft × 120ft)';
        } else if (sqm >= 1150 && sqm <= 1250) {
            sqmBadge.textContent = '≈ 2 Plots (1,200 SQM)';
        } else if (sqm >= 1750 && sqm <= 1850) {
            sqmBadge.textContent = '≈ 3 Plots (1,800 SQM)';
        } else if (sqm >= 3950 && sqm <= 4150) {
            sqmBadge.textContent = '≈ 1 Full Acre (4,047 SQM / 6.7 Plots)';
        } else if (sqm >= 9800 && sqm <= 10200) {
            sqmBadge.textContent = '≈ 1 Hectare (10,000 SQM / 16.7 Plots)';
        } else {
            var p = (sqm / 600).toFixed(1);
            sqmBadge.textContent = '≈ ' + p + ' Lagos Plots (' + sqm.toLocaleString() + ' SQM)';
        }
    }

    if (areaSqmInput) {
        areaSqmInput.addEventListener('input', function() {
            var val = parseFloat(this.value) || 0;
            if (plotsInput && val > 0) {
                plotsInput.value = (val / 600).toFixed(1);
            }
            updatePlotDescription(val);
        });
        updatePlotDescription(parseFloat(areaSqmInput.value) || 0);
    }

    if (plotsInput) {
        plotsInput.addEventListener('input', function() {
            var p = parseFloat(this.value) || 0;
            if (areaSqmInput && p > 0) {
                areaSqmInput.value = Math.round(p * 600);
                updatePlotDescription(p * 600);
            }
        });
    }

    document.querySelectorAll('.ofp-sqm-preset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var sqm = parseFloat(this.getAttribute('data-sqm'));
            var plots = parseFloat(this.getAttribute('data-plots'));
            if (areaSqmInput) areaSqmInput.value = sqm;
            if (plotsInput) plotsInput.value = plots;
            updatePlotDescription(sqm);
        });
    });

    // Amenities Select All / Clear All
    var btnAllAmen = document.getElementById('ofp_amenities_all');
    var btnClrAmen = document.getElementById('ofp_amenities_clear');
    if (btnAllAmen) {
        btnAllAmen.addEventListener('click', function() {
            document.querySelectorAll('.ofp-amenity-tile').forEach(function(t) {
                var chk = t.querySelector('input[type="checkbox"]');
                if (chk) chk.checked = true;
                t.classList.add('is-checked');
            });
        });
    }
    if (btnClrAmen) {
        btnClrAmen.addEventListener('click', function() {
            document.querySelectorAll('.ofp-amenity-tile').forEach(function(t) {
                var chk = t.querySelector('input[type="checkbox"]');
                if (chk) chk.checked = false;
                t.classList.remove('is-checked');
            });
        });
    }
    document.querySelectorAll('.ofp-amenity-tile input').forEach(function(chk) {
        chk.addEventListener('change', function() {
            this.closest('.ofp-amenity-tile').classList.toggle('is-checked', this.checked);
        });
    });

    // Video Embed Preview
    var videoInput = document.getElementById('ofp_video_url_input');
    var videoWrap  = document.getElementById('ofp_video_embed_preview');
    function renderVideoPreview() {
        if (!videoInput || !videoWrap) return;
        var url = videoInput.value.trim();
        if (!url) {
            videoWrap.style.display = 'none';
            videoWrap.innerHTML = '';
            return;
        }
        var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([\w-]{11})/);
        var vmMatch = url.match(/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|)(\d+)/);

        if (ytMatch && ytMatch[1]) {
            videoWrap.innerHTML = '<iframe src="https://www.youtube.com/embed/' + ytMatch[1] + '" frameborder="0" allowfullscreen style="width:100%;height:100%;aspect-ratio:16/9;border-radius:8px;"></iframe>';
            videoWrap.style.display = 'block';
        } else if (vmMatch && vmMatch[3]) {
            videoWrap.innerHTML = '<iframe src="https://player.vimeo.com/video/' + vmMatch[3] + '" frameborder="0" allowfullscreen style="width:100%;height:100%;aspect-ratio:16/9;border-radius:8px;"></iframe>';
            videoWrap.style.display = 'block';
        } else {
            videoWrap.style.display = 'none';
            videoWrap.innerHTML = '';
        }
    }
    if (videoInput) {
        videoInput.addEventListener('input', renderVideoPreview);
        renderVideoPreview();
    }

    // Gallery & Cover Photo Management
    var coverIdInput     = document.getElementById('ofp_featured_image_id');
    var coverUrlInput    = document.getElementById('ofp_feat_image_url');
    var coverPreviewBox  = document.getElementById('ofp_cover_preview');
    var galleryGrid      = document.getElementById('ofp_gallery_cards_grid');
    var galleryInput     = document.getElementById('ofp_gallery_ids_input');

    var currentGallery = [];
    if (galleryInput && galleryInput.value) {
        currentGallery = galleryInput.value.split(',').map(function(id) { return parseInt(id.trim(), 10); }).filter(Boolean);
    }

    function syncGalleryInput() {
        if (galleryInput) galleryInput.value = currentGallery.join(',');
    }

    function attachCardEvents(card) {
        var id = parseInt(card.getAttribute('data-id'), 10);
        var btnDel = card.querySelector('.ofp-gallery-btn-del');
        var btnSetCover = card.querySelector('.ofp-btn-set-cover');

        if (btnDel) {
            btnDel.addEventListener('click', function() {
                var idx = currentGallery.indexOf(id);
                if (idx !== -1) currentGallery.splice(idx, 1);
                card.remove();
                syncGalleryInput();
            });
        }

        if (btnSetCover) {
            btnSetCover.addEventListener('click', function() {
                var imgUrl = this.getAttribute('data-url');
                if (coverIdInput) coverIdInput.value = id;
                if (coverUrlInput) coverUrlInput.value = imgUrl;
                if (coverPreviewBox) {
                    coverPreviewBox.innerHTML = '<img src="' + imgUrl + '" style="width:100%;height:100%;object-fit:cover;">';
                }
                document.querySelectorAll('.ofp-gallery-card').forEach(function(c) {
                    c.classList.remove('is-cover');
                    var b = c.querySelector('.ofp-gallery-badge-cover');
                    if (b) b.remove();
                    var sc = c.querySelector('.ofp-btn-set-cover');
                    if (sc) sc.textContent = 'Set Main';
                });
                card.classList.add('is-cover');
                var badge = document.createElement('span');
                badge.className = 'ofp-gallery-badge-cover';
                badge.textContent = '★ Cover';
                card.appendChild(badge);
                this.textContent = 'Main';
            });
        }
    }

    if (galleryGrid) {
        galleryGrid.querySelectorAll('.ofp-gallery-card').forEach(attachCardEvents);
    }

    var btnPickCover = document.getElementById('ofp_btn_pick_cover');
    if (btnPickCover && window.wp && window.wp.media) {
        btnPickCover.addEventListener('click', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: 'Select Property Cover Photo',
                button: { text: 'Use as Cover Photo' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                var id  = attachment.id;
                var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                if (coverIdInput) coverIdInput.value = id;
                if (coverUrlInput) coverUrlInput.value = url;
                if (coverPreviewBox) {
                    coverPreviewBox.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
                }
            });
            frame.open();
        });
    }

    var btnAddGallery = document.getElementById('ofp_btn_add_gallery');
    if (btnAddGallery && window.wp && window.wp.media && galleryGrid) {
        btnAddGallery.addEventListener('click', function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: 'Add Property Gallery Photos',
                button: { text: 'Add to Property Gallery' },
                multiple: true,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var emptyEl = document.getElementById('ofp_gallery_empty');
                if (emptyEl) emptyEl.remove();

                var selection = frame.state().get('selection');
                selection.map(function(att) {
                    att = att.toJSON();
                    var id = parseInt(att.id, 10);
                    if (!id || currentGallery.indexOf(id) !== -1) return;

                    currentGallery.push(id);
                    var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                    var isCover = (currentGallery.length === 1 && !coverIdInput.value);

                    if (isCover) {
                        coverIdInput.value = id;
                        if (coverUrlInput) coverUrlInput.value = url;
                        if (coverPreviewBox) {
                            coverPreviewBox.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
                        }
                    }

                    var card = document.createElement('div');
                    card.className = 'ofp-gallery-card' + (isCover ? ' is-cover' : '');
                    card.setAttribute('data-id', id);
                    card.innerHTML = '<img src="' + url + '" alt="Gallery image">' +
                        (isCover ? '<span class="ofp-gallery-badge-cover">★ Cover</span>' : '') +
                        '<div class="ofp-gallery-actions">' +
                            '<button type="button" class="ofp-gallery-btn ofp-btn-set-cover" data-id="' + id + '" data-url="' + url + '">' + (isCover ? 'Main' : 'Set Main') + '</button>' +
                            '<button type="button" class="ofp-gallery-btn ofp-gallery-btn-del" data-id="' + id + '">&times;</button>' +
                        '</div>';
                    galleryGrid.appendChild(card);
                    attachCardEvents(card);
                });
                syncGalleryInput();
            });
            frame.open();
        });
    }
});

// Step counter helper
function stepCount(id, delta) {
    var el = document.getElementById(id);
    if (!el) return;
    var val = parseInt(el.value, 10) || 0;
    val = Math.max(0, val + delta);
    el.value = val;
}
window.stepCount = stepCount;

