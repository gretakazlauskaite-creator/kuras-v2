/* filters.js — Station list page */
(function () {
    'use strict';

    const container  = document.getElementById('stationsContainer');
    const pagination = document.getElementById('pagination');
    const muniEl     = document.getElementById('filterMuni');
    const cityEl     = document.getElementById('filterCity');
    const brandEl    = document.getElementById('filterBrand');
    const sortEl     = document.getElementById('filterSort');
    const mapBtn     = document.getElementById('showOnMapBtn');

    // City filter not yet useful — keep in DOM but hidden
    if (cityEl) cityEl.closest('select') && (cityEl.style.display = 'none');

    let currentPage = 1;
    let lastAvg     = 0;

    function getFuel() {
        return document.querySelector('input[name="fuel"]:checked')?.value || 'pb95';
    }

    function getFilters() {
        return {
            municipality: muniEl?.value || '',
            city:         cityEl?.value || '',
            brand:        brandEl?.value || '',
            fuel:         getFuel(),
            page:         currentPage,
        };
    }

    function buildQuery(filters) {
        return Object.entries(filters)
            .filter(([, v]) => v !== '' && v !== 0)
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
            .join('&');
    }

    // ── Brand filter reload ───────────────────────────────────

    function reloadBrands(municipality, fuel, keepBrand) {
        const qs = 'available_brands=1'
            + (municipality ? '&municipality=' + encodeURIComponent(municipality) : '')
            + (fuel         ? '&fuel='         + encodeURIComponent(fuel)         : '');

        fetch('/api/stations?' + qs)
            .then(r => r.json())
            .then(brands => {
                const prev = keepBrand || brandEl.value;
                brandEl.innerHTML = `<option value="">${escHtml(I18N.all_brands)}</option>`;
                brands.forEach(name => {
                    const opt = document.createElement('option');
                    opt.value       = name;
                    opt.textContent = name;
                    if (name === prev) opt.selected = true;
                    brandEl.appendChild(opt);
                });
                // If the previously selected brand is no longer available, clear it
                if (prev && brandEl.value !== prev) {
                    brandEl.value = '';
                }
            });
    }

    // ── Station list loading ──────────────────────────────────

    function loadStations() {
        container.innerHTML = '<div class="loading-spinner">Kraunama...</div>';
        const f   = getFilters();
        const qs  = buildQuery(f);

        if (mapBtn) {
            const mapParams = { fuel: f.fuel };
            if (f.municipality) mapParams.municipality = f.municipality;
            if (f.city)         mapParams.city         = f.city;
            mapBtn.href = '/map?' + Object.entries(mapParams)
                .map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
        }

        fetch('/api/stations?' + qs)
            .then(r => r.json())
            .then(data => renderStations(data, f.fuel))
            .catch(() => {
                container.innerHTML = '<p class="muted" style="padding:2rem">Klaida kraunant duomenis.</p>';
            });
    }

    function renderStations(data, fuel) {
        const stations = data.data || [];
        const meta     = data.meta || {};
        const total    = meta.total || 0;

        if (!stations.length) {
            container.innerHTML = '<p class="muted" style="padding:2rem;text-align:center">' + I18N.not_found + '</p>';
            pagination.innerHTML = '';
            return;
        }

        const prices = stations.map(s => s.price).filter(Boolean);
        lastAvg = prices.length ? prices.reduce((a, b) => a + b, 0) / prices.length : 0;

        container.innerHTML = stations.map(s => renderCard(s)).join('');
        renderPagination(total, meta.page, meta.per_page);
    }

    function renderCard(s) {
        const price    = s.price != null ? parseFloat(s.price) : null;
        const priceStr = price != null ? price.toFixed(3) + ' ' + I18N.per_liter : '—';

        let deltaHtml = '';
        if (price && lastAvg) {
            const delta = price - lastAvg;
            const sign  = delta >= 0 ? '+' : '';
            const cls   = delta > 0 ? 'station-card__delta--plus' : 'station-card__delta--minus';
            deltaHtml   = `<div class="station-card__delta ${cls}">${sign}${(delta * 100).toFixed(1)} ${I18N.vs_avg}</div>`;
        }

        const sponsored = s.is_sponsored ? 'station-card--sponsored' : '';
        const star      = s.is_sponsored ? `<span style="color:#eab308" title="${I18N.sponsored}">★</span> ` : '';

        return `
        <a href="/station/${s.id}" class="station-card ${sponsored}" style="display:flex; text-decoration:none; color:inherit;">
            <div class="station-card__brand">${star}${escHtml(s.brand || '')}</div>
            <div class="station-card__info">
                <div class="station-card__name">${escHtml(s.name || '')}</div>
                <div class="station-card__addr">${escHtml(s.address || '')}</div>
            </div>
            <div style="text-align:right">
                <div class="station-card__price">${priceStr}</div>
                ${deltaHtml}
            </div>
        </a>`;
    }

    function renderPagination(total, page, perPage) {
        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) { pagination.innerHTML = ''; return; }

        const pages = [];
        for (let i = 1; i <= totalPages; i++) {
            pages.push(`<a href="#" data-page="${i}" class="${i === page ? 'active' : ''}">${i}</a>`);
        }
        pagination.innerHTML = pages.join('');

        pagination.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                currentPage = parseInt(a.dataset.page, 10);
                loadStations();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    }

    // ── Event wiring ──────────────────────────────────────────

    muniEl?.addEventListener('change', () => {
        currentPage = 1;
        brandEl.value = '';
        reloadBrands(muniEl.value, getFuel());
        loadStations();
    });

    document.querySelectorAll('input[name="fuel"]').forEach(r => {
        r.addEventListener('change', () => {
            currentPage = 1;
            reloadBrands(muniEl?.value || '', getFuel());
            loadStations();
        });
    });

    brandEl?.addEventListener('change', () => { currentPage = 1; loadStations(); });
    sortEl?.addEventListener('change',  () => { currentPage = 1; loadStations(); });

    // ── Initial load ──────────────────────────────────────────

    const params   = new URLSearchParams(location.search);
    const urlMuni  = params.get('municipality') || '';

    if (urlMuni && muniEl) muniEl.value = urlMuni;

    // Load brands filtered to current context, then stations
    reloadBrands(muniEl?.value || '', getFuel());
    loadStations();

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
