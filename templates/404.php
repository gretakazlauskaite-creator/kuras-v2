<?php
$pageTitle = __('404.title') . ' — Kuras Pricer';
$pageDesc  = __('404.desc');
$ads = [];
include __DIR__ . '/layout_top.php';
?>

<div class="error-page">
    <div class="error-page__icon">⛽</div>
    <h1><?= __('404.title') ?></h1>
    <p><?= __('404.desc') ?></p>
    <a href="/" class="btn btn--primary"><?= __('404.back') ?></a>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>
