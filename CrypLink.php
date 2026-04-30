<?php
/*
Plugin Name: CrypLink Payment Gateway for WooCommerce
Plugin URI: https://merchant.cryplink.xyz/
Description: Accept cryptocurrency payments on your WooCommerce website
Version: 5.1.5
Requires at least: 5.8
Tested up to: 6.9
WC requires at least: 5.8
WC tested up to: 10.4.3
Requires PHP: 7.2
Author: cryplink
Author URI: https://cryplink.xyz
License: MIT
*/
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('CRYPLINK_PLUGIN_VERSION', '5.1.5');
define('CRYPLINK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CRYPLINK_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'CrypLink\\';
    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    // Hardening: prevent path traversal (e.g. CrypLink\..\..\wp-config)
    if (strpos($relative_class, '..') !== false || strpos($relative_class, "\0") !== false) {
        return;
    }

    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        $real_file = realpath($file);
        $real_base = realpath($base_dir);
        if ($real_file && $real_base && strpos($real_file, $real_base) === 0) {
            require $real_file;
        }
    }
});

add_action('init', function () {
    $plugin_dir = plugin_dir_path(__FILE__);
    $mo_file_path = $plugin_dir . 'languages/cryplink-payment-gateway-for-woocommerce-' . get_locale() . '.mo';

    if (file_exists($mo_file_path)) {
        load_textdomain('cryplink', $mo_file_path);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . sprintf(esc_html__('CrypLink requires WooCommerce to be installed and active. You can download %s here.', 'cryplink'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
        });
        return;
    }

    if (!extension_loaded('bcmath')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . sprintf(esc_html__('CrypLink requires PHP\'s BCMath extension. You can know more about it %s.', 'cryplink'), '<a href="https://www.php.net/manual/en/book.bc.php" target="_blank">here</a>') . '</strong></p></div>';
        });
        return;
    }

    $register = new \CrypLink\Register();
    $register->register();

    $initialize = new \CrypLink\Initialize();
    $initialize->initialize();
});


add_filter('cron_schedules', function ($cryplink_interval) {
    $cryplink_interval['cryplink_interval'] = array(
        'interval' => 60,
        'display' => esc_html__('CrypLink Interval'),
    );

    return $cryplink_interval;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cryplink_cronjob')) {
        wp_schedule_event(time(), 'cryplink_interval', 'cryplink_cronjob');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cryplink_cronjob');
});

use Automattic\WooCommerce\Utilities\FeaturesUtil;
// Declare compatibility with WooCommerce features
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Register minimum endpoint to be used in the blocks

add_action('rest_api_init', function () {
    register_rest_route('cryplink/v1', '/get-minimum', array(
        'methods' => 'POST',
        'callback' => 'cryplink_get_minimum',
        'permission_callback' => 'cryplink_verify_nonce',
    ));
    register_rest_route('cryplink/v1', '/update-coin', array(
        'methods' => 'POST',
        'callback' => 'cryplink_update_coin',
        'permission_callback' => 'cryplink_verify_nonce',
    ));
});

function cryplink_verify_nonce(WP_REST_Request $request) {
    // Permission check for REST endpoints used by Blocks checkout UI.
    // Rely on WP REST nonce (X-WP-Nonce). Do not depend on Origin header,
    // as it can be missing for non-browser clients.
    $nonce = (string) $request->get_header('X-WP-Nonce');
    if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
        return false;
    }

    return true;
}

function cryplink_get_minimum(WP_REST_Request $request) {
    $coin = sanitize_text_field($request->get_param('coin'));
    $fiat = sanitize_text_field($request->get_param('fiat'));
    $value = $request->get_param('value');

    if (empty($coin) || empty($fiat) || empty($value)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Missing parameters'], 400);
    }

    // Normalise: blocks.js sends fiat lowercase (e.g. 'vnd'), Api::get_conversion needs uppercase
    $fiat = strtoupper($fiat);
    $value_f = (float) $value;

    // Cache conversion checks briefly to reduce API calls during peak checkout traffic.
    // Keyed by (coin, fiat, rounded value). Keep TTL short so pricing stays fresh.
    $rounded_value = number_format($value_f, 2, '.', ''); // 2 decimals is enough for minimum checks
    $cache_key = 'cryplink_min_' . md5(strtolower($coin) . '|' . strtoupper($fiat) . '|' . $rounded_value);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['status'])) {
        return new WP_REST_Response($cached, 200);
    }

    try {
        $raw_convert = \CrypLink\Utils\Api::get_conversion($fiat, $coin, (string) $value, false);

        // Null means conversion API failed entirely — surface the real error
        if ($raw_convert === null) {
            $api_err = \CrypLink\Utils\Api::$last_error ?? 'Conversion API unavailable';
            $resp = ['status' => 'error', 'message' => $api_err];
            set_transient($cache_key, $resp, 30);
            return new WP_REST_Response($resp, 200);
        }

        $convert = (float) $raw_convert;

        $info = \CrypLink\Utils\Api::get_info($coin);
        if ($info === null) {
            $resp = ['status' => 'error', 'message' => 'Failed to fetch coin info'];
            set_transient($cache_key, $resp, 30);
            return new WP_REST_Response($resp, 200);
        }

        // Support both field names: _minimum->coin (REST) and minimum_transaction_coin (process_payment)
        if (isset($info->_minimum->coin)) {
            $minimum = (float) $info->_minimum->coin;
        } elseif (isset($info->minimum_transaction_coin)) {
            $minimum = (float) $info->minimum_transaction_coin;
        } else {
            $minimum = 0.0;
        }

        if ($convert > $minimum) {
            $resp = ['status' => 'success', 'convert' => $convert, 'minimum' => $minimum];
            set_transient($cache_key, $resp, 60);
            return new WP_REST_Response($resp, 200);
        }

        $resp = ['status' => 'error', 'convert' => $convert, 'minimum' => $minimum];
        set_transient($cache_key, $resp, 60);
        return new WP_REST_Response($resp, 200);
    } catch (\Throwable $e) {
        return new WP_REST_Response(['status' => 'error', 'message' => $e->getMessage()], 400);
    }
}

function cryplink_update_coin(WP_REST_Request $request) {
    $coin = sanitize_text_field((string) $request->get_param('coin'));
    $coin = strtolower(trim($coin));
    $selected = (bool) $request->get_param('selected', false);

    if (empty($coin)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Missing parameters'], 400);
    }

    if (!class_exists('WooCommerce')) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'WooCommerce not active'], 400);
    }

    // Ensure WC session exists in REST context
    if (!function_exists('WC') || !WC() || !isset(WC()->session)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'WooCommerce session unavailable'], 400);
    }

    // Validate token against known CrypLink tickers to prevent arbitrary values being
    // stored in session (which can lead to extra API calls / noisy logs).
    try {
        if ($coin !== 'none' && class_exists('\CrypLink\Controllers\WC_CrypLink_Gateway')) {
            $valid = \CrypLink\Controllers\WC_CrypLink_Gateway::load_coins();
            if (!is_array($valid) || !array_key_exists($coin, $valid)) {
                // Treat invalid token as "none"
                WC()->session->set('cryplink_coin', 'none');
                return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid token'], 400);
            }
        }
    } catch (\Throwable $e) {
        // If validation fails due to transient API/cache issues, fall back to safety:
        // do not store unknown tokens.
        WC()->session->set('cryplink_coin', 'none');
        return new WP_REST_Response(['status' => 'error', 'message' => 'Token validation failed'], 400);
    }

    if (!$selected) {
        WC()->session->set('cryplink_coin', 'none');
        WC()->session->set('chosen_payment_method', '');
        return new WP_REST_Response(['success' => true, 'coin' => $coin], 200);
    }

    // Set the session value
    WC()->session->set('cryplink_coin', $coin);
    WC()->session->set('chosen_payment_method', 'cryplink');

    return new WP_REST_Response(['success' => true, 'coin' => $coin], 200);
}
