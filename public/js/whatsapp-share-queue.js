/**
 * WhatsApp share queue (events/guests/whatsapp-queue.blade.php). The link itself opens the chat in a new tab and the
 * server records it; this only updates the row and the counter so the host can see where they are without reloading.
 */
(function () {
    'use strict';

    function init() {
        var counter = document.querySelector('[data-wa-queue-opened]');

        document.querySelectorAll('[data-wa-queue-open]').forEach(function (link) {
            link.addEventListener('click', function () {
                var row = link.closest('[data-wa-queue-row]');
                if (!row) {
                    return;
                }

                if (row.getAttribute('data-opened') !== '1') {
                    row.setAttribute('data-opened', '1');
                    row.classList.add('is-opened');

                    if (counter) {
                        counter.textContent = String((parseInt(counter.textContent, 10) || 0) + 1);
                    }
                }

                var status = row.querySelector('[data-wa-queue-status]');
                if (status) {
                    status.textContent = 'Opened';
                    status.classList.remove('evt-pill--pending');
                    status.classList.add('evt-pill--accepted');
                }

                var label = link.querySelector('[data-wa-queue-label]');
                if (label) {
                    label.textContent = 'Open again';
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
