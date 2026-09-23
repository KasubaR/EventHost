(function () {
    'use strict';

    function initCopyButtons(root) {
        root.querySelectorAll('[data-copy-text]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy-text');
                if (!text) {
                    return;
                }

                function done(ok) {
                    btn.setAttribute('aria-busy', 'false');
                    var prev = btn.getAttribute('data-copy-label') || 'Copy link';
                    var label = btn.querySelector('[data-copy-label-text]') || btn;
                    if (ok) {
                        label.textContent = 'Copied';
                        window.setTimeout(function () {
                            label.textContent = prev;
                        }, 1600);
                    }
                }

                btn.setAttribute('aria-busy', 'true');

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        done(true);
                    }).catch(function () {
                        done(false);
                    });
                } else {
                    done(false);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCopyButtons(document);
    });
})();
