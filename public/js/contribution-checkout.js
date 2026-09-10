(function () {
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-contribute-root]');
        if (!root) {
            return;
        }

        const contributeUrl = root.dataset.contributeUrl;
        const statusBase = root.dataset.contributionStatusBase;
        const csrf = root.dataset.csrf;

        const payBtn = document.getElementById('ctbPayBtn');
        const statusEl = document.getElementById('ctbStatus');

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

        function showStatus(message, type) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.className = 'tkc-status tkc-status--' + type;
        }

        const form = document.getElementById('ctbContributeForm');
        if (form) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitContribution();
            });
        }

        async function submitContribution() {
            const name = document.getElementById('ctb-name')?.value.trim();
            const phone = document.getElementById('ctb-phone')?.value.trim();
            const email = document.getElementById('ctb-email')?.value.trim();
            const amount = document.getElementById('ctb-amount')?.value;

            if (!name || !phone || !amount) {
                showStatus('Please enter your name, phone number and an amount.', 'error');
                return;
            }

            const methodTab = root.querySelector('.tkc-method-tab.is-active');
            const method = methodTab ? methodTab.dataset.method : 'mobile_money';

            const payload = {
                name: name,
                phone: phone,
                email: email || null,
                amount: amount,
                payment_method: method,
            };

            if (method === 'mobile_money') {
                const providerInput = root.querySelector('input[name="provider"]:checked');
                payload.provider = providerInput ? providerInput.value : 'mtn';
                payload.momo_phone = document.getElementById('ctb-momo-phone')?.value.trim();
            } else {
                payload.bank_name = document.getElementById('ctb-bank')?.value.trim();
            }

            payBtn.disabled = true;
            showStatus('Starting payment…', 'pending');

            try {
                const response = await fetch(contributeUrl, {
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

                showStatus('Redirecting to confirm your payment…', 'success');
                window.location.href = statusBase + '/' + encodeURIComponent(data.reference);
            } catch (err) {
                showStatus('Network error. Please check your connection and try again.', 'error');
                payBtn.disabled = false;
            }
        }
    });
})();
