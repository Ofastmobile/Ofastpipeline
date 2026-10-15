/**
 * OFast Pipeline — Admin Property Editor JavaScript (2026 SaaS Studio Edition)
 * Handles:
 * - Two-way sync with native WordPress Title and Content (replaces archaic postdivrich)
 * - Live Marketplace Card Preview updating in real-time
 * - Dynamic Listing Readiness Score (0-100%) and interactive checklist
 * - Fast save actions & Ctrl+S / Cmd+S keyboard shortcut
 * - WordPress Media Gallery with immediate cover photo preview
 * - Currency verbalizer, video walkthrough embeds, amenities, and dynamic client banks
 */

(function () {
    'use strict';

    function initOfpPropertyStudio() {
        var wrap = document.getElementById('ofp-property-editor-app');
        if (!wrap) return;

        var nativeForm = document.getElementById('post');
        var nativeTitle = document.getElementById('title');
        var nativeContent = document.getElementById('content');

        // ── 1. Title Sync & Real-Time Card Preview ────────────────────────────
        var studioTitle = document.getElementById('ofp_studio_title');
        var titleCounter = document.getElementById('ofp-title-counter');
        var permalinkSlug = document.getElementById('ofp-permalink-slug');
        var previewTitle = document.getElementById('ofp-preview-title');

        function slugify(text) {
            return text.toString().toLowerCase().trim()
                .replace(/\s+/g, '-')
                .replace(/[^\w\-]+/g, '')
                .replace(/\-\-+/g, '-')
                .substring(0, 80);
        }

        function handleTitleChange() {
            if (!studioTitle) return;
            var val = studioTitle.value;

            // Sync to native WordPress title input
            if (nativeTitle) {
                nativeTitle.value = val;
            }

            // Update character count
            if (titleCounter) {
                titleCounter.textContent = val.length + ' / 120 chars';
                titleCounter.classList.toggle('is-warning', val.length > 100);
            }

            // Update live card preview title
            if (previewTitle) {
                previewTitle.textContent = val.trim() || 'Untitled Luxury Listing';
            }

            // Update permalink preview slug if it contains default slug
            if (permalinkSlug && permalinkSlug.textContent.indexOf('/properties/') !== -1) {
                var s = slugify(val);
                if (s) {
                    var parts = permalinkSlug.textContent.split('/properties/');
                    permalinkSlug.textContent = parts[0] + '/properties/' + s + '/';
                }
            }

            markDirty();
            updateReadinessScore();
        }

        if (studioTitle) {
            studioTitle.addEventListener('input', handleTitleChange);
            // Initial sync if nativeTitle has value and studioTitle is empty
            if (nativeTitle && nativeTitle.value && !studioTitle.value) {
                studioTitle.value = nativeTitle.value;
            }
            handleTitleChange();
        }

        // ── 2. Walkthrough Description Sync & Tools ──────────────────────────
        var studioContent = document.getElementById('ofp_studio_content');
        var descWordCount = document.getElementById('ofp-desc-word-count');

        function countWords(str) {
            if (!str) return 0;
            var trimmed = str.trim();
            return trimmed ? trimmed.split(/\s+/).length : 0;
        }

        function handleContentChange() {
            if (!studioContent) return;
            var val = studioContent.value;

            // Sync to native WordPress content textarea
            if (nativeContent) {
                nativeContent.value = val;
            }

            // Update word count
            if (descWordCount) {
                var words = countWords(val);
                descWordCount.textContent = words + (words === 1 ? ' word' : ' words');
            }

            markDirty();
            updateReadinessScore();
        }

        if (studioContent) {
            studioContent.addEventListener('input', handleContentChange);
            if (nativeContent && nativeContent.value && !studioContent.value) {
                studioContent.value = nativeContent.value;
            }
            handleContentChange();
        }

        // Description helper tools
        var descToolBtns = wrap.querySelectorAll('.ofp-desc-tool-btn');
        descToolBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!studioContent) return;
                var tool = this.getAttribute('data-tool');
                var start = studioContent.selectionStart || 0;
                var end = studioContent.selectionEnd || 0;
                var text = studioContent.value;

                if (tool === 'bullet') {
                    var insertion = (start > 0 && text.charAt(start - 1) !== '\n' ? '\n• ' : '• ');
                    studioContent.value = text.substring(0, start) + insertion + text.substring(end);
                    studioContent.selectionStart = studioContent.selectionEnd = start + insertion.length;
                } else if (tool === 'features') {
                    var template = "\n\nKey Architectural Highlights:\n• Exquisite master suite with custom walk-in closet\n• Fully equipped chef's kitchen with premium imported fittings\n• 24/7 dedicated security surveillance and access control\n• Prime neighborhood with high rental yield and capital appreciation\n";
                    studioContent.value = text + template;
                }
                studioContent.focus();
                handleContentChange();
            });
        });

        // ── 3. Tab Navigation ────────────────────────────────────────────────
        var tabBtns = wrap.querySelectorAll('.ofp-studio-tab-btn');
        var tabPanels = wrap.querySelectorAll('.ofp-studio-panel');

        function activateTab(tabKey) {
            tabBtns.forEach(function (btn) {
                var isTarget = btn.getAttribute('data-tab') === tabKey;
                btn.classList.toggle('is-active', isTarget);
                btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            });
            tabPanels.forEach(function (panel) {
                var isTarget = panel.getAttribute('data-panel') === tabKey;
                panel.classList.toggle('is-active', isTarget);
            });
            try {
                sessionStorage.setItem('ofp_property_active_tab', tabKey);
            } catch (e) {}
        }

        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var tabKey = this.getAttribute('data-tab');
                activateTab(tabKey);
            });
        });

        try {
            var savedTab = sessionStorage.getItem('ofp_property_active_tab');
            if (savedTab && wrap.querySelector('.ofp-studio-panel[data-panel="' + savedTab + '"]')) {
                activateTab(savedTab);
            }
        } catch (e) {}

        // ── 4. Live Currency Verbalizer & Price Preview ───────────────────────
        var priceInput = document.getElementById('ofp_price_input');
        var priceWordsEl = document.getElementById('ofp_price_words_preview');
        var previewPrice = document.getElementById('ofp-preview-price');
        var pricePeriodSelect = document.getElementById('ofp_price_period_select');
        var previewPeriod = document.getElementById('ofp-preview-period');

        function numberToWordsNaira(num) {
            num = Math.floor(num);
            if (num <= 0) return '';
            if (num > 1000000000000) return 'Over 1 Trillion Naira';

            var a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
                'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            var b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

            function convert(n) {
                if (n === 0) return '';
                if (n < 20) return a[n] + ' ';
                if (n < 100) return b[Math.floor(n / 10)] + (n % 10 !== 0 ? '-' + a[n % 10] : '') + ' ';
                if (n < 1000) return a[Math.floor(n / 100)] + ' Hundred ' + (n % 100 !== 0 ? 'and ' + convert(n % 100) : '');
                if (n < 1000000) return convert(Math.floor(n / 1000)) + 'Thousand ' + (n % 1000 !== 0 ? convert(n % 1000) : '');
                if (n < 1000000000) return convert(Math.floor(n / 1000000)) + 'Million ' + (n % 1000000 !== 0 ? convert(n % 1000000) : '');
                return convert(Math.floor(n / 1000000000)) + 'Billion ' + (n % 1000000000 !== 0 ? convert(n % 1000000000) : '');
            }

            var words = convert(num).trim();
            return '₦ ' + num.toLocaleString() + ' — ' + words + ' Naira';
        }

        function updatePrice() {
            if (!priceInput) return;
            var val = parseFloat(priceInput.value);

            if (!isNaN(val) && val > 0) {
                if (priceWordsEl) {
                    priceWordsEl.textContent = numberToWordsNaira(val);
                    priceWordsEl.classList.add('is-visible');
                }
                if (previewPrice) {
                    previewPrice.textContent = '₦' + val.toLocaleString();
                }
            } else {
                if (priceWordsEl) {
                    priceWordsEl.textContent = '';
                    priceWordsEl.classList.remove('is-visible');
                }
                if (previewPrice) {
                    previewPrice.textContent = '₦0.00';
                }
            }

            markDirty();
            updateReadinessScore();
        }

        function updatePricePeriod() {
            if (!pricePeriodSelect || !previewPeriod) return;
            var p = pricePeriodSelect.value;
            if (p === 'year') {
                previewPeriod.textContent = '/ year';
            } else if (p === '2years') {
                previewPeriod.textContent = '/ 2 yrs';
            } else if (p === 'month') {
                previewPeriod.textContent = '/ mo';
            } else {
                previewPeriod.textContent = '';
            }
            markDirty();
        }

        if (priceInput) {
            priceInput.addEventListener('input', updatePrice);
            updatePrice();
        }
        if (pricePeriodSelect) {
            pricePeriodSelect.addEventListener('change', updatePricePeriod);
            updatePricePeriod();
        }

        // ── 5. Listing Type & Land Mode ──────────────────────────────────────
        var listingTypeSelect = document.getElementById('ofp_listing_type_select');
        var propertyTypeSelect = document.getElementById('ofp_property_type_select');
        var landNotice = document.getElementById('ofp_land_notice');
        var buildingSpecs = document.getElementById('ofp_building_specs_section');
        var landSpecs = document.getElementById('ofp_land_specs_section');
        var previewTypePill = document.getElementById('ofp-preview-type-pill');
        var previewBedSpec = document.getElementById('ofp-preview-bed-spec');
        var previewBathSpec = document.getElementById('ofp-preview-bath-spec');
        var previewCarSpec = document.getElementById('ofp-preview-car-spec');
        var previewPlotsSpec = document.getElementById('ofp-preview-plots-spec');
        var previewPlotsVal = document.getElementById('ofp-preview-plots-val');
        var plotsInput = document.getElementById('ofp_plots_count_input');
        var adminPlotHint = document.getElementById('ofp_admin_plot_hint');

        function syncListingTypes() {
            if (!listingTypeSelect) return;
            var lt = listingTypeSelect.value;
            var pt = propertyTypeSelect ? propertyTypeSelect.value : '';
            var isLand = (lt === 'land' || pt === 'land');

            // Update live preview pill
            if (previewTypePill) {
                var map = { sale: 'For Sale', rent: 'For Rent', shortlet: 'Short Let', commercial: 'Commercial', land: 'Land' };
                previewTypePill.textContent = map[lt] || lt;
            }

            // Price period lock
            if (pricePeriodSelect) {
                if (lt === 'sale' || lt === 'land') {
                    pricePeriodSelect.value = 'sales';
                    updatePricePeriod();
                } else if (pricePeriodSelect.value === 'sales' && (lt === 'rent' || lt === 'shortlet')) {
                    pricePeriodSelect.value = (lt === 'shortlet') ? 'month' : 'year';
                    updatePricePeriod();
                }
            }

            // Land specifications toggle
            if (isLand) {
                if (landNotice) landNotice.style.display = 'flex';
                if (buildingSpecs) buildingSpecs.style.display = 'none';
                if (landSpecs) landSpecs.style.display = 'block';
                if (previewBedSpec) previewBedSpec.style.display = 'none';
                if (previewBathSpec) previewBathSpec.style.display = 'none';
                if (previewCarSpec) previewCarSpec.style.display = 'none';
                if (previewPlotsSpec) previewPlotsSpec.style.display = '';
                if (propertyTypeSelect && lt === 'land') {
                    propertyTypeSelect.value = 'land';
                }
            } else {
                if (landNotice) landNotice.style.display = 'none';
                if (buildingSpecs) buildingSpecs.style.display = 'block';
                if (landSpecs) landSpecs.style.display = 'none';
                if (previewBedSpec) previewBedSpec.style.display = '';
                if (previewBathSpec) previewBathSpec.style.display = '';
                if (previewCarSpec) previewCarSpec.style.display = '';
                if (previewPlotsSpec) previewPlotsSpec.style.display = 'none';
            }

            markDirty();
            updateReadinessScore();
        }

        if (listingTypeSelect) {
            listingTypeSelect.addEventListener('change', syncListingTypes);
        }
        if (propertyTypeSelect) {
            propertyTypeSelect.addEventListener('change', syncListingTypes);
        }
        syncListingTypes();

        // ── 6. Spaces & Specs Live Sync ──────────────────────────────────────
        var bedsInput = document.getElementById('ofp_bedrooms_input');
        var bathsInput = document.getElementById('ofp_bathrooms_input');
        var parkingInput = document.getElementById('ofp_parking_input');
        var sqmInput = document.getElementById('ofp_area_sqm_input');

        var previewBedsVal = document.getElementById('ofp-preview-beds-val');
        var previewBathsVal = document.getElementById('ofp-preview-baths-val');
        var previewParkingVal = document.getElementById('ofp-preview-parking-val');
        var previewSqmVal = document.getElementById('ofp-preview-sqm-val');

        var adminSqmLiveText = document.getElementById('ofp_admin_sqm_live_text');

        function computePlotDescription(sqm, plots) {
            if (sqm <= 0 && plots <= 0) {
                return 'Standard Lagos Plot = 600 SQM (60ft × 120ft)';
            }
            if (sqm <= 0 && plots > 0) sqm = plots * 600;
            if (plots <= 0 && sqm > 0) plots = sqm / 600;

            if (sqm >= 280 && sqm <= 325) {
                return 'Half Plot (~300 SQM / 50ft × 60ft)';
            } else if (sqm >= 580 && sqm <= 650) {
                return '1 Standard Plot (~600 SQM / 60ft × 120ft)';
            } else if (sqm >= 880 && sqm <= 950) {
                return '1.5 Plots (~900 SQM)';
            } else if (sqm >= 1150 && sqm <= 1300) {
                return '2 Standard Plots (~1,200 SQM / 120ft × 120ft)';
            } else if (sqm >= 1700 && sqm <= 1900) {
                return '3 Standard Plots (~1,800 SQM)';
            } else if (sqm >= 2300 && sqm <= 2500) {
                return '4 Standard Plots (~2,400 SQM)';
            } else if (sqm >= 3900 && sqm <= 4200) {
                return '1 Acre (~6 Standard Plots / 4,047 SQM)';
            } else if (sqm >= 7800 && sqm <= 8400) {
                return '2 Acres (~12 Standard Plots)';
            } else if (sqm >= 9800 && sqm <= 10500) {
                return '1 Hectare (~16.7 Plots / 10,000 SQM)';
            } else {
                var roundedPlots = Math.round(plots * 10) / 10;
                return roundedPlots + ' Plot(s) (' + Math.round(sqm).toLocaleString() + ' SQM)';
            }
        }

        function updatePlotHint(plots, sqm) {
            var desc = computePlotDescription(sqm, plots);
            if (adminSqmLiveText) adminSqmLiveText.textContent = desc;
            if (adminPlotHint) {
                if (plots > 0 && sqm > 0) {
                    adminPlotHint.textContent = plots + ' Plot(s) = ' + sqm.toLocaleString() + ' SQM (~' + Math.round(sqm * 10.764).toLocaleString() + ' Sq Ft).';
                } else {
                    adminPlotHint.textContent = desc;
                }
            }
            if (previewPlotsVal) {
                if (plots > 0) {
                    previewPlotsVal.textContent = plots + ' Plot' + (plots == 1 ? '' : 's');
                } else if (sqm > 0) {
                    var est = (sqm / 600).toFixed(1).replace('.0', '');
                    previewPlotsVal.textContent = est + ' Plot' + (est == 1 ? '' : 's');
                } else {
                    previewPlotsVal.textContent = 'Plot(s)';
                }
            }
        }

        // Quick Plot Preset clickers in admin studio
        document.querySelectorAll('.ofp-admin-sqm-preset-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var s = parseFloat(btn.dataset.sqm) || 0;
                var p = parseFloat(btn.dataset.plots) || 0;
                if (sqmInput) {
                    sqmInput.value = s;
                    sqmInput.dataset.autoCalc = '1';
                }
                if (plotsInput) {
                    plotsInput.value = p;
                    plotsInput.dataset.autoCalc = '1';
                }
                syncSpecs();
            });
        });

        function syncSpecs() {
            if (bedsInput && previewBedsVal) previewBedsVal.textContent = bedsInput.value || '0';
            if (bathsInput && previewBathsVal) previewBathsVal.textContent = bathsInput.value || '0';
            if (parkingInput && previewParkingVal) previewParkingVal.textContent = parkingInput.value || '0';
            if (sqmInput && previewSqmVal) previewSqmVal.textContent = sqmInput.value || '0';
            updatePlotHint(parseFloat(plotsInput ? plotsInput.value : 0) || 0, parseFloat(sqmInput ? sqmInput.value : 0) || 0);
            markDirty();
            updateReadinessScore();
        }

        if (plotsInput && sqmInput) {
            plotsInput.addEventListener('input', function() {
                var p = parseFloat(plotsInput.value);
                if (!isNaN(p) && p > 0) {
                    if (!sqmInput.value || sqmInput.dataset.autoCalc === '1') {
                        sqmInput.value = Math.round(p * 600);
                        sqmInput.dataset.autoCalc = '1';
                        if (previewSqmVal) previewSqmVal.textContent = sqmInput.value;
                    }
                }
                syncSpecs();
            });

            sqmInput.addEventListener('input', function() {
                sqmInput.dataset.autoCalc = '0';
                var s = parseFloat(sqmInput.value);
                if (!isNaN(s) && s > 0 && (!plotsInput.value || plotsInput.dataset.autoCalc === '1')) {
                    var derivedPlots = Math.round((s / 600) * 10) / 10;
                    plotsInput.value = derivedPlots;
                    plotsInput.dataset.autoCalc = '1';
                }
                syncSpecs();
            });
        } else if (sqmInput) {
            sqmInput.addEventListener('input', function() {
                syncSpecs();
            });
        }

        [bedsInput, bathsInput, parkingInput, sqmInput].forEach(function (inp) {
            if (inp) inp.addEventListener('input', syncSpecs);
        });
        syncSpecs();

        // ── 7. Physical Address & Location Sync ──────────────────────────────
        var locationInput = document.getElementById('ofp_location_text_input');
        var previewLocationText = document.getElementById('ofp-preview-location-text');
        var locationChips = wrap.querySelectorAll('.ofp-location-chip');

        function updateLocation() {
            if (!locationInput) return;
            var val = locationInput.value.trim();
            if (previewLocationText) {
                previewLocationText.textContent = val || 'Location not specified';
            }
            markDirty();
            updateReadinessScore();
        }

        if (locationInput) {
            locationInput.addEventListener('input', updateLocation);
            updateLocation();
        }

        locationChips.forEach(function (chip) {
            chip.addEventListener('click', function (e) {
                e.preventDefault();
                if (!locationInput) return;
                var text = this.textContent.trim();
                var cur = locationInput.value.trim();
                if (!cur) {
                    locationInput.value = text;
                } else if (cur.indexOf(text) === -1) {
                    locationInput.value = cur + ', ' + text;
                }
                locationInput.focus();
                updateLocation();
            });
        });

        // ── 8. Featured Switch Sync ──────────────────────────────────────────
        var featuredCheckbox = document.getElementById('ofp_is_featured_checkbox');
        var previewFeaturedPill = document.getElementById('ofp-preview-featured-pill');

        function syncFeatured() {
            if (!featuredCheckbox) return;
            var isFeatured = featuredCheckbox.checked;
            if (previewFeaturedPill) {
                previewFeaturedPill.style.display = isFeatured ? '' : 'none';
            }
            markDirty();
        }

        if (featuredCheckbox) {
            featuredCheckbox.addEventListener('change', syncFeatured);
            syncFeatured();
        }

        // ── 9. WP Media Gallery Manager ──────────────────────────────────────
        var galleryGrid = document.getElementById('ofp-gallery-grid');
        var galleryEmpty = document.getElementById('ofp-gallery-empty');
        var galleryInput = document.getElementById('ofp_gallery_ids_input');
        var coverInput = document.getElementById('ofp_set_cover_photo');
        var addBtn = document.getElementById('ofp-btn-add-gallery');
        var galleryCountBadge = document.getElementById('ofp-gallery-count-badge');
        var previewImg = document.getElementById('ofp-preview-img');

        var galleryIds = [];
        try {
            galleryIds = JSON.parse(galleryInput ? galleryInput.value : '[]') || [];
            if (!Array.isArray(galleryIds)) galleryIds = [];
        } catch (e) {
            galleryIds = [];
        }

        function updateGalleryCount() {
            if (galleryCountBadge) {
                galleryCountBadge.textContent = galleryIds.length;
            }
            if (galleryInput) {
                galleryInput.value = JSON.stringify(galleryIds);
            }
            if (galleryEmpty) {
                galleryEmpty.style.display = galleryIds.length === 0 ? 'block' : 'none';
            }
            markDirty();
            updateReadinessScore();
        }

        function renderGalleryCard(id, url, isCover) {
            var card = document.createElement('div');
            card.className = 'ofp-gallery-card' + (isCover ? ' is-cover' : '');
            card.setAttribute('data-id', id);

            card.innerHTML =
                '<div class="ofp-gallery-thumb-wrap">' +
                    '<img src="' + url + '" alt="Gallery item">' +
                    (isCover ? '<span class="ofp-gallery-badge-cover">★ Cover Photo</span>' : '') +
                '</div>' +
                '<div class="ofp-gallery-actions">' +
                    '<button type="button" class="ofp-btn-move-photo ofp-btn-move-left" data-dir="-1" title="Move left">&larr;</button>' +
                    '<button type="button" class="ofp-btn-set-cover" title="Make this the cover photo">' + (isCover ? 'Main Cover' : 'Set as Cover') + '</button>' +
                    '<button type="button" class="ofp-btn-move-photo ofp-btn-move-right" data-dir="1" title="Move right">&rarr;</button>' +
                    '<button type="button" class="ofp-btn-remove-photo" title="Remove photo">&times;</button>' +
                '</div>';

            card.querySelector('.ofp-btn-set-cover').addEventListener('click', function (e) {
                e.preventDefault();
                setCoverPhoto(id, url);
            });
            card.querySelector('.ofp-btn-move-left').addEventListener('click', function (e) {
                e.preventDefault();
                movePhoto(id, -1);
            });
            card.querySelector('.ofp-btn-move-right').addEventListener('click', function (e) {
                e.preventDefault();
                movePhoto(id, 1);
            });
            card.querySelector('.ofp-btn-remove-photo').addEventListener('click', function (e) {
                e.preventDefault();
                removePhoto(id);
            });

            return card;
        }

        function setCoverPhoto(id, url) {
            id = parseInt(id, 10);
            if (coverInput) coverInput.value = id;

            // Update UI card badges
            wrap.querySelectorAll('.ofp-gallery-card').forEach(function (c) {
                var cid = parseInt(c.getAttribute('data-id'), 10);
                var isThis = (cid === id);
                c.classList.toggle('is-cover', isThis);
                var badge = c.querySelector('.ofp-gallery-badge-cover');
                var btn = c.querySelector('.ofp-btn-set-cover');
                if (isThis) {
                    if (!badge) {
                        var b = document.createElement('span');
                        b.className = 'ofp-gallery-badge-cover';
                        b.textContent = '★ Cover Photo';
                        c.querySelector('.ofp-gallery-thumb-wrap').appendChild(b);
                    }
                    if (btn) btn.textContent = 'Main Cover';
                } else {
                    if (badge) badge.remove();
                    if (btn) btn.textContent = 'Set as Cover';
                }
            });

            // Update Live Preview Card Image immediately!
            if (previewImg) {
                if (url) {
                    previewImg.src = url;
                } else {
                    var targetCard = wrap.querySelector('.ofp-gallery-card[data-id="' + id + '"] img');
                    if (targetCard && targetCard.src) {
                        previewImg.src = targetCard.src;
                    }
                }
            }

            markDirty();
            updateReadinessScore();
        }

        function removePhoto(id) {
            id = parseInt(id, 10);
            galleryIds = galleryIds.filter(function (gid) { return gid !== id; });
            var card = wrap.querySelector('.ofp-gallery-card[data-id="' + id + '"]');
            if (card) card.remove();

            if (coverInput && parseInt(coverInput.value, 10) === id) {
                if (galleryIds.length > 0) {
                    setCoverPhoto(galleryIds[0]);
                } else {
                    coverInput.value = '';
                    if (previewImg) {
                        previewImg.src = 'data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'260\' viewBox=\'0 0 400 260\' fill=\'%230f172a\'><rect width=\'400\' height=\'260\' fill=\'%231e293b\'/><text x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' fill=\'%2394a3b8\' font-family=\'sans-serif\' font-size=\'16\'>No Cover Photo Uploaded</text></svg>';
                    }
                }
            }

            updateGalleryCount();
        }

        function movePhoto(id, direction) {
            id = parseInt(id, 10);
            var index = galleryIds.indexOf(id);
            if (index === -1) return;
            var newIndex = index + direction;
            if (newIndex < 0 || newIndex >= galleryIds.length) return;

            var temp = galleryIds[index];
            galleryIds[index] = galleryIds[newIndex];
            galleryIds[newIndex] = temp;

            galleryIds.forEach(function (gid) {
                var c = galleryGrid.querySelector('.ofp-gallery-card[data-id="' + gid + '"]');
                if (c) galleryGrid.appendChild(c);
            });

            updateGalleryCount();
        }

        if (addBtn && window.wp && window.wp.media) {
            var mediaFrame = null;
            addBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (mediaFrame) {
                    mediaFrame.open();
                    return;
                }

                mediaFrame = wp.media({
                    title: 'Select Property Gallery Photos',
                    button: { text: 'Add to Gallery' },
                    multiple: true,
                    library: { type: 'image' }
                });

                mediaFrame.on('select', function () {
                    var selection = mediaFrame.state().get('selection');
                    selection.map(function (attachment) {
                        attachment = attachment.toJSON();
                        var id = parseInt(attachment.id, 10);
                        if (!id || galleryIds.indexOf(id) !== -1) return;

                        galleryIds.push(id);
                        var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                        var isCover = (galleryIds.length === 1 && !coverInput.value);

                        if (isCover) {
                            coverInput.value = id;
                            if (previewImg) previewImg.src = url;
                        }

                        var card = renderGalleryCard(id, url, isCover);
                        galleryGrid.appendChild(card);
                    });

                    updateGalleryCount();
                });

                mediaFrame.open();
            });
        }

        // Attach listeners to pre-rendered cards
        if (galleryGrid) {
            galleryGrid.querySelectorAll('.ofp-gallery-card').forEach(function (card) {
                var id = parseInt(card.getAttribute('data-id'), 10);
                var setCoverBtn = card.querySelector('.ofp-btn-set-cover');
                if (setCoverBtn) {
                    setCoverBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        setCoverPhoto(id);
                    });
                }
                var moveLeftBtn = card.querySelector('.ofp-btn-move-left');
                if (moveLeftBtn) {
                    moveLeftBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        movePhoto(id, -1);
                    });
                }
                var moveRightBtn = card.querySelector('.ofp-btn-move-right');
                if (moveRightBtn) {
                    moveRightBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        movePhoto(id, 1);
                    });
                }
                var removeBtn = card.querySelector('.ofp-btn-remove-photo');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        removePhoto(id);
                    });
                }
            });
        }

        updateGalleryCount();

        // ── 10. Video Tour Embed Live Preview ────────────────────────────────
        var videoUrlInput = document.getElementById('ofp_video_url_input');
        var videoPreviewWrap = document.getElementById('ofp_video_preview_wrap');

        function parseVideoEmbed(url) {
            if (!url) return null;
            var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([\w-]{11})/);
            if (ytMatch && ytMatch[1]) {
                return 'https://www.youtube.com/embed/' + ytMatch[1] + '?rel=0';
            }
            var vimeoMatch = url.match(/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|)(\d+)(?:$|\/|\?)/);
            if (vimeoMatch && vimeoMatch[3]) {
                return 'https://player.vimeo.com/video/' + vimeoMatch[3];
            }
            return null;
        }

        function updateVideoPreview() {
            if (!videoUrlInput || !videoPreviewWrap) return;
            var embedUrl = parseVideoEmbed(videoUrlInput.value.trim());
            if (embedUrl) {
                videoPreviewWrap.innerHTML = '<iframe src="' + embedUrl + '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
                videoPreviewWrap.style.display = 'block';
            } else {
                videoPreviewWrap.innerHTML = '';
                videoPreviewWrap.style.display = 'none';
            }
        }

        if (videoUrlInput) {
            videoUrlInput.addEventListener('input', updateVideoPreview);
            updateVideoPreview();
        }

        // ── 11. Interactive Amenity Cards ────────────────────────────────────
        var amenityCards = wrap.querySelectorAll('.ofp-amenity-card');
        var amenityBadge = document.getElementById('ofp-amenities-count-badge');

        function updateAmenityCount() {
            var checkedCount = wrap.querySelectorAll('.ofp-amenity-card input[type="checkbox"]:checked').length;
            if (amenityBadge) {
                amenityBadge.textContent = checkedCount;
            }
            markDirty();
        }

        amenityCards.forEach(function (card) {
            var cb = card.querySelector('input[type="checkbox"]');
            if (!cb) return;

            card.addEventListener('click', function (e) {
                if (e.target !== cb) {
                    cb.checked = !cb.checked;
                }
                card.classList.toggle('is-selected', cb.checked);
                updateAmenityCount();
            });

            card.classList.toggle('is-selected', cb.checked);
        });

        var btnSelectAllAmenities = document.getElementById('ofp-btn-amenities-all');
        var btnClearAllAmenities = document.getElementById('ofp-btn-amenities-none');

        if (btnSelectAllAmenities) {
            btnSelectAllAmenities.addEventListener('click', function (e) {
                e.preventDefault();
                amenityCards.forEach(function (c) {
                    var cb = c.querySelector('input[type="checkbox"]');
                    if (cb) cb.checked = true;
                    c.classList.add('is-selected');
                });
                updateAmenityCount();
            });
        }

        if (btnClearAllAmenities) {
            btnClearAllAmenities.addEventListener('click', function (e) {
                e.preventDefault();
                amenityCards.forEach(function (c) {
                    var cb = c.querySelector('input[type="checkbox"]');
                    if (cb) cb.checked = false;
                    c.classList.remove('is-selected');
                });
                updateAmenityCount();
            });
        }

        updateAmenityCount();

        // ── 12. Dynamic Bank Accounts by Client & Owner Cards ────────────────
        var clientSelect = document.getElementById('ofp_client_id_select');
        var bankSelect = document.getElementById('ofp_bank_account_select');

        // Main tab owner card
        var clientCard = document.getElementById('ofp_client_preview_card');
        var clientAvatar = document.getElementById('ofp_client_avatar');
        var clientName = document.getElementById('ofp_client_name');
        var clientMeta = document.getElementById('ofp_client_meta');
        var clientPlanBadge = document.getElementById('ofp_client_plan_badge');

        // Sidebar owner card
        var sidebarAvatar = document.getElementById('ofp_client_sidebar_avatar');
        var sidebarName = document.getElementById('ofp_client_sidebar_name');
        var sidebarMeta = document.getElementById('ofp_client_sidebar_meta');
        var sidebarPlan = document.getElementById('ofp_client_sidebar_plan');

        var clientBanks = window.ofpClientBanksData || {};
        var clientsInfo = window.ofpClientsInfoData || {};
        var currentBankId = parseInt(bankSelect ? bankSelect.getAttribute('data-selected') : '0', 10);

        function updateClientAndBanks() {
            if (!clientSelect) return;
            var clientId = clientSelect.value;
            var info = clientsInfo[clientId];

            if (clientId === '0') {
                if (clientAvatar) clientAvatar.textContent = 'OF';
                if (clientName) clientName.textContent = 'Platform / Admin Listing';
                if (clientMeta) clientMeta.textContent = 'Internal company asset';
                if (clientPlanBadge) {
                    clientPlanBadge.textContent = 'Platform Core';
                    clientPlanBadge.style.background = '#dbeafe';
                    clientPlanBadge.style.color = '#1d4ed8';
                }

                if (sidebarAvatar) sidebarAvatar.textContent = 'OF';
                if (sidebarName) sidebarName.textContent = 'Platform Admin';
                if (sidebarMeta) sidebarMeta.textContent = 'Internal Core Listing';
                if (sidebarPlan) {
                    sidebarPlan.textContent = 'CORE';
                    sidebarPlan.style.background = '#dbeafe';
                    sidebarPlan.style.color = '#1d4ed8';
                }
            } else if (info) {
                var initial = (info.business_name || 'C').charAt(0).toUpperCase();
                var displayName = info.business_name + (info.owner_name ? ' (' + info.owner_name + ')' : '');
                var contact = (info.email || '') + (info.phone ? ' • ' + info.phone : '');
                var plan = (info.plan || 'Free').toUpperCase();
                var isPaid = info.plan === 'silver' || info.plan === 'gold';

                if (clientAvatar) clientAvatar.textContent = initial;
                if (clientName) clientName.textContent = displayName;
                if (clientMeta) clientMeta.textContent = contact;
                if (clientPlanBadge) {
                    clientPlanBadge.textContent = plan;
                    clientPlanBadge.style.background = isPaid ? '#dcfce7' : '#f1f5f9';
                    clientPlanBadge.style.color = isPaid ? '#15803d' : '#64748b';
                }

                if (sidebarAvatar) sidebarAvatar.textContent = initial;
                if (sidebarName) sidebarName.textContent = info.business_name;
                if (sidebarMeta) sidebarMeta.textContent = info.owner_name || contact;
                if (sidebarPlan) {
                    sidebarPlan.textContent = plan;
                    sidebarPlan.style.background = isPaid ? '#dcfce7' : '#f1f5f9';
                    sidebarPlan.style.color = isPaid ? '#15803d' : '#64748b';
                }
            }

            if (bankSelect) {
                bankSelect.innerHTML = '';
                var optDefault = document.createElement('option');
                optDefault.value = '0';
                optDefault.textContent = (clientId === '0') ? 'Company Primary Bank Account' : 'Default Client Primary Account';
                bankSelect.appendChild(optDefault);

                var accounts = clientBanks[clientId] || [];
                accounts.forEach(function (acc) {
                    var opt = document.createElement('option');
                    opt.value = acc.id;
                    opt.textContent = acc.bank_name + ' — ' + acc.account_number + ' (' + acc.account_name + ')' + (acc.is_default == '1' ? ' [Default]' : '');
                    if (parseInt(acc.id, 10) === currentBankId) {
                        opt.selected = true;
                    }
                    bankSelect.appendChild(opt);
                });
            }

            markDirty();
            updateReadinessScore();
        }

        if (clientSelect) {
            clientSelect.addEventListener('change', function () {
                currentBankId = 0;
                updateClientAndBanks();
            });
            updateClientAndBanks();
        }

        // ── 13. Dynamic Listing Readiness Score & Checklist ───────────────────
        var titleDocSelect = document.getElementById('ofp_title_document_select');
        var readinessPct = document.getElementById('ofp-readiness-pct');
        var readinessBar = document.getElementById('ofp-readiness-bar');
        var checklistWrap = document.getElementById('ofp-readiness-checklist');

        if (titleDocSelect) {
            titleDocSelect.addEventListener('change', function () {
                markDirty();
                updateReadinessScore();
            });
        }

        function updateReadinessScore() {
            if (!readinessPct || !readinessBar || !checklistWrap) return;

            var items = {
                title: (studioTitle && studioTitle.value.trim().length >= 6),
                price: (priceInput && parseFloat(priceInput.value) > 0),
                desc: (studioContent && countWords(studioContent.value) >= 15),
                photo: (coverInput && parseInt(coverInput.value, 10) > 0) || (galleryIds && galleryIds.length > 0),
                location: (locationInput && locationInput.value.trim().length >= 4),
                specs: (listingTypeSelect && listingTypeSelect.value === 'land')
                    ? (sqmInput && parseInt(sqmInput.value, 10) > 0)
                    : (bedsInput && parseInt(bedsInput.value, 10) > 0),
                title_doc: (titleDocSelect && titleDocSelect.value !== ''),
                owner: (clientSelect && clientSelect.value !== '')
            };

            var total = 0;
            var completed = 0;

            for (var key in items) {
                if (items.hasOwnProperty(key)) {
                    total++;
                    var isDone = items[key];
                    if (isDone) completed++;

                    var checkEl = checklistWrap.querySelector('.ofp-check-item[data-check="' + key + '"]');
                    if (checkEl) {
                        checkEl.classList.toggle('is-done', isDone);
                    }
                }
            }

            var pct = total > 0 ? Math.round((completed / total) * 100) : 0;
            readinessPct.textContent = pct + '%';
            readinessBar.style.width = pct + '%';

            if (pct >= 85) {
                readinessBar.style.background = 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
            } else if (pct >= 50) {
                readinessBar.style.background = 'linear-gradient(90deg, #3b82f6 0%, #10b981 100%)';
            } else {
                readinessBar.style.background = 'linear-gradient(90deg, #f59e0b 0%, #3b82f6 100%)';
            }
        }

        updateReadinessScore();

        // ── 14. Status Selectors Synchronization ─────────────────────────────
        var topbarStatus = document.getElementById('ofp_status_select');
        var sidebarStatus = document.getElementById('ofp_status_sidebar_select');

        function syncStatus(source, target) {
            if (!source || !target) return;
            target.value = source.value;
            markDirty();
        }

        if (topbarStatus && sidebarStatus) {
            topbarStatus.addEventListener('change', function () { syncStatus(topbarStatus, sidebarStatus); });
            sidebarStatus.addEventListener('change', function () { syncStatus(sidebarStatus, topbarStatus); });
        }

        // ── 15. Save Property Controls & Keyboard Shortcut (Ctrl+S / Cmd+S) ──
        var btnTopSave = document.getElementById('ofp-btn-studio-save');
        var btnSidebarSave = document.getElementById('ofp-btn-sidebar-save');
        var syncStatusEl = document.getElementById('ofp-sync-status');
        var syncTextEl = document.getElementById('ofp-sync-text');

        function markDirty() {
            if (syncStatusEl) syncStatusEl.classList.add('is-dirty');
            if (syncTextEl) syncTextEl.textContent = 'Unsaved changes';
        }

        function triggerSave() {
            if (!nativeForm) return;

            // Update button states
            [btnTopSave, btnSidebarSave].forEach(function (btn) {
                if (!btn) return;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update spin" style="font-size:15px;width:15px;height:15px;animation:spin 1s linear infinite;"></span> <span>Saving Property...</span>';
            });

            if (syncTextEl) syncTextEl.textContent = 'Saving changes...';

            // Ensure title & content are synced one last time
            if (studioTitle && nativeTitle) nativeTitle.value = studioTitle.value;
            if (studioContent && nativeContent) nativeContent.value = studioContent.value;

            // Submit form
            nativeForm.submit();
        }

        if (btnTopSave) {
            btnTopSave.addEventListener('click', function (e) {
                e.preventDefault();
                triggerSave();
            });
        }

        if (btnSidebarSave) {
            btnSidebarSave.addEventListener('click', function (e) {
                e.preventDefault();
                triggerSave();
            });
        }

        // Global Ctrl+S / Cmd+S listener
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.keyCode === 83)) {
                e.preventDefault();
                triggerSave();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOfpPropertyStudio);
    } else {
        initOfpPropertyStudio();
    }
})();
