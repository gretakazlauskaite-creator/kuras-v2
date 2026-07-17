/* station.js — Price history chart + mini map */
(function () {
    'use strict';

    // ── Price history chart ────────────────────────────────────
    const canvas = document.getElementById('priceChart');
    if (canvas && typeof STATION_ID !== 'undefined') {
        fetch(`/api/prices?station_id=${STATION_ID}&days=30`)
            .then(r => r.json())
            .then(data => renderChart(data.datasets || []))
            .catch(err => console.warn('Chart fetch error', err));
    }

    function renderChart(datasets) {
        if (!datasets.length) return;

        const FUEL_COLORS = {
            pb95:   { border: '#0ea5e9', bg: 'rgba(14,165,233,.1)' },
            pb98:   { border: '#8b5cf6', bg: 'rgba(139,92,246,.1)' },
            diesel: { border: '#f59e0b', bg: 'rgba(245,158,11,.1)' },
            lpg:    { border: '#22c55e', bg: 'rgba(34,197,94,.1)'  },
        };

        // Collect all unique dates
        const allDates = [...new Set(
            datasets.flatMap(ds => ds.data.map(d => d.date))
        )].sort();

        const chartDatasets = datasets.map(ds => {
            const dateMap = Object.fromEntries(ds.data.map(d => [d.date, d.price]));
            const c = FUEL_COLORS[ds.fuel] || { border: '#64748b', bg: 'rgba(100,116,139,.1)' };
            return {
                label:           ds.label,
                data:            allDates.map(d => dateMap[d] ?? null),
                borderColor:     c.border,
                backgroundColor: c.bg,
                tension:         0.3,
                fill:            true,
                spanGaps:        true,
                pointRadius:     2,
            };
        });

        new Chart(canvas, {
            type: 'line',
            data: { labels: allDates, datasets: chartDatasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y?.toFixed(3) ?? '—'} €/L`,
                        },
                    },
                },
                scales: {
                    y: {
                        ticks: { callback: v => v.toFixed(3) + ' €' },
                    },
                },
            },
        });
    }

    // ── Mini map ───────────────────────────────────────────────
    const miniMapEl = document.getElementById('miniMap');
    if (miniMapEl &&
        typeof STATION_LAT !== 'undefined' && STATION_LAT &&
        typeof STATION_LNG !== 'undefined' && STATION_LNG) {

        // Leaflet is loaded on this page via inline script in station.php
        const miniMap = L.map('miniMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '© OpenStreetMap',
        }).addTo(miniMap);

        miniMap.setView([STATION_LAT, STATION_LNG], 15);

        L.marker([STATION_LAT, STATION_LNG])
            .addTo(miniMap)
            .bindPopup(miniMapEl.dataset.name || 'Degalinė')
            .openPopup();
    }
})();
