/**
 * Ofast Pipeline / MyPropertyHQ - Embeddable Property Widget
 * Allows clients to embed their active properties onto any external website.
 *
 * Usage:
 * <div id="ofp-property-widget" data-client-id="YOUR_CLIENT_ID" data-limit="6"></div>
 * <script src="https://mypropertyhq.ng/wp-content/plugins/ofast-pipeline/assets/js/ofp-embed.js" async></script>
 */
(function() {
    'use strict';

    // Prevent double execution
    if (window.__OFP_EMBED_INIT__) return;
    window.__OFP_EMBED_INIT__ = true;

    // Determine script host origin
    var scriptOrigin = '';
    var currentScript = document.currentScript;
    if (currentScript && currentScript.src) {
        try {
            var urlObj = new URL(currentScript.src);
            scriptOrigin = urlObj.origin;
        } catch(e) {}
    }

    // Scoped CSS styles for property cards
    var styles = `
        .ofp-widget-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            box-sizing: border-box;
            width: 100%;
            margin: 0 auto;
            padding: 10px 0;
        }
        .ofp-widget-container *, .ofp-widget-container *:before, .ofp-widget-container *:after {
            box-sizing: border-box;
        }
        .ofp-property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 22px;
            width: 100%;
        }
        .ofp-embed-card {
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
        }
        .ofp-embed-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.10);
        }
        .ofp-embed-media {
            position: relative;
            width: 100%;
            height: 200px;
            background: #f1f5f9;
            overflow: hidden;
        }
        .ofp-embed-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
            display: block;
        }
        .ofp-embed-card:hover .ofp-embed-media img {
            transform: scale(1.04);
        }
        .ofp-embed-pill-type {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(4px);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 4px 10px;
            border-radius: 9999px;
        }
        .ofp-embed-body {
            padding: 18px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .ofp-embed-price {
            font-size: 19px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 6px;
        }
        .ofp-embed-title {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 8px 0;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ofp-embed-location {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12.5px;
            color: #64748b;
            margin-bottom: 14px;
        }
        .ofp-embed-location svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            color: #94a3b8;
        }
        .ofp-embed-specs {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12.5px;
            color: #475569;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }
        .ofp-embed-spec-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .ofp-embed-btn {
            margin-left: auto;
            font-size: 12px;
            font-weight: 700;
            color: #5b35d5;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .ofp-embed-loading {
            text-align: center;
            padding: 30px;
            color: #64748b;
            font-size: 14px;
        }
        .ofp-embed-empty {
            text-align: center;
            padding: 30px;
            color: #94a3b8;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px dashed #cbd5e1;
            font-size: 14px;
        }
    `;

    // Inject styles
    function injectStyles() {
        if (document.getElementById('ofp-embed-styles')) return;
        var styleEl = document.createElement('style');
        styleEl.id = 'ofp-embed-styles';
        styleEl.textContent = styles;
        document.head.appendChild(styleEl);
    }

    // Render properties into target widget
    function renderWidget(container) {
        var clientId = container.getAttribute('data-client-id') || container.getAttribute('data-client');
        var limit    = container.getAttribute('data-limit') || '6';
        var type     = container.getAttribute('data-type') || '';
        var baseApi  = container.getAttribute('data-endpoint') || scriptOrigin || window.location.origin;

        container.innerHTML = '<div class="ofp-embed-loading">Loading featured properties...</div>';
        container.classList.add('ofp-widget-container');

        var apiUrl = baseApi.replace(/\/+$/, '') + '/api/v1/properties?limit=' + encodeURIComponent(limit);
        if (clientId) {
            apiUrl += '&client_id=' + encodeURIComponent(clientId);
        }
        if (type) {
            apiUrl += '&type=' + encodeURIComponent(type);
        }

        fetch(apiUrl)
            .then(function(res) {
                if (!res.ok) throw new Error('Network response error: ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (!data.success || !data.properties || data.properties.length === 0) {
                    container.innerHTML = '<div class="ofp-embed-empty">No active properties currently available. Check back soon.</div>';
                    return;
                }

                var html = '<div class="ofp-property-grid">';
                data.properties.forEach(function(item) {
                    var title = item.title || 'Property';
                    var price = item.formatted_price || 'Price on request';
                    var loc = item.location || 'Location upon inquiry';
                    var typeLabel = item.listing_type ? item.listing_type.toUpperCase() : 'FOR SALE';
                    var img = item.image_url || '';
                    var link = item.url || '#';

                    html += '<a href="' + link + '" target="_blank" rel="noopener noreferrer" class="ofp-embed-card">';
                    html += '<div class="ofp-embed-media">';
                    if (img) {
                        html += '<img src="' + img + '" alt="' + title + '" loading="lazy" />';
                    }
                    html += '<span class="ofp-embed-pill-type">' + typeLabel + '</span>';
                    html += '</div>';

                    html += '<div class="ofp-embed-body">';
                    html += '<div class="ofp-embed-price">' + price + '</div>';
                    html += '<h4 class="ofp-embed-title">' + title + '</h4>';
                    html += '<div class="ofp-embed-location">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>';
                    html += '<span>' + loc + '</span>';
                    html += '</div>';

                    html += '<div class="ofp-embed-specs">';
                    if (item.bedrooms > 0) {
                        html += '<span class="ofp-embed-spec-item"><strong>' + item.bedrooms + '</strong> Beds</span>';
                    }
                    if (item.bathrooms > 0) {
                        html += '<span class="ofp-embed-spec-item"><strong>' + item.bathrooms + '</strong> Baths</span>';
                    }
                    html += '<span class="ofp-embed-btn">View Details &rarr;</span>';
                    html += '</div>';

                    html += '</div>'; // .ofp-embed-body
                    html += '</a>';   // .ofp-embed-card
                });
                html += '</div>'; // .ofp-property-grid

                container.innerHTML = html;
            })
            .catch(function(err) {
                container.innerHTML = '<div class="ofp-embed-empty">Unable to load property feed at this time.</div>';
            });
    }

    // Auto-initialize when DOM is ready
    function init() {
        injectStyles();
        var widgets = document.querySelectorAll('#ofp-property-widget, .ofp-property-widget, [data-ofp-property-widget]');
        widgets.forEach(function(el) {
            renderWidget(el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
