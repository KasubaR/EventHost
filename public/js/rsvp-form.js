document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form.rsvp-form').forEach(function (form) {
        form.addEventListener('change', function (event) {
            var target = event.target;

            if (!target || target.name !== 'status') {
                return;
            }

            var count = form.querySelector('select[name="attendee_count"]');

            if (!count) {
                return;
            }

            if (target.value === 'accepted') {
                if (count.value === '0' && count.querySelector('option[value="1"]')) {
                    count.value = '1';
                }

                return;
            }

            if (count.querySelector('option[value="0"]')) {
                count.value = '0';
            }
        });

        initSubmitLock(form);
    });

    // One submit per press: a second tap while the first is in flight is swallowed, the button says it is
    // sending, and a hung or failed request gives the guest a way to try again. Preview forms never submit.
    function initSubmitLock(form) {
        if (form.hasAttribute('data-rsvp-preview')) {
            return;
        }

        var button = form.querySelector('button[type="submit"]');
        var submitting = false;
        var slowTimer = null;
        var retryTimer = null;
        var originalHtml = button ? button.innerHTML : '';
        var note = null;

        function unlock() {
            submitting = false;
            clearTimeout(slowTimer);
            clearTimeout(retryTimer);

            if (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.innerHTML = originalHtml;
            }

            if (note && note.parentNode) {
                note.parentNode.removeChild(note);
            }

            note = null;
        }

        form.addEventListener('submit', function (event) {
            if (submitting) {
                event.preventDefault();

                return;
            }

            // Native validation failed: the browser blocks the submit itself, so do not lock.
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                return;
            }

            submitting = true;

            if (!button) {
                return;
            }

            // Disable after the browser has captured the submit; disabling fields would drop them from the post.
            setTimeout(function () {
                if (!submitting) {
                    return;
                }

                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = 'Sending…';
            }, 0);

            slowTimer = setTimeout(function () {
                if (!submitting || note) {
                    return;
                }

                note = document.createElement('p');
                note.className = 'rsvp-field-hint';
                note.setAttribute('role', 'status');
                note.textContent = 'Still sending. Check your connection. Your answer is not lost.';
                button.parentNode.insertBefore(note, button.nextSibling);
            }, 8000);

            // Give up waiting so a dead connection does not leave the button stuck for good. The server
            // ignores an identical resubmit, so trying again is safe.
            retryTimer = setTimeout(unlock, 25000);
        });

        // Back/forward cache restores the page exactly as it was left, with the button disabled.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                unlock();
            }
        });
    }
});
