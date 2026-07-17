<?php
$ads = [];
$extraHead = '
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<style>
  body { overflow: hidden; }
  .site-main { padding: 0; }
  .site-main > .container { padding: 0; max-width: 100%; }
</style>';
include __DIR__ . '/layout_top.php';
?>

<div class="map-layout">
    <!-- Sidebar -->
    <aside class="map-sidebar">
        <div class="map-controls">
            <h2 class="map-controls__title"><?= __('map.filters') ?></h2>

            <label class="map-label"><?= __('map.fuel_type') ?></label>
            <div class="fuel-btns" id="fuelBtns">
                <button class="fuel-btn fuel-btn--active" data-fuel="pb95">Pb 95</button>
                <button class="fuel-btn" data-fuel="pb98">Pb 98</button>
                <button class="fuel-btn" data-fuel="diesel"><?= __('map.diesel') ?></button>
                <button class="fuel-btn" data-fuel="lpg">LPG</button>
            </div>

            <label class="map-label" for="cityFilter"><?= __('map.city') ?></label>
            <select id="cityFilter" class="input">
                <option value=""><?= __('map.all_cities') ?></option>
                <option>Vilnius</option>
                <option>Kaunas</option>
                <option>Klaipėda</option>
                <option>Šiauliai</option>
                <option>Panevėžys</option>
                <option>Alytus</option>
                <option>Marijampolė</option>
            </select>

            <div class="map-legend">
                <div class="legend-item"><span class="legend-dot legend-dot--cheap"></span> <?= __('map.legend_cheap') ?></div>
                <div class="legend-item"><span class="legend-dot legend-dot--average"></span> <?= __('map.legend_avg') ?></div>
                <div class="legend-item"><span class="legend-dot legend-dot--expensive"></span> <?= __('map.legend_expensive') ?></div>
                <div class="legend-item"><span class="legend-dot legend-dot--sponsored">★</span> <?= __('map.legend_sponsored') ?></div>
            </div>
        </div>

        <div class="map-best-list">
            <h3><?= __('map.top5') ?></h3>
            <ol id="bestInView"></ol>
        </div>
    </aside>

    <!-- Map container -->
    <div id="map"></div>
</div>

<?php
$extraScripts = '
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="/assets/js/map.js"></script>';
include __DIR__ . '/layout_bottom.php';
?>
