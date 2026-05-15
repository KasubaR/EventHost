(function () {
    function boot() {
        var root = document.getElementById('inv-section-sortable-root');
        if (!root || typeof Sortable === 'undefined') {
            return;
        }

        var list = root.querySelector('[data-inv-sortable-list]');
        if (!list) {
            return;
        }

        Sortable.create(list, {
            animation: 160,
            handle: '[data-inv-sort-handle]',
            draggable: '.evt-design-section-row',
        });
    }

    function initScheduleRows() {
        var scheduleList = document.getElementById('evt-schedule-list');
        var addBtn = document.getElementById('evt-schedule-add-btn');
        var tpl = document.getElementById('evt-schedule-row-tpl');
        if (!scheduleList || !addBtn || !tpl) return;

        var counter = scheduleList.querySelectorAll('.evt-design-schedule-row').length;

        function updateRemoveBtns() {
            var rows = scheduleList.querySelectorAll('.evt-design-schedule-row');
            var btns = scheduleList.querySelectorAll('[data-schedule-remove]');
            btns.forEach(function (btn) {
                btn.disabled = rows.length <= 1;
            });
        }

        addBtn.addEventListener('click', function () {
            var html = tpl.innerHTML.replace(/__IDX__/g, counter++);
            var li = document.createElement('li');
            li.className = 'evt-design-schedule-row';
            li.innerHTML = html;
            scheduleList.appendChild(li);
            li.querySelector('input').focus();
            updateRemoveBtns();
        });

        scheduleList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-schedule-remove]');
            if (!btn || btn.disabled) return;
            btn.closest('.evt-design-schedule-row').remove();
            updateRemoveBtns();
        });

        updateRemoveBtns();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); initScheduleRows(); });
    } else {
        boot();
        initScheduleRows();
    }
})();
