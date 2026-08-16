(function () {
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-checkout-root]');
        if (!root) {
            return;
        }

        const checkoutUrl = root.dataset.checkoutUrl;
        const orderStatusBase = root.dataset.orderStatusBase;
        const csrf = root.dataset.csrf;
        const expiresAt = root.dataset.holdExpiresAt ? new Date(root.dataset.holdExpiresAt) : null;

        const payBtn = document.getElementById('tkcPayBtn');
        const statusEl = document.getElementById('tkcStatus');
        const timerEl = document.getElementById('tkcHoldTimer');
        const timerValueEl = document.getElementById('tkcHoldTimerValue');

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

        if (expiresAt && timerEl && timerValueEl) {
            const tick = () => {
                const remainingMs = expiresAt.getTime() - Date.now();
                if (remainingMs <= 0) {
                    timerValueEl.textContent = '0:00';
                    timerEl.classList.add('is-urgent');
                    showStatus('Your hold has expired. Please choose your tickets again.', 'error');
                    if (payBtn) payBtn.disabled = true;
                    clearInterval(intervalId);
                    return;
                }
                const totalSeconds = Math.floor(remainingMs / 1000);
                const minutes = Math.floor(totalSeconds / 60);
                const seconds = totalSeconds % 60;
                timerValueEl.textContent = minutes + ':' + String(seconds).padStart(2, '0');
                if (remainingMs <= 60000) {
                    timerEl.classList.add('is-urgent');
                }
            };
            tick();
            const intervalId = setInterval(tick, 1000);
        }

        function showStatus(message, type) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.className = 'tkc-status tkc-status--' + type;
        }

        if (payBtn) {
            payBtn.addEventListener('click', submitCheckout);
        }

        async function submitCheckout() {
            const name = document.getElementById('tkc-name')?.value.trim();
            const email = document.getElementById('tkc-email')?.value.trim();
            const phone = document.getElementById('tkc-phone')?.value.trim();

            if (!name || !email) {
                showStatus('Please enter your name and email.', 'error');
                return;
            }

            const methodTab = root.querySelector('.tkc-method-tab.is-active');
            const method = methodTab ? methodTab.dataset.method : 'mobile_money';

            const payload = {
                name: name,
                email: email,
                phone: phone,
                payment_method: method,
            };

            if (method === 'mobile_money') {
                const providerInput = root.querySelector('input[name="provider"]:checked');
                payload.provider = providerInput ? providerInput.value : 'mtn';
                payload.momo_phone = document.getElementById('tkc-momo-phone')?.value.trim();
            } else {
                payload.bank_name = document.getElementById('tkc-bank')?.value.trim();
            }

            payBtn.disabled = true;
            showStatus('Starting payment…', 'pending');

            try {
                const response = await fetch(checkoutUrl, {
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

                showStatus('Redirecting to confirm your payment…', 'success');
                window.location.href = orderStatusBase + '/' + encodeURIComponent(data.order_reference);
            } catch (err) {
                showStatus('Network error. Please check your connection and try again.', 'error');
                payBtn.disabled = false;
            }
        }
    });
})();
