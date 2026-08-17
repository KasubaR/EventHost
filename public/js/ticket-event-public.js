(function () {
    'use strict';

    // Same navigator.share-with-clipboard-fallback pattern as rsvp-thanks.js's
    // initShareButton() — duplicated rather than shared because that file is
    // scoped to the RSVP thank-you page's own markup/attributes.
    function initShareButton() {
        var btn = document.querySelector('[data-tev-share]');
        if (!btn) {
            return;
        }

        var label = btn.querySelector('[data-tev-share-label]');
        var url = btn.getAttribute('data-share-url');
        var title = btn.getAttribute('data-share-title') || document.title;

        if (!url) {
            return;
        }

        function setLabel(text, revertAfterMs) {
            if (!label) {
                return;
            }
            var prev = label.textContent;
            label.textContent = text;
            if (revertAfterMs) {
                window.setTimeout(function () {
                    label.textContent = prev;
                }, revertAfterMs);
            }
        }

        btn.addEventListener('click', function () {
            if (typeof navigator.share === 'function') {
                navigator.share({ title: title, url: url }).catch(function () {});
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    setLabel('Copied', 1600);
                }).catch(function () {});
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initShareButton);
})();
