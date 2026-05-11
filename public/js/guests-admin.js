(function () {
    'use strict';

    function initConfirmForms() {
        document.querySelectorAll('form.evt-confirm-form[data-evt-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var msg = form.getAttribute('data-evt-confirm');
                if (msg && !window.confirm(msg)) {
                    event.preventDefault();
                }
            });
        });
    }

    function initCopyButtons(root) {
        root.querySelectorAll('[data-copy-text]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy-text');
                if (!text) {
                    return;
                }

                function done(ok) {
                    btn.setAttribute('aria-busy', 'false');
                    var prev = btn.getAttribute('data-copy-label') || 'Copy link';
                    if (ok) {
                        btn.textContent = 'Copied';
                        window.setTimeout(function () {
                            btn.textContent = prev;
                        }, 1600);
                    }
                }

                btn.setAttribute('aria-busy', 'true');

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        done(true);
                    }).catch(function () {
                        done(false);
                    });
                } else {
                    done(false);
                }
            });
        });
    }

    function initBulkSelect(root) {
        var table = root.querySelector('table[data-evt-guest-bulk-table]');
        if (!table) {
            return;
        }

        var master = table.querySelector('[data-evt-select-all-guests]');
        var boxes = table.querySelectorAll('[data-evt-guest-select]');

        if (!master || !boxes.length) {
            return;
        }

        master.addEventListener('change', function () {
            var checked = master.checked;
            boxes.forEach(function (cb) {
                cb.checked = checked;
            });
        });

        boxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                var allOn = Array.prototype.every.call(boxes, function (b) {
                    return b.checked;
                });
                master.checked = allOn;
            });
        });
    }

    function initBulkActionInputs(root) {
        var actionSelect = root.getElementById('bulk_action_select');
        var groupSelect = root.getElementById('bulk_group_select');
        var daysSelect = root.getElementById('bulk_days_until');
        var updateInput = root.getElementById('bulk_update_message');

        if (!actionSelect || !groupSelect || !daysSelect || !updateInput) {
            return;
        }

        function applyActionState() {
            var action = actionSelect.value;
            var needsGroup = action === 'assign_group';
            var needsDays = action === 'send_reminder_email';
            var needsUpdate = action === 'send_update_email';

            groupSelect.disabled = !needsGroup;
            daysSelect.disabled = !needsDays;
            updateInput.disabled = !needsUpdate;
            updateInput.required = needsUpdate;

            if (!needsUpdate) {
                updateInput.value = '';
            }
        }

        actionSelect.addEventListener('change', applyActionState);
        applyActionState();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmForms();
        initCopyButtons(document);
        initBulkSelect(document);
        initBulkActionInputs(document);
    });
})();
