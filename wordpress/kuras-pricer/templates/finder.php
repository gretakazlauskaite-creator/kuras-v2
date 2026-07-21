<?php
/** @var array<string,mixed> $initial */
/** @var list<array<string,string>> $news */
?>
<div class="kuras-app" data-kuras-finder>
    <section class="kuras-hero">
        <div class="kuras-shell kuras-hero__grid">
            <div>
                <p class="kuras-eyebrow">Oficialūs LEA duomenys</p>
                <h1>Rask pigesnius degalus šalia savęs</h1>
                <p class="kuras-lead">Palygink Lietuvos degalinių kainas pagal miestą, tinklą ar atstumą – vienoje aiškioje vietoje.</p>
                <div class="kuras-source" data-source-status aria-live="polite">
                    <span class="kuras-source__dot" aria-hidden="true"></span>
                    <span>Kraunami naujausi duomenys…</span>
                </div>
            </div>
            <dl class="kuras-summary" aria-label="Kainų santrauka">
                <div><dt>Vidutinė kaina</dt><dd data-average-price>—</dd></div>
                <div><dt>Mažiausia kaina</dt><dd data-minimum-price>—</dd></div>
                <div><dt>Degalinių su kaina</dt><dd data-station-count>—</dd></div>
            </dl>
        </div>
    </section>

    <div class="kuras-shell kuras-main">
        <div class="kuras-notice" data-global-notice hidden role="status"></div>

        <div class="kuras-fuels" data-fuel-tabs role="tablist" aria-label="Degalų rūšis"></div>

        <form class="kuras-filters" data-filter-form>
            <label class="kuras-field kuras-field--search">
                <span>Paieška</span>
                <input data-search type="search" placeholder="Miestas, adresas ar tinklas" autocomplete="off">
            </label>
            <label class="kuras-field">
                <span>Miestas</span>
                <select data-city><option value="">Visa Lietuva</option></select>
            </label>
            <label class="kuras-field">
                <span>Tinklas</span>
                <select data-brand><option value="">Visi tinklai</option></select>
            </label>
            <label class="kuras-field">
                <span>Rūšiavimas</span>
                <select data-sort>
                    <option value="price_asc">Pigiausi pirmiausia</option>
                    <option value="price_desc">Brangiausi pirmiausia</option>
                    <option value="name_asc">Pagal pavadinimą</option>
                    <option value="distance_asc" disabled>Pagal atstumą</option>
                </select>
            </label>
            <button class="kuras-button kuras-button--primary" type="submit">Rodyti kainas</button>
        </form>

        <section class="kuras-top" aria-labelledby="kuras-top-title">
            <header class="kuras-section-heading">
                <div><p class="kuras-eyebrow">Pigiausi šiandien</p><h2 id="kuras-top-title">Degalinių TOP 3</h2></div>
                <span data-top-fuel>Pb 95</span>
            </header>
            <div class="kuras-top__grid" data-top-stations aria-live="polite">
                <div class="kuras-skeleton"></div><div class="kuras-skeleton"></div><div class="kuras-skeleton"></div>
            </div>
        </section>

        <?php do_action('kuras_pricer_ad_slot', 'after_rankings'); ?>

        <div class="kuras-view-tabs" role="tablist" aria-label="Rezultatų vaizdas">
            <button type="button" role="tab" aria-selected="true" data-view-tab="list">Kainų sąrašas</button>
            <button type="button" role="tab" aria-selected="false" data-view-tab="map">Žemėlapis</button>
        </div>

        <div class="kuras-results">
            <section class="kuras-panel kuras-list" data-view="list" aria-labelledby="kuras-results-title">
                <header class="kuras-panel__header">
                    <div><p class="kuras-eyebrow">Kainų lentelė</p><h2 id="kuras-results-title">Degalinės</h2></div>
                    <span data-results-count>—</span>
                </header>
                <div class="kuras-table-wrap">
                    <table>
                        <thead><tr><th>Degalinė</th><th>Vieta</th><th class="kuras-price-cell">Kaina</th></tr></thead>
                        <tbody data-stations-table><tr><td colspan="3" class="kuras-empty">Kraunami duomenys…</td></tr></tbody>
                    </table>
                </div>
                <nav class="kuras-pagination" aria-label="Rezultatų puslapiai">
                    <button data-previous-page type="button">Atgal</button>
                    <span data-page-label>1 puslapis</span>
                    <button data-next-page type="button">Toliau</button>
                </nav>
            </section>

            <section class="kuras-panel kuras-map-panel" data-view="map" aria-labelledby="kuras-map-title">
                <header class="kuras-panel__header">
                    <div><p class="kuras-eyebrow">Žemėlapis</p><h2 id="kuras-map-title">Kainos aplinkui</h2></div>
                    <button class="kuras-button kuras-button--location" data-locate type="button">Naudoti mano vietą</button>
                </header>
                <p class="kuras-consent">Vieta naudojama tik artimiausioms degalinėms parodyti ir nėra išsaugoma.</p>
                <div class="kuras-map" data-map aria-label="Interaktyvus degalinių žemėlapis"></div>
                <p class="kuras-map-note" data-map-note>Žemėlapyje rodomos degalinės su patvirtintomis koordinatėmis.</p>
            </section>
        </div>

        <aside class="kuras-data-note">
            <span aria-hidden="true">i</span>
            <div><strong>Kaip atnaujinamos kainos?</strong><p>LEA skelbia darbo dienomis 10:00 val. surinktas kainas. Tai oficialūs kasdieniai, bet ne realaus laiko duomenys. Rodome tikrą šaltinio datą ir paskutinio sėkmingo importo laiką.</p></div>
        </aside>

        <?php if ($news !== []) : ?>
            <section class="kuras-news" aria-labelledby="kuras-news-title">
                <header class="kuras-section-heading"><div><p class="kuras-eyebrow">Pricer naujienos</p><h2 id="kuras-news-title">Apie kurą ir energetiką</h2></div></header>
                <div class="kuras-news__grid">
                    <?php foreach ($news as $article) : ?>
                        <article class="kuras-news-card">
                            <?php if ($article['image'] !== '') : ?><a href="<?= esc_url($article['url']) ?>"><img src="<?= esc_url($article['image']) ?>" alt="" loading="lazy"></a><?php endif; ?>
                            <div><time datetime="<?= esc_attr($article['date']) ?>"><?= esc_html($article['date']) ?></time><h3><a href="<?= esc_url($article['url']) ?>"><?= esc_html($article['title']) ?></a></h3><p><?= esc_html($article['excerpt']) ?></p></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <script type="application/json" data-kuras-initial><?= wp_json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</div>
