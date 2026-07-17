<?php include __DIR__ . '/layout_top.php'; ?>

<div class="station-profile">

    <!-- Header -->
    <div class="station-profile__header">
        <div class="station-profile__brand">
            <?php if ($station['brand_logo']): ?>
                <img src="<?= htmlspecialchars($station['brand_logo']) ?>"
                     alt="<?= htmlspecialchars($station['brand_name']) ?>" class="brand-logo brand-logo--lg">
            <?php else: ?>
                <span class="brand-badge"><?= htmlspecialchars($station['brand_name']) ?></span>
            <?php endif; ?>
            <?php if ($station['is_sponsored']): ?>
                <span class="sponsored-badge">★ <?= __('station.sponsored') ?></span>
            <?php endif; ?>
        </div>
        <h1 class="station-profile__name"><?= htmlspecialchars($station['name']) ?></h1>
        <p class="station-profile__address">
            📍 <?= htmlspecialchars($station['address']) ?>, <?= htmlspecialchars($station['city']) ?>
        </p>
    </div>

    <!-- Promo banner -->
    <?php if ($station['is_sponsored'] && $station['promo_banner']): ?>
    <div class="promo-banner">
        <img src="<?= htmlspecialchars($station['promo_banner']) ?>" alt="Reklama" class="promo-banner__img">
    </div>
    <?php endif; ?>

    <div class="station-profile__body">

        <!-- Current prices -->
        <section class="card">
            <h2>
                <?php if (!$isStale): ?>
                    <?= __('station.today_prices') ?>
                <?php else: ?>
                    <?= __('prices.as_of', ['date' => $priceDate]) ?>
                <?php endif; ?>
            </h2>
            <?php if ($isStale): ?>
            <p class="stale-notice stale-notice--inline"><?= __('prices.stale_notice') ?></p>
            <?php endif; ?>
            <?php if ($todayPrices): ?>
            <table class="price-table">
                <thead><tr><th><?= __('station.fuel') ?></th><th><?= __('station.price') ?> <?= __('general.per_liter') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($todayPrices as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td class="price-table__price"><?= number_format((float)$p['price'], 3) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="muted"><?= __('station.no_prices') ?></p>
            <?php endif; ?>
        </section>

        <!-- Services -->
        <section class="card">
            <h2><?= __('station.services') ?></h2>
            <div class="services-list">
                <span class="service <?= $station['has_coffee']  ? 'service--active' : 'service--inactive' ?>">☕ <?= __('station.svc_coffee') ?></span>
                <span class="service <?= $station['has_carwash'] ? 'service--active' : 'service--inactive' ?>">🚗 <?= __('station.svc_carwash') ?></span>
                <span class="service <?= $station['has_shop']    ? 'service--active' : 'service--inactive' ?>">🛒 <?= __('station.svc_shop') ?></span>
                <span class="service <?= $station['has_loyalty'] ? 'service--active' : 'service--inactive' ?>">💳 <?= __('station.svc_loyalty') ?></span>
            </div>
        </section>

        <!-- Price history chart -->
        <?php if ($priceHistory): ?>
        <section class="card">
            <h2><?= __('station.history') ?></h2>
            <canvas id="priceChart" height="120"></canvas>
        </section>
        <?php endif; ?>

        <!-- Profile text -->
        <?php if ($station['profile_text']): ?>
        <section class="card">
            <h2><?= __('station.about') ?></h2>
            <div class="profile-text"><?= $station['profile_text'] ?></div>
        </section>
        <?php endif; ?>

        <!-- Mini map -->
        <?php if ($station['lat'] && $station['lng']): ?>
        <section class="card">
            <h2><?= __('station.location') ?></h2>
            <div id="miniMap" style="height:250px;"
                 data-lat="<?= (float)$station['lat'] ?>"
                 data-lng="<?= (float)$station['lng'] ?>"
                 data-name="<?= htmlspecialchars($station['name']) ?>">
            </div>
        </section>
        <?php endif; ?>

        <!-- Nearby stations -->
        <?php if ($nearby): ?>
        <section class="card" style="grid-column: 1 / -1">
            <h2><?= __('station.nearby') ?></h2>
            <div style="overflow-x:auto">
            <table class="price-table">
                <thead>
                    <tr>
                        <th><?= __('stations.network') ?></th>
                        <th><?= __('station.address') ?></th>
                        <th style="text-align:right">Pb 95</th>
                        <th style="text-align:right">Dyzelinas</th>
                        <th style="text-align:right">LPG</th>
                        <th style="text-align:right">km</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($nearby as $n):
                    $fmt = fn($v) => $v !== null ? number_format((float)$v, 3) : '—';
                ?>
                <tr>
                    <td><a href="/station/<?= $n['id'] ?>"><?= htmlspecialchars($n['brand']) ?></a></td>
                    <td><?= htmlspecialchars($n['address']) ?><?= $n['city'] ? ', ' . htmlspecialchars($n['city']) : '' ?></td>
                    <td class="price-table__price" style="text-align:right"><?= $fmt($n['price_pb95']) ?></td>
                    <td class="price-table__price" style="text-align:right"><?= $fmt($n['price_diesel']) ?></td>
                    <td class="price-table__price" style="text-align:right"><?= $fmt($n['price_lpg']) ?></td>
                    <td style="text-align:right;color:#888;font-size:.85em"><?= number_format((float)$n['distance_km'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        <?php endif; ?>

    </div><!-- /.station-profile__body -->
</div>

<?php
$stationId = $station['id'];
$stationLat = $station['lat'];
$stationLng = $station['lng'];
$extraScripts = '
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const STATION_ID = ' . (int)$stationId . ';
const STATION_LAT = ' . ($stationLat ?? 'null') . ';
const STATION_LNG = ' . ($stationLng ?? 'null') . ';
</script>
<script src="/assets/js/station.js"></script>
';
include __DIR__ . '/layout_bottom.php';
?>
