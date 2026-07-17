<?php
$extraHead = '<link rel="stylesheet" href="/assets/css/app.css">';
include __DIR__ . '/layout_top.php';
?>

<section class="hero">
    <h1><?= __('home.title') ?></h1>
    <p class="hero-sub">
        <?php if (!$isStale): ?>
            <?= __('home.subtitle') ?> — <?= $priceDate ?>
        <?php else: ?>
            <?= __('prices.as_of', ['date' => $priceDate]) ?>
        <?php endif; ?>
    </p>
</section>

<?php if ($isStale): ?>
<div class="stale-notice">
    <?= __('prices.stale_notice') ?>
</div>
<?php endif; ?>

<!-- Fuel type tabs -->
<div class="fuel-tabs" role="tablist">
    <?php foreach ($fuelTypes as $ft): ?>
    <a href="/?fuel=<?= $ft['slug'] ?>"
       class="fuel-tab <?= $ft['slug'] === $activeFuel['slug'] ? 'fuel-tab--active' : '' ?>"
       role="tab">
        <?= htmlspecialchars($ft['name']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Best prices by city grid -->
<section class="city-grid">
    <?php
    $majorCities = ['Vilnius','Kaunas','Klaipėda','Šiauliai','Panevėžys','Alytus','Marijampolė'];
    foreach ($majorCities as $city):
        $best = $bestByCity[$city] ?? null;
    ?>
    <article class="city-card <?= $best ? '' : 'city-card--empty' ?>">
        <div class="city-card__city"><?= htmlspecialchars($city) ?></div>
        <?php if ($best): ?>
            <div class="city-card__price"><?= number_format((float)$best['price'], 3) ?> <?= __('general.per_liter') ?></div>
            <div class="city-card__brand">
                <?php if ($best['logo']): ?>
                    <img src="<?= htmlspecialchars($best['logo']) ?>" alt="<?= htmlspecialchars($best['brand']) ?>" class="brand-logo">
                <?php else: ?>
                    <span class="brand-name"><?= htmlspecialchars($best['brand']) ?></span>
                <?php endif; ?>
            </div>
            <div class="city-card__station"><?= htmlspecialchars($best['station_name']) ?></div>
            <a href="/stations?city=<?= urlencode($city) ?>&fuel=<?= $activeFuel['slug'] ?>" class="city-card__link">
                <?= __('home.see_all') ?>
            </a>
        <?php else: ?>
            <div class="city-card__empty"><?= __('home.no_data') ?></div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>

<!-- Best Station Rankings -->
<section class="rankings-section">
    <h2>🏆 <?= __('home.rankings_title') ?> — <?= htmlspecialchars($activeFuel['name']) ?></h2>
    <div class="rankings-grid">

        <?php foreach ([
            ['label' => __('rank.today'), 'key' => 'days', 'station' => $bestDay,   'days' => false],
            ['label' => __('rank.week'),  'key' => 'days', 'station' => $bestWeek,  'days' => true],
            ['label' => __('rank.month'), 'key' => 'days', 'station' => $bestMonth, 'days' => true],
        ] as $rank): ?>
        <div class="rank-card">
            <div class="rank-card__period"><?= $rank['label'] ?></div>
            <?php if ($rank['station']): $s = $rank['station']; ?>
                <div class="rank-card__brand"><?= htmlspecialchars($s['brand'] ?? '') ?></div>
                <div class="rank-card__name"><?= htmlspecialchars($s['name'] ?? '') ?></div>
                <div class="rank-card__city"><?= htmlspecialchars($s['city'] ?? '') ?></div>
                <?php if (!empty($s['price'])): ?>
                    <div class="rank-card__price"><?= number_format((float)$s['price'], 3) ?> <?= __('general.per_liter') ?></div>
                <?php endif; ?>
                <?php if (!empty($s['days_cheapest']) && $rank['days']): ?>
                    <div class="rank-card__days"><?= __('rank.days_cheapest', ['n' => (int)$s['days_cheapest']]) ?></div>
                <?php endif; ?>
                <a href="/station/<?= $s['id'] ?>" class="rank-card__link"><?= __('rank.view') ?></a>
            <?php else: ?>
                <div class="rank-card__empty"><?= __('rank.no_data') ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    </div>
</section>

<!-- Price Alert CTA -->
<section class="alert-cta">
    <div class="alert-cta__inner">
        <h2><?= __('alert.title') ?></h2>
        <p><?= __('alert.desc') ?></p>
        <form id="alertForm" class="alert-form">
            <div class="alert-form__row">
                <input type="email" name="email" placeholder="<?= __('alert.email') ?>" required class="input">
                <select name="fuel" class="input">
                    <?php foreach ($fuelTypes as $ft): ?>
                        <option value="<?= $ft['slug'] ?>" <?= $ft['slug'] === $activeFuel['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ft['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="target_price" step="0.001" min="0.5" max="5"
                       placeholder="<?= __('alert.target_price') ?>" required class="input">
                <button type="submit" class="btn btn--primary"><?= __('alert.submit') ?></button>
            </div>
            <div id="alertFormMsg" class="alert-form__msg" hidden></div>
        </form>
    </div>
</section>

<?php if (!empty($ads['realestate'])): ?>
<section class="ad-slot ad-slot--realestate">
    <?= $ads['realestate'] ?>
</section>
<?php endif; ?>

<?php
$extraScripts = '<script src="/assets/js/alerts.js"></script>';
include __DIR__ . '/layout_bottom.php';
?>
