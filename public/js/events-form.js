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

    async function doSearch() {
        const q = searchInput.value.trim();
        if (!q) {
            return;
        }

        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            const res = await fetch(
                'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(q) + '&format=json&limit=1',
                { headers: { 'Accept-Language': 'en' } }
            );
            const data = await res.json();

            if (data.length > 0) {
                const lat = parseFloat(data[0].lat);
                const lng = parseFloat(data[0].lon);
                map.flyTo([lat, lng], 15);
                placeMarker(lat, lng);
                syncCoords(lat, lng);

                const locationInput = document.getElementById('location_name');
                if (locationInput && !locationInput.value.trim()) {
                    locationInput.value = data[0].display_name.split(',').slice(0, 2).join(',').trim();
                }
            } else {
                searchInput.classList.add('evt-map-search--no-result');
                setTimeout(() => searchInput.classList.remove('evt-map-search--no-result'), 2000);
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
}
