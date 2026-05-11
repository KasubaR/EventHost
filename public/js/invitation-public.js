(function () {
    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function tickCountdown(root) {
        var el = root.querySelector('[data-inv-countdown]');
        if (!el) return;

        var targetRaw = el.getAttribute('data-target');
        if (!targetRaw) return;

        var target = new Date(targetRaw);
        if (Number.isNaN(target.getTime())) return;

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var daysEl = el.querySelector('[data-inv-cd-days]');
        var hoursEl = el.querySelector('[data-inv-cd-hours]');
        var minutesEl = el.querySelector('[data-inv-cd-minutes]');
        var secondsEl = el.querySelector('[data-inv-cd-seconds]');
        var doneEl = el.querySelector('[data-inv-cd-done]');
        var grid = el.querySelector('.evt-inv-countdown-grid');
        var ringEls = el.querySelectorAll('[data-inv-cd-ring]');
        var botanicalRings = ringEls.length > 0;
        var initialDaysSnap = null;

        function ringCircumference(ringNode) {
            var r = 43;
            if (ringNode.r && ringNode.r.baseVal !== undefined) {
                r = ringNode.r.baseVal.value;
            } else {
                var rAttr = ringNode.getAttribute('r');
                if (rAttr) {
                    r = parseFloat(rAttr, 10);
                }
            }
            return 2 * Math.PI * r;
        }

        function setRingProgress(ringNode, fraction) {
            var c = ringNode._evtCirc || (ringNode._evtCirc = ringCircumference(ringNode));
            var f = Math.min(1, Math.max(0, fraction));
            ringNode.style.strokeDasharray = String(c);
            ringNode.style.strokeDashoffset = String(c * (1 - f));
        }

        function render() {
            var now = new Date();
            var diff = target.getTime() - now.getTime();

            if (diff <= 0) {
                if (daysEl) daysEl.textContent = '0';
                if (hoursEl) hoursEl.textContent = '0';
                if (minutesEl) minutesEl.textContent = '0';
                if (secondsEl) secondsEl.textContent = '0';
                if (doneEl) doneEl.classList.remove('evt-inv-countdown-done--hidden');
                if (grid) grid.style.display = 'none';
                if (botanicalRings) {
                    ringEls.forEach(function (ringNode) {
                        setRingProgress(ringNode, 0);
                    });
                }
                return false;
            }

            var totalSeconds = Math.floor(diff / 1000);
            var days = Math.floor(totalSeconds / 86400);
            var hours = Math.floor((totalSeconds % 86400) / 3600);
            var minutes = Math.floor((totalSeconds % 3600) / 60);
            var seconds = totalSeconds % 60;

            if (initialDaysSnap === null) {
                initialDaysSnap = days;
            }

            if (daysEl) daysEl.textContent = String(days);
            if (hoursEl) hoursEl.textContent = pad(hours);
            if (minutesEl) minutesEl.textContent = pad(minutes);
            if (secondsEl) secondsEl.textContent = pad(seconds);

            if (botanicalRings) {
                var dayFrac = initialDaysSnap > 0 ? days / initialDaysSnap : 0;
                var hourFrac = hours / 24;
                var minuteFrac = minutes / 60;
                var secondFrac = seconds / 60;

                ringEls.forEach(function (ringNode) {
                    var kind = ringNode.getAttribute('data-inv-cd-ring');
                    if (kind === 'days') {
                        setRingProgress(ringNode, dayFrac);
                    } else if (kind === 'hours') {
                        setRingProgress(ringNode, hourFrac);
                    } else if (kind === 'minutes') {
                        setRingProgress(ringNode, minuteFrac);
                    } else if (kind === 'seconds') {
                        setRingProgress(ringNode, secondFrac);
                    }
                });

                var daysRingNode = el.querySelector('[data-inv-cd-ring="days"]');
                if (daysRingNode) {
                    var daysWrap = daysRingNode.closest('.evt-bg-countdown-ring');
                    if (daysWrap) {
                        daysWrap.classList.toggle('evt-bg-countdown-ring--gone', days === 0);
                    }
                }
            }

            return true;
        }

        if (!render()) return;

        var intervalMs = reduceMotion ? 60000 : 1000;
        var intervalId = window.setInterval(function () {
            if (!render()) {
                window.clearInterval(intervalId);
            }
        }, intervalMs);
    }

    function initGallery(root) {
        var wrap = root.querySelector('[data-inv-gallery]');
        if (!wrap || typeof window.Swiper === 'undefined') {
            return;
        }

        var el = wrap.querySelector('.evt-inv-gallery-swiper');
        if (!el) {
            return;
        }

        var slides = wrap.querySelectorAll('.swiper-slide');
        var slideCount = slides.length;
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        new window.Swiper(el, {
            slidesPerView: 1.12,
            spaceBetween: 12,
            centeredSlides: slideCount > 1,
            loop: slideCount > 2 && !reduceMotion,
            speed: reduceMotion ? 0 : 400,
            pagination: {
                el: wrap.querySelector('.evt-inv-gallery-pagination'),
                clickable: true,
            },
            navigation: {
                nextEl: wrap.querySelector('.evt-inv-gallery-next'),
                prevEl: wrap.querySelector('.evt-inv-gallery-prev'),
            },
            breakpoints: {
                560: {
                    slidesPerView: Math.min(2, slideCount),
                    centeredSlides: false,
                },
                880: {
                    slidesPerView: Math.min(3, slideCount),
                    centeredSlides: false,
                },
            },
        });

        if (typeof window.GLightbox !== 'undefined') {
            window.GLightbox({
                selector: '.evt-inv-gallery-lightbox',
                touchNavigation: true,
                loop: slideCount > 1,
            });
        }
    }

    function bindAudio(root) {
        var btn = root.querySelector('[data-inv-audio-play]');
        if (!btn) return;

        var src = btn.getAttribute('data-audio-src');
        if (!src) return;

        var audio = new Audio(src);
        audio.loop = true;
        audio.preload = 'none';

        var label = btn.querySelector('.evt-inv-audio-label');

        btn.addEventListener('click', function () {
            if (audio.paused) {
                audio.play().catch(function () {});
                if (label) label.textContent = 'Pause music';
            } else {
                audio.pause();
                if (label) label.textContent = 'Play music';
            }
        });
    }

    function boot() {
        document.querySelectorAll('.evt-invitation').forEach(function (root) {
            tickCountdown(root);
            bindAudio(root);
            initGallery(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
