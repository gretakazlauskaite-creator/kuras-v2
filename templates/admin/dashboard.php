<?php include __DIR__ . '/layout_top.php'; ?>

<h1>Dashboard</h1>

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-card__val"><?= number_format($stationCount) ?></div>
        <div class="stat-card__label"><?= __('admin.dash.stations') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__val"><?= number_format($priceCount) ?></div>
        <div class="stat-card__label"><?= __('admin.dash.prices') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__val"><?= number_format($alertCount) ?></div>
        <div class="stat-card__label"><?= __('admin.dash.alerts') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card__val" style="font-size:1rem"><?= $lastImport ? date('d.m H:i', strtotime($lastImport)) : '—' ?></div>
        <div class="stat-card__label"><?= __('admin.dash.last_import') ?></div>
    </div>
</div>

<section>
    <h2><?= __('admin.dash.run_import') ?></h2>
    <form method="POST" action="/admin/import" target="_blank">
        <button type="submit" class="btn btn--primary"><?= __('admin.dash.import_btn') ?></button>
    </form>
    <p class="muted" style="margin-top:.5rem"><?= __('admin.dash.import_hint') ?></p>
</section>

<?php include __DIR__ . '/layout_bottom.php'; ?>
