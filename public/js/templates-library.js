/**
 * Template library filters: picking a category applies it straight away, the same
 * as clicking a plan tab. The Search button still submits typed search text.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('form.tpl-filters select[data-tpl-autosubmit]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (select.form) select.form.requestSubmit();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
