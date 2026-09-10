(function () {
    const POLL_FAST_MS = 5000;
    const POLL_SLOW_MS = 10000;
    const FAST_POLL_COUNT = 12;
    const MAX_POLLS = 36;

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-contribution-status-root]');
        if (!root) {
            return;
        }

        initPolling(root);
        initMethodTabs(root);
        initPayMoreForm(root);
    });

    function initPolling(root) {
        if (root.dataset.completed === '1') {
            return;
        }
        // Only poll while the most recent installment is still in flight —
        // once it lands (terminal), the page already shows the right state
        // and the "pay the rest" form for the next installment, if any.
        if (root.dataset.latestPaymentTerminal === '1') {
            return;
        }

        const verifyUrl = root.dataset.verifyUrl;
        const initialStatus = root.dataset.status;
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
    }

    function initMethodTabs(root) {
        root.querySelectorAll('.tkc-method-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                const method = tab.dataset.method;
                root.querySelectorAll('.tkc-method-tab').forEach((t) => {
                    t.classList.toggle('is-active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                root.querySelectorAll('.tkc-method-panel').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.panel === method);
                });
            });
        });
    }

    function initPayMoreForm(root) {
        const form = document.getElementById('ctbPayMoreForm');
        if (!form) {
            return;
        }

        const payUrl = root.dataset.payUrl;
        const csrf = root.dataset.csrf;
        const payBtn = document.getElementById('ctbPayMoreBtn');
        const statusEl = document.getElementById('ctbMoreStatus');

        function showStatus(message, type) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.className = 'tkc-status tkc-status--' + type;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const amount = document.getElementById('ctb-more-amount')?.value;
            if (!amount) {
                showStatus('Enter an amount.', 'error');
                return;
            }

            const methodTab = root.querySelector('.tkc-method-tab.is-active');
            const method = methodTab ? methodTab.dataset.method : 'mobile_money';

            const payload = { amount: amount, payment_method: method };

            if (method === 'mobile_money') {
                const providerInput = root.querySelector('input[name="provider"]:checked');
                payload.provider = providerInput ? providerInput.value : 'mtn';
                payload.momo_phone = document.getElementById('ctb-more-momo-phone')?.value.trim();
            } else {
                payload.bank_name = document.getElementById('ctb-more-bank')?.value.trim();
            }

            payBtn.disabled = true;
            showStatus('Starting payment…', 'pending');

            try {
                const response = await fetch(payUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    const firstError = data.errors
                        ? Object.values(data.errors).flat()[0]
                        : null;
                    showStatus(firstError || data.message || 'Could not start payment. Please try again.', 'error');
                    payBtn.disabled = false;
                    return;
                }

                showStatus('Payment started — refreshing…', 'success');
                window.location.reload();
            } catch (err) {
                showStatus('Network error. Please check your connection and try again.', 'error');
                payBtn.disabled = false;
            }
        });
    }
})();
