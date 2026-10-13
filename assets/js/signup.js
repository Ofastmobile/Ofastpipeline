/**
 * OFast Pipeline — Signup Page JavaScript
 *
 * Handles the live subdomain preview on the signup form.
 * ofpSignupData.baseDomain is localised via wp_localize_script().
 */
(function () {
    'use strict';

    var baseDomain = (window.ofpSignupData && window.ofpSignupData.baseDomain)
        ? window.ofpSignupData.baseDomain
        : '';

    var bizInput = document.querySelector('input[name="business_name"]');
    var subInput = document.getElementById('ofp-subdomain-input');
    var preview  = document.getElementById('ofp-subdomain-preview');

    if (!bizInput || !subInput) return;

    var userModified = false;

    subInput.addEventListener('input', function () {
        userModified = true;
        updatePreview();
    });

    bizInput.addEventListener('input', function () {
        if (!userModified && (!subInput.value || subInput.value === slugify(bizInput.dataset.lastVal || ''))) {
            subInput.value = slugify(bizInput.value);
            updatePreview();
        }
        bizInput.dataset.lastVal = bizInput.value;
    });

    function slugify(text) {
        return (text || '').toString().toLowerCase()
            .trim()
            .replace(/\s+/g, '-')
            .replace(/[^a-z0-9\-]+/g, '')
            .replace(/\-\-+/g, '-')
            .replace(/^-+/, '')
            .replace(/-+$/, '');
    }

    function updatePreview() {
        var slug = slugify(subInput.value) || 'youragency';
        if (preview) {
            preview.textContent = 'https://' + slug + '.' + baseDomain;
        }
    }

    // Pre-fill subdomain from business name if arriving back with a value
    if (bizInput.value && !subInput.value) {
        subInput.value = slugify(bizInput.value);
    }
    updatePreview();
})();
