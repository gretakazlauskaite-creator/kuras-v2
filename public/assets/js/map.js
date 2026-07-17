/* map.js — Leaflet interactive map for Kuras Pricer */
(function () {
    'use strict';

    // Lithuania center
    const LT_CENTER = [55.17, 23.88];
    const LT_ZOOM   = 7;

    let map, clusterGroup;
    let currentFuel = 'pb95';
    let fetchTimer  = null;

    // ── Init map ───────────────────────────────────────────────
    map = L.map('map', { center: LT_CENTER, zoom: LT_ZOOM });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    clusterGroup = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 50 });
    map.addLayer(clusterGroup);

    // ── Colored circle icons ───────────────────────────────────
    function makeIcon(tier, sponsored) {
        const colors = { cheap: '#22c55e', average: '#eab308', expensive: '#ef4444', unknown: '#94a3b8' };
        const color  = colors[tier] || colors.unknown;
        const star   = sponsored ? '★' : '';
        const size   = sponsored ? 22 : 16;

        return L.divIcon({
            className: '',
            iconSize:  [size, size],
            iconAnchor: [size / 2, size / 2],
            html: `<div style="
                width:${size}px;height:${size}px;border-radius:50%;
                background:${color};border:2px solid rgba(0,0,0,.25);
                display:flex;align-items:center;justify-content:center;
                font-size:${size * 0.55}px;color:#fff;font-weight:700;
                box-shadow:0 1px 3px rgba(0,0,0,.3);">${star}</div>`,
        });
    }

    // ── Load markers ───────────────────────────────────────────
    function loadMarkers() {
        const bounds = map.getBounds();
        const bbox   = [
            bounds.getWest().toFixed(4),
            bounds.getSouth().toFixed(4),
            bounds.getEast().toFixed(4),
            bounds.getNorth().toFixed(4),
        ].join(',');

        fetch(`/api/stations?fuel=${currentFuel}&bounds=${bbox}`)
            .then(r => r.json())
            .then(geojson => renderMarkers(geojson))
            .catch(err => console.error('Map fetch error:', err));
    }

    function renderMarkers(geojson) {
        clusterGroup.clearLayers();
        const features = geojson.features || [];

        // Sort for "best in view" sidebar
        const sorted = [...features]
            .filter(f => f.properties.price !== null)
            .sort((a, b) => a.properties.price - b.properties.price);

        features.forEach(f => {
            const p   = f.properties;
            const lat = f.geometry.coordinates[1];
            const lng = f.geometry.coordinates[0];

            const marker = L.marker([lat, lng], { icon: makeIcon(p.tier, p.is_sponsored) });

            const priceStr = p.price !== null ? p.price.toFixed(3) + ' €/L' : 'kaina nežinoma';
            marker.bindPopup(`
                <strong>${escHtml(p.name)}</strong><br>
                <small>${escHtml(p.address)}</small><br>
                <span style="font-size:1.2rem;font-weight:700;color:#0ea5e9">${priceStr}</span><br>
                <a href="/station/${p.id}" style="font-size:.85rem">Peržiūrėti →</a>
            `);

            clusterGroup.addLayer(marker);
        });

        updateBestInView(sorted.slice(0, 5));
    }

    function updateBestInView(top5) {
        const list = document.getElementById('bestInView');
        if (!list) return;

        if (!top5.length) {
            list.innerHTML = '<li style="color:#94a3b8">Nėra duomenų</li>';
            return;
        }

        list.innerHTML = top5.map((f, i) => {
            const p = f.properties;
            return `<li>
                <strong>${p.price.toFixed(3)} €/L</strong> —
                <a href="/station/${p.id}">${escHtml(p.brand)} ${escHtml(p.city)}</a>
            </li>`;
        }).join('');
    }

    // ── City focus ─────────────────────────────────────────────
    const CITY_COORDS = {
        'Vilnius':     [54.6872, 25.2797],
        'Kaunas':      [54.8985, 23.9036],
        'Klaipėda':    [55.7033, 21.1443],
        'Šiauliai':    [55.9349, 23.3137],
        'Panevėžys':   [55.7348, 24.3643],
        'Alytus':      [54.3962, 24.0467],
        'Marijampolė': [54.5594, 23.3565],
    };

    const cityFilter = document.getElementById('cityFilter');
    if (cityFilter) {
        cityFilter.addEventListener('change', () => {
            const city = cityFilter.value;
            if (city && CITY_COORDS[city]) {
                map.setView(CITY_COORDS[city], 12);
            }
        });
    }

    // ── Fuel buttons ───────────────────────────────────────────
    document.querySelectorAll('.fuel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.fuel-btn').forEach(b => b.classList.remove('fuel-btn--active'));
            btn.classList.add('fuel-btn--active');
            currentFuel = btn.dataset.fuel;
            loadMarkers();
        });
    });

    // ── Debounced map move reload ───────────────────────────────
    map.on('moveend zoomend', () => {
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(loadMarkers, 400);
    });

    // ── Initial load ────────────────────────────────────────────
    loadMarkers();

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
