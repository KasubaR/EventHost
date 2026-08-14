/**
 * Event edit page — one button that saves every form on the page.
 *
 * The event details and the invitation design are two separate endpoints with
 * their own validation and their own transaction, so they still post separately;
 * this just submits them in order from a single click and reports the result in
 * one place. Publishing runs the same save-all pass first, so an event can never
 * go public with the edits still sitting unsaved in the form.
 *
 * The per-form save buttons are hidden here rather than in the Blade template, so
 * they remain the working fallback when this script does not run.
 */
(function () {
    'use strict';

    const bar = document.getElementById('evt-save-all-bar');
    if (!bar) return;

    const detailsForm = document.getElementById('event-update-form');
    const designForm = document.querySelector('.evt-design-form');
    const forms = [detailsForm, designForm].filter(Boolean);
    if (forms.length === 0) return;

    // Only now that we know the script is live do we drop the per-form buttons.
    document.querySelectorAll('.evt-per-form-actions').forEach((el) => {
        el.hidden = true;
    });

    const buttons = Array.from(bar.querySelectorAll('[data-save-all]'));

    function clearErrors() {
        const existing = document.getElementById('evt-save-all-errors');
        if (existing) existing.remove();
        document.querySelectorAll('.profile-input--error').forEach((el) => {
            el.classList.remove('profile-input--error');
        });
    }

    function showErrors(errors) {
        const box = document.createElement('div');
        box.id = 'evt-save-all-errors';
        box.className = 'profile-field-error evt-flash';
        box.setAttribute('role', 'alert');

        const messages = [];
        Object.keys(errors).forEach((field) => {
            const list = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
            list.forEach((m) => messages.push(m));

            // Highlight the offending control where the name maps cleanly.
            const input = document.querySelector('[name="' + CSS.escape(field) + '"]');
            if (input) input.classList.add('profile-input--error');
        });

        box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' +
            messages.map((m) => String(m).replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]))).join('<br>');

        const stack = document.querySelector('.evt-stack') || document.body;
        stack.insertBefore(box, stack.firstChild);
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setBusy(busy, label) {
        buttons.forEach((b) => {
            b.disabled = busy;
            if (busy && label && b.dataset.busyLabel === undefined) {
                b.dataset.originalHtml = b.innerHTML;
                b.dataset.busyLabel = '1';
                b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + label;
            } else if (!busy && b.dataset.busyLabel !== undefined) {
                b.innerHTML = b.dataset.originalHtml;
                delete b.dataset.busyLabel;
                delete b.dataset.originalHtml;
            }
        });
    }

    async function postForm(form) {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (response.status === 422) {
            const payload = await response.json().catch(() => ({}));
            return { ok: false, errors: payload.errors || { form: [payload.message || 'Please check the highlighted fields.'] } };
        }

        if (!response.ok) {
            return { ok: false, errors: { form: ['Could not save (server returned ' + response.status + ').'] } };
        }

        return { ok: true };
    }

    async function saveAll(publish) {
        clearErrors();
        setBusy(true, publish ? 'Publishing…' : 'Saving…');

        try {
            for (const form of forms) {
                const result = await postForm(form);
                if (!result.ok) {
                    setBusy(false);
                    showErrors(result.errors);
                    return;
                }
            }

            if (publish) {
                // Native submit so the browser follows the redirect to the public page.
                const publishForm = document.createElement('form');
                publishForm.method = 'POST';
                publishForm.action = bar.dataset.publishUrl;
                publishForm.innerHTML =
                    '<input type="hidden" name="_method" value="PATCH">' +
                    '<input type="hidden" name="_token" value="' + (document.querySelector('meta[name="csrf-token"]')?.content || '') + '">';
                document.body.appendChild(publishForm);
                publishForm.submit();
                return;
            }

            window.location.reload();
        } catch (e) {
            setBusy(false);
            showErrors({ form: ['Could not reach the server. Check your connection and try again.'] });
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => saveAll(button.hasAttribute('data-publish')));
    });
})();
