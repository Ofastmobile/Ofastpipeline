/**
 * OFast Pipeline - Plans & Pricing Page Micro-Interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    if (typeof gsap !== 'undefined') {
        // Interactive micro-lift on card hover
        document.querySelectorAll('.plan-hover-card').forEach(function(card) {
            card.addEventListener('mouseenter', function() {
                gsap.to(card, { y: -5, duration: 0.25, ease: 'power1.out' });
            });
            card.addEventListener('mouseleave', function() {
                gsap.to(card, { y: 0, duration: 0.25, ease: 'power1.out' });
            });
        });
    }
});

