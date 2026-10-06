(function () {
    'use strict';

    function initCalendarMenu() {
        var wrap = document.querySelector('[data-rsvp-calendar-menu]');
        if (!wrap) {
            return;
        }

        var trigger = wrap.querySelector('[data-rsvp-calendar-trigger]');
        var panel = wrap.querySelector('[data-rsvp-calendar-panel]');
        if (!trigger || !panel) {
            return;
        }

        // Facebook and Instagram's in-app browsers often cannot open these links or hand a file to the calendar app.
        if (/FBAN|FBAV|FB_IAB|Instagram/i.test(navigator.userAgent || '') && !panel.querySelector('.rsvp-inapp-hint')) {
            var hint = document.createElement('p');
            hint.className = 'rsvp-inapp-hint';
            hint.textContent = 'If a link does not open, use the menu to open this page in your browser.';
            panel.insertBefore(hint, panel.firstChild);
        }

        function close() {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        function open() {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });

        document.addEventListener('click', function (event) {
            if (!panel.hidden && !wrap.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                close();
                trigger.focus();
            }
        });
    }

    function initShareButton() {
        var btn = document.querySelector('[data-rsvp-share]');
        if (!btn) {
            return;
        }

        var label = btn.querySelector('[data-rsvp-share-label]');
        var url = btn.getAttribute('data-share-url');
        var title = btn.getAttribute('data-share-title') || document.title;

        if (!url) {
            return;
        }

        function setLabel(text, revertAfterMs) {
            if (!label) {
                return;
            }
            var prev = label.textContent;
            label.textContent = text;
            if (revertAfterMs) {
                window.setTimeout(function () {
                    label.textContent = prev;
                }, revertAfterMs);
            }
        }

        btn.addEventListener('click', function () {
            if (typeof navigator.share === 'function') {
                navigator.share({ title: title, url: url }).catch(function () {});
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    setLabel('Copied', 1600);
                }).catch(function () {});
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCalendarMenu();
        initShareButton();
    });
})();
