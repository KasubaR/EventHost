(function () {
    'use strict';

    // Per-credential-type config (Phase 17). The scan/camera/lookup mechanics
    // below are identical either way — only which result fields to show, which
    // JSON keys the response uses, and a couple of copy strings differ. Picked
    // once from data-checkin-kind, set by the server for a given event
    // (product_kind is mutually exclusive, so a page never needs to guess).
    var CONFIGS = {
        guest: {
            resultKey: 'guest',
            lookupKey: 'guests',
            lookupIdSegment: 'guest',
            notFoundMessage: 'Could not check in this guest.',
            nameOf: function (record) {
                return record.name;
            },
            detailFields: [
                ['Email', 'email'],
                ['Phone', 'phone'],
                ['Table', 'table'],
                ['Meal preference', 'meal_preference'],
                ['RSVP note', 'rsvp_note'],
            ],
        },
        ticket: {
            resultKey: 'ticket',
            lookupKey: 'tickets',
            lookupIdSegment: 'ticket',
            notFoundMessage: 'Could not check in this ticket.',
            nameOf: function (record) {
                return record.attendee_name || 'Ticket #' + record.id;
            },
            detailFields: [
                ['Ticket', 'ticket_type'],
                ['Email', 'attendee_email'],
                ['Phone', 'attendee_phone'],
                ['Order', 'order_reference'],
            ],
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('checkinScanner');
        if (!root || typeof jsQR === 'undefined') {
            return;
        }

        var config = CONFIGS[root.getAttribute('data-checkin-kind') || 'guest'] || CONFIGS.guest;

        var base = root.getAttribute('data-checkin-base') || '';
        var selfQrBase = root.getAttribute('data-self-qr-base') || '';
        var lookupUrl = root.getAttribute('data-lookup-url') || '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var video = document.getElementById('ckinVideo');
        var canvas = document.getElementById('ckinCanvas');
        var hint = document.getElementById('ckinCameraHint');
        var cameraPane = root.querySelector('[data-ckin-camera]');
        var resultPane = root.querySelector('[data-ckin-result-pane]');
        var resultBox = document.getElementById('ckinResult');
        var resultDetails = document.getElementById('ckinResultDetails');
        var scanAgainBtn = document.getElementById('ckinScanAgain');
        var lookupInput = document.getElementById('ckinLookupInput');
        var lookupResults = document.getElementById('ckinLookupResults');
        var arrivedStat = document.querySelector('[data-checkin-arrived]');
        var checkInOpen = root.getAttribute('data-checkin-open') === '1';

        var ctx = canvas ? canvas.getContext('2d', { willReadFrequently: true }) : null;
        var scanning = false;
        var lastToken = null;
        var lastAt = 0;
        var rafId = null;

        function bumpArrivedCount() {
            if (arrivedStat) {
                arrivedStat.textContent = String((parseInt(arrivedStat.textContent, 10) || 0) + 1);
            }
        }

        function showResult(kind, message) {
            if (!resultBox) {
                return;
            }
            resultBox.className = 'ckin-result ckin-result--' + kind;
            resultBox.textContent = message;
        }

        function showResultPane() {
            if (cameraPane) {
                cameraPane.hidden = true;
            }
            if (resultPane) {
                resultPane.hidden = false;
            }
            if (scanAgainBtn) {
                scanAgainBtn.hidden = false;
            }
        }

        function hideResultPane() {
            if (resultPane) {
                resultPane.hidden = true;
            }
            if (scanAgainBtn) {
                scanAgainBtn.hidden = true;
            }
            if (cameraPane) {
                cameraPane.hidden = false;
            }
            showResult('idle', '');
            showResultDetails(null);
        }

        // Built with createElement/textContent, not innerHTML — guest/attendee
        // entered fields (name, notes, meal preference…) are untrusted strings
        // and must never be parsed as markup.
        function showResultDetails(record) {
            if (!resultDetails) {
                return;
            }
            resultDetails.innerHTML = '';
            if (!record) {
                return;
            }

            config.detailFields.forEach(function (row) {
                var label = row[0];
                var value = record[row[1]];
                if (!value) {
                    return;
                }

                var wrap = document.createElement('div');
                wrap.className = 'ckin-detail-row';

                var dt = document.createElement('dt');
                dt.textContent = label;
                wrap.appendChild(dt);

                var dd = document.createElement('dd');
                dd.textContent = value;
                wrap.appendChild(dd);

                resultDetails.appendChild(wrap);
            });
        }

        function csrfHeaders() {
            return {
                'X-CSRF-TOKEN': csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            };
        }

        function confirm(url) {
            stopCamera();
            showResultPane();
            showResult('busy', 'Checking…');
            showResultDetails(null);

            fetch(url, { method: 'POST', headers: csrfHeaders() })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (payload) {
                    if (!payload.ok) {
                        showResult('error', (payload.data && payload.data.message) || config.notFoundMessage);
                        return;
                    }

                    var record = payload.data[config.resultKey];
                    var name = config.nameOf(record);
                    if (payload.data.already_checked_in) {
                        showResult('warn', name + ' already checked in.');
                    } else {
                        showResult('success', name + ' checked in ✓');
                        bumpArrivedCount();
                    }
                    showResultDetails(record);
                })
                .catch(function () {
                    showResult('error', 'Network error — try again.');
                });
        }

        // Every credential's own QR always encodes the same fixed shape
        // (Guest::checkInQrUrl()'s dashboard route, or Ticket::publicUrl()'s /t
        // route) regardless of which scanner page decodes it — a staff-link
        // device has no reason to hold a login session, and printing a second,
        // page-specific QR per credential was never on the table. Recognizing
        // either shape here — this page's own base, or the shape a credential's
        // QR always carries — is what lets one unreprinted badge/ticket check in
        // through either scanning path. The extracted token is never dialled at
        // the URL it was found under; it is always resubmitted against THIS
        // page's own base, which is the endpoint already authorized for it
        // (a login session for the dashboard page, the staffToken bearer secret
        // baked into `base` for the staff-link page).
        function extractToken(text) {
            if (typeof text !== 'string') {
                return null;
            }

            var prefixes = [base, selfQrBase];
            for (var i = 0; i < prefixes.length; i++) {
                var prefix = prefixes[i];
                if (!prefix || text.indexOf(prefix + '/') !== 0) {
                    continue;
                }

                var token = text.slice(prefix.length + 1);
                if (token && token.indexOf('/') === -1) {
                    return token;
                }
            }

            return null;
        }

        function onDecoded(text) {
            var now = Date.now();
            if (text === lastToken && now - lastAt < 4000) {
                return;
            }

            var token = extractToken(text);
            if (!token) {
                return;
            }

            lastToken = text;
            lastAt = now;
            confirm(base + '/' + encodeURIComponent(token));
        }

        function tick() {
            rafId = window.requestAnimationFrame(tick);

            if (!scanning || video.readyState !== video.HAVE_ENOUGH_DATA) {
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            var imageData;
            try {
                imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            } catch (e) {
                return;
            }

            var code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
            if (code && code.data) {
                onDecoded(code.data);
            }
        }

        function stopCamera() {
            scanning = false;
            if (rafId) {
                window.cancelAnimationFrame(rafId);
                rafId = null;
            }
            if (video && video.srcObject) {
                video.srcObject.getTracks().forEach(function (track) {
                    track.stop();
                });
                video.srcObject = null;
            }
        }

        function startCamera() {
            if (!video || !canvas || !ctx) {
                return;
            }

            lastToken = null;
            lastAt = 0;
            scanning = true;
            hideResultPane();

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                if (hint) {
                    hint.textContent = 'Camera access is not supported in this browser — use search below instead.';
                }
                return;
            }

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (stream) {
                    video.srcObject = stream;
                    video.play();
                    rafId = window.requestAnimationFrame(tick);
                })
                .catch(function () {
                    if (hint) {
                        hint.textContent = 'Camera access was blocked — use search below instead.';
                    }
                });
        }

        function renderLookupResults(records) {
            lookupResults.innerHTML = '';

            if (!records.length) {
                var empty = document.createElement('li');
                empty.className = 'ckin-lookup-empty';
                empty.textContent = 'No matching records.';
                lookupResults.appendChild(empty);
                return;
            }

            records.forEach(function (record) {
                var li = document.createElement('li');
                li.className = 'ckin-lookup-row';

                var name = document.createElement('span');
                name.textContent = config.nameOf(record) + (record.checked_in_at ? ' (checked in)' : '');
                li.appendChild(name);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'evt-btn-outline evt-btn-tiny';
                btn.textContent = record.checked_in_at ? 'Already in' : 'Check in';
                btn.disabled = !!record.checked_in_at;
                btn.addEventListener('click', function () {
                    confirm(base + '/' + config.lookupIdSegment + '/' + encodeURIComponent(record.id));
                });
                li.appendChild(btn);

                lookupResults.appendChild(li);
            });
        }

        var lookupTimer = null;
        if (lookupInput && lookupUrl) {
            lookupInput.addEventListener('input', function () {
                window.clearTimeout(lookupTimer);
                var term = lookupInput.value.trim();

                if (term.length < 2) {
                    lookupResults.innerHTML = '';
                    return;
                }

                lookupTimer = window.setTimeout(function () {
                    fetch(lookupUrl + '?q=' + encodeURIComponent(term), { headers: csrfHeaders() })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            renderLookupResults(data[config.lookupKey] || []);
                        })
                        .catch(function () {
                            lookupResults.innerHTML = '';
                        });
                }, 300);
            });
        }

        if (scanAgainBtn) {
            scanAgainBtn.addEventListener('click', function () {
                startCamera();
            });
        }

        window.addEventListener('beforeunload', function () {
            stopCamera();
        });

        if (checkInOpen) {
            startCamera();
        }
    });
})();
