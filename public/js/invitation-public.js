(function () {
    function pad(n) {
        var text = String(n);

        return text.length < 2 ? '0' + text : text;
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
        if (!wrap) {
            return;
        }

        // The slider library did not load (blocked, or the request failed): leave the photos as a plain grid.
        if (typeof window.Swiper === 'undefined') {
            wrap.classList.add('evt-inv-gallery--static');
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

        // Creating the audio with its source up front would start fetching the file at once. Where media may not start by itself (a phone on a connection we
        // cannot measure, Save-Data, 2g) nothing is fetched until the guest taps the music button.
        var autoStart = mayStartMedia();
        var audio = new Audio();
        audio.loop = true;
        audio.preload = autoStart ? 'auto' : 'none';
        audio.src = src;

        // Show what is playing, next to whichever music button this layout draws.
        var caption = root.getAttribute('data-audio-caption');
        if (caption && btn.parentNode) {
            var song = document.createElement('span');
            song.className = 'evt-inv-audio-caption';
            song.textContent = caption;
            btn.parentNode.insertBefore(song, btn.nextSibling);
        }

        // Attach the copyright report link to whichever music button this layout draws.
        var reportUrl = root.getAttribute('data-audio-report-url');
        if (reportUrl && btn.parentNode) {
            var report = document.createElement('a');
            report.className = 'evt-inv-audio-report';
            report.href = reportUrl;
            report.rel = 'nofollow';
            report.textContent = 'Report this music';
            // After the song caption when there is one, so the order is button, song, report link.
            var after = btn.parentNode.querySelector('.evt-inv-audio-caption') || btn;
            btn.parentNode.insertBefore(report, after.nextSibling);
        }

        var label = btn.querySelector('.evt-inv-audio-label');
        var userPaused = false;

        function sync() {
            if (label) label.textContent = audio.paused ? 'Play music' : 'Pause music';
        }

        audio.addEventListener('play', sync);
        audio.addEventListener('pause', sync);

        btn.addEventListener('click', function () {
            if (audio.paused) {
                userPaused = false;
                audio.play().catch(function () {});
            } else {
                userPaused = true;
                audio.pause();
            }
        });

        // Browsers block sound before the visitor interacts with the page, so try
        // straight away and, if refused, start on the first tap or key press instead.
        // Only these count as a user gesture for audio; scroll and wheel do not.
        var events = ['pointerup', 'touchend', 'click', 'keydown'];
        function stopWaiting() {
            events.forEach(function (name) {
                window.removeEventListener(name, onFirstGesture, true);
            });
        }
        function onFirstGesture(e) {
            if (btn.contains(e.target)) { stopWaiting(); return; }
            stopWaiting();
            if (!userPaused && audio.paused) audio.play().catch(function () {});
        }

        if (!autoStart) {
            return;
        }

        audio.play().catch(function () {
            events.forEach(function (name) {
                window.addEventListener(name, onFirstGesture, { capture: true, passive: true });
            });
        });
    }

    // Facebook and Instagram's browsers often cannot open a link that leaves the page or hand a file to the calendar app. A guest
    // there sees one line saying how to get out; everywhere else nothing is added.
    function initInAppHint(root) {
        if (!isInAppBrowser()) {
            return;
        }
        var calendar = root.querySelector('.evt-calendar-actions');
        if (!calendar || calendar.querySelector('.evt-inv-inapp-hint')) {
            return;
        }
        var hint = document.createElement('p');
        hint.className = 'evt-inv-inapp-hint';
        hint.textContent = 'If a link does not open, use the menu to open this page in your browser.';
        calendar.insertBefore(hint, calendar.firstChild);
    }

    function initWeddingNoirReveal(root) {
        var nodes = root.querySelectorAll('[data-wi2-reveal]');
        if (nodes.length === 0) {
            return;
        }

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof IntersectionObserver === 'undefined') {
            nodes.forEach(function (node) {
                node.classList.add('wi2-reveal--visible');
            });

            return;
        }

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('wi2-reveal--visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12 }
        );

        nodes.forEach(function (node) {
            observer.observe(node);
        });
    }

    // Grid galleries of the layouts that override the gallery section. The shared
    // Swiper gallery (.evt-inv-gallery-lightbox) is wired up in initGallery().
    var LAYOUT_LIGHTBOX_SELECTORS = [
        '.wi-gallery-grid .glightbox',
        '.wi2-gallery-lightbox',
        '.mm-gallery-lightbox',
        '.mg-gallery-item',
        '.db-gallery .glightbox',
        '.evt-bg-gallery-lightbox',
    ];

    function initLayoutLightboxes(root) {
        if (typeof window.GLightbox === 'undefined') {
            return;
        }

        LAYOUT_LIGHTBOX_SELECTORS.forEach(function (selector) {
            var links = root.querySelectorAll(selector);
            if (links.length === 0) {
                return;
            }

            window.GLightbox({
                selector: selector,
                touchNavigation: true,
                loop: links.length > 1,
            });
        });
    }

    function initWeddingReveal(root) {
        var nodes = root.querySelectorAll('[data-wi-reveal]');
        if (nodes.length === 0) {
            return;
        }

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof IntersectionObserver === 'undefined') {
            nodes.forEach(function (node) {
                node.classList.add('wi-reveal--visible');
            });

            return;
        }

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('wi-reveal--visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.1 }
        );

        nodes.forEach(function (node) {
            observer.observe(node);
        });
    }

    function initEventInviteLights(root) {
        var wrap = root.querySelector('[data-ei-lights]');
        if (!wrap || wrap.childElementCount > 0) {
            return;
        }

        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var count = reduceMotion ? 10 : 22;

        for (var i = 0; i < count; i++) {
            var light = document.createElement('div');
            light.className = 'ei-light';
            light.style.left = Math.random() * 100 + '%';
            light.style.top = Math.random() * 100 + '%';
            if (!reduceMotion) {
                light.style.animationDelay = Math.random() * 3 + 's';
                light.style.animationDuration = 1.5 + Math.random() * 2 + 's';
            }
            var size = 4 + Math.random() * 5;
            light.style.width = size + 'px';
            light.style.height = size + 'px';
            wrap.appendChild(light);
        }
    }

    // A slow or metered connection should not pay for a background video nobody asked for. Unknown (no Network
    // Information API, e.g. Safari and Firefox) counts as fine.
    function connectionQuality() {
        var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!c) {
            return 'unknown';
        }
        if (c.saveData) {
            return 'no';
        }

        return c.effectiveType === 'slow-2g' || c.effectiveType === '2g' ? 'no' : 'yes';
    }

    // Facebook and Instagram's in-app browsers. WhatsApp's cannot be recognised (iOS sends no marker), so nothing here relies on
    // detecting it: a phone whose connection cannot be measured is treated the same way whatever app it is in.
    function isInAppBrowser() {
        return /FBAN|FBAV|FB_IAB|Instagram/i.test(navigator.userAgent || '');
    }

    // A desktop-class device: a mouse-like pointer and a wide window. Without matchMedia we cannot tell, so we say no.
    function isDesktopClass() {
        if (!window.matchMedia) {
            return false;
        }

        return window.matchMedia('(pointer: fine)').matches && window.matchMedia('(min-width: 900px)').matches;
    }

    // May media (a background video, the YouTube player, music) start by itself? The connection says yes or no outright when the
    // browser reports it. When it cannot be measured (iOS, Firefox, most in-app browsers) a phone is where a guest is likely to be
    // on cellular data, so only a desktop-class device starts on its own; a phone gets a button and one tap.
    function mayStartMedia() {
        var quality = connectionQuality();
        if (quality === 'yes') {
            return true;
        }
        if (quality === 'no') {
            return false;
        }

        return isDesktopClass() && !isInAppBrowser();
    }

    // Used by tests/js/invitation-media.cjs; nothing on the page depends on it.
    window.EventHostInvitation = { mayStartMedia: mayStartMedia, connectionQuality: connectionQuality, isInAppBrowser: isInAppBrowser };

    // The hero background video (a file, or a YouTube embed) is not in the page until it is wanted: the markup only
    // holds a placeholder. Start it on a normal connection; otherwise, or when the guest prefers reduced motion,
    // show a "Play video" button over the cover and start it on tap.
    function initHeroMedia(root) {
        var embed = root.querySelector('[data-inv-video-embed]');
        var video = root.querySelector('[data-inv-video]');
        var media = embed || video;
        if (!media) {
            return;
        }

        function startEmbed() {
            if (embed.querySelector('iframe')) {
                return;
            }
            var frame = document.createElement('iframe');
            frame.className = 'evt-inv-hero-video-iframe';
            frame.src = embed.getAttribute('data-embed-src');
            frame.title = 'Background video';
            frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
            frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            embed.appendChild(frame);
        }

        function startVideo() {
            var started = video.play();
            if (started && typeof started.catch === 'function') {
                started.catch(function () {});
            }
        }

        var start = embed ? startEmbed : startVideo;
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (mayStartMedia() && !reduceMotion) {
            start();
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'evt-inv-video-play';
        button.innerHTML = '<i class="fa-solid fa-play" aria-hidden="true"></i> Play video';
        button.addEventListener('click', function () {
            start();
            button.remove();
        });
        media.parentNode.appendChild(button);
    }

    // An image that cannot load (network drop, a 404 from the web server, a corrupt file) must not leave a broken-image
    // icon or a hole. A gallery photo is removed from its slide or grid cell; any other image (cover, portrait) gets a
    // calm theme-coloured block of the same shape, so text over it stays readable and nothing jumps.
    function initImageFallbacks(root) {
        function failedGalleryUnit(img) {
            var link = img.closest('a');
            var unit = img.closest('.swiper-slide');
            if (!unit && link && link.parentElement && link.parentElement !== root && link.parentElement.children.length === 1) {
                unit = link.parentElement;
            }

            return unit || link || img;
        }

        function onFailed(img) {
            // The small copy of a gallery photo is gone but the full-size file may be fine: try that once.
            if (img.hasAttribute('srcset') && !img.hasAttribute('data-inv-retried')) {
                img.setAttribute('data-inv-retried', '');
                img.removeAttribute('srcset');
                img.removeAttribute('sizes');
                img.src = img.getAttribute('src');

                return;
            }

            if (img.classList.contains('evt-inv-img-failed')) {
                return;
            }
            img.classList.add('evt-inv-img-failed');

            if (img.closest('a.glightbox, [data-inv-gallery], .glightbox')) {
                failedGalleryUnit(img).classList.add('evt-inv-img-gone');
                var slider = img.closest('.swiper');
                if (slider && slider.swiper && typeof slider.swiper.update === 'function') {
                    slider.swiper.update();
                }

                return;
            }

            // Keep the frame the image would have had, and drop the alt text so the browser does not print it.
            var w = parseInt(img.getAttribute('width'), 10);
            var h = parseInt(img.getAttribute('height'), 10);
            if (w > 0 && h > 0) {
                img.style.aspectRatio = w + ' / ' + h;
            }
            img.alt = '';
        }

        root.addEventListener('error', function (event) {
            if (event.target && event.target.tagName === 'IMG') {
                onFailed(event.target);
            }
        }, true);

        // An image that failed before this script ran has already fired its error. Only eager images are checked:
        // a lazy one that has not started loading also reports no size.
        root.querySelectorAll('img').forEach(function (img) {
            if (img.getAttribute('loading') !== 'lazy' && img.getAttribute('src') && img.complete && img.naturalWidth === 0) {
                onFailed(img);
            }
        });
    }

    // Every step runs on its own. An old or unusual browser that throws in one (the slider, say) must not stop the
    // others: above all the reveals, because a layout that hides its sections until they are revealed would stay blank.
    function safely(name, step) {
        try {
            step();
        } catch (error) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('invitation: ' + name + ' failed', error);
            }
        }
    }

    // Tells the stall guard in the page head that scripts are running; undoes it if it already fired.
    function markReady() {
        document.documentElement.setAttribute('data-inv-ready', '');
        document.documentElement.classList.remove('js-stalled');
    }

    function boot() {
        var roots = document.querySelectorAll('.evt-invitation');

        Array.prototype.forEach.call(roots, function (root) {
            // What reveals hidden content comes first. The page is marked ready right after, so the stall guard is
            // only cancelled once nothing is left hiding; a slow gallery or video start below can no longer matter.
            safely('wedding reveal', function () { initWeddingReveal(root); });
            safely('noir reveal', function () { initWeddingNoirReveal(root); });
            markReady();

            safely('countdown', function () { tickCountdown(root); });
            safely('image fallbacks', function () { initImageFallbacks(root); });
            safely('hero media', function () { initHeroMedia(root); });
            safely('audio', function () { bindAudio(root); });
            safely('gallery', function () { initGallery(root); });
            safely('event invite lights', function () { initEventInviteLights(root); });
            safely('layout lightboxes', function () { initLayoutLightboxes(root); });
            safely('in-app hint', function () { initInAppHint(root); });
        });

        // A page with no invitation on it still tells the guard scripts ran.
        markReady();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
