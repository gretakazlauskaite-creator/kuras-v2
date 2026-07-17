
    </div><!-- /.container -->
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <strong>Kuras Pricer</strong>
                <p>Kasdien atnaujiname kuro kainas iš Lietuvos energetikos agentūros duomenų.</p>
            </div>
            <div>
                <strong><?= __('footer.links') ?></strong>
                <ul>
                    <li><a href="/"><?= __('nav.prices') ?></a></li>
                    <li><a href="/stations"><?= __('nav.stations') ?></a></li>
                    <li><a href="/map"><?= __('nav.map') ?></a></li>
                    <li><a href="/rankings"><?= __('nav.rankings') ?></a></li>
                </ul>
            </div>
            <div>
                <strong><?= __('footer.data_src') ?></strong>
                <p><a href="https://www.ena.lt/degalu-kainos-degalinese/" target="_blank" rel="nofollow">Lietuvos energetikos agentūra</a></p>
                <p><?= __('footer.updated') ?></p>
            </div>
        </div>
        <div class="footer-bottom">
            <small><?= __('footer.rights', ['year' => date('Y')]) ?></small>
        </div>
    </div>
</footer>

<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
