<?php include __DIR__ . '/layout_top.php'; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <h1><?= __('admin.stations.title') ?></h1>
    <?php if (!empty($_GET['updated'])): ?>
        <div class="alert alert--success"><?= __('admin.stations.saved') ?></div>
    <?php endif; ?>
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th><?= __('admin.stations.col_name') ?></th>
            <th><?= __('admin.stations.col_city') ?></th>
            <th><?= __('admin.stations.col_brand') ?></th>
            <th><?= __('admin.stations.col_coords') ?></th>
            <th><?= __('admin.stations.col_spon') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($stations as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['city']) ?></td>
            <td><?= htmlspecialchars($s['brand'] ?? $s['brand_name'] ?? '') ?></td>
            <td><?= $s['lat'] ? '✅' : '❌' ?></td>
            <td><?= $s['is_sponsored'] ? '⭐' : '' ?></td>
            <td><a href="/admin/station/<?= $s['id'] ?>"><?= __('admin.stations.edit') ?></a></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$totalPages = (int)ceil($total / 50);
$page = (int)($_GET['page'] ?? 1);
if ($totalPages > 1):
?>
<div class="pagination" style="margin-top:1rem;">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="/admin/stations?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_bottom.php'; ?>
