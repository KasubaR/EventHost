(function () {
    const POLL_FAST_MS = 5000;
    const POLL_SLOW_MS = 10000;
    const FAST_POLL_COUNT = 12;
    const MAX_POLLS = 36;

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('.billing-page');
        if (!root) {
            return;
        }

        const initiateUrl = root.dataset.initiateUrl;
        const verifyUrl = root.dataset.verifyUrl;
        const verifyRefUrl = root.dataset.verifyRefUrl;
        const eventsUrl = root.dataset.eventsUrl;
        const csrf = root.dataset.csrf;

        const payBtn = document.getElementById('billingPayBtn');
        const statusEl = document.getElementById('billingStatus');
        const instructionsEl = document.getElementById('billingInstructions');
        const bankDetailsEl = document.getElementById('billingBankDetails');

        let pollTimer = null;
        let pollCount = 0;

        const summaryPlanEl  = document.getElementById('billingSummaryPlan');
        const summaryPriceEl = document.getElementById('billingSummaryPrice');

        function syncSummary(card) {
            if (summaryPlanEl && card.dataset.planLabel)  summaryPlanEl.textContent  = card.dataset.planLabel;
            if (summaryPriceEl && card.dataset.planPrice) summaryPriceEl.textContent = card.dataset.planPrice;
        }

        root.querySelectorAll('.billing-plan-card:not(.is-static)').forEach((card) => {
            card.addEventListener('click', () => {
                root.querySelectorAll('.billing-plan-card').forEach((c) => c.classList.remove('is-selected'));
                card.classList.add('is-selected');
                const input = card.querySelector('.billing-plan-input');
                if (input) {
                    input.checked = true;
                }
                syncSummary(card);
            });
        });

        root.querySelectorAll('.billing-method-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                const method = tab.dataset.method;
                root.querySelectorAll('.billing-method-tab').forEach((t) => {
                    t.classList.toggle('is-active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                root.querySelectorAll('.billing-method-panel').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.panel === method);
                });
            });
        });

        if (payBtn) {
            payBtn.addEventListener('click', initiatePayment);
        }

        function showStatus(message, type) {
            statusEl.textContent = message;
            statusEl.className = 'billing-status billing-status--' + type;
        }

        function hideExtras() {
            instructionsEl.classList.add('is-hidden');
            bankDetailsEl.classList.add('is-hidden');
            instructionsEl.textContent = '';
            bankDetailsEl.innerHTML = '';
        }

        function stopPolling() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            pollCount = 0;
        }

        function pollDelayMs() {
            return pollCount < FAST_POLL_COUNT ? POLL_FAST_MS : POLL_SLOW_MS;
        }

        async function initiatePayment() {
            stopPolling();
            hideExtras();
            statusEl.classList.remove('is-hidden');
            showStatus('Starting payment…', 'pending');
            payBtn.disabled = true;

            const planInput = root.querySelector('input[name="plan_key"]:checked');
            const methodTab = root.querySelector('.billing-method-tab.is-active');
            const method = methodTab ? methodTab.dataset.method : 'mobile_money';

            const payload = {
                plan_key: planInput ? planInput.value : 'pro',
                payment_method: method,
            };

            if (method === 'mobile_money') {
                const providerInput = root.querySelector('input[name="provider"]:checked');
                const phoneInput = document.getElementById('billing-phone');
                payload.provider = providerInput ? providerInput.value : 'mtn';
                payload.phone = phoneInput ? phoneInput.value.trim() : '';
            } else {
                const bankSelect = document.getElementById('billing-bank');
                payload.bank_name = bankSelect ? bankSelect.value : '';
            }

            try {
                const response = await fetch(initiateUrl, {
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
                    showStatus(data.message || 'Could not start payment. Please try again.', 'error');
                    payBtn.disabled = false;
                    return;
                }

                if (data.payment_instructions) {
                    instructionsEl.textContent = data.payment_instructions;
                    instructionsEl.classList.remove('is-hidden');
                }

                if (data.bank_details && typeof data.bank_details === 'object') {
                    const details = data.bank_details;
                    bankDetailsEl.innerHTML = Object.keys(details)
                        .map((key) => '<dl><dt>' + key + '</dt><dd>' + details[key] + '</dd></dl>')
                        .join('');
                    bankDetailsEl.classList.remove('is-hidden');
                }

                if (data.status === 'completed') {
                    showStatus('Payment successful! Redirecting…', 'success');
                    window.location.href = eventsUrl;
                    return;
                }

                if (data.status === 'queued' || !data.transaction_id) {
                    if (data.payment_reference) {
                        showStatus('Payment is being processed. Checking status…', 'pending');
                        schedulePoll(verifyRefUrl + '/' + encodeURIComponent(data.payment_reference));
                    }
                    return;
                }

                showStatus('Complete the payment on your phone or bank app. Waiting for confirmation…', 'pending');
                schedulePoll(verifyUrl + '/' + encodeURIComponent(data.transaction_id));
            } catch (err) {
                showStatus('Network error. Please check your connection and try again.', 'error');
                payBtn.disabled = false;
            }
        }

        function schedulePoll(url) {
            stopPolling();
            pollCount = 0;
            pollVerify(url);
        }

        async function pollVerify(url) {
            pollCount++;

            try {
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();

                if (data.status === 'completed') {
                    stopPolling();
                    showStatus('Payment successful! Redirecting…', 'success');
                    window.location.href = data.redirect_url || eventsUrl;
                    return;
                }

                if (data.status === 'failed' || data.status === 'cancelled') {
                    stopPolling();
                    showStatus(data.failure_reason || 'Payment was not completed. Please try again.', 'error');
                    payBtn.disabled = false;
                    return;
                }

                if (pollCount >= MAX_POLLS) {
                    stopPolling();
                    showStatus('Payment timed out. If you were charged, contact support with your reference.', 'error');
                    payBtn.disabled = false;
                    return;
                }

                pollTimer = setTimeout(() => pollVerify(url), pollDelayMs());
            } catch (err) {
                if (pollCount >= MAX_POLLS) {
                    stopPolling();
                    showStatus('Could not confirm payment. Please contact support if you were charged.', 'error');
                    payBtn.disabled = false;
                    return;
                }

                pollTimer = setTimeout(() => pollVerify(url), pollDelayMs());
            }
        }
    });
})();
