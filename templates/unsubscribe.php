<?php
$pageTitle = __('alert.unsub_ok') . ' — Kuras Pricer';
$pageDesc  = '';
$ads = [];
include __DIR__ . '/layout_top.php';
?>

<div class="info-page">
    <?php if (!empty($success)): ?>
        <div class="alert alert--success">
            <?= __('unsub.success') ?>
        </div>
    <?php else: ?>
        <div class="alert alert--error">
            ❌ <?= htmlspecialchars($error ?? __('alert.unsub_err')) ?>
        </div>
    <?php endif; ?>
    <a href="/" class="btn btn--primary"><?= __('unsub.back') ?></a>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>
