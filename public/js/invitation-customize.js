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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
