<!DOCTYPE html>
<html lang="<?= \App\I18n::getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Kuras Pricer') ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc ?? '') ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($pageTitle ?? '') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc ?? '') ?>">
    <meta property="og:type"        content="website">
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>

<header class="site-header">
    <div class="container">
        <a href="/" class="site-logo">
            <span class="logo-icon">⛽</span>
            <span class="logo-text">Kuras<strong>Pricer</strong></span>
        </a>
        <nav class="site-nav">
            <a href="/"          class="<?= ($_SERVER['REQUEST_URI'] === '/' || strtok($_SERVER['REQUEST_URI'],'?') === '/') ? 'active' : '' ?>"><?= __('nav.prices') ?></a>
            <a href="/stations"  class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/stations') ? 'active' : '' ?>"><?= __('nav.stations') ?></a>
            <a href="/map"       class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/map')      ? 'active' : '' ?>"><?= __('nav.map') ?></a>
            <a href="/rankings"  class="<?= str_starts_with($_SERVER['REQUEST_URI'], '/rankings') ? 'active' : '' ?>"><?= __('nav.rankings') ?></a>
        </nav>
        <div class="lang-switcher">
            <?php foreach (\App\I18n::getSupported() as $lng): ?>
                <?php $active = $lng === \App\I18n::getLang(); ?>
                <a href="<?= htmlspecialchars(\App\I18n::langUrl($lng)) ?>"
                   class="lang-btn <?= $active ? 'lang-btn--active' : '' ?>"
                   hreflang="<?= $lng ?>"><?= strtoupper($lng) ?></a>
            <?php endforeach; ?>
        </div>
        <button class="nav-toggle" aria-label="<?= __('nav.menu') ?>" onclick="document.querySelector('.site-nav').classList.toggle('open')">☰</button>
    </div>
</header>

<?php if (!empty($ads['header'])): ?>
    <div class="ad-banner ad-banner--header container"><?= $ads['header'] ?></div>
<?php endif; ?>

<main class="site-main">
    <div class="container">
