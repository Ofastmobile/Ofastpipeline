/**
 * OFast Pipeline - Field Agent Portal JS
 */
(function () {
    'use strict';

    function initFieldForm() {
        var type = document.getElementById('fa-type');
        if (type) {
            function sync() {
                var t = type.value;
                var sale = (t === 'sale' || t === 'land');
                var periodWrap = document.getElementById('fa-period-wrap');
                var rooms = document.getElementById('fa-rooms');
                var docs = document.getElementById('fa-docs');

                if (periodWrap) periodWrap.classList.toggle('fa-hide', sale);
                if (rooms) rooms.classList.toggle('fa-hide', t === 'land');
                if (docs) docs.classList.toggle('fa-hide', !sale);
            }
            type.addEventListener('change', sync);
            sync();
        }

        var gps = document.getElementById('fa-gps');
        if (gps) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (p) {
                        var latEl = document.getElementById('fa-lat');
                        var lngEl = document.getElementById('fa-lng');
                        if (latEl) latEl.value = p.coords.latitude.toFixed(6);
                        if (lngEl) lngEl.value = p.coords.longitude.toFixed(6);
                        gps.textContent = 'Location captured ✓';
                    },
                    function () {
                        gps.textContent = 'Location not shared. You can still submit, but reviewers will see it was not captured.';
                    },
                    { enableHighAccuracy: true, timeout: 12000 }
                );
            } else {
                gps.textContent = '';
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFieldForm);
    } else {
        initFieldForm();
    }
})();

