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
    });
});
