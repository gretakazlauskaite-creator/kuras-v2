<?php
/**
 * Plugin Name: Kuras Pricer
 * Description: LEA kuro kainų paieška, reitingai, žemėlapis ir degalinių puslapiai Pricer.lt svetainei.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Pricer.lt
 * Text Domain: kuras-pricer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('KURAS_PRICER_VERSION', '0.3.0');
define('KURAS_PRICER_FILE', __FILE__);
define('KURAS_PRICER_PATH', plugin_dir_path(__FILE__));
define('KURAS_PRICER_URL', plugin_dir_url(__FILE__));

require_once KURAS_PRICER_PATH . 'includes/class-kuras-pricer-query.php';
require_once KURAS_PRICER_PATH . 'includes/class-kuras-pricer-api-client.php';
require_once KURAS_PRICER_PATH . 'includes/class-kuras-pricer-plugin.php';

register_activation_hook(__FILE__, ['Kuras_Pricer_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Kuras_Pricer_Plugin', 'deactivate']);

Kuras_Pricer_Plugin::boot();
