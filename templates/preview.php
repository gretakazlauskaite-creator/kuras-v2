<!doctype html>
<html lang="lt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Kuro kainų peržiūra — Kuras Pricer</title>
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/assets/css/preview.css">
</head>
<body>
<header class="preview-header">
    <div class="preview-shell preview-header__inner">
        <a class="preview-brand" href="/preview" aria-label="Kuras Pricer pradžia">
            <span class="preview-brand__mark" aria-hidden="true">K</span>
            <span>Kuras <strong>Pricer</strong></span>
        </a>
        <span class="preview-chip">Darbinė peržiūra</span>
        <a class="preview-api-link" href="/api/v1/meta">API duomenys</a>
    </div>
</header>

<main>
    <section class="preview-hero">
        <div class="preview-shell preview-hero__grid">
            <div>
                <p class="preview-eyebrow">Oficialūs LEA duomenys</p>
                <h1>Rask pigesnius degalus šalia savęs</h1>
                <p class="preview-lead">Palygink Lietuvos degalinių kainas, filtruok pagal vietą ir tinklą, o pigiausius variantus matyk vienoje vietoje.</p>
                <div class="preview-source" id="sourceStatus" aria-live="polite">
                    <span class="preview-source__dot"></span>
                    <span>Kraunami naujausi duomenys…</span>
                </div>
            </div>
            <div class="preview-summary" aria-label="Kainų santrauka">
                <div><span>Vidutinė kaina</span><strong id="averagePrice">—</strong></div>
                <div><span>Mažiausia kaina</span><strong id="minimumPrice">—</strong></div>
                <div><span>Degalinių su kaina</span><strong id="stationCount">—</strong></div>
            </div>
        </div>
    </section>

    <section class="preview-shell preview-content">
        <div class="preview-fuels" id="fuelTabs" role="tablist" aria-label="Degalų rūšis"></div>

        <form class="preview-filters" id="filtersForm">
            <label>
                <span>Paieška</span>
                <input id="searchInput" type="search" placeholder="Miestas, adresas ar tinklas">
            </label>
            <label>
                <span>Savivaldybė</span>
                <select id="municipalityFilter"><option value="">Visa Lietuva</option></select>
            </label>
            <label>
                <span>Tinklas</span>
                <select id="brandFilter"><option value="">Visi tinklai</option></select>
            </label>
            <label>
                <span>Rūšiavimas</span>
                <select id="sortFilter">
                    <option value="price_asc">Pigiausi pirmiausia</option>
                    <option value="price_desc">Brangiausi pirmiausia</option>
                    <option value="name_asc">Pagal pavadinimą</option>
                </select>
            </label>
            <button type="submit">Rodyti rezultatus</button>
        </form>

        <section class="preview-top" aria-labelledby="topTitle">
            <div class="preview-section-heading">
                <div>
                    <p class="preview-eyebrow">Šiandienos TOP</p>
                    <h2 id="topTitle">Pigiausios degalinės</h2>
                </div>
                <span id="topFuelLabel">—</span>
            </div>
            <div class="preview-top__grid" id="topStations">
                <div class="preview-skeleton"></div><div class="preview-skeleton"></div><div class="preview-skeleton"></div>
            </div>
        </section>

        <div class="preview-results">
            <section class="preview-panel preview-list" aria-labelledby="resultsTitle">
                <div class="preview-panel__header">
                    <div>
                        <p class="preview-eyebrow">Kainų lentelė</p>
                        <h2 id="resultsTitle">Degalinės</h2>
                    </div>
                    <span id="resultsCount">—</span>
                </div>
                <div class="preview-table-wrap">
                    <table>
                        <thead><tr><th>Degalinė</th><th>Vieta</th><th class="preview-price-cell">Kaina</th></tr></thead>
                        <tbody id="stationsTable"><tr><td colspan="3" class="preview-empty">Kraunami duomenys…</td></tr></tbody>
                    </table>
                </div>
                <div class="preview-pagination">
                    <button id="previousPage" type="button">Atgal</button>
                    <span id="pageLabel">1 puslapis</span>
                    <button id="nextPage" type="button">Toliau</button>
                </div>
            </section>

            <section class="preview-panel preview-map-panel" aria-labelledby="mapTitle">
                <div class="preview-panel__header">
                    <div><p class="preview-eyebrow">Žemėlapis</p><h2 id="mapTitle">Kainos aplinkui</h2></div>
                    <button class="preview-location" id="locateButton" type="button">Rasti mane</button>
                </div>
                <div id="previewMap" aria-label="Interaktyvus degalinių žemėlapis"></div>
                <p class="preview-map-note" id="mapNote">Žemėlapyje rodomos tik degalinės su patvirtintomis koordinatėmis.</p>
            </section>
        </div>

        <aside class="preview-disclaimer">
            <strong>Svarbu apie duomenis</strong>
            <p>LEA skelbia darbo dienomis surinktas 10:00 val. kainas. Tai oficialūs kasdieniai, bet ne realaus laiko duomenys. Visada rodome šaltinio datą ir paskutinio sėkmingo importo laiką.</p>
        </aside>
    </section>
</main>

<script>
window.KURAS_PREVIEW_CONFIG = <?= json_encode([
    'tileUrl' => $tileUrl,
    'tileAttribution' => $tileAttribution,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="/assets/js/preview.js"></script>
</body>
</html>
