(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('checkinScanner');
        if (!root || typeof jsQR === 'undefined') {
            return;
        }

        var base = root.getAttribute('data-checkin-base') || '';
        var guestQrBase = root.getAttribute('data-guest-qr-base') || '';
        var lookupUrl = root.getAttribute('data-lookup-url') || '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var video = document.getElementById('ckinVideo');
        var canvas = document.getElementById('ckinCanvas');
        var hint = document.getElementById('ckinCameraHint');
        var resultBox = document.getElementById('ckinResult');
        var resultDetails = document.getElementById('ckinResultDetails');
        var lookupInput = document.getElementById('ckinLookupInput');
        var lookupResults = document.getElementById('ckinLookupResults');
        var arrivedStat = document.querySelector('[data-checkin-arrived]');

        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var scanning = true;
        var lastToken = null;
        var lastAt = 0;
        var rafId = null;

        function bumpArrivedCount() {
            if (arrivedStat) {
                arrivedStat.textContent = String((parseInt(arrivedStat.textContent, 10) || 0) + 1);
            }
        }

        function showResult(kind, message) {
            resultBox.className = 'ckin-result ckin-result--' + kind;
            resultBox.textContent = message;
        }

        // Built with createElement/textContent, not innerHTML — guest-entered fields
        // (name, notes, meal preference…) are untrusted strings and must never be
        // parsed as markup.
        function showResultDetails(guest) {
            resultDetails.innerHTML = '';
            if (!guest) {
                return;
            }

            var rows = [
                ['Email', guest.email],
                ['Phone', guest.phone],
                ['Table', guest.table],
                ['Meal preference', guest.meal_preference],
                ['RSVP note', guest.rsvp_note],
            ];

            rows.forEach(function (row) {
                var label = row[0];
                var value = row[1];
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
            scanning = false;
            showResult('busy', 'Checking…');

            fetch(url, { method: 'POST', headers: csrfHeaders() })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (payload) {
                    if (!payload.ok) {
                        showResult('error', (payload.data && payload.data.message) || 'Could not check in this guest.');
                        return;
                    }

                    var guest = payload.data.guest;
                    if (payload.data.already_checked_in) {
                        showResult('warn', guest.name + ' already checked in.');
                    } else {
                        showResult('success', guest.name + ' checked in ✓');
                        bumpArrivedCount();
                    }
                    showResultDetails(guest);
                })
                .catch(function () {
                    showResult('error', 'Network error — try again.');
                })
                .finally(function () {
                    window.setTimeout(function () {
                        scanning = true;
                        showResult('idle', '');
                        showResultDetails(null);
                    }, 2200);
                });
        }

        // Every guest's own QR always encodes the same dashboard-route shape
        // (see Guest::checkInQrUrl()), regardless of which scanner page decodes
        // it — a staff-link device has no reason to hold a login session, and
        // printing a second, page-specific QR per guest was never on the table.
        // Recognizing either shape here — this page's own base, or the shape
        // guest QRs always carry — is what lets one unreprinted badge check in
        // through either scanning path. The extracted token is never dialled at
        // the URL it was found under; it is always resubmitted against THIS
        // page's own base, which is the endpoint already authorized for it
        // (a login session for the dashboard page, the staffToken bearer secret
        // baked into `base` for the staff-link page).
        function extractToken(text) {
            if (typeof text !== 'string') {
                return null;
            }

            var prefixes = [base, guestQrBase];
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

        function startCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                hint.textContent = 'Camera access is not supported in this browser — use search below instead.';
                return;
            }

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (stream) {
                    video.srcObject = stream;
                    video.play();
                    hint.textContent = "Point the camera at a guest's invitation QR code.";
                    rafId = window.requestAnimationFrame(tick);
                })
                .catch(function () {
                    hint.textContent = 'Camera access was blocked — use search below instead.';
                });
        }

        function renderLookupResults(guests) {
            lookupResults.innerHTML = '';

            if (!guests.length) {
                var empty = document.createElement('li');
                empty.className = 'ckin-lookup-empty';
                empty.textContent = 'No matching guests.';
                lookupResults.appendChild(empty);
                return;
            }

            guests.forEach(function (guest) {
                var li = document.createElement('li');
                li.className = 'ckin-lookup-row';

                var name = document.createElement('span');
                name.textContent = guest.name + (guest.checked_in_at ? ' (checked in)' : '');
                li.appendChild(name);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'evt-btn-outline evt-btn-tiny';
                btn.textContent = guest.checked_in_at ? 'Already in' : 'Check in';
                btn.disabled = !!guest.checked_in_at;
                btn.addEventListener('click', function () {
                    confirm(base + '/guest/' + encodeURIComponent(guest.id));
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
                            renderLookupResults(data.guests || []);
                        })
                        .catch(function () {
                            lookupResults.innerHTML = '';
                        });
                }, 300);
            });
        }

        window.addEventListener('beforeunload', function () {
            if (rafId) {
                window.cancelAnimationFrame(rafId);
            }
            if (video.srcObject) {
                video.srcObject.getTracks().forEach(function (track) {
                    track.stop();
                });
            }
        });

        startCamera();
    });
})();
