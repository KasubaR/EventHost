document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const message = form.getAttribute('data-confirm');
    if (!message) {
        return;
    }
    if (!window.confirm(message)) {
        event.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Guest limit radio toggle
    const radioOpen    = document.getElementById('guest_limit_open');
    const radioSet     = document.getElementById('guest_limit_set');
    const limitWrap    = document.getElementById('guest_limit_wrap');
    const limitInput   = document.getElementById('guest_limit');

    if (radioOpen && radioSet && limitWrap && limitInput) {
        const hasStoredLimit = limitInput.value.trim() !== '';

        if (hasStoredLimit) {
            radioSet.checked = true;
        } else {
            radioOpen.checked = true;
            limitWrap.style.display = 'none';
        }

        radioOpen.addEventListener('change', () => {
            limitWrap.style.display = 'none';
            limitInput.value = '';
        });

        radioSet.addEventListener('change', () => {
            limitWrap.style.display = '';
            limitInput.focus();
        });
    }

    // Cover image preview
    const input = document.getElementById('cover_image');
    const preview = document.getElementById('evt-cover-preview');
    if (input && preview) {
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file || !file.type.startsWith('image/')) {
                return;
            }
            preview.src = URL.createObjectURL(file);
        });
    }

    // Map picker
    initMap();
});

/**
 * Pull lat/lng straight out of a pasted Google Maps URL — or a raw "lat, lng" pair — with no
 * network round-trip. Only matches URL shapes that carry coordinates in the text itself; short
 * links (maps.app.goo.gl, goo.gl/maps/...) don't and are handled server-side, see isGoogleMapsShortLink.
 *
 * Order matters: `!3d{lat}!4d{lng}` is the actual pin on a Google "place" link and can legitimately
 * disagree with `@{lat},{lng}`, which is just the map's viewport center at the time the link was
 * copied — so it's checked first. Keep this in sync with GoogleMapsLinkParser::extractCoordinates()
 * (app/Support/GoogleMapsLinkParser.php), which parses the same shapes server-side after resolving
 * a short link's redirect.
 */
function parseGoogleMapsCoords(text) {
    let m = text.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    m = text.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    m = text.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    m = text.match(/^(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    return null;
}

/** The `{name}` segment of a `/maps/place/{name}/@...` link, decoded back into a readable label. */
function extractGoogleMapsPlaceName(text) {
    const m = text.match(/\/maps\/place\/([^/@]+)\/@/);
    if (!m) {
        return null;
    }
    try {
        return decodeURIComponent(m[1].replace(/\+/g, ' '));
    } catch (_) {
        return null;
    }
}

/** Short links carry no coordinates in the text — they only appear after Google's redirect resolves. */
function isGoogleMapsShortLink(text) {
    let url;
    try {
        url = new URL(text);
    } catch (_) {
        return false;
    }
    if (url.hostname === 'maps.app.goo.gl') {
        return true;
    }
    return url.hostname === 'goo.gl' && url.pathname.startsWith('/maps/');
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

function initMap() {
    const mapEl = document.getElementById('evt-map');
    if (!mapEl || typeof window.L === 'undefined') {
        return;
    }

    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    if (!latInput || !lngInput) {
        return;
    }

    const existingLat = parseFloat(latInput.value);
    const existingLng = parseFloat(lngInput.value);
    const hasCoords = !isNaN(existingLat) && !isNaN(existingLng);

    const map = L.map(mapEl, { zoomControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    let marker = null;

    function placeMarker(lat, lng) {
        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', () => {
                const pos = marker.getLatLng();
                syncCoords(pos.lat, pos.lng);
            });
        }
    }

    function syncCoords(lat, lng) {
        latInput.value = lat.toFixed(7);
        lngInput.value = lng.toFixed(7);
    }

    if (hasCoords) {
        map.setView([existingLat, existingLng], 14);
        placeMarker(existingLat, existingLng);
    } else {
        map.setView([20, 0], 2);
    }

    map.on('click', (e) => {
        placeMarker(e.latlng.lat, e.latlng.lng);
        syncCoords(e.latlng.lat, e.latlng.lng);
    });

    // Address search
    const searchInput = document.getElementById('evt-map-search');
    const searchBtn = document.getElementById('evt-map-search-btn');
    if (!searchInput || !searchBtn) {
        return;
    }

    function applyFoundCoords(lat, lng, placeName) {
        map.flyTo([lat, lng], 15);
        placeMarker(lat, lng);
        syncCoords(lat, lng);

        if (placeName) {
            const locationInput = document.getElementById('location_name');
            if (locationInput && !locationInput.value.trim()) {
                locationInput.value = placeName;
            }
        }
    }

    function flashNoResult() {
        searchInput.classList.add('evt-map-search--no-result');
        setTimeout(() => searchInput.classList.remove('evt-map-search--no-result'), 2000);
    }

    async function doSearch() {
        const q = searchInput.value.trim();
        if (!q) {
            return;
        }

        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            // 1. A pasted Google Maps link that already carries coordinates in its text, or a raw
            // "lat, lng" pair — parsed locally, no request at all.
            const localCoords = parseGoogleMapsCoords(q);
            if (localCoords) {
                applyFoundCoords(localCoords.lat, localCoords.lng, extractGoogleMapsPlaceName(q));
                return;
            }

            // 2. A short Google Maps link (maps.app.goo.gl, goo.gl/maps/...) — its coordinates only
            // exist after the redirect resolves, which the browser can't do (Google sends no CORS
            // headers), so a small backend endpoint follows it instead.
            if (isGoogleMapsShortLink(q)) {
                const res = await fetch('/maps/resolve-link', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ url: q }),
                });
                if (res.ok) {
                    const data = await res.json();
                    applyFoundCoords(data.latitude, data.longitude);
                } else {
                    flashNoResult();
                }
                return;
            }

            // 3. A plain address — the original behaviour.
            const res = await fetch(
                'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(q) + '&format=json&limit=1',
                { headers: { 'Accept-Language': 'en' } }
            );
            const data = await res.json();

            if (data.length > 0) {
                applyFoundCoords(
                    parseFloat(data[0].lat),
                    parseFloat(data[0].lon),
                    data[0].display_name.split(',').slice(0, 2).join(',').trim()
                );
            } else {
                flashNoResult();
            }
        } catch (_) {
            // silent fail — user can retry
        } finally {
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
        }
    }

    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            doSearch();
        }
    });

    // "Use my current location"
    const locateBtn = document.getElementById('evt-map-locate-btn');
    if (!locateBtn) {
        return;
    }

    if (!navigator.geolocation) {
        // No point offering a control that can only ever fail.
        locateBtn.style.display = 'none';
        return;
    }

    async function reverseGeocode(lat, lng) {
        const locationInput = document.getElementById('location_name');
        if (!locationInput || locationInput.value.trim()) {
            return;
        }
        try {
            const res = await fetch(
                'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json',
                { headers: { 'Accept-Language': 'en' } }
            );
            const data = await res.json();
            if (data && data.display_name) {
                locationInput.value = data.display_name.split(',').slice(0, 2).join(',').trim();
            }
        } catch (_) {
            // Best-effort only — the pin itself is already placed.
        }
    }

    function flashLocateError() {
        locateBtn.classList.add('evt-map-search--no-result');
        setTimeout(() => locateBtn.classList.remove('evt-map-search--no-result'), 2000);
    }

    function setLocating(isLocating) {
        locateBtn.disabled = isLocating;
        locateBtn.innerHTML = isLocating
            ? '<i class="fa-solid fa-spinner fa-spin"></i>'
            : '<i class="fa-solid fa-location-crosshairs"></i>';
    }

    locateBtn.addEventListener('click', () => {
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude, longitude } = pos.coords;
                map.flyTo([latitude, longitude], 15);
                placeMarker(latitude, longitude);
                syncCoords(latitude, longitude);
                reverseGeocode(latitude, longitude);
                setLocating(false);
            },
            () => {
                // Permission denied, unavailable, or timed out — user can still
                // search or click the map, so fail quietly with a visual nudge.
                flashLocateError();
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
}

function bindProductKindToggle() {
    const radios = document.querySelectorAll('input[type="radio"][name="product_kind"]');
    if (radios.length === 0) {
        return;
    }

    const invitationPanels = document.querySelectorAll('[data-product-panel="invitation"]');
    const ticketedPanels = document.querySelectorAll('[data-product-panel="ticketed"]');

    const apply = () => {
        const selected = document.querySelector('input[type="radio"][name="product_kind"]:checked');
        const isTicketed = selected instanceof HTMLInputElement && selected.value === 'ticketed';

        invitationPanels.forEach((panel) => {
            panel.hidden = isTicketed;
        });
        ticketedPanels.forEach((panel) => {
            panel.hidden = ! isTicketed;
        });
    };

    radios.forEach((radio) => {
        radio.addEventListener('change', apply);
    });
    apply();
}

document.addEventListener('DOMContentLoaded', () => {
    bindProductKindToggle();
});
