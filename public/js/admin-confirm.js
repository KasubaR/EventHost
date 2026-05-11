/**
 * Confirm destructive admin forms via data-confirm (no inline handlers).
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            const msg = form.getAttribute('data-confirm');
            if (typeof msg === 'string' && msg !== '' && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
});
