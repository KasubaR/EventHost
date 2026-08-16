(function () {
    const POLL_FAST_MS = 5000;
    const POLL_SLOW_MS = 10000;
    const FAST_POLL_COUNT = 12;
    const MAX_POLLS = 36;

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-order-status-root]');
        if (!root) {
            return;
        }

        if (root.dataset.terminalStatus === '1') {
            return;
        }

        const verifyUrl = root.dataset.verifyUrl;
        const initialStatus = root.dataset.orderStatus;
        let pollCount = 0;

        function poll() {
            pollCount++;

            fetch(verifyUrl, { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((data) => {
                    if (data.status && data.status !== initialStatus) {
                        window.location.reload();
                        return;
                    }

                    if (pollCount >= MAX_POLLS) {
                        return;
                    }

                    setTimeout(poll, pollCount < FAST_POLL_COUNT ? POLL_FAST_MS : POLL_SLOW_MS);
                })
                .catch(() => {
                    if (pollCount < MAX_POLLS) {
                        setTimeout(poll, pollCount < FAST_POLL_COUNT ? POLL_FAST_MS : POLL_SLOW_MS);
                    }
                });
        }

        poll();
    });
})();
