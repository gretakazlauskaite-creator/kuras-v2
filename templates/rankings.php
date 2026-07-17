<?php include __DIR__ . '/layout_top.php'; ?>

<h1 class="page-title"><?= __('rankings.title') ?></h1>

<?php if ($isStale): ?>
<div class="stale-notice">
    <?= __('prices.stale_notice') ?>
</div>
<?php endif; ?>

<div class="fuel-tabs">
    <?php foreach ($fuelTypes as $ft): ?>
    <a href="/rankings?fuel=<?= $ft['slug'] ?>"
       class="fuel-tab <?= $ft['slug'] === $activeFuel['slug'] ? 'fuel-tab--active' : '' ?>">
        <?= htmlspecialchars($ft['name']) ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="rankings-grid rankings-grid--full">

    <?php
    $periods = [
        ['label' => '🗓 ' . __('rank.today'), 'desc' => __('rankings.desc_today'), 'station' => $bestDay, 'days' => false],
        ['label' => '📅 ' . __('rank.week'),  'desc' => __('rankings.desc_week'),  'station' => $bestWeek,  'days' => true],
        ['label' => '📆 ' . __('rank.month'), 'desc' => __('rankings.desc_month'), 'station' => $bestMonth, 'days' => true],
    ];
    foreach ($periods as $rank): ?>

    <div class="rank-card rank-card--full">
        <div class="rank-card__period"><?= $rank['label'] ?></div>
        <p class="rank-card__desc"><?= $rank['desc'] ?></p>
        <?php if ($rank['station']): $s = $rank['station']; ?>
            <?php if ($s['logo'] ?? null): ?>
                <img src="<?= htmlspecialchars($s['logo']) ?>" alt="<?= htmlspecialchars($s['brand']) ?>" class="brand-logo">
            <?php else: ?>
                <div class="rank-card__brand"><?= htmlspecialchars($s['brand'] ?? '') ?></div>
            <?php endif; ?>
            <div class="rank-card__name"><?= htmlspecialchars($s['name'] ?? '') ?></div>
            <div class="rank-card__city"><?= htmlspecialchars($s['city'] ?? '') ?></div>
            <?php if (!empty($s['price'])): ?>
                <div class="rank-card__price"><?= number_format((float)$s['price'], 3) ?> <?= __('general.per_liter') ?> <?= __('rank.today_lower') ?></div>
            <?php endif; ?>
            <?php if ($rank['days'] && !empty($s['days_cheapest'])): ?>
                <div class="rank-card__stat"><?= __('rank.days_cheapest', ['n' => (int)$s['days_cheapest']]) ?></div>
            <?php endif; ?>
            <a href="/station/<?= $s['id'] ?>" class="btn btn--primary rank-card__btn"><?= __('rank.view') ?></a>
        <?php else: ?>
            <div class="rank-card__empty"><?= __('rank.no_data') ?></div>
        <?php endif; ?>
    </div>

    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>
