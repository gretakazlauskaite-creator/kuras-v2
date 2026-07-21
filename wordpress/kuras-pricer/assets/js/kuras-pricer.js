(function () {
    'use strict';

    const config = window.KURAS_PRICER || {};
    const fuelLabels = {pb95: 'Pb 95', pb98: 'Pb 98', diesel: 'Dyzelinas', lpg: 'Dujos'};
    const euro = value => value === null || value === undefined ? '—' : Number(value).toFixed(3).replace('.', ',') + ' €';
    const number = value => new Intl.NumberFormat('lt-LT').format(Number(value || 0));
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character]));

    async function api(path, params = {}) {
        const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined));
        const response = await fetch(config.restUrl + path + (query.size ? '?' + query : ''), {headers: {Accept: 'application/json'}});
        let body;
        try { body = await response.json(); } catch (_) { body = {}; }
        if (!response.ok) throw new Error(body.message || body.error?.message || 'Kainų duomenų gauti nepavyko.');
        return body;
    }

    function dateTime(value) {
        if (!value) return 'laikas nežinomas';
        const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
        return Number.isNaN(parsed.getTime()) ? value : new Intl.DateTimeFormat('lt-LT', {dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Vilnius'}).format(parsed);
    }

    function finder(root) {
        const get = selector => root.querySelector(selector);
        const initialNode = get('[data-kuras-initial]');
        let initial = {};
        try { initial = JSON.parse(initialNode?.textContent || '{}'); } catch (_) { initial = {}; }
        const state = {fuel: 'pb95', page: 1, perPage: 15, totalPages: 1, map: null, markers: null, timer: null, lat: null, lng: null};

        function showNotice(message, tone = 'warning') {
            const notice = get('[data-global-notice]');
            notice.hidden = !message;
            notice.className = 'kuras-notice kuras-notice--' + tone;
            notice.textContent = message;
        }

        function renderMeta(body) {
            if (!body?.data) return;
            const meta = body.data;
            const stale = Boolean(body.meta?.wordpress_cache?.stale || meta.freshness?.is_stale);
            const status = get('[data-source-status]');
            status.classList.toggle('is-stale', stale);
            status.querySelector('span:last-child').textContent = `Duomenų data: ${meta.source_date || '—'} · importuota ${dateTime(meta.last_successful_import_at)}`;
            if (body.meta?.wordpress_cache?.stale) showNotice('API laikinai nepasiekiamas, todėl rodoma paskutinė sėkmingai išsaugota kainų kopija.', 'warning');
        }

        function fillSelect(select, items, firstLabel) {
            select.innerHTML = `<option value="">${firstLabel}</option>` + (items || []).map(item => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)} (${number(item.station_count)})</option>`).join('');
        }

        function renderFilters(body) {
            const filters = body?.data || {};
            const fuels = filters.fuels?.length ? filters.fuels : [{value: 'pb95', label: 'Pb 95'}, {value: 'diesel', label: 'Dyzelinas'}, {value: 'lpg', label: 'Dujos'}];
            if (!fuels.some(item => item.value === state.fuel)) state.fuel = fuels[0].value;
            get('[data-fuel-tabs]').innerHTML = fuels.map(item => `<button type="button" role="tab" data-fuel="${escapeHtml(item.value)}" aria-selected="${item.value === state.fuel}">${escapeHtml(item.label)} <small>${item.station_count ? number(item.station_count) : ''}</small></button>`).join('');
            get('[data-fuel-tabs]').querySelectorAll('button').forEach(button => button.addEventListener('click', () => {
                state.fuel = button.dataset.fuel;
                state.page = 1;
                get('[data-fuel-tabs]').querySelectorAll('button').forEach(item => item.setAttribute('aria-selected', String(item === button)));
                refresh();
            }));
            fillSelect(get('[data-city]'), filters.cities, 'Visa Lietuva');
            fillSelect(get('[data-brand]'), filters.brands, 'Visi tinklai');
        }

        function params() {
            return {
                fuel: state.fuel, page: state.page, per_page: state.perPage,
                sort: get('[data-sort]').value, city: get('[data-city]').value,
                brand: get('[data-brand]').value, q: get('[data-search]').value,
                lat: state.lat, lng: state.lng
            };
        }

        function renderStatistics(body) {
            const data = body?.data?.national || {};
            get('[data-average-price]').textContent = euro(data.average_price);
            get('[data-minimum-price]').textContent = euro(data.min_price);
            get('[data-station-count]').textContent = number(data.station_count);
        }

        function renderRankings(body) {
            const rows = body?.data || [];
            const container = get('[data-top-stations]');
            get('[data-top-fuel]').textContent = fuelLabels[state.fuel] || state.fuel;
            if (!rows.length) {
                container.innerHTML = '<p class="kuras-empty">Šiai degalų rūšiai kainų dar nėra.</p>';
                return;
            }
            container.innerHTML = rows.slice(0, 3).map((row, index) => `<article class="kuras-top-card"><span class="kuras-top-card__rank">${index + 1}</span><div><strong>${escapeHtml(row.brand || row.name)}</strong><small>${escapeHtml(row.address)}, ${escapeHtml(row.city || row.municipality || '')}</small></div><a class="kuras-top-card__price" href="${config.stationBaseUrl}${encodeURIComponent(row.id)}/">${euro(row.price)}</a></article>`).join('');
        }

        function renderStations(body) {
            const rows = body?.data || [];
            const pagination = body?.meta?.pagination || {page: 1, total_pages: 1, total: rows.length};
            state.totalPages = pagination.total_pages || 1;
            get('[data-results-count]').textContent = `Rasta ${number(pagination.total)}`;
            get('[data-page-label]').textContent = `${pagination.page} iš ${pagination.total_pages}`;
            get('[data-previous-page]').disabled = pagination.page <= 1;
            get('[data-next-page]').disabled = pagination.page >= pagination.total_pages;
            const table = get('[data-stations-table]');
            if (!rows.length) {
                table.innerHTML = '<tr><td colspan="3" class="kuras-empty">Pagal pasirinktus filtrus degalinių nerasta.</td></tr>';
                return;
            }
            table.innerHTML = rows.map(row => `<tr><td><a class="kuras-station-name" href="${config.stationBaseUrl}${encodeURIComponent(row.id)}/">${escapeHtml(row.name || row.brand)}</a><span class="kuras-station-brand">${escapeHtml(row.brand)}</span></td><td><span class="kuras-location-text">${escapeHtml(row.address)}<br>${escapeHtml(row.city || row.municipality || '')}${row.distance_km != null ? ` · ${Number(row.distance_km).toFixed(1)} km` : ''}</span></td><td class="kuras-price-cell"><strong class="kuras-price">${euro(row.price)}</strong><span class="kuras-station-brand">už litrą</span></td></tr>`).join('');
        }

        async function load(resource, query, render) {
            try { render(await api(resource, query)); }
            catch (error) { showNotice(error.message, 'error'); }
        }

        async function refresh() {
            get('[data-stations-table]').innerHTML = '<tr><td colspan="3" class="kuras-empty">Kraunami duomenys…</td></tr>';
            await Promise.all([
                load('statistics', {fuel: state.fuel}, renderStatistics),
                load('rankings', {fuel: state.fuel, limit: 3, city: get('[data-city]').value, brand: get('[data-brand]').value}, renderRankings),
                load('stations', params(), renderStations)
            ]);
            if (state.map) loadMarkers();
        }

        function initializeMap() {
            if (state.map || !window.L || !config.tileUrl) return;
            state.map = window.L.map(get('[data-map]'), {zoomControl: true}).setView([55.17, 23.88], 7);
            window.L.tileLayer(config.tileUrl, {maxZoom: 18, attribution: config.tileAttribution || ''}).addTo(state.map);
            state.markers = window.L.layerGroup().addTo(state.map);
            state.map.on('moveend', () => { clearTimeout(state.timer); state.timer = setTimeout(loadMarkers, 250); });
            loadMarkers();
            setTimeout(() => state.map.invalidateSize(), 50);
        }

        async function loadMarkers() {
            if (!state.map) return;
            const bounds = state.map.getBounds();
            const box = [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].map(value => value.toFixed(5)).join(',');
            try {
                const body = await api('map/stations', {fuel: state.fuel, bounds: box, limit: 500, city: get('[data-city]').value, brand: get('[data-brand]').value});
                const features = body?.data?.features || [];
                state.markers.clearLayers();
                features.forEach(feature => {
                    const p = feature.properties;
                    const marker = window.L.marker([feature.geometry.coordinates[1], feature.geometry.coordinates[0]], {icon: window.L.divIcon({className: '', html: `<span class="kuras-marker kuras-marker--${escapeHtml(p.tier)}">${euro(p.price)}</span>`, iconSize: [68, 28], iconAnchor: [34, 14]})});
                    marker.bindPopup(`<strong>${escapeHtml(p.brand || p.name)}</strong><br>${escapeHtml(p.address)}<a class="kuras-popup-link" href="${config.stationBaseUrl}${encodeURIComponent(p.id)}/">${euro(p.price)} · Plačiau</a>`).addTo(state.markers);
                });
                get('[data-map-note]').textContent = features.length ? `Šiame vaizde rodoma ${number(features.length)} degalinių.` : 'Šiame žemėlapio plote degalinių su koordinatėmis nerasta.';
            } catch (error) { get('[data-map-note]').textContent = error.message; }
        }

        function locate() {
            if (!navigator.geolocation) return showNotice('Ši naršyklė vietos nustatymo nepalaiko.', 'error');
            const button = get('[data-locate]');
            button.disabled = true;
            button.textContent = 'Nustatoma vieta…';
            navigator.geolocation.getCurrentPosition(position => {
                state.lat = Number(position.coords.latitude.toFixed(6));
                state.lng = Number(position.coords.longitude.toFixed(6));
                get('[data-sort]').querySelector('[value="distance_asc"]').disabled = false;
                get('[data-sort]').value = 'distance_asc';
                initializeMap();
                state.map?.setView([state.lat, state.lng], 12);
                state.page = 1;
                refresh();
                button.disabled = false;
                button.textContent = 'Vieta nustatyta';
            }, () => {
                showNotice('Vietos nustatyti nepavyko. Patikrink naršyklės leidimą.', 'error');
                button.disabled = false;
                button.textContent = 'Naudoti mano vietą';
            }, {enableHighAccuracy: false, timeout: 8000, maximumAge: 300000});
        }

        get('[data-filter-form]').addEventListener('submit', event => { event.preventDefault(); state.page = 1; refresh(); });
        get('[data-previous-page]').addEventListener('click', () => { if (state.page > 1) { state.page--; load('stations', params(), renderStations); } });
        get('[data-next-page]').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; load('stations', params(), renderStations); } });
        get('[data-locate]').addEventListener('click', locate);
        root.querySelectorAll('[data-view-tab]').forEach(tab => tab.addEventListener('click', () => {
            root.querySelectorAll('[data-view-tab]').forEach(item => item.setAttribute('aria-selected', String(item === tab)));
            root.dataset.mobileView = tab.dataset.viewTab;
            if (tab.dataset.viewTab === 'map') initializeMap();
        }));

        renderMeta(initial.meta);
        renderFilters(initial.filters);
        renderStatistics(initial.statistics);
        renderRankings(initial.rankings);
        renderStations(initial.stations);
        if (!initial.meta?.data) showNotice(initial.meta?.error?.message || 'Kainų duomenys laikinai nepasiekiami.', 'error');
        if (window.matchMedia('(min-width: 981px)').matches) initializeMap();
    }

    function station(root) {
        const panel = root.querySelector('[data-station-id]');
        if (!panel) return;
        const select = panel.querySelector('[data-history-fuel]');
        const chart = panel.querySelector('[data-history-chart]');
        async function loadHistory() {
            chart.innerHTML = '<p class="kuras-empty">Kraunama kainų istorija…</p>';
            try {
                const body = await api(`stations/${encodeURIComponent(panel.dataset.stationId)}/history`, {fuel: select.value, days: 30});
                const rows = body.data || [];
                if (rows.length < 2) { chart.innerHTML = '<p class="kuras-empty">Šiai degalų rūšiai istorijos dar nepakanka.</p>'; return; }
                const values = rows.map(row => Number(row.price));
                const min = Math.min(...values), max = Math.max(...values), span = Math.max(0.01, max - min);
                const points = rows.map((row, index) => `${(index / (rows.length - 1) * 100).toFixed(2)},${(92 - ((Number(row.price) - min) / span * 76)).toFixed(2)}`).join(' ');
                chart.innerHTML = `<div class="kuras-chart__labels"><span>${euro(max)}</span><span>${euro(min)}</span></div><svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="30 dienų kainos grafikas"><polyline points="${points}" fill="none" vector-effect="non-scaling-stroke"/></svg><div class="kuras-chart__dates"><span>${escapeHtml(rows[0].price_date || rows[0].date)}</span><span>${escapeHtml(rows[rows.length - 1].price_date || rows[rows.length - 1].date)}</span></div>`;
            } catch (error) { chart.innerHTML = `<p class="kuras-empty">${escapeHtml(error.message)}</p>`; }
        }
        select.addEventListener('change', loadHistory);
        loadHistory();
    }

    document.querySelectorAll('[data-kuras-finder]').forEach(finder);
    document.querySelectorAll('[data-kuras-station]').forEach(station);
}());
