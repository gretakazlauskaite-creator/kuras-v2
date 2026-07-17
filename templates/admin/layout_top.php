<!DOCTYPE html>
<html lang="<?= \App\I18n::getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin — Kuras Pricer</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .admin-layout { display: flex; min-height: 100vh; }
        .admin-sidebar { width: 200px; background: #1e293b; color: #fff; padding: 1.5rem 1rem; flex-shrink: 0; }
        .admin-sidebar a { display: block; color: #cbd5e1; padding: .5rem .75rem; border-radius: 6px; margin-bottom: .25rem; text-decoration: none; }
        .admin-sidebar a:hover, .admin-sidebar a.active { background: #334155; color: #fff; }
        .admin-main { flex: 1; padding: 2rem; }
        .stat-cards { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 2rem; }
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.5rem 2rem; min-width: 160px; }
        .stat-card__val { font-size: 2rem; font-weight: 700; color: #0ea5e9; }
        .stat-card__label { color: #64748b; font-size: .875rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; }
        th, td { padding: .75rem 1rem; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-size: .8rem; text-transform: uppercase; color: #64748b; }
        .admin-lang { display: flex; gap: .25rem; margin-top: auto; padding-top: 1rem; }
        .admin-lang a { font-size: .7rem; font-weight: 700; padding: .15rem .35rem; border-radius: 3px; border: 1px solid #475569; color: #94a3b8; text-decoration: none; }
        .admin-lang a.active, .admin-lang a:hover { background: #334155; color: #fff; }
    </style>
</head>
<body>
<div class="admin-layout">
<nav class="admin-sidebar">
    <div style="font-weight:700; font-size:1.1rem; margin-bottom:1.5rem;">⛽ Admin</div>
    <a href="/admin"          <?= $_SERVER['REQUEST_URI'] === '/admin' ? 'class="active"' : '' ?>><?= __('admin.nav.dashboard') ?></a>
    <a href="/admin/stations" <?= str_starts_with($_SERVER['REQUEST_URI'], '/admin/stations') ? 'class="active"' : '' ?>><?= __('admin.nav.stations') ?></a>
    <a href="/admin/ads"      <?= str_starts_with($_SERVER['REQUEST_URI'], '/admin/ads') ? 'class="active"' : '' ?>><?= __('admin.nav.ads') ?></a>
    <hr style="border-color:#334155; margin:1rem 0">
    <a href="/" target="_blank"><?= __('admin.nav.site') ?></a>
    <div class="admin-lang">
        <?php foreach (\App\I18n::getSupported() as $lng): ?>
            <a href="<?= htmlspecialchars(\App\I18n::langUrl($lng)) ?>"
               class="<?= $lng === \App\I18n::getLang() ? 'active' : '' ?>"><?= strtoupper($lng) ?></a>
        <?php endforeach; ?>
    </div>
</nav>
<main class="admin-main">
