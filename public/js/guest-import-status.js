/**
 * Background guest import status page (events/guests/import-status.blade.php).
 * Polls the progress endpoint, updates the row count and bar, and reloads once
 * the import has finished so the server renders the full result. Without this
 * script the page still shows its state and a Refresh link.
 */
(function () {
    'use strict';

    var POLL_MS = 3000;

    function start() {
        var root = document.querySelector('[data-import-progress-url]');
        if (!root) {
            return;
        }

        var url = root.getAttribute('data-import-progress-url');
        var text = root.querySelector('[data-import-progress-text]');
        var bar = root.querySelector('[data-import-progress-bar]');
        var format = typeof Intl !== 'undefined' && Intl.NumberFormat ? new Intl.NumberFormat() : null;

        function number(n) {
            return format ? format.format(n) : String(n);
        }

        function poll() {
            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (data.finished) {
                        window.location.reload();
                        return;
                    }

                    if (data.status === 'processing') {
                        if (text) {
                            text.textContent = 'Checked ' + number(data.processed_rows) + ' of ' + number(data.total_rows) + ' rows.';
                        }
                        if (bar) {
                            bar.value = data.processed_rows;
                        }
                    }

                    window.setTimeout(poll, POLL_MS);
                })
                .catch(function () {
                    window.setTimeout(poll, POLL_MS * 3);
                });
        }

        window.setTimeout(poll, POLL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
