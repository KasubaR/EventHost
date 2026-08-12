(function () {
    'use strict';

    function initConfirmForms() {
        document.querySelectorAll('form.evt-confirm-form[data-evt-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var msg = form.getAttribute('data-evt-confirm');
                if (msg && !window.confirm(msg)) {
                    event.preventDefault();
                }
            });
        });
    }

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
                    if (ok) {
                        btn.textContent = 'Copied';
                        window.setTimeout(function () {
                            btn.textContent = prev;
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
        initConfirmForms();
        initCopyButtons(document);
    });
})();
