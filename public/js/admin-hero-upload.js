/**
 * After media-uploader.js commits the admin ticket hero, swap the preview
 * and unlock Approve. The upload itself (progress tile, XHR) lives in
 * media-uploader.js — this file only updates the page around it.
 */
(function () {
    'use strict';

    document.addEventListener('mediauploader:complete', function (e) {
        var url = e.detail && e.detail.url;
        if (!url) return;

        var card = e.target.closest('.admin-hero-card');
        if (!card) return;

        var empty = card.querySelector('[data-hero-empty]');
        if (empty) empty.hidden = true;

        var img = card.querySelector('.admin-hero-preview');
        if (!img) {
            img = document.createElement('img');
            img.className = 'admin-hero-preview';
            img.alt = '';
            img.width = 1200;
            img.height = 630;
            var heading = card.querySelector('h2');
            var after = heading ? heading.nextElementSibling : null;
            if (after) {
                after.parentNode.insertBefore(img, after.nextSibling);
            } else {
                card.insertBefore(img, card.firstChild);
            }
        }
        img.hidden = false;
        img.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();

        var label = card.querySelector('[data-hero-label]');
        if (label) label.textContent = 'Replace hero image';

        document.querySelectorAll('[data-hero-approve]').forEach(function (btn) {
            btn.disabled = false;
        });
    });
})();
