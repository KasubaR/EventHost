(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('checkinScanner');
        if (!root || typeof jsQR === 'undefined') {
            return;
        }

        var base = root.getAttribute('data-checkin-base') || '';
        var lookupUrl = root.getAttribute('data-lookup-url') || '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var video = document.getElementById('ckinVideo');
        var canvas = document.getElementById('ckinCanvas');
        var hint = document.getElementById('ckinCameraHint');
        var resultBox = document.getElementById('ckinResult');
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
                })
                .catch(function () {
                    showResult('error', 'Network error — try again.');
                })
                .finally(function () {
                    window.setTimeout(function () {
                        scanning = true;
                        showResult('idle', '');
                    }, 2200);
                });
        }

        function onDecoded(text) {
            var now = Date.now();
            if (text === lastToken && now - lastAt < 4000) {
                return;
            }

            if (typeof text !== 'string' || text.indexOf(base + '/') !== 0) {
                return;
            }

            var token = text.slice(base.length + 1);
            if (!token || token.indexOf('/') !== -1) {
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
