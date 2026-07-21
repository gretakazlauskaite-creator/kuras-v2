<?php
/** @var array<string,mixed>|WP_Error $payload */
$payload = $GLOBALS['kuras_pricer_station_payload'] ?? new WP_Error('kuras_missing_station', 'Degalinė nerasta.');
$station = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : null;
$meta = is_array($payload) && is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
get_header();
?>
<main class="kuras-app kuras-station-page" data-kuras-station>
    <div class="kuras-shell">
        <nav class="kuras-breadcrumbs" aria-label="Navigacija"><a href="<?= esc_url(home_url('/kuras/')) ?>">Kuro kainos</a><span>/</span><span>Degalinė</span></nav>
        <?php if ($station === null) : ?>
            <section class="kuras-panel kuras-station-error"><h1>Degalinė nerasta</h1><p><?= esc_html(is_wp_error($payload) ? $payload->get_error_message() : 'Šios degalinės duomenų parodyti nepavyko.') ?></p></section>
        <?php else : ?>
            <header class="kuras-station-hero">
                <div><p class="kuras-eyebrow"><?= esc_html((string) ($station['brand'] ?? 'Degalinė')) ?></p><h1><?= esc_html((string) ($station['name'] ?? $station['brand'] ?? 'Degalinė')) ?></h1><p><?= esc_html(trim((string) ($station['address'] ?? '') . ', ' . (string) ($station['city'] ?? $station['municipality'] ?? ''), ', ')) ?></p></div>
                <div class="kuras-station-prices">
                    <?php foreach ((array) ($station['prices'] ?? []) as $price) : ?>
                        <div><span><?= esc_html(strtoupper((string) ($price['fuel'] ?? ''))) ?></span><strong><?= esc_html(number_format((float) ($price['price'] ?? 0), 3, ',', ' ')) ?> €</strong></div>
                    <?php endforeach; ?>
                </div>
            </header>
            <p class="kuras-source kuras-source--station">Duomenų data: <?= esc_html((string) ($meta['source_date'] ?? '—')) ?></p>
            <section class="kuras-panel kuras-history" data-station-id="<?= esc_attr((string) ($station['id'] ?? '')) ?>">
                <header class="kuras-panel__header"><div><p class="kuras-eyebrow">Kainų istorija</p><h2>Pastarųjų 30 dienų pokytis</h2></div><select data-history-fuel aria-label="Degalų rūšis"><?php foreach (['pb95' => 'Pb 95', 'pb98' => 'Pb 98', 'diesel' => 'Dyzelinas', 'lpg' => 'Dujos'] as $fuel => $label) : ?><option value="<?= esc_attr($fuel) ?>"><?= esc_html($label) ?></option><?php endforeach; ?></select></header>
                <div class="kuras-chart" data-history-chart><p class="kuras-empty">Kraunama kainų istorija…</p></div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php get_footer(); ?>
