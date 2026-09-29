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
        const upgradeHint = document.getElementById('guest_limit_upgrade_hint');

        if (hasStoredLimit) {
            radioSet.checked = true;
        } else {
            radioOpen.checked = true;
            limitWrap.style.display = 'none';
        }

        const syncUpgradeHint = () => {
            if (!upgradeHint) {
                return;
            }
            const capacity = limitInput.dataset.guestCapacity
                ? parseInt(limitInput.dataset.guestCapacity, 10)
                : null;
            const value = parseInt(limitInput.value, 10);
            const upgradeUrl = limitInput.dataset.upgradeUrl;
            const upgradeLabel = limitInput.dataset.upgradeLabel;

            if (capacity && upgradeUrl && upgradeLabel && Number.isFinite(value) && value > capacity) {
                upgradeHint.hidden = false;
                upgradeHint.innerHTML = '';
                const link = document.createElement('a');
                link.href = upgradeUrl;
                link.textContent = 'Upgrade to ' + upgradeLabel + ' for a higher guest limit';
                upgradeHint.appendChild(link);
            } else {
                upgradeHint.hidden = true;
                upgradeHint.textContent = '';
            }
        };

        radioOpen.addEventListener('change', () => {
            limitWrap.style.display = 'none';
            limitInput.value = '';
            syncUpgradeHint();
        });

        radioSet.addEventListener('change', () => {
            limitWrap.style.display = '';
            limitInput.focus();
            syncUpgradeHint();
        });

        limitInput.addEventListener('input', syncUpgradeHint);
        syncUpgradeHint();
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

    // Ticketing wizard step 3 — commission picker saves itself the moment a
    // card is picked, instead of needing a separate "Save commission
    // setting" click. Scoped to data-auto-submit so the Settings-page copy
    // of this same form (outside the wizard) still requires an explicit save.
    document.querySelectorAll('form.tkt-commission-form[data-auto-submit]').forEach((form) => {
        form.querySelectorAll('input[type="radio"]').forEach((radio) => {
            radio.addEventListener('change', () => form.requestSubmit());
        });
    });

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

    m = text.match(/[?&](?:q|query|ll|center|sll)=(-?\d+\.\d+)(?:,|%2C)(-?\d+\.\d+)/i);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    m = text.match(/^(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/);
    if (m) {
        return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
    }

    return null;
}

/**
 * The `{name}` segment of a `/maps/place/{name}/...` link, decoded back into a readable label.
 * Mirrors GoogleMapsLinkParser::extractPlaceName().
 */
function extractGoogleMapsPlaceName(text) {
    const m = text.match(/\/maps\/place\/([^/@?]+)/);
    if (!m) {
        return null;
    }
    let name;
    try {
        name = decodeURIComponent(m[1].replace(/\+/g, ' ')).trim();
    } catch (_) {
        return null;
    }
    // A bare "lat,lng" is not a name.
    if (!name || /^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?$/.test(name)) {
        return null;
    }
    return name;
}

/** A full (not short) Google Maps URL — on any regional Google domain. Used only for messaging. */
function isGoogleMapsUrl(text) {
    let url;
    try {
        url = new URL(text);
    } catch (_) {
        return false;
    }
    if (!/(^|\.)google\.(com|co\.[a-z]{2}|com\.[a-z]{2})$/i.test(url.hostname)) {
        return false;
    }
    return url.hostname.toLowerCase().startsWith('maps.') || url.pathname.startsWith('/maps');
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

/**
 * Dispatches to whichever map provider the server picked (form-fields.blade.php sets
 * data-provider from config('services.google_maps.key') being present). The Google
 * path can't run synchronously here — its script tag loads async and may not have
 * called back yet — so it just flags the DOM as ready and lets tryInitGoogleMap()
 * decide once both halves (DOM + Google's own script) are in.
 */
function initMap() {
    const mapEl = document.getElementById('evt-map');
    if (!mapEl) {
        return;
    }

    if (mapEl.dataset.provider === 'google') {
        eventMapDomReady = true;
        tryInitGoogleMap();
        return;
    }

    initLeafletMap(mapEl);
}

let eventMapDomReady = false;
let googleMapsApiReady = false;

/**
 * Named in the Google Maps JS API script tag's `callback=` param (form-fields.blade.php).
 * Fires whenever the script finishes loading, which — because it's `async` — can happen
 * before or after DOMContentLoaded. tryInitGoogleMap() only proceeds once both have happened.
 */
window.initEventGoogleMap = function () {
    googleMapsApiReady = true;
    tryInitGoogleMap();
};

function tryInitGoogleMap() {
    if (!eventMapDomReady || !googleMapsApiReady) {
        return;
    }
    const mapEl = document.getElementById('evt-map');
    if (!mapEl || mapEl.dataset.provider !== 'google' || mapEl.dataset.initialized === '1') {
        return;
    }
    mapEl.dataset.initialized = '1';
    initGoogleMap(mapEl);
}

function initLeafletMap(mapEl) {
    if (typeof window.L === 'undefined') {
        return;
    }

    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    if (!latInput || !lngInput) {
        return;
    }

    // A text counterpart to the outline-flash cues below — those are
    // CSS-only (a border color change), so a screen reader announces
    // nothing at all when a search or "use my location" attempt fails.
    const statusEl = document.getElementById('evt-map-status');

    function setMapStatus(message) {
        if (!statusEl) return;
        if (!message) {
            statusEl.hidden = true;
            statusEl.textContent = '';
            return;
        }
        statusEl.hidden = false;
        statusEl.textContent = message;
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
        setMapStatus(null);
    });

    // Address search
    const searchInput = document.getElementById('evt-map-search');
    const searchBtn = document.getElementById('evt-map-search-btn');
    if (!searchInput || !searchBtn) {
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

    function fillIfEmpty(id, value) {
        const input = document.getElementById(id);
        if (input && !input.value.trim()) {
            input.value = value;
        }
    }

    function applyFoundCoords(lat, lng, placeName) {
        map.flyTo([lat, lng], 15);
        placeMarker(lat, lng);
        syncCoords(lat, lng);
        setMapStatus(null);

        if (placeName) {
            fillIfEmpty('location_name', placeName);
        }
    }

    // A pasted Google Maps link names the place (a venue), unlike an address search, which names
    // an area. Put it in Venue, then let reverse-geocoding suggest the area for the location label.
    function applyFoundLink(lat, lng, placeName) {
        applyFoundCoords(lat, lng);

        if (placeName) {
            fillIfEmpty('venue', placeName);
        }
        reverseGeocode(lat, lng);
    }

    function flashNoResult(message) {
        searchInput.classList.add('evt-map-search--no-result');
        setTimeout(() => searchInput.classList.remove('evt-map-search--no-result'), 2000);
        setMapStatus(message || 'No results for that search. Try a different address, or click the map to drop a pin yourself.');
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
                applyFoundLink(localCoords.lat, localCoords.lng, extractGoogleMapsPlaceName(q));
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
                    applyFoundLink(data.latitude, data.longitude, data.name);
                } else {
                    let message = null;
                    try {
                        message = (await res.json()).message;
                    } catch (_) {
                        // Non-JSON error body — fall back to the generic message below.
                    }
                    flashNoResult(message);
                }
                return;
            }

            // A full Google Maps link that carries no pin (e.g. a search or a bare place name). Don't
            // feed it to the address search — it would return nonsense — say how to get a usable link.
            if (isGoogleMapsUrl(q)) {
                flashNoResult('That link has no pin in it. Open it in Google Maps, tap the place, and copy the link from there.');
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

    // Pasting a Google Maps link or a "lat, lng" pair is unambiguous, so apply it straight away
    // instead of making the host find the search button. Plain addresses still wait for Enter/click.
    searchInput.addEventListener('paste', () => {
        setTimeout(() => {
            const q = searchInput.value.trim();
            if (parseGoogleMapsCoords(q) || isGoogleMapsShortLink(q) || isGoogleMapsUrl(q)) {
                doSearch();
            }
        }, 0);
    });
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

    function flashLocateError() {
        locateBtn.classList.add('evt-map-search--no-result');
        setTimeout(() => locateBtn.classList.remove('evt-map-search--no-result'), 2000);
        setMapStatus('Could not get your location. Try searching instead, or click the map to drop a pin yourself.');
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
                setMapStatus(null);
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

/**
 * Google Maps equivalent of initLeafletMap(), driving the same hidden latitude/longitude
 * inputs plus the two new hidden google_place_id/formatted_address inputs. Places Autocomplete
 * replaces the free-text Nominatim search; google.maps.Geocoder replaces reverse-geocode.
 * The pasted-link parsing helpers (parseGoogleMapsCoords etc.) and the /maps/resolve-link
 * backend call are unchanged and shared with the Leaflet path.
 */
function initGoogleMap(mapEl) {
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const placeIdInput = document.getElementById('google_place_id');
    const addressInput = document.getElementById('formatted_address');
    if (!latInput || !lngInput) {
        return;
    }

    const statusEl = document.getElementById('evt-map-status');

    function setMapStatus(message) {
        if (!statusEl) return;
        if (!message) {
            statusEl.hidden = true;
            statusEl.textContent = '';
            return;
        }
        statusEl.hidden = false;
        statusEl.textContent = message;
    }

    const existingLat = parseFloat(latInput.value);
    const existingLng = parseFloat(lngInput.value);
    const hasCoords = !isNaN(existingLat) && !isNaN(existingLng);

    const map = new google.maps.Map(mapEl, {
        center: hasCoords ? { lat: existingLat, lng: existingLng } : { lat: 20, lng: 0 },
        zoom: hasCoords ? 14 : 2,
        streetViewControl: false,
        mapTypeControl: false,
    });

    let marker = null;

    function placeMarker(lat, lng) {
        const position = { lat, lng };
        if (marker) {
            marker.setPosition(position);
        } else {
            marker = new google.maps.Marker({ position, map, draggable: true });
            marker.addListener('dragend', () => {
                const pos = marker.getPosition();
                syncCoords(pos.lat(), pos.lng());
                clearPlaceMeta();
                reverseGeocodeCoords(pos.lat(), pos.lng());
            });
        }
    }

    function syncCoords(lat, lng) {
        latInput.value = lat.toFixed(7);
        lngInput.value = lng.toFixed(7);
    }

    function clearPlaceMeta() {
        if (placeIdInput) placeIdInput.value = '';
        if (addressInput) addressInput.value = '';
    }

    function fillIfEmpty(id, value) {
        const input = document.getElementById(id);
        if (input && value && !input.value.trim()) {
            input.value = value;
        }
    }

    const geocoder = new google.maps.Geocoder();

    // A dragged/clicked pin has no known place_id of its own — reverse-geocode finds the
    // nearest match so a manually-placed pin isn't a second-class citizen next to a search pick.
    function reverseGeocodeCoords(lat, lng) {
        geocoder.geocode({ location: { lat, lng } }, (results, status) => {
            if (status !== 'OK' || !results || !results[0]) {
                return;
            }
            const result = results[0];
            if (placeIdInput) placeIdInput.value = result.place_id || '';
            if (addressInput) addressInput.value = result.formatted_address || '';
            fillIfEmpty('location_name', result.formatted_address);
        });
    }

    // Shared by an Autocomplete selection and a plain-address Geocoder result — both return
    // the same PlaceResult/GeocoderResult shape for the fields this reads.
    function applyPlaceResult(place) {
        if (!place || !place.geometry || !place.geometry.location) {
            return false;
        }
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();

        map.panTo({ lat, lng });
        map.setZoom(15);
        placeMarker(lat, lng);
        syncCoords(lat, lng);
        setMapStatus(null);

        if (placeIdInput) placeIdInput.value = place.place_id || '';
        if (addressInput) addressInput.value = place.formatted_address || '';
        fillIfEmpty('venue', place.name);
        fillIfEmpty('location_name', place.formatted_address);

        return true;
    }

    if (hasCoords) {
        placeMarker(existingLat, existingLng);
    }

    map.addListener('click', (e) => {
        placeMarker(e.latLng.lat(), e.latLng.lng());
        syncCoords(e.latLng.lat(), e.latLng.lng());
        setMapStatus(null);
        clearPlaceMeta();
        reverseGeocodeCoords(e.latLng.lat(), e.latLng.lng());
    });

    const searchInput = document.getElementById('evt-map-search');
    const searchBtn = document.getElementById('evt-map-search-btn');
    if (!searchInput || !searchBtn) {
        return;
    }

    function flashNoResult(message) {
        searchInput.classList.add('evt-map-search--no-result');
        setTimeout(() => searchInput.classList.remove('evt-map-search--no-result'), 2000);
        setMapStatus(message || 'No results for that search. Try a different address, or click the map to drop a pin yourself.');
    }

    function applyFoundLinkCoords(lat, lng, placeName) {
        map.panTo({ lat, lng });
        map.setZoom(15);
        placeMarker(lat, lng);
        syncCoords(lat, lng);
        setMapStatus(null);
        clearPlaceMeta();
        if (placeName) {
            fillIfEmpty('venue', placeName);
        }
        // Pasted links carry no place_id — backfill it and the formatted address the same
        // way a manual drag does, so search/drag/paste all end up with equivalent metadata.
        reverseGeocodeCoords(lat, lng);
    }

    /** Returns true if `q` was handled as a Google Maps link (resolved or rejected). */
    async function resolvePastedLink(q) {
        const localCoords = parseGoogleMapsCoords(q);
        if (localCoords) {
            applyFoundLinkCoords(localCoords.lat, localCoords.lng, extractGoogleMapsPlaceName(q));
            return true;
        }

        if (isGoogleMapsShortLink(q)) {
            searchBtn.disabled = true;
            searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            try {
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
                    applyFoundLinkCoords(data.latitude, data.longitude, data.name);
                } else {
                    let message = null;
                    try {
                        message = (await res.json()).message;
                    } catch (_) {
                        // Non-JSON error body — fall back to the generic message below.
                    }
                    flashNoResult(message);
                }
            } finally {
                searchBtn.disabled = false;
                searchBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
            }
            return true;
        }

        if (isGoogleMapsUrl(q)) {
            flashNoResult('That link has no pin in it. Open it in Google Maps, tap the place, and copy the link from there.');
            return true;
        }

        return false;
    }

    searchInput.addEventListener('paste', () => {
        setTimeout(() => {
            const q = searchInput.value.trim();
            if (parseGoogleMapsCoords(q) || isGoogleMapsShortLink(q) || isGoogleMapsUrl(q)) {
                resolvePastedLink(q);
            }
        }, 0);
    });

    async function doSearch() {
        const q = searchInput.value.trim();
        if (!q) {
            return;
        }

        if (await resolvePastedLink(q)) {
            return;
        }

        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        geocoder.geocode({ address: q }, (results, status) => {
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
            if (status !== 'OK' || !results || !results[0]) {
                flashNoResult();
                return;
            }
            applyPlaceResult(results[0]);
        });
    }

    searchBtn.addEventListener('click', doSearch);

    // Enter must not submit the whole event form. Selecting a suggestion with Enter is handled
    // by Autocomplete's own place_changed event below, not here, so the two never race.
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });

    const autocomplete = new google.maps.places.Autocomplete(searchInput, {
        fields: ['place_id', 'name', 'formatted_address', 'geometry'],
    });
    autocomplete.bindTo('bounds', map);

    autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (applyPlaceResult(place)) {
            return;
        }
        // No suggestion was selected (typed text + Enter with nothing highlighted) — Google
        // still fires place_changed here with a name-only result, so fall back to the same
        // link/geocode handling the search button uses.
        doSearch();
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

    function flashLocateError() {
        locateBtn.classList.add('evt-map-search--no-result');
        setTimeout(() => locateBtn.classList.remove('evt-map-search--no-result'), 2000);
        setMapStatus('Could not get your location. Try searching instead, or click the map to drop a pin yourself.');
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
                map.panTo({ lat: latitude, lng: longitude });
                map.setZoom(15);
                placeMarker(latitude, longitude);
                syncCoords(latitude, longitude);
                setMapStatus(null);
                reverseGeocodeCoords(latitude, longitude);
                setLocating(false);
            },
            () => {
                flashLocateError();
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
}

function todayIsoDate() {
    const now = new Date();
    const pad = (n) => (n < 10 ? '0' : '') + n;
    return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
}

/**
 * event_date's own "no past days" is a static min="today" set server-side
 * (form-fields.blade.php, skipped only when correcting an already-locked/past
 * event — see Event::isLocked()). event_time can't get a static bound the same
 * way: whether "today" is even a legal choice depends on that flag, and once
 * it is, "too early today" keeps changing with the clock. datetime-picker.js
 * re-reads the native min attribute fresh every time its dropdown opens
 * (Picker.readBounds()), so updating it here on every date change is enough —
 * no need to poke the picker instance directly.
 */
function bindEventDateTimeGuard() {
    const dateInput = document.getElementById('event_date');
    const timeInput = document.getElementById('event_time');
    if (!dateInput || !timeInput) {
        return;
    }

    const syncTimeMin = () => {
        if (dateInput.value && dateInput.value === todayIsoDate()) {
            const now = new Date();
            const pad = (n) => (n < 10 ? '0' : '') + n;
            timeInput.min = pad(now.getHours()) + ':' + pad(now.getMinutes());
        } else {
            timeInput.removeAttribute('min');
        }
    };

    dateInput.addEventListener('change', syncTimeMin);
    syncTimeMin();
}

/**
 * Inline, as-you-go validation for the event form: the same server-rendered
 * markup (.profile-field-error span, .profile-input--error class) but driven
 * by the field's own input/change/blur instead of only appearing after a full
 * round trip to the server on submit. Only checks what the browser's native
 * constraint validation already knows (required, pattern, min/max) — rules
 * that need the server (slug availability, audience/tier combinations, RSVP
 * deadline vs. event date) still only surface after submitting.
 */
function bindLiveFieldValidation(form) {
    if (!form) {
        return;
    }

    function messageFor(field) {
        if (field.validity.valueMissing) {
            return 'This field is required.';
        }
        // min="today" (form-fields.blade.php) isn't a real date string, so the
        // browser's own rangeUnderflow check never fires for it — checked here instead.
        if (field.type === 'date' && field.value && field.value < todayIsoDate()) {
            return 'That date has already passed. Please choose a later date.';
        }
        if (field.validity.rangeUnderflow) {
            return field.type === 'time'
                ? 'That time has already passed today. Please choose a later time.'
                : field.validationMessage;
        }
        if (!field.validity.valid) {
            return field.type === 'time' ? 'Enter a valid time.' : field.validationMessage;
        }
        return '';
    }

    function decorate(field) {
        const wrap = field.closest('.profile-field');
        if (!wrap) {
            return;
        }
        // The datetime-picker/custom-select trigger is what's actually visible
        // — the native field it replaces sits underneath at opacity: 0 (see
        // CLAUDE.md's note on why it's never display: none), so the red-border
        // cue has to land on that wrapper, not the input itself.
        const outer = field.closest('.dtp, .cs') || field;
        let span = wrap.querySelector(':scope > .profile-field-error');
        const message = messageFor(field);

        if (message) {
            if (!span) {
                span = document.createElement('span');
                span.className = 'profile-field-error';
                span.appendChild(Object.assign(document.createElement('i'), { className: 'fa-solid fa-circle-exclamation' }));
                span.appendChild(document.createTextNode(' ' + message));
                wrap.appendChild(span);
            } else {
                const textNode = span.lastChild;
                if (textNode && textNode.nodeType === Node.TEXT_NODE) {
                    textNode.textContent = ' ' + message;
                } else {
                    span.appendChild(document.createTextNode(' ' + message));
                }
            }
            field.classList.add('profile-input--error');
            outer.classList.add('is-invalid');
        } else if (span) {
            span.remove();
            field.classList.remove('profile-input--error');
            outer.classList.remove('is-invalid');
        }
    }

    // No initial decorate() pass: datetime-picker.js / custom-select.js wrap
    // these fields on their own DOMContentLoaded listener, registered after
    // this one runs, so field.closest('.dtp, .cs') would find nothing yet —
    // any pre-existing server-side error already has its own @error markup.
    form.querySelectorAll('input[required], select[required], input[type="date"], input[type="time"]').forEach((field) => {
        ['input', 'change', 'blur'].forEach((evt) => field.addEventListener(evt, () => decorate(field)));
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
    bindEventDateTimeGuard();

    const dateField = document.getElementById('event_date');
    bindLiveFieldValidation(dateField && dateField.closest('form'));
});
