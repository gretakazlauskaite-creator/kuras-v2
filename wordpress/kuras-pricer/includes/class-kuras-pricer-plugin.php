<?php

declare(strict_types=1);

final class Kuras_Pricer_Plugin
{
    private static ?self $instance = null;
    private Kuras_Pricer_Api_Client $api;

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self();
    }

    private function __construct()
    {
        $this->api = new Kuras_Pricer_Api_Client();
        add_action('init', [$this, 'registerFrontend']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('admin_menu', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_filter('template_include', [$this, 'stationTemplate']);
        add_filter('pre_get_document_title', [$this, 'stationTitle']);
        add_action('wp_head', [$this, 'stationCanonical']);
    }

    public static function activate(): void
    {
        add_option('kuras_pricer_api_base_url', 'https://kuras.pricer.lt/api/v1');
        add_option('kuras_pricer_news_category', 'kuras');
        add_option('kuras_pricer_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        add_option('kuras_pricer_tile_attribution', '© OpenStreetMap contributors');
        add_option('kuras_pricer_primary_color', '#336688');
        self::registerRewriteRules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function registerFrontend(): void
    {
        self::registerRewriteRules();
        add_shortcode('kuras_pricer', [$this, 'renderFinder']);

        wp_register_style('kuras-pricer-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_register_style('kuras-pricer', KURAS_PRICER_URL . 'assets/css/kuras-pricer.css', [], KURAS_PRICER_VERSION);
        wp_register_script('kuras-pricer-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_register_script('kuras-pricer', KURAS_PRICER_URL . 'assets/js/kuras-pricer.js', ['kuras-pricer-leaflet'], KURAS_PRICER_VERSION, true);
        wp_register_script('kuras-pricer-editor', KURAS_PRICER_URL . 'assets/js/editor.js', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'], KURAS_PRICER_VERSION, true);

        register_block_type('kuras-pricer/finder', [
            'api_version' => 3,
            'editor_script' => 'kuras-pricer-editor',
            'render_callback' => [$this, 'renderFinder'],
            'attributes' => [
                'showNews' => ['type' => 'boolean', 'default' => true],
            ],
        ]);
    }

    public static function registerRewriteRules(): void
    {
        add_rewrite_rule('^degaline/(st_[a-f0-9]{20}|[0-9]+)/?$', 'index.php?kuras_station=$matches[1]', 'top');
    }

    /** @param array<string,mixed> $attributes */
    public function renderFinder(array $attributes = []): string
    {
        $this->enqueueAssets();
        $initial = [
            'meta' => $this->api->get('meta', [], 60),
            'filters' => $this->api->get('filters', [], 300),
            'statistics' => $this->api->get('statistics', ['fuel' => 'pb95'], 300),
            'rankings' => $this->api->get('rankings', ['fuel' => 'pb95', 'limit' => 3], 120),
            'stations' => $this->api->get('stations', ['fuel' => 'pb95', 'sort' => 'price_asc', 'page' => 1, 'per_page' => 15], 60),
        ];
        foreach ($initial as $key => $value) {
            if (is_wp_error($value)) {
                $initial[$key] = ['error' => ['message' => $value->get_error_message()]];
            }
        }
        $news = ($attributes['showNews'] ?? true) ? $this->newsCards() : [];

        ob_start();
        include KURAS_PRICER_PATH . 'templates/finder.php';
        return (string) ob_get_clean();
    }

    public function registerRestRoutes(): void
    {
        foreach (['meta', 'filters', 'stations', 'rankings', 'statistics', 'map/stations', 'nearby'] as $endpoint) {
            register_rest_route('kuras-pricer/v1', '/' . $endpoint, [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request): WP_REST_Response|WP_Error => $this->proxy($endpoint, $request),
                'permission_callback' => '__return_true',
            ]);
        }
        register_rest_route('kuras-pricer/v1', '/stations/(?P<id>st_[a-f0-9]{20}|[0-9]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response|WP_Error => $this->proxyStation($request, false),
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('kuras-pricer/v1', '/stations/(?P<id>st_[a-f0-9]{20}|[0-9]+)/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response|WP_Error => $this->proxyStation($request, true),
            'permission_callback' => '__return_true',
        ]);
    }

    public function registerSettingsPage(): void
    {
        add_options_page('Kuras Pricer', 'Kuras Pricer', 'manage_options', 'kuras-pricer', [$this, 'settingsPage']);
    }

    public function registerSettings(): void
    {
        register_setting('kuras_pricer', 'kuras_pricer_api_base_url', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting('kuras_pricer', 'kuras_pricer_news_category', ['type' => 'string', 'sanitize_callback' => 'sanitize_title']);
        register_setting('kuras_pricer', 'kuras_pricer_tile_url', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting('kuras_pricer', 'kuras_pricer_tile_attribution', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('kuras_pricer', 'kuras_pricer_primary_color', ['type' => 'string', 'sanitize_callback' => 'sanitize_hex_color']);
    }

    public function settingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $fields = [
            'kuras_pricer_api_base_url' => ['API adresas', 'url'],
            'kuras_pricer_news_category' => ['Pricer naujienų kategorijos trumpinys', 'text'],
            'kuras_pricer_tile_url' => ['Žemėlapio sluoksnio URL', 'url'],
            'kuras_pricer_tile_attribution' => ['Žemėlapio autorystė', 'text'],
            'kuras_pricer_primary_color' => ['Pagrindinė spalva', 'color'],
        ];
        ?>
        <div class="wrap">
            <h1>Kuras Pricer nustatymai</h1>
            <p>Čia nurodoma, iš kur WordPress ima patvirtintas kainas ir kokį žemėlapio tiekėją naudoja.</p>
            <form method="post" action="options.php">
                <?php settings_fields('kuras_pricer'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach ($fields as $name => [$label, $type]) : ?>
                        <tr><th scope="row"><label for="<?= esc_attr($name) ?>"><?= esc_html($label) ?></label></th>
                            <td><input class="regular-text" type="<?= esc_attr($type) ?>" id="<?= esc_attr($name) ?>" name="<?= esc_attr($name) ?>" value="<?= esc_attr((string) get_option($name)) ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array
    {
        $vars[] = 'kuras_station';
        return $vars;
    }

    public function stationTemplate(string $template): string
    {
        $identifier = (string) get_query_var('kuras_station');
        if ($identifier === '') {
            return $template;
        }
        $station = $this->api->get('stations/' . $identifier, [], 60);
        if (is_wp_error($station)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
        }
        $GLOBALS['kuras_pricer_station_payload'] = $station;
        $this->enqueueAssets();
        return KURAS_PRICER_PATH . 'templates/station.php';
    }

    public function stationTitle(string $title): string
    {
        $payload = $GLOBALS['kuras_pricer_station_payload'] ?? null;
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $title;
        }
        $station = $payload['data'];
        return trim((string) ($station['brand'] ?? '') . ' ' . (string) ($station['city'] ?? '') . ' – kuro kainos');
    }

    public function stationCanonical(): void
    {
        $identifier = (string) get_query_var('kuras_station');
        if ($identifier !== '') {
            printf("<link rel=\"canonical\" href=\"%s\">\n", esc_url(home_url('/degaline/' . rawurlencode($identifier) . '/')));
        }
    }

    private function proxy(string $endpoint, WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $query = Kuras_Pricer_Query::sanitize($endpoint, $request->get_query_params());
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('kuras_invalid_parameter', $exception->getMessage(), ['status' => 422]);
        }
        $ttl = match ($endpoint) {
            'filters', 'statistics' => 300,
            'rankings' => 120,
            'nearby' => 30,
            default => 60,
        };
        $body = $this->api->get($endpoint, $query, $ttl);
        return is_wp_error($body) ? $body : rest_ensure_response($body);
    }

    private function proxyStation(WP_REST_Request $request, bool $history): WP_REST_Response|WP_Error
    {
        $identifier = (string) $request['id'];
        try {
            $query = Kuras_Pricer_Query::sanitize($history ? 'history' : 'station', $request->get_query_params());
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('kuras_invalid_parameter', $exception->getMessage(), ['status' => 422]);
        }
        $path = 'stations/' . $identifier . ($history ? '/history' : '');
        $body = $this->api->get($path, $query, $history ? 300 : 60);
        return is_wp_error($body) ? $body : rest_ensure_response($body);
    }

    private function enqueueAssets(): void
    {
        wp_enqueue_style('kuras-pricer-leaflet');
        wp_enqueue_style('kuras-pricer');
        wp_enqueue_script('kuras-pricer-leaflet');
        wp_enqueue_script('kuras-pricer');
        $color = sanitize_hex_color((string) get_option('kuras_pricer_primary_color', '#336688')) ?: '#336688';
        wp_add_inline_style('kuras-pricer', '.kuras-app{--kuras-primary:' . $color . '}');
        wp_localize_script('kuras-pricer', 'KURAS_PRICER', [
            'restUrl' => esc_url_raw(rest_url('kuras-pricer/v1/')),
            'stationBaseUrl' => esc_url_raw(home_url('/degaline/')),
            'tileUrl' => (string) get_option('kuras_pricer_tile_url'),
            'tileAttribution' => (string) get_option('kuras_pricer_tile_attribution'),
        ]);
    }

    /** @return list<array<string,string>> */
    private function newsCards(): array
    {
        $category = (string) get_option('kuras_pricer_news_category', 'kuras');
        $cacheKey = 'kuras_news_' . md5($category);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $posts = get_posts(['numberposts' => 3, 'category_name' => $category, 'post_status' => 'publish']);
        $cards = array_map(static fn (WP_Post $post): array => [
            'title' => get_the_title($post),
            'url' => get_permalink($post),
            'date' => get_the_date('Y-m-d', $post),
            'excerpt' => wp_trim_words(get_the_excerpt($post), 18),
            'image' => (string) get_the_post_thumbnail_url($post, 'medium_large'),
        ], $posts);
        set_transient($cacheKey, $cards, 10 * MINUTE_IN_SECONDS);
        return $cards;
    }
}
