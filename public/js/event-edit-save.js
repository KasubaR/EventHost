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

    /**
     * Force-preview gate: a host must open the real invitation preview at
     * least once before "Save & publish" is clickable. Any edit made after
     * that re-locks it — the preview they looked at is stale otherwise.
     * Client-side only (same trust level as the confirm() dialogs below);
     * there is no server-side enforcement of this.
     */
    const previewLink = document.getElementById('evt-preview-link');
    const publishButtons = Array.from(bar.querySelectorAll('[data-requires-preview]'));
    const previewHint = bar.querySelector('[data-preview-required-hint]');
    let previewed = false;

    function updatePublishGate() {
        publishButtons.forEach((b) => {
            b.disabled = !previewed;
            b.title = previewed ? '' : 'Preview your invitation above before publishing.';
        });
        if (previewHint) previewHint.hidden = previewed;
    }

    if (previewLink && publishButtons.length > 0) {
        updatePublishGate();

        previewLink.addEventListener('click', () => {
            previewed = true;
            updatePublishGate();
        });

        forms.forEach((form) => {
            form.addEventListener('change', () => {
                if (!previewed) return;
                previewed = false;
                updatePublishGate();
            });
            form.addEventListener('input', () => {
                if (!previewed) return;
                previewed = false;
                updatePublishGate();
            });
        });
    }

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
            if (busy && label) {
                // Only capture the original once — a save can pass through more
                // than one label ("Finishing uploads…" then "Saving…").
                if (b.dataset.busyLabel === undefined) {
                    b.dataset.originalHtml = b.innerHTML;
                    b.dataset.busyLabel = '1';
                }
                b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + label;
            } else if (!busy && b.dataset.busyLabel !== undefined) {
                b.innerHTML = b.dataset.originalHtml;
                delete b.dataset.busyLabel;
                delete b.dataset.originalHtml;
            }
        });

        // The loop above just force-enabled every button, including any
        // publish button the preview gate has locked — reassert the gate.
        if (!busy) updatePublishGate();
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

        // A plain redirect (the common case) is followed transparently by
        // fetch() and lands here with an empty/HTML body — .json() on that
        // rejects, which is fine, it just means there is nothing to report.
        // The one case with something to report — venue/location changed on
        // a live event with guests already invited/RSVP'd — returns real
        // JSON directly instead of a redirect specifically so it survives
        // the fetch, see EventController::update().
        const payload = await response.json().catch(() => null);

        return { ok: true, notifyGuests: payload && payload.notify_guests ? payload.notify_guests : null };
    }

    /**
     * Images upload as they are picked (js/media-uploader.js), so a save fired
     * mid-upload would post ids for files the server has not finished receiving.
     * Wait them out rather than racing them.
     */
    function waitForUploads() {
        if (!window.MediaUploader || window.MediaUploader.pending() === 0) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            const poll = setInterval(() => {
                if (window.MediaUploader.pending() === 0) {
                    clearInterval(poll);
                    resolve();
                }
            }, 150);
        });
    }

    async function saveAll(publish) {
        if (publish) {
            const publishConfirm = bar.dataset.publishConfirm;
            if (publishConfirm && !window.confirm(publishConfirm)) {
                return;
            }
        }

        // Saves go through fetch(), so a data-confirm on the form would never
        // fire — the confirm for a chargeable edit has to live here.
        const redefineConfirm = bar.dataset.redefineConfirm;
        if (redefineConfirm && !window.confirm(redefineConfirm)) {
            return;
        }

        if (window.MediaUploader && window.MediaUploader.failed() > 0) {
            const count = window.MediaUploader.failed();
            const message = count === 1
                ? 'One image failed to upload and will not be saved. Save anyway?'
                : count + ' images failed to upload and will not be saved. Save anyway?';
            if (!window.confirm(message)) {
                return;
            }
        }

        clearErrors();

        if (window.MediaUploader && window.MediaUploader.pending() > 0) {
            setBusy(true, 'Finishing uploads…');
            await waitForUploads();
        }

        setBusy(true, publish ? 'Publishing…' : 'Saving…');

        try {
            let notifyGuests = null;

            for (const form of forms) {
                const result = await postForm(form);
                if (!result.ok) {
                    setBusy(false);
                    showErrors(result.errors);
                    return;
                }
                if (result.notifyGuests) {
                    notifyGuests = result.notifyGuests;
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

            if (notifyGuests && notifyGuests.count > 0) {
                const noun = notifyGuests.count === 1 ? 'guest already has' : 'guests already have';
                const goToGuests = window.confirm(
                    'You changed the venue or location. ' + notifyGuests.count + ' ' + noun +
                    ' an invitation or RSVP for this event and will not be told automatically. ' +
                    'Go to the guest list to notify them now?'
                );
                if (goToGuests) {
                    window.location.href = notifyGuests.url;
                    return;
                }
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
