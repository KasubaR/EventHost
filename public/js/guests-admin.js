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
                    var label = btn.querySelector('[data-copy-label-text]') || btn;
                    if (ok) {
                        label.textContent = 'Copied';
                        window.setTimeout(function () {
                            label.textContent = prev;
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

    function initMoreMenus(root) {
        var hosts = root.querySelectorAll('[data-evt-more]');
        if (!hosts.length) {
            return;
        }

        var openHost = null;

        function menuOf(host) {
            return host.querySelector('[data-evt-more-menu]') || host._evtMoreMenu;
        }

        function close() {
            if (!openHost) {
                return;
            }

            var menu = openHost._evtMoreMenu || openHost.querySelector('[data-evt-more-menu]');
            var toggle = openHost.querySelector('[data-evt-more-toggle]');

            if (menu) {
                menu.hidden = true;
                openHost.appendChild(menu);
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }

            openHost = null;
        }

        function open(host) {
            var toggle = host.querySelector('[data-evt-more-toggle]');
            var menu = menuOf(host);
            if (!toggle || !menu) {
                return;
            }

            close();

            host._evtMoreMenu = menu;
            menu.hidden = false;
            document.body.appendChild(menu);

            var rect = toggle.getBoundingClientRect();
            var width = menu.offsetWidth;
            var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
            var top = rect.bottom + 6;

            if (top + menu.offsetHeight > window.innerHeight - 8) {
                top = Math.max(8, rect.top - menu.offsetHeight - 6);
            }

            menu.style.top = top + 'px';
            menu.style.left = left + 'px';
            toggle.setAttribute('aria-expanded', 'true');
            openHost = host;
        }

        hosts.forEach(function (host) {
            var toggle = host.querySelector('[data-evt-more-toggle]');
            var menu = host.querySelector('[data-evt-more-menu]');
            if (!toggle || !menu) {
                return;
            }

            toggle.hidden = false;
            menu.hidden = true;
            menu.setAttribute('role', 'menu');

            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                if (openHost === host) {
                    close();
                    return;
                }
                open(host);
            });
        });

        document.addEventListener('click', function (event) {
            if (!openHost) {
                return;
            }
            var menu = openHost._evtMoreMenu;
            if (menu && menu.contains(event.target)) {
                return;
            }
            close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });

        window.addEventListener('resize', close);
        document.addEventListener('scroll', close, true);

        return { close: close };
    }

    function qrPngBlob(img, done) {
        var canvas = document.createElement('canvas');
        var size = 512;
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        if (!ctx) {
            done(null);
            return;
        }
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.drawImage(img, 0, 0, size, size);
        canvas.toBlob(done, 'image/png');
    }

    function initQrLightbox(root, moreMenu) {
        var lightbox = root.querySelector('[data-evt-qr-lightbox]');
        if (!lightbox) {
            return;
        }

        var titleEl = lightbox.querySelector('[data-evt-qr-title]');
        var imgEl = lightbox.querySelector('[data-evt-qr-img]');
        var downloadEl = lightbox.querySelector('[data-evt-qr-download]');
        var shareEl = lightbox.querySelector('[data-evt-qr-share]');
        var closeEls = lightbox.querySelectorAll('[data-evt-qr-close]');
        var lastFocus = null;
        var objectUrl = null;

        function canShareFiles() {
            return !!(navigator.share && navigator.canShare);
        }

        if (shareEl && typeof navigator.share === 'function') {
            shareEl.hidden = false;
        }

        function revokeObjectUrl() {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        }

        function closeLightbox() {
            if (lightbox.hidden) {
                return;
            }
            lightbox.hidden = true;
            document.body.classList.remove('evt-qr-lightbox-open');
            if (imgEl) {
                imgEl.removeAttribute('src');
                imgEl.alt = '';
            }
            revokeObjectUrl();
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
            lastFocus = null;
        }

        function openLightbox(trigger) {
            var src = trigger.getAttribute('href');
            var name = trigger.getAttribute('data-qr-name') || 'Guest';
            var filename = trigger.getAttribute('data-qr-filename') || 'guest-qr.png';
            if (!src || !imgEl || !titleEl) {
                return;
            }

            lastFocus = document.activeElement;
            titleEl.textContent = name;
            imgEl.alt = 'Check-in QR for ' + name;
            imgEl.src = src;
            if (downloadEl) {
                downloadEl.setAttribute('href', src);
                downloadEl.setAttribute('download', filename);
            }
            lightbox.hidden = false;
            document.body.classList.add('evt-qr-lightbox-open');
            var closeBtn = lightbox.querySelector('.evt-qr-lightbox-close');
            if (closeBtn) {
                closeBtn.focus();
            }
        }

        root.querySelectorAll('[data-evt-qr-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                if (moreMenu && typeof moreMenu.close === 'function') {
                    moreMenu.close();
                }
                openLightbox(trigger);
            });
        });

        closeEls.forEach(function (el) {
            el.addEventListener('click', closeLightbox);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !lightbox.hidden) {
                event.stopPropagation();
                closeLightbox();
            }
        });

        if (downloadEl) {
            downloadEl.addEventListener('click', function (event) {
                if (!imgEl || !imgEl.src || !imgEl.complete) {
                    return;
                }
                event.preventDefault();
                qrPngBlob(imgEl, function (blob) {
                    if (!blob) {
                        window.location.href = downloadEl.getAttribute('href');
                        return;
                    }
                    revokeObjectUrl();
                    objectUrl = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = objectUrl;
                    link.download = downloadEl.getAttribute('download') || 'guest-qr.png';
                    link.click();
                });
            });
        }

        if (shareEl) {
            shareEl.addEventListener('click', function () {
                if (!imgEl || !imgEl.src || typeof navigator.share !== 'function') {
                    return;
                }

                var name = titleEl ? titleEl.textContent : 'Guest';
                var filename = (downloadEl && downloadEl.getAttribute('download')) || 'guest-qr.png';
                var payload = {
                    title: 'Check-in QR for ' + name,
                    text: 'Check-in QR for ' + name,
                };

                function share(data) {
                    navigator.share(data).catch(function () {});
                }

                if (!imgEl.complete) {
                    share(payload);
                    return;
                }

                qrPngBlob(imgEl, function (blob) {
                    if (!blob) {
                        share(payload);
                        return;
                    }
                    var file = new File([blob], filename, { type: 'image/png' });
                    if (canShareFiles() && navigator.canShare({ files: [file] })) {
                        payload.files = [file];
                    }
                    share(payload);
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmForms();
        initCopyButtons(document);
        initBulkSelect(document);
        initBulkActionInputs(document);
        var moreMenu = initMoreMenus(document);
        initQrLightbox(document, moreMenu);
    });
})();
