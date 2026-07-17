<?php include __DIR__ . '/layout_top.php'; ?>

<h1 class="page-title"><?= __('stations.title') ?></h1>

<div class="filter-bar" id="filterBar">

    <select id="filterMuni" class="input" aria-label="<?= __('stations.district') ?>">
        <option value=""><?= __('stations.all_districts') ?></option>
        <?php foreach ($municipalities as $muni): ?>
            <option value="<?= htmlspecialchars($muni) ?>"
                <?= ($_GET['municipality'] ?? '') === $muni ? 'selected' : '' ?>>
                <?= htmlspecialchars($muni) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select id="filterCity" class="input" aria-label="<?= __('stations.city') ?>">
        <option value=""><?= __('stations.all_cities') ?></option>
    </select>

    <select id="filterBrand" class="input" aria-label="<?= __('stations.network') ?>">
        <option value=""><?= __('stations.all_brands') ?></option>
        <?php foreach ($brands as $brand): ?>
            <option value="<?= htmlspecialchars($brand['name']) ?>"
                <?= ($_GET['brand'] ?? '') === $brand['name'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($brand['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div class="fuel-radio-group" role="group" aria-label="<?= __('stations.fuel_type') ?>">
        <?php foreach ($fuelTypes as $ft): ?>
        <label class="fuel-radio">
            <input type="radio" name="fuel" value="<?= $ft['slug'] ?>"
                <?= ($_GET['fuel'] ?? 'pb95') === $ft['slug'] ? 'checked' : '' ?>>
            <?= htmlspecialchars($ft['name']) ?>
        </label>
        <?php endforeach; ?>
    </div>

    <select id="filterSort" class="input" aria-label="<?= __('stations.sort') ?>">
        <option value="price_asc"><?= __('stations.sort_price_asc') ?></option>
        <option value="price_desc"><?= __('stations.sort_price_desc') ?></option>
    </select>

    <a id="showOnMapBtn" href="/map" class="btn btn--secondary"><?= __('stations.show_map') ?></a>
</div>

<div id="stationsContainer">
    <div class="loading-spinner"><?= __('stations.loading') ?></div>
</div>

<nav id="pagination" class="pagination" aria-label="Puslapiavimas"></nav>

<script>
const I18N = {
    not_found:   <?= json_encode(__('stations.not_found')) ?>,
    loading:     <?= json_encode(__('stations.loading')) ?>,
    vs_avg:      <?= json_encode(__('stations.vs_avg')) ?>,
    sponsored:   <?= json_encode(__('stations.sponsored')) ?>,
    per_liter:   <?= json_encode(__('general.per_liter')) ?>,
    all_cities:  <?= json_encode(__('stations.all_cities')) ?>,
    all_brands:  <?= json_encode(__('stations.all_brands')) ?>,
};
</script>

<?php
$extraScripts = '<script src="/assets/js/filters.js"></script>';
include __DIR__ . '/layout_bottom.php';
?>
