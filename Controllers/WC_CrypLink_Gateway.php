<?php

namespace CrypLink\Controllers;

use Exception;

class WC_CrypLink_Gateway extends \WC_Payment_Gateway {
    private static $HAS_TRIGGERED = false;

    public $id;
    public $enabled;
    public $title;
    public $description;
    public $qrcode_size;
    public $qrcode_default;
    public $qrcode_setting;
    public $coins;
    public $coin_wallets; // array: ['btc' => 'bc1...', 'eth' => '0x...']
    public $show_branding;
    public $show_crypto_logos;
    public $color_scheme;
    public $refresh_value_interval;
    public $order_cancelation_timeout;
    public $add_blockchain_fee;
    public $fee_order_percentage;
    public $virtual_complete;
    public $disable_conversion;
    public $tolerance;
    public $icon;
    public $debug;
    public $auto_complete_all;
    public $callback_ip_allowlist;

    function __construct()
    {
        $this->id = 'cryplink';
        // Use the hosted logo to avoid aggressive CDN/static caching issues.
        $this->icon = 'https://res.cloudinary.com/diica7at6/image/upload/v1774226418/cryplink-logo-blue_w6el8p.png';
        $this->has_fields = true;
        $this->method_title = 'CrypLink';
        $this->method_description = esc_attr(__('CrypLink allows customers to pay in cryptocurrency', 'cryplink'));

        $this->supports = array(
            'products',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_amount_changes',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_date_changes',
            'multiple_subscriptions',
        );
        $this->init_form_fields();
        $this->init_settings();
        $this->ca_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'validate_payment'));

        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_mail'), 10, 2);

        add_action('wcs_create_pending_renewal', array($this, 'subscription_send_email'));

        add_action('wp_ajax_nopriv_' . $this->id . '_order_status', array($this, 'order_status'));
        add_action('wp_ajax_' . $this->id . '_order_status', array($this, 'order_status'));

        add_action('wp_ajax_' . $this->id . '_validate_logs', array($this, 'validate_logs'));

        add_action('cryplink_cronjob', array($this, 'ca_cronjob'), 10, 3);

        add_action('woocommerce_cart_calculate_fees', array($this, 'handling_fee'));

        add_action('woocommerce_checkout_update_order_review', array($this, 'chosen_currency_value_to_wc_session'));

        add_action('wp_footer', array($this, 'refresh_checkout'));

        add_action('woocommerce_email_order_details', array($this, 'add_email_link'), 2, 4);

        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_order_link'), 10, 2);

        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'order_detail_validate_logs'));

        add_action('admin_post_cryplink_refresh_coins', [$this, 'handle_admin_refresh_coins']);
        add_action('admin_post_cryplink_refresh_pubkey', [$this, 'handle_admin_refresh_pubkey']);
        add_action('admin_post_cryplink_allowlist_last_ip', [$this, 'handle_admin_allowlist_last_ip']);
        add_action('admin_post_cryplink_fetch_ips', [$this, 'handle_admin_fetch_ips']);
    }

    function reset_load_coins(): bool {
        $now = time();

        try {
            $coins = \CrypLink\Utils\Api::get_supported_coins();
            if (empty($coins) || !is_array($coins)) {
                throw new Exception('No cryptocurrencies available at the moment. Please choose a different payment method or try again later.');
            }

            update_option('cryplink_coins_cache', [
                'coins'   => $coins,
                'expires' => $now + 3600,
            ], false);

            // Used by ca_cronjob() to trigger a daily background refresh.
            update_option('cryplink_last_coins_refresh', $now, false);

            return true;
        } catch (\Throwable $e) {
            // We don't want to reset the cache if we can't load the coins.
            return false;
        }
    }

    static function load_coins() {
        $cache = get_option('cryplink_coins_cache', []);
        $now   = time();

        try {
            // Use cache if still valid
            if (!empty($cache['coins']) && isset($cache['expires']) && $cache['expires'] > $now) {
                $coins = $cache['coins'];
            } else {
                // Return stale cache immediately during admin page renders to avoid timeout.
                // Use the Refresh Token List button or cronjob to update the cache.
                if (!empty($cache['coins']) && is_admin() && !wp_doing_ajax()) {
                    $coins = $cache['coins'];
                } else {
                    $coins = \CrypLink\Utils\Api::get_supported_coins();

                    if (empty($coins)) {
                        throw new Exception('No cryptocurrencies available at the moment. Please choose a different payment method or try again later.');
                    }

                    update_option('cryplink_coins_cache', [
                        'coins'   => $coins,
                        'expires' => $now + 3600,
                    ], false);
                }
            }

            if (isset($coins['xmr'])) {
                unset($coins['xmr']);
            }

            return $coins;
        } catch (\Throwable $e) {
            if (!empty($cache['coins'])) {
                return $cache['coins'];
            }
            return [];
        }
    }

    public function handle_admin_refresh_coins() {
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die(__('You do not have permission to do this.', 'cryplink'));
        }

        check_admin_referer('cryplink_refresh_coins');

        delete_option('cryplink_coins_cache');

        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
        }
        $back = add_query_arg('cryplink_refreshed', '1', $back);
        wp_safe_redirect($back);
        exit;
    }

    function admin_options()
    {
        parent::admin_options();
        if (!empty($_GET['cryplink_refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__('Cryptocurrency cache refreshed successfully', 'cryplink') .
                '</p></div>';
        }
        if (!empty($_GET['cryplink_pubkey_refreshed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__('CrypLink pubkey cache refreshed successfully', 'cryplink') .
                '</p></div>';
        }
        if (isset($_GET['cryplink_allowlist_ip'])) {
            $v = sanitize_text_field((string) $_GET['cryplink_allowlist_ip']);
            if ($v === 'missing') {
                echo '<div class="notice notice-warning is-dismissible"><p>' .
                    esc_html__('No callback IP has been detected yet. Wait for a callback attempt, then try again.', 'cryplink') .
                    '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(esc_html__('Added IP to allowlist: %s', 'cryplink'), esc_html($v)) .
                    '</p></div>';
            }
        }
        if (isset($_GET['cryplink_ips_fetch'])) {
            $v = sanitize_text_field((string) $_GET['cryplink_ips_fetch']);
            if ($v === 'ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html__('Fetched callback IPs from CrypLink and updated allowlist.', 'cryplink') .
                    '</p></div>';
            } elseif ($v === 'failed') {
                echo '<div class="notice notice-error is-dismissible"><p>' .
                    esc_html__('Failed to fetch callback IPs from CrypLink. Please try again later.', 'cryplink') .
                    '</p></div>';
            }
        }

        // Test API connectivity: call a lightweight endpoint and check for domain allowlist error
        $test = \CrypLink\Utils\Api::test_connection();
        if ($test !== true) {
            echo '<div class="notice notice-error"><p><strong>CrypLink API Error:</strong> ' .
                esc_html($test) .
                '</p><p>' .
                wp_kses(
                    __('Your domain is not whitelisted by CrypLink. Please <a href="https://cryplink.xyz/contacts/" target="_blank">contact CrypLink support</a> and ask them to whitelist your domain: <strong>' . home_url() . '</strong>', 'cryplink'),
                    ['a' => ['href' => [], 'target' => []], 'strong' => []]
                ) .
                '</p></div>';
        }
        ?>
        <style>
            /* CrypLink admin tools (compact layout) */
            #woocommerce_cryplink_callback_ip_allowlist{width:100%;max-width:680px}
            #woocommerce_cryplink_callback_ip_allowlist + p.description{margin-top:6px;font-size:12px;color:#646970}
            .cryplink-tools{margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:980px}
            .cryplink-tools .cryplink-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px}
            .cryplink-tools .cryplink-card h4{margin:0 0 8px;font-size:13px}
            .cryplink-tools .cryplink-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .cryplink-tools .cryplink-muted{margin:6px 0 0;color:#646970;font-size:12px}
        </style>

        <?php
        $last_ips  = get_option('cryplink_last_fetched_ips', []);
        $ips_val   = is_array($last_ips) ? ($last_ips['ips'] ?? []) : [];
        $ips_time  = is_array($last_ips) ? (int) ($last_ips['time'] ?? 0) : 0;

        $last_ip      = get_option('cryplink_last_callback_ip', []);
        $last_ip_val  = is_array($last_ip) ? (string) ($last_ip['ip'] ?? '') : '';
        $last_ip_time = is_array($last_ip) ? (int) ($last_ip['time'] ?? 0) : 0;

        // IMPORTANT: Do not render nested <form> tags inside WooCommerce settings forms
        // (it breaks "Save changes"). Use nonce-protected admin-post links instead.
        $refresh_pubkey_url = wp_nonce_url(admin_url('admin-post.php?action=cryplink_refresh_pubkey'), 'cryplink_refresh_pubkey');
        $fetch_ips_url      = wp_nonce_url(admin_url('admin-post.php?action=cryplink_fetch_ips'), 'cryplink_fetch_ips');
        $allowlist_ip_url   = wp_nonce_url(admin_url('admin-post.php?action=cryplink_allowlist_last_ip'), 'cryplink_allowlist_last_ip');
        ?>

        <div class="cryplink-tools">
            <div class="cryplink-card">
                <h4><?php echo esc_html__('Callback tools', 'cryplink'); ?></h4>
                <div class="cryplink-row">
                    <a class="button" href="<?php echo esc_url($refresh_pubkey_url); ?>">
                        <?php echo esc_html__('Refresh Pubkey Cache', 'cryplink'); ?>
                    </a>
                    <a class="button button-primary" href="<?php echo esc_url($fetch_ips_url); ?>">
                        <?php echo esc_html__('Fetch IPs from CrypLink', 'cryplink'); ?>
                    </a>
                </div>
                <?php if (!empty($ips_val) && is_array($ips_val)) : ?>
                    <p class="cryplink-muted">
                        <?php
                        echo esc_html__('Last fetched:', 'cryplink') . ' ' .
                            esc_html(implode(', ', array_map('sanitize_text_field', $ips_val))) .
                            ($ips_time ? ' (' . esc_html(gmdate('Y-m-d H:i:s', $ips_time)) . ' UTC)' : '');
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="cryplink-card">
                <h4><?php echo esc_html__('Callback IP detection', 'cryplink'); ?></h4>
                <p class="cryplink-muted" style="margin-top:0;">
                    <strong><?php echo esc_html__('Last callback IP:', 'cryplink'); ?></strong>
                    <?php
                    if (!empty($last_ip_val)) {
                        echo esc_html($last_ip_val) . ($last_ip_time ? ' (' . esc_html(gmdate('Y-m-d H:i:s', $last_ip_time)) . ' UTC)' : '');
                    } else {
                        echo esc_html__('(none yet)', 'cryplink');
                    }
                    ?>
                </p>
                <div class="cryplink-row">
                    <a class="button" href="<?php echo esc_url($allowlist_ip_url); ?>">
                        <?php echo esc_html__('Allowlist last callback IP', 'cryplink'); ?>
                    </a>
                </div>
                <p class="cryplink-muted">
                    <?php echo esc_html__('Works only after a callback attempt reaches your site (even if rejected).', 'cryplink'); ?>
                </p>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Tolerance field validation
                var $toleranceField = $('#woocommerce_cryplink_tolerance');

                if ($toleranceField.length) {
                    // Validate on input
                    $toleranceField.on('input change', function() {
                        var value = $(this).val();
                        var numValue = parseInt(value, 10);

                        // Check if it's a valid integer
                        if (value !== '' && (!Number.isInteger(parseFloat(value)) || numValue < 0 || numValue > 10)) {
                            $(this).css('border-color', 'red');

                            // Show error message if it doesn't exist
                            if (!$(this).next('.tolerance-error').length) {
                                $(this).after('<p class="tolerance-error" style="color: red; margin-top: 5px;"><?php echo esc_js(__("Tolerance must be an integer between 0 and 10.", "cryplink")); ?></p>');
                            }
                        } else {
                            $(this).css('border-color', '');
                            $(this).next('.tolerance-error').remove();
                        }
                    });

                    // Validate on form submit
                    $('form').on('submit', function(e) {
                        var value = $toleranceField.val();
                        var numValue = parseInt(value, 10);

                        if (value !== '' && (!Number.isInteger(parseFloat(value)) || numValue < 0 || numValue > 10)) {
                            e.preventDefault();
                            $toleranceField.focus();
                            alert('<?php echo esc_js(__("Please enter a valid tolerance percentage (0-10).", "cryplink")); ?>');
                            return false;
                        }
                    });
                }
            });
        </script>
        <div style="margin-top: 1.5rem">
            <a href="https://uk.trustpilot.com/review/cryplink.xyz" target="_blank">
                <svg width="145" viewBox="0 0 200 39" xmlns="http://www.w3.org/2000/svg"
                     xmlns:xlink="http://www.w3.org/1999/xlink"
                     style="fill-rule:evenodd;clip-rule:evenodd;stroke-linecap:square;stroke-linejoin:round;stroke-miterlimit:1.5;">
                    <g id="Trustpilot" transform="matrix(1,0,0,0.065,0,0)">
                        <rect x="0" y="0" width="200" height="600" style="fill:none;"></rect>
                        <g transform="matrix(0.98251,0,0,66.8611,-599.243,-59226.5)">
                            <g>
                                <g transform="matrix(1,0,0,1,487.904,8.98364)">
                                    <g transform="matrix(0.695702,0,0,0.695702,-619.165,278.271)">
                                        <g transform="matrix(1,0,0,0.226074,1213.4,863.302)">
                                            <path d="M33.064,11.07L45.818,11.07L45.818,13.434L40.807,13.434L40.807,26.725L38.052,26.725L38.052,13.434L33.064,13.434L33.064,11.07ZM45.274,15.39L47.629,15.39L47.629,17.577L47.673,17.577C47.751,17.268 47.896,16.969 48.107,16.682C48.318,16.395 48.573,16.119 48.873,15.887C49.173,15.644 49.507,15.456 49.873,15.301C50.24,15.158 50.618,15.08 50.995,15.08C51.284,15.08 51.495,15.091 51.606,15.102C51.718,15.113 51.829,15.136 51.951,15.147L51.951,17.555C51.773,17.522 51.595,17.5 51.406,17.478C51.218,17.456 51.04,17.445 50.862,17.445C50.44,17.445 50.04,17.533 49.662,17.699C49.284,17.864 48.962,18.118 48.684,18.439C48.407,18.77 48.185,19.168 48.018,19.654C47.851,20.14 47.773,20.693 47.773,21.322L47.773,26.714L45.263,26.714L45.263,15.39L45.274,15.39ZM63.494,26.725L61.028,26.725L61.028,25.145L60.983,25.145C60.672,25.719 60.217,26.172 59.606,26.515C58.995,26.857 58.372,27.034 57.739,27.034C56.239,27.034 55.151,26.669 54.484,25.929C53.817,25.189 53.484,24.073 53.484,22.582L53.484,15.39L55.995,15.39L55.995,22.339C55.995,23.333 56.184,24.04 56.573,24.449C56.95,24.858 57.495,25.067 58.184,25.067C58.717,25.067 59.15,24.99 59.506,24.824C59.861,24.659 60.15,24.449 60.361,24.173C60.583,23.907 60.739,23.576 60.839,23.2C60.939,22.825 60.983,22.416 60.983,21.974L60.983,15.401L63.494,15.401L63.494,26.725ZM67.772,23.09C67.849,23.819 68.127,24.327 68.605,24.626C69.094,24.913 69.671,25.067 70.349,25.067C70.582,25.067 70.849,25.045 71.149,25.012C71.449,24.979 71.738,24.902 71.993,24.802C72.26,24.703 72.471,24.548 72.649,24.349C72.816,24.15 72.893,23.896 72.882,23.576C72.871,23.256 72.749,22.99 72.527,22.792C72.305,22.582 72.027,22.427 71.682,22.294C71.338,22.173 70.949,22.062 70.505,21.974C70.06,21.886 69.616,21.786 69.16,21.687C68.694,21.587 68.238,21.455 67.805,21.311C67.372,21.168 66.983,20.969 66.638,20.715C66.294,20.472 66.016,20.151 65.816,19.765C65.605,19.378 65.505,18.903 65.505,18.328C65.505,17.71 65.661,17.201 65.961,16.782C66.261,16.362 66.65,16.03 67.105,15.776C67.572,15.522 68.083,15.345 68.649,15.235C69.216,15.136 69.76,15.08 70.271,15.08C70.86,15.08 71.427,15.147 71.96,15.268C72.493,15.39 72.982,15.589 73.416,15.876C73.849,16.152 74.204,16.517 74.493,16.958C74.782,17.4 74.96,17.942 75.038,18.571L72.416,18.571C72.293,17.975 72.027,17.566 71.593,17.367C71.16,17.157 70.66,17.058 70.105,17.058C69.927,17.058 69.716,17.069 69.471,17.102C69.227,17.135 69.005,17.19 68.783,17.268C68.572,17.345 68.394,17.467 68.238,17.621C68.094,17.776 68.016,17.975 68.016,18.229C68.016,18.538 68.127,18.781 68.338,18.969C68.549,19.157 68.827,19.312 69.171,19.444C69.516,19.566 69.905,19.676 70.349,19.765C70.794,19.853 71.249,19.952 71.716,20.052C72.171,20.151 72.616,20.284 73.06,20.427C73.504,20.571 73.893,20.77 74.238,21.024C74.582,21.278 74.86,21.587 75.071,21.963C75.282,22.339 75.393,22.814 75.393,23.366C75.393,24.04 75.238,24.603 74.927,25.078C74.615,25.542 74.215,25.929 73.727,26.216C73.238,26.504 72.682,26.725 72.082,26.857C71.482,26.99 70.882,27.056 70.294,27.056C69.571,27.056 68.905,26.979 68.294,26.813C67.683,26.647 67.149,26.404 66.705,26.084C66.261,25.752 65.905,25.344 65.65,24.858C65.394,24.371 65.261,23.786 65.239,23.112L67.772,23.112L67.772,23.09ZM76.06,15.39L77.96,15.39L77.96,11.987L80.47,11.987L80.47,15.39L82.737,15.39L82.737,17.257L80.47,17.257L80.47,23.311C80.47,23.576 80.482,23.797 80.504,23.996C80.526,24.184 80.582,24.349 80.659,24.482C80.737,24.614 80.859,24.714 81.026,24.78C81.193,24.846 81.404,24.88 81.693,24.88C81.87,24.88 82.048,24.88 82.226,24.869C82.404,24.858 82.581,24.835 82.759,24.791L82.759,26.725C82.481,26.758 82.204,26.78 81.948,26.813C81.681,26.846 81.415,26.857 81.137,26.857C80.47,26.857 79.937,26.791 79.537,26.669C79.137,26.548 78.815,26.36 78.593,26.117C78.36,25.874 78.215,25.576 78.126,25.211C78.048,24.846 77.993,24.427 77.982,23.963L77.982,17.279L76.082,17.279L76.082,15.39L76.06,15.39ZM84.515,15.39L86.892,15.39L86.892,16.925L86.937,16.925C87.292,16.262 87.781,15.798 88.414,15.511C89.047,15.224 89.725,15.08 90.47,15.08C91.369,15.08 92.147,15.235 92.814,15.555C93.48,15.865 94.036,16.296 94.48,16.848C94.925,17.4 95.247,18.041 95.469,18.77C95.691,19.499 95.802,20.284 95.802,21.112C95.802,21.875 95.702,22.615 95.502,23.322C95.302,24.04 95.002,24.67 94.603,25.222C94.203,25.774 93.691,26.205 93.069,26.537C92.447,26.868 91.725,27.034 90.881,27.034C90.514,27.034 90.147,27.001 89.781,26.934C89.414,26.868 89.059,26.758 88.725,26.614C88.392,26.47 88.07,26.283 87.792,26.051C87.503,25.819 87.27,25.554 87.07,25.255L87.025,25.255L87.025,30.912L84.515,30.912L84.515,15.39ZM93.292,21.068C93.292,20.56 93.225,20.063 93.092,19.577C92.958,19.091 92.758,18.671 92.492,18.295C92.225,17.92 91.892,17.621 91.503,17.4C91.103,17.179 90.647,17.058 90.136,17.058C89.081,17.058 88.281,17.422 87.748,18.152C87.214,18.881 86.948,19.853 86.948,21.068C86.948,21.643 87.014,22.173 87.159,22.659C87.303,23.145 87.503,23.565 87.792,23.918C88.07,24.272 88.403,24.548 88.792,24.747C89.181,24.957 89.636,25.056 90.147,25.056C90.725,25.056 91.203,24.935 91.603,24.703C92.003,24.471 92.325,24.162 92.58,23.797C92.836,23.421 93.025,23.002 93.136,22.526C93.236,22.051 93.292,21.565 93.292,21.068ZM97.724,11.07L100.235,11.07L100.235,13.434L97.724,13.434L97.724,11.07ZM97.724,15.39L100.235,15.39L100.235,26.725L97.724,26.725L97.724,15.39ZM102.48,11.07L104.99,11.07L104.99,26.725L102.48,26.725L102.48,11.07ZM112.69,27.034C111.779,27.034 110.968,26.879 110.257,26.581C109.546,26.283 108.946,25.863 108.446,25.344C107.957,24.813 107.579,24.184 107.324,23.454C107.068,22.725 106.935,21.919 106.935,21.046C106.935,20.184 107.068,19.389 107.324,18.66C107.579,17.931 107.957,17.301 108.446,16.771C108.935,16.24 109.546,15.832 110.257,15.533C110.968,15.235 111.779,15.08 112.69,15.08C113.601,15.08 114.412,15.235 115.123,15.533C115.834,15.832 116.434,16.251 116.934,16.771C117.423,17.301 117.8,17.931 118.056,18.66C118.311,19.389 118.445,20.184 118.445,21.046C118.445,21.919 118.311,22.725 118.056,23.454C117.8,24.184 117.423,24.813 116.934,25.344C116.445,25.874 115.834,26.283 115.123,26.581C114.412,26.879 113.601,27.034 112.69,27.034ZM112.69,25.056C113.245,25.056 113.734,24.935 114.145,24.703C114.556,24.471 114.89,24.162 115.156,23.786C115.423,23.41 115.612,22.979 115.745,22.504C115.867,22.029 115.934,21.543 115.934,21.046C115.934,20.56 115.867,20.085 115.745,19.599C115.623,19.113 115.423,18.693 115.156,18.317C114.89,17.942 114.556,17.643 114.145,17.411C113.734,17.179 113.245,17.058 112.69,17.058C112.134,17.058 111.645,17.179 111.234,17.411C110.823,17.643 110.49,17.953 110.223,18.317C109.957,18.693 109.768,19.113 109.634,19.599C109.512,20.085 109.446,20.56 109.446,21.046C109.446,21.543 109.512,22.029 109.634,22.504C109.757,22.979 109.957,23.41 110.223,23.786C110.49,24.162 110.823,24.471 111.234,24.703C111.645,24.946 112.134,25.056 112.69,25.056ZM119.178,15.39L121.078,15.39L121.078,11.987L123.589,11.987L123.589,15.39L125.855,15.39L125.855,17.257L123.589,17.257L123.589,23.311C123.589,23.576 123.6,23.797 123.622,23.996C123.644,24.184 123.7,24.349 123.778,24.482C123.855,24.614 123.978,24.714 124.144,24.78C124.311,24.846 124.522,24.88 124.811,24.88C124.989,24.88 125.166,24.88 125.344,24.869C125.522,24.858 125.7,24.835 125.877,24.791L125.877,26.725C125.6,26.758 125.322,26.78 125.066,26.813C124.8,26.846 124.533,26.857 124.255,26.857C123.589,26.857 123.055,26.791 122.656,26.669C122.256,26.548 121.933,26.36 121.711,26.117C121.478,25.874 121.333,25.576 121.245,25.211C121.167,24.846 121.111,24.427 121.1,23.963L121.1,17.279L119.2,17.279L119.2,15.39L119.178,15.39Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(1,0,0,0.226074,1213.4,863.302)">
                                            <path d="M30.142,11.07L18.632,11.07L15.076,0.177L11.51,11.07L0,11.059L9.321,17.798L5.755,28.68L15.076,21.952L24.387,28.68L20.831,17.798L30.142,11.07L30.142,11.07Z"
                                                  style="fill:rgb(0,182,122);fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(1,0,0,0.226074,1213.4,863.302)">
                                            <path d="M21.631,20.262L20.831,17.798L15.076,21.952L21.631,20.262Z"
                                                  style="fill:rgb(0,81,40);fill-rule:nonzero;"></path>
                                        </g>
                                    </g>
                                    <g transform="matrix(1.12388,0,0,0.0893092,-1103.52,543.912)">
                                        <g transform="matrix(10.6773,0,0,30.3763,1102,3793.54)">
                                            <path d="M0.552,0L0.409,-0.205C0.403,-0.204 0.394,-0.204 0.382,-0.204L0.224,-0.204L0.224,0L0.094,0L0.094,-0.7L0.382,-0.7C0.443,-0.7 0.496,-0.69 0.541,-0.67C0.586,-0.65 0.62,-0.621 0.644,-0.584C0.668,-0.547 0.68,-0.502 0.68,-0.451C0.68,-0.398 0.667,-0.353 0.642,-0.315C0.616,-0.277 0.579,-0.249 0.531,-0.23L0.692,0L0.552,0ZM0.549,-0.451C0.549,-0.496 0.534,-0.53 0.505,-0.554C0.476,-0.578 0.433,-0.59 0.376,-0.59L0.224,-0.59L0.224,-0.311L0.376,-0.311C0.433,-0.311 0.476,-0.323 0.505,-0.347C0.534,-0.372 0.549,-0.406 0.549,-0.451Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1109.81,3793.54)">
                                            <path d="M0.584,-0.264C0.584,-0.255 0.583,-0.243 0.582,-0.227L0.163,-0.227C0.17,-0.188 0.19,-0.157 0.221,-0.134C0.252,-0.111 0.29,-0.099 0.336,-0.099C0.395,-0.099 0.443,-0.118 0.481,-0.157L0.548,-0.08C0.524,-0.051 0.494,-0.03 0.457,-0.015C0.42,0 0.379,0.007 0.333,0.007C0.274,0.007 0.223,-0.005 0.178,-0.028C0.133,-0.051 0.099,-0.084 0.075,-0.126C0.05,-0.167 0.038,-0.214 0.038,-0.267C0.038,-0.319 0.05,-0.366 0.074,-0.407C0.097,-0.449 0.13,-0.482 0.172,-0.505C0.214,-0.528 0.261,-0.54 0.314,-0.54C0.366,-0.54 0.412,-0.529 0.454,-0.505C0.495,-0.483 0.527,-0.45 0.55,-0.408C0.573,-0.367 0.584,-0.319 0.584,-0.264ZM0.314,-0.44C0.274,-0.44 0.24,-0.428 0.213,-0.405C0.185,-0.381 0.168,-0.349 0.162,-0.31L0.465,-0.31C0.46,-0.349 0.443,-0.38 0.416,-0.404C0.389,-0.428 0.355,-0.44 0.314,-0.44Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1116.34,3793.54)">
                                            <path d="M0.582,-0.534L0.353,0L0.224,0L-0.005,-0.534L0.125,-0.534L0.291,-0.138L0.462,-0.534L0.582,-0.534Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1122.51,3793.54)">
                                            <path d="M0.082,-0.534L0.207,-0.534L0.207,0L0.082,0L0.082,-0.534ZM0.145,-0.622C0.122,-0.622 0.103,-0.629 0.088,-0.644C0.073,-0.658 0.065,-0.676 0.065,-0.697C0.065,-0.718 0.073,-0.736 0.088,-0.75C0.103,-0.765 0.122,-0.772 0.145,-0.772C0.168,-0.772 0.187,-0.765 0.202,-0.751C0.217,-0.738 0.225,-0.721 0.225,-0.7C0.225,-0.678 0.218,-0.66 0.203,-0.645C0.188,-0.629 0.168,-0.622 0.145,-0.622Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1125.6,3793.54)">
                                            <path d="M0.584,-0.264C0.584,-0.255 0.583,-0.243 0.582,-0.227L0.163,-0.227C0.17,-0.188 0.19,-0.157 0.221,-0.134C0.252,-0.111 0.29,-0.099 0.336,-0.099C0.395,-0.099 0.443,-0.118 0.481,-0.157L0.548,-0.08C0.524,-0.051 0.494,-0.03 0.457,-0.015C0.42,0 0.379,0.007 0.333,0.007C0.274,0.007 0.223,-0.005 0.178,-0.028C0.133,-0.051 0.099,-0.084 0.075,-0.126C0.05,-0.167 0.038,-0.214 0.038,-0.267C0.038,-0.319 0.05,-0.366 0.074,-0.407C0.097,-0.449 0.13,-0.482 0.172,-0.505C0.214,-0.528 0.261,-0.54 0.314,-0.54C0.366,-0.54 0.412,-0.529 0.454,-0.505C0.495,-0.483 0.527,-0.45 0.55,-0.408C0.573,-0.367 0.584,-0.319 0.584,-0.264ZM0.314,-0.44C0.274,-0.44 0.24,-0.428 0.213,-0.405C0.185,-0.381 0.168,-0.349 0.162,-0.31L0.465,-0.31C0.46,-0.349 0.443,-0.38 0.416,-0.404C0.389,-0.428 0.355,-0.44 0.314,-0.44Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1132.13,3793.54)">
                                            <path d="M0.915,-0.534L0.718,0L0.598,0L0.46,-0.368L0.32,0L0.2,0L0.004,-0.534L0.122,-0.534L0.263,-0.14L0.41,-0.534L0.515,-0.534L0.659,-0.138L0.804,-0.534L0.915,-0.534Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1144.89,3793.54)">
                                            <path d="M0.599,-0.534L0.599,0L0.48,0L0.48,-0.068C0.46,-0.044 0.435,-0.025 0.405,-0.013C0.375,0 0.343,0.007 0.308,0.007C0.237,0.007 0.181,-0.013 0.14,-0.053C0.099,-0.092 0.078,-0.151 0.078,-0.229L0.078,-0.534L0.203,-0.534L0.203,-0.246C0.203,-0.198 0.214,-0.162 0.235,-0.139C0.257,-0.115 0.288,-0.103 0.328,-0.103C0.373,-0.103 0.408,-0.117 0.435,-0.145C0.461,-0.172 0.474,-0.212 0.474,-0.264L0.474,-0.534L0.599,-0.534Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1152.16,3793.54)">
                                            <path d="M0.247,0.007C0.204,0.007 0.161,0.001 0.12,-0.01C0.079,-0.021 0.046,-0.036 0.021,-0.053L0.069,-0.148C0.093,-0.132 0.122,-0.119 0.156,-0.11C0.189,-0.1 0.222,-0.095 0.255,-0.095C0.33,-0.095 0.367,-0.115 0.367,-0.154C0.367,-0.173 0.358,-0.186 0.339,-0.193C0.32,-0.2 0.289,-0.207 0.247,-0.214C0.203,-0.221 0.167,-0.228 0.14,-0.237C0.112,-0.246 0.088,-0.261 0.068,-0.282C0.047,-0.304 0.037,-0.334 0.037,-0.373C0.037,-0.424 0.058,-0.464 0.101,-0.495C0.143,-0.525 0.2,-0.54 0.272,-0.54C0.309,-0.54 0.345,-0.536 0.382,-0.528C0.419,-0.519 0.449,-0.508 0.472,-0.494L0.424,-0.399C0.379,-0.426 0.328,-0.439 0.271,-0.439C0.234,-0.439 0.206,-0.434 0.188,-0.423C0.169,-0.411 0.159,-0.397 0.159,-0.379C0.159,-0.359 0.169,-0.345 0.19,-0.337C0.21,-0.328 0.241,-0.32 0.284,-0.313C0.327,-0.306 0.362,-0.299 0.389,-0.29C0.416,-0.281 0.44,-0.267 0.46,-0.246C0.479,-0.225 0.489,-0.196 0.489,-0.158C0.489,-0.108 0.467,-0.068 0.424,-0.038C0.381,-0.008 0.322,0.007 0.247,0.007Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1160.61,3793.54)">
                                            <path d="M0.322,0.007C0.268,0.007 0.219,-0.005 0.176,-0.028C0.133,-0.051 0.099,-0.084 0.075,-0.126C0.05,-0.167 0.038,-0.214 0.038,-0.267C0.038,-0.32 0.05,-0.367 0.075,-0.408C0.099,-0.449 0.133,-0.482 0.176,-0.505C0.219,-0.528 0.268,-0.54 0.322,-0.54C0.377,-0.54 0.426,-0.528 0.469,-0.505C0.512,-0.482 0.546,-0.449 0.571,-0.408C0.595,-0.367 0.607,-0.32 0.607,-0.267C0.607,-0.214 0.595,-0.167 0.571,-0.126C0.546,-0.084 0.512,-0.051 0.469,-0.028C0.426,-0.005 0.377,0.007 0.322,0.007ZM0.322,-0.1C0.368,-0.1 0.406,-0.115 0.436,-0.146C0.466,-0.177 0.481,-0.217 0.481,-0.267C0.481,-0.317 0.466,-0.357 0.436,-0.388C0.406,-0.419 0.368,-0.434 0.322,-0.434C0.276,-0.434 0.238,-0.419 0.209,-0.388C0.179,-0.357 0.164,-0.317 0.164,-0.267C0.164,-0.217 0.179,-0.177 0.209,-0.146C0.238,-0.115 0.276,-0.1 0.322,-0.1Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                        <g transform="matrix(10.6773,0,0,30.3763,1167.49,3793.54)">
                                            <path d="M0.385,-0.54C0.452,-0.54 0.506,-0.52 0.547,-0.481C0.588,-0.442 0.608,-0.383 0.608,-0.306L0.608,0L0.483,0L0.483,-0.29C0.483,-0.337 0.472,-0.372 0.45,-0.396C0.428,-0.419 0.397,-0.431 0.356,-0.431C0.31,-0.431 0.274,-0.417 0.247,-0.39C0.22,-0.362 0.207,-0.322 0.207,-0.27L0.207,0L0.082,0L0.082,-0.534L0.201,-0.534L0.201,-0.465C0.222,-0.49 0.248,-0.508 0.279,-0.521C0.31,-0.534 0.346,-0.54 0.385,-0.54Z"
                                                  style="fill-rule:nonzero;"></path>
                                        </g>
                                    </g>
                                </g>
                                <g transform="matrix(1.21212,0,0,0.215332,142.599,49.6458)">
                                    <rect x="387" y="3885" width="165" height="38"
                                          style="fill:none;stroke:rgb(0,182,122);stroke-width:2px;"></rect>
                                </g>
                            </g>
                        </g>
                    </g>
                </svg>
            </a>
        </div>

        <?php
    }

    private function ca_settings()
    {
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->qrcode_size = $this->get_option('qrcode_size');
        $this->qrcode_default = $this->get_option('qrcode_default') === 'yes';
        $this->qrcode_setting = $this->get_option('qrcode_setting');
        $this->coins = $this->get_option('coins');
        $coin_wallets_raw = $this->get_option('coin_wallets', []);
        $this->coin_wallets = is_array($coin_wallets_raw) ? $coin_wallets_raw : [];
        $this->show_branding = $this->get_option('show_branding') === 'yes';
        $this->show_crypto_logos = $this->get_option('show_crypto_logos') === 'yes';
        $this->color_scheme = $this->get_option('color_scheme');
        $this->refresh_value_interval = $this->get_option('refresh_value_interval');
        $this->order_cancelation_timeout = $this->get_option('order_cancelation_timeout');
        $this->add_blockchain_fee = $this->get_option('add_blockchain_fee') === 'yes';
        $this->fee_order_percentage = $this->get_option('fee_order_percentage');
        $this->virtual_complete = $this->get_option('virtual_complete') === 'yes';
        $this->disable_conversion = $this->get_option('disable_conversion') === 'yes';
        $this->tolerance = $this->get_option('tolerance');
        $this->debug = $this->get_option('debug') === 'yes';
        $this->auto_complete_all = $this->get_option('auto_complete_all') === 'yes';
        $this->callback_ip_allowlist = (string) $this->get_option('callback_ip_allowlist', '51.77.105.132,135.125.112.47');
        $this->icon = '';
    }

    /**
     * Hardening: accept only base64-encoded PNG payloads for QR images.
     * Prevents potential XSS via data URIs (e.g. SVG in some browsers).
     */
    private function sanitize_qr_base64($b64): string
    {
        $b64 = trim((string) $b64);
        if ($b64 === '') {
            return '';
        }
        // sanity size limit (~1.5MB base64) to avoid memory abuse
        if (strlen($b64) > 1500000) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9+\\/=]+$/', $b64)) {
            return '';
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) < 8) {
            return '';
        }
        // PNG signature
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return '';
        }
        return $b64;
    }

    function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => esc_attr(__('Enabled', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__('Enable CrypLink Payments', 'cryplink')),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => esc_attr(__('Title', 'cryplink')),
                'type' => 'text',
                'description' => esc_attr(__('This controls the title which the user sees during checkout.', 'cryplink')),
                'default' => esc_attr(__('Cryptocurrency', 'cryplink')),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => esc_attr(__('Description', 'cryplink')),
                'type' => 'textarea',
                'default' => '',
                'description' => esc_attr(__('Payment method description that the customer will see on your checkout', 'cryplink'))
            ),
            'coins' => array(
                'title'       => esc_attr(__('Accepted Tokens & Wallet Addresses', 'cryplink')),
                'type'        => 'coins_multiselect',
                'description' => esc_attr(__('Select tokens to accept and set a wallet address for each.', 'cryplink')),
                'default'     => array(),
            ),
            'show_branding' => array(
                'title' => esc_attr(__('Show CrypLink branding', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__('Show CrypLink logo and credits below the QR code', 'cryplink')),
                'default' => 'yes'
            ),
            'show_crypto_logos' => array(
                'title' => esc_attr(__('Show crypto logos in checkout', 'cryplink')),
                'type' => 'checkbox',
                'label' => sprintf(esc_attr(__('Enable this to show the cryptocurrencies logos in the checkout %1$s %2$s Notice: %3$s It may break in some templates. Use at your own risk.', 'cryplink')), '<br/>', '<strong>', '</strong>'),
                'default' => 'no'
            ),
            'add_blockchain_fee' => array(
                'title' => esc_attr(__('Add the blockchain fee to the order', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__("This will add an estimation of the blockchain fee to the order value", 'cryplink')),
                'default' => 'no'
            ),
            'tolerance' => array(
                'title' => __('Payment tolerance (%)', 'cryplink'),
                'type' => 'number',
                'description' => __('Set the payment tolerance percentage (0-10%). <strong>Recommended: 1%</strong> to handle minor rounding differences. Higher values may result in underpayment being accepted.', 'cryplink'),
                'default' => 0,
                'placeholder' => '0',
                'custom_attributes' => array(
                    'min' => '0',
                    'max' => '10',
                    'step' => '1',
                ),
            ),
            'fee_order_percentage' => array(
                'title' => esc_attr(__('Service fee manager', 'cryplink')),
                'type' => 'select',
                'default' => 'none',
                'options' => array(
                    '0.05' => '5%', '0.048' => '4.8%', '0.045' => '4.5%',
                    '0.042' => '4.2%', '0.04' => '4%', '0.038' => '3.8%',
                    '0.035' => '3.5%', '0.032' => '3.2%', '0.03' => '3%',
                    '0.028' => '2.8%', '0.025' => '2.5%', '0.022' => '2.2%',
                    '0.02' => '2%', '0.018' => '1.8%', '0.015' => '1.5%',
                    '0.012' => '1.2%', '0.01' => '1%', '0.0090' => '0.90%',
                    '0.0085' => '0.85%', '0.0080' => '0.80%', '0.0075' => '0.75%',
                    '0.0070' => '0.70%', '0.0065' => '0.65%', '0.0060' => '0.60%',
                    '0.0055' => '0.55%', '0.0050' => '0.50%', '0.0040' => '0.40%',
                    '0.0030' => '0.30%', '0.0025' => '0.25%', 'none' => '0%',
                ),
                'description' => sprintf(esc_attr(__('Service fee to charge the customer. %1$s %2$s Note:%3$s To cover CrypLink fees fully or partially.', 'cryplink')), '<br/>', '<strong>', '</strong>')
            ),
            'qrcode_default' => array(
                'title' => esc_attr(__('QR Code by default', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__('Show the QR Code by default', 'cryplink')),
                'default' => 'yes'
            ),
            'qrcode_size' => array(
                'title' => esc_attr(__('QR Code size', 'cryplink')),
                'type' => 'number',
                'default' => 300,
                'description' => esc_attr(__('QR code image size', 'cryplink'))
            ),
            'qrcode_setting' => array(
                'title' => esc_attr(__('QR Code to show', 'cryplink')),
                'type' => 'select',
                'default' => 'without_ammount',
                'options' => array(
                    'without_ammount'      => esc_attr(__('Default Without Amount', 'cryplink')),
                    'ammount'              => esc_attr(__('Default Amount', 'cryplink')),
                    'hide_ammount'         => esc_attr(__('Hide Amount', 'cryplink')),
                    'hide_without_ammount' => esc_attr(__('Hide Without Amount', 'cryplink')),
                ),
                'description' => esc_attr(__('Select how you want to show the QR Code to the user.', 'cryplink'))
            ),
            'color_scheme' => array(
                'title' => esc_attr(__('Color Scheme', 'cryplink')),
                'type' => 'select',
                'default' => 'light',
                'description' => esc_attr(__('Selects the color scheme of the plugin (Light, Dark, Auto)', 'cryplink')),
                'options' => array(
                    'light' => esc_attr(__('Light', 'cryplink')),
                    'dark'  => esc_attr(__('Dark', 'cryplink')),
                    'auto'  => esc_attr(__('Auto', 'cryplink')),
                ),
            ),
            'refresh_value_interval' => array(
                'title' => esc_attr(__('Refresh converted value', 'cryplink')),
                'type' => 'select',
                'default' => '300',
                'options' => array(
                    '0'    => esc_attr(__('Never', 'cryplink')),
                    '300'  => esc_attr(__('Every 5 Minutes', 'cryplink')),
                    '600'  => esc_attr(__('Every 10 Minutes', 'cryplink')),
                    '900'  => esc_attr(__('Every 15 Minutes', 'cryplink')),
                    '1800' => esc_attr(__('Every 30 Minutes', 'cryplink')),
                    '2700' => esc_attr(__('Every 45 Minutes', 'cryplink')),
                    '3600' => esc_attr(__('Every 60 Minutes', 'cryplink')),
                ),
                'description' => esc_attr(__('How often to refresh the crypto conversion value on open invoices. Recommended: 5 minutes.', 'cryplink')),
            ),
            'order_cancelation_timeout' => array(
                'title' => esc_attr(__('Order cancelation timeout', 'cryplink')),
                'type' => 'select',
                'default' => '0',
                'options' => array(
                    '0'     => esc_attr(__('Never', 'cryplink')),
                    '900'   => esc_attr(__('15 Minutes', 'cryplink')),
                    '1800'  => esc_attr(__('30 Minutes', 'cryplink')),
                    '2700'  => esc_attr(__('45 Minutes', 'cryplink')),
                    '3600'  => esc_attr(__('1 Hour', 'cryplink')),
                    '21600' => esc_attr(__('6 Hours', 'cryplink')),
                    '43200' => esc_attr(__('12 Hours', 'cryplink')),
                    '64800' => esc_attr(__('18 Hours', 'cryplink')),
                    '86400' => esc_attr(__('24 Hours', 'cryplink')),
                ),
                'description' => esc_attr(__('Time allowed for the customer to pay before the order is automatically cancelled. We do not advise more than 1 hour.', 'cryplink')),
            ),
            'virtual_complete' => array(
                'title' => esc_attr(__('Completed status for virtual products', 'cryplink')),
                'type' => 'checkbox',
                'label' => sprintf(__('Auto-complete orders when payment is received %1$s (virtual products only)%2$s.', 'cryplink'), '<strong>', '</strong>'),
                'default' => 'no'
            ),
            'disable_conversion' => array(
                'title' => esc_attr(__('Disable price conversion', 'cryplink')),
                'type' => 'checkbox',
                'label' => sprintf(__('%2$s Attention: disables price conversion for ALL cryptocurrencies.%3$s %1$s Users will be asked to pay the same numeric value as shown in your shop currency.', 'cryplink'), '<br/>', '<strong>', '</strong>'),
                'default' => 'no'
            ),
            'debug' => array(
                'title' => esc_attr(__('Debug logging', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__('Enable CrypLink debug logs (WooCommerce → Status → Logs → source: cryplink)', 'cryplink')),
                'default' => 'no',
                'description' => esc_attr(__('Turn this on temporarily to troubleshoot callbacks/logs/cron. Remember to turn it off after debugging.', 'cryplink')),
            ),
            'auto_complete_all' => array(
                'title' => esc_attr(__('Auto-complete paid orders', 'cryplink')),
                'type' => 'checkbox',
                'label' => esc_attr(__('Mark orders as Completed automatically after payment is confirmed (including physical products).', 'cryplink')),
                'default' => 'no',
                'description' => esc_attr(__('WooCommerce normally sets paid orders to Processing when shipping is required. Enable this only if you want Completed for all paid orders.', 'cryplink')),
            ),
            'callback_ip_allowlist' => array(
                'title'       => esc_attr(__('Callback IP allowlist', 'cryplink')),
                'type'        => 'text',
                'description' => esc_attr(__('Comma-separated list of IPs allowed to call the callback endpoint (?wc-api=WC_Gateway_CrypLink). Leave empty to disable. Tip: you can auto-fill by using “Fetch IPs from CrypLink” or “Allowlist last callback IP” on this page.', 'cryplink')),
                'default'     => '51.77.105.132,135.125.112.47',
            ),
        );
    }

    private function is_allowed_callback_ip(): bool
    {
        $raw = trim((string) $this->callback_ip_allowlist);
        if ($raw === '') {
            return true; // disabled
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($ip)) {
            return false;
        }
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        return in_array($ip, $allowed, true);
    }

    /**
     * Admin helper: add the last seen callback IP to the allowlist automatically.
     * Note: this can only work after at least one callback attempt has reached the site.
     */
    public function handle_admin_allowlist_last_ip()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to do this.', 'cryplink'));
        }
        check_admin_referer('cryplink_allowlist_last_ip');

        $last = get_option('cryplink_last_callback_ip', []);
        $ip = is_array($last) ? (string) ($last['ip'] ?? '') : '';
        $ip = trim($ip);

        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
        }

        if (empty($ip)) {
            $back = add_query_arg('cryplink_allowlist_ip', 'missing', $back);
            wp_safe_redirect($back);
            exit;
        }

        $raw = (string) $this->get_option('callback_ip_allowlist', '');
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        if (!in_array($ip, $allowed, true)) {
            $allowed[] = $ip;
        }
        $new = implode(',', $allowed);
        // Persist in gateway options
        $options = get_option('woocommerce_cryplink_settings', []);
        if (!is_array($options)) {
            $options = [];
        }
        $options['callback_ip_allowlist'] = $new;
        update_option('woocommerce_cryplink_settings', $options);

        $back = add_query_arg('cryplink_allowlist_ip', rawurlencode($ip), $back);
        wp_safe_redirect($back);
        exit;
    }

    /**
     * Admin helper: fetch official IPs from CrypLink API (/ips) and merge into allowlist.
     */
    public function handle_admin_fetch_ips()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to do this.', 'cryplink'));
        }
        check_admin_referer('cryplink_fetch_ips');

        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
        }

        $ips = \CrypLink\Utils\Api::get_callback_ips();
        if (empty($ips)) {
            $back = add_query_arg('cryplink_ips_fetch', 'failed', $back);
            wp_safe_redirect($back);
            exit;
        }

        // Merge into existing allowlist
        $raw = (string) $this->get_option('callback_ip_allowlist', '');
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($ips as $ip) {
            if (!in_array($ip, $allowed, true)) {
                $allowed[] = $ip;
            }
        }
        $new = implode(',', $allowed);

        $options = get_option('woocommerce_cryplink_settings', []);
        if (!is_array($options)) {
            $options = [];
        }
        $options['callback_ip_allowlist'] = $new;
        update_option('woocommerce_cryplink_settings', $options);

        update_option('cryplink_last_fetched_ips', ['ips' => $ips, 'time' => time()], false);

        $back = add_query_arg('cryplink_ips_fetch', 'ok', $back);
        wp_safe_redirect($back);
        exit;
    }

    public function handle_admin_refresh_pubkey()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to do this.', 'cryplink'));
        }
        check_admin_referer('cryplink_refresh_pubkey');
        delete_transient('cryplink_pubkey');
        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id);
        }
        $back = add_query_arg('cryplink_pubkey_refreshed', '1', $back);
        wp_safe_redirect($back);
        exit;
    }

    private function cryplink_log($level, $message, $context = [])
    {
        if (empty($this->debug)) {
            return;
        }
        if (!function_exists('wc_get_logger')) {
            return;
        }
        try {
            $logger = wc_get_logger();
            $ctx = array_merge(['source' => 'cryplink'], is_array($context) ? $context : []);
            $logger->log($level, $message, $ctx);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Ensure LiteSpeed (and other caches) never cache dynamic callback/status responses.
     */
    private function send_nocache_headers()
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (!headers_sent()) {
            // LiteSpeed Cache respects this header to bypass caching.
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    /**
     * Render the coins multiselect field.
     * Token list is loaded from the WP option cache (populated by load_coins / reset_load_coins).
     */
    /**
     * Called by parent::process_admin_options() for the 'coins_multiselect' field type.
     * Without this, WC falls back to validate_text_field which converts the array to
     * the string "Array" and overwrites the correctly-saved option with garbage.
     */
    public function validate_coins_multiselect_field($key, $value)
    {
        $field_key = $this->get_field_key($key);
        $coins_raw = isset($_POST[$field_key]) ? (array) $_POST[$field_key] : [];
        $coins     = array_map('sanitize_text_field', $coins_raw);
        $coins     = array_values(array_unique(array_filter($coins)));

        // Only allow known CrypLink tickers to be saved.
        $all_coins = self::load_coins();
        if (empty($all_coins) || !is_array($all_coins)) {
            return [];
        }

        $out = [];
        foreach ($coins as $ticker) {
            $ticker = strtolower(trim((string) $ticker));
            if ($ticker === '' || $ticker === 'none') {
                continue;
            }
            if (array_key_exists($ticker, $all_coins)) {
                $out[] = $ticker;
            }
        }
        return array_values(array_unique($out));
    }

    public function generate_coins_multiselect_html($key, $data)
    {
        $field_key   = $this->get_field_key($key);
        $saved_coins = $this->get_option($key, array());
        if (!is_array($saved_coins)) {
            $saved_coins = array();
        }

        $saved_wallets = $this->get_option('coin_wallets', []);
        if (!is_array($saved_wallets)) {
            $saved_wallets = [];
        }

        $all_coins = self::load_coins();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp" style="padding-bottom:16px;">
                <?php if (empty($all_coins)) : ?>
                    <p style="color:#c00;">
                        <?php esc_html_e('Could not load token list. Click "Refresh Token List" to retry.', 'cryplink'); ?>
                    </p>
                <?php else : ?>

                <!-- Toolbar -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                    <div style="position:relative;flex:1;min-width:200px;max-width:360px;">
                        <input type="text" id="cryplink_coin_search"
                               autocomplete="off"
                               placeholder="<?php esc_attr_e('Search & add token…', 'cryplink'); ?>"
                               style="width:100%;padding:7px 32px 7px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;" />
                        <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#aaa;pointer-events:none;">▾</span>
                        <!-- Dropdown list -->
                        <div id="cryplink_dropdown" style="
                            display:none;
                            position:absolute;
                            top:calc(100% + 2px);
                            left:0;right:0;
                            max-height:260px;
                            overflow-y:auto;
                            background:#fff;
                            border:1px solid #ddd;
                            border-radius:4px;
                            box-shadow:0 4px 12px rgba(0,0,0,.12);
                            z-index:9999;
                        ">
                            <?php foreach ($all_coins as $ticker => $coin) :
                                $name = is_array($coin) ? ($coin['name'] ?? strtoupper($ticker)) : $coin;
                                $logo = is_array($coin) ? ($coin['logo'] ?? '') : '';
                                $state = is_array($coin) ? (string) ($coin['state'] ?? 'live') : 'live';
                                $state_label = ($state === 'maintenance') ? 'Maintenance' : 'live';
                            ?>
                            <div class="cryplink-dd-item"
                                 data-ticker="<?php echo esc_attr($ticker); ?>"
                                 data-name="<?php echo esc_attr($name); ?>"
                                 data-logo="<?php echo esc_url($logo); ?>"
                                 data-search="<?php echo esc_attr(strtolower($ticker . ' ' . $name)); ?>"
                                 style="display:flex;align-items:center;gap:8px;padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;">
                                <?php if ($logo) : ?>
                                <img src="<?php echo esc_url($logo); ?>" width="20" height="20"
                                     style="border-radius:50%;object-fit:contain;flex-shrink:0;"
                                     onerror="this.style.display='none'" />
                                <?php else : ?>
                                <span style="width:20px;height:20px;border-radius:50%;background:#e9eef5;display:inline-flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;color:#555;flex-shrink:0;">
                                    <?php echo esc_html(strtoupper(substr($ticker,0,2))); ?>
                                </span>
                                <?php endif; ?>
                                <span>
                                    <strong><?php echo esc_html(strtoupper($ticker)); ?></strong>
                                    <span style="color:#6b7280;font-size:11px;margin-left:6px;">(<?php echo esc_html($state_label); ?>)</span>
                                    <span style="color:#888;font-size:11px;margin-left:4px;"><?php echo esc_html($name); ?></span>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cryplink_refresh_coins'), 'cryplink_refresh_coins')); ?>"
                       style="font-size:12px;white-space:nowrap;text-decoration:none;color:#2271b1;">
                        ↺ <?php esc_html_e('Refresh Token List', 'cryplink'); ?>
                    </a>
                    <span id="cryplink_selected_count" style="font-size:12px;color:#666;"></span>
                </div>

                <!-- Selected token rows -->
                <div id="cryplink_selected_list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                    <?php foreach ($saved_coins as $ticker) :
                        if (!isset($all_coins[$ticker])) continue;
                        $coin   = $all_coins[$ticker];
                        $name   = is_array($coin) ? ($coin['name'] ?? strtoupper($ticker)) : $coin;
                        $logo   = is_array($coin) ? ($coin['logo'] ?? '') : '';
                        $state = is_array($coin) ? (string) ($coin['state'] ?? 'live') : 'live';
                        $state_label = ($state === 'maintenance') ? 'Maintenance' : 'live';
                        $wallet = $saved_wallets[$ticker] ?? '';
                    ?>
                    <div class="cryplink-selected-row"
                         data-ticker="<?php echo esc_attr($ticker); ?>"
                         style="display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:7px 10px;">
                        <!-- hidden checkbox to submit selected coins -->
                        <input type="checkbox"
                               name="<?php echo esc_attr($field_key); ?>[]"
                               value="<?php echo esc_attr($ticker); ?>"
                               checked style="display:none;" />
                        <?php if ($logo) : ?>
                        <img src="<?php echo esc_url($logo); ?>" width="24" height="24"
                             style="border-radius:50%;object-fit:contain;flex-shrink:0;"
                             onerror="this.style.display='none'" />
                        <?php else : ?>
                        <span style="width:24px;height:24px;border-radius:50%;background:#e9eef5;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#555;flex-shrink:0;">
                            <?php echo esc_html(strtoupper(substr($ticker,0,2))); ?>
                        </span>
                        <?php endif; ?>
                        <strong style="font-size:13px;min-width:48px;"><?php echo esc_html(strtoupper($ticker)); ?></strong>
                        <span style="font-size:11px;color:#6b7280;white-space:nowrap;">(<?php echo esc_html($state_label); ?>)</span>
                        <span style="font-size:11px;color:#888;min-width:80px;"><?php echo esc_html($name); ?></span>
                        <input type="text"
                               name="cryplink_coin_wallet[<?php echo esc_attr($ticker); ?>]"
                               value="<?php echo esc_attr($wallet); ?>"
                               placeholder="<?php esc_attr_e('Wallet address', 'cryplink'); ?>"
                               class="cryplink-wallet-input"
                               style="flex:1;font-size:12px;padding:5px 8px;border:1px solid #ddd;border-radius:4px;min-width:0;" />
                        <button type="button" class="cryplink-remove-btn"
                                title="<?php esc_attr_e('Remove', 'cryplink'); ?>"
                                style="background:none;border:none;cursor:pointer;color:#c00;font-size:18px;line-height:1;padding:0 4px;flex-shrink:0;">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top:4px;font-size:12px;color:#888;">
                    <?php printf(esc_html__('%d tokens available · source: payme.cryplink.xyz/tickers', 'cryplink'), count($all_coins)); ?>
                </p>

                <?php endif; ?>

                <?php if (!empty($data['description'])) : ?>
                    <p class="description" style="margin-top:6px;"><?php echo wp_kses_post($data['description']); ?></p>
                <?php endif; ?>

                <style>
                .cryplink-dd-item:hover { background:#f0f6fd; }
                .cryplink-dd-item.hidden { display:none !important; }
                .cryplink-selected-row:hover { border-color:#2271b1; }
                </style>
                <script>
                (function () {
                    var searchEl  = document.getElementById('cryplink_coin_search');
                    var dropdown  = document.getElementById('cryplink_dropdown');
                    var listEl    = document.getElementById('cryplink_selected_list');
                    var badge     = document.getElementById('cryplink_selected_count');
                    var fieldKey  = '<?php echo esc_js($field_key); ?>';

                    var allCoins  = <?php
                        $coins_js = [];
                        foreach ($all_coins as $t => $c) {
                            $n = is_array($c) ? ($c['name'] ?? strtoupper($t)) : $c;
                            $l = is_array($c) ? ($c['logo'] ?? '') : '';
                            $st = is_array($c) ? (string) ($c['state'] ?? 'live') : 'live';
                            $sl = ($st === 'maintenance') ? 'Maintenance' : 'live';
                            $coins_js[$t] = ['name' => $n, 'logo' => $l, 'state' => $st, 'state_label' => $sl];
                        }
                        echo json_encode($coins_js);
                    ?>;

                    function getSelected() {
                        return Array.from(listEl.querySelectorAll('.cryplink-selected-row')).map(function(r){ return r.dataset.ticker; });
                    }

                    function updateBadge() {
                        var n = getSelected().length;
                        badge.textContent = n + ' <?php echo esc_js(__('selected', 'cryplink')); ?>';
                    }

                    function addCoin(ticker) {
                        if (getSelected().includes(ticker)) { closeDropdown(); return; }
                        var coin = allCoins[ticker];
                        if (!coin) return;

                        var logoHtml = coin.logo
                            ? '<img src="'+coin.logo+'" width="24" height="24" style="border-radius:50%;object-fit:contain;flex-shrink:0;" onerror="this.style.display=\'none\'" />'
                            : '<span style="width:24px;height:24px;border-radius:50%;background:#e9eef5;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#555;flex-shrink:0;">'+ticker.substring(0,2).toUpperCase()+'</span>';

                        var row = document.createElement('div');
                        row.className = 'cryplink-selected-row';
                        row.dataset.ticker = ticker;
                        row.style.cssText = 'display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:7px 10px;';
                        row.innerHTML =
                            '<input type="checkbox" name="'+fieldKey+'[]" value="'+ticker+'" checked style="display:none;" />' +
                            logoHtml +
                            '<strong style="font-size:13px;min-width:48px;">'+ticker.toUpperCase()+'</strong>' +
                            '<span style="font-size:11px;color:#6b7280;white-space:nowrap;">('+(coin.state_label || 'live')+')</span>' +
                            '<span style="font-size:11px;color:#888;min-width:80px;">'+coin.name+'</span>' +
                            '<input type="text" name="cryplink_coin_wallet['+ticker+']" value="" placeholder="<?php echo esc_js(__('Wallet address', 'cryplink')); ?>" class="cryplink-wallet-input" style="flex:1;font-size:12px;padding:5px 8px;border:1px solid #ddd;border-radius:4px;min-width:0;" />' +
                            '<button type="button" class="cryplink-remove-btn" title="<?php echo esc_js(__('Remove', 'cryplink')); ?>" style="background:none;border:none;cursor:pointer;color:#c00;font-size:18px;line-height:1;padding:0 4px;flex-shrink:0;">×</button>';

                        row.querySelector('.cryplink-remove-btn').addEventListener('click', function(){ removeCoin(row); });
                        listEl.appendChild(row);
                        updateBadge();
                        closeDropdown();
                        searchEl.value = '';
                    }

                    function removeCoin(row) {
                        row.parentNode.removeChild(row);
                        updateBadge();
                    }

                    function closeDropdown() {
                        if (dropdown) dropdown.style.display = 'none';
                    }

                    // Wire up existing remove buttons (PHP-rendered rows)
                    listEl.querySelectorAll('.cryplink-remove-btn').forEach(function(btn) {
                        btn.addEventListener('click', function(){ removeCoin(btn.closest('.cryplink-selected-row')); });
                    });

                    // Search input → filter dropdown
                    if (searchEl && dropdown) {
                        searchEl.addEventListener('focus', function() { dropdown.style.display = 'block'; filterDropdown(this.value); });
                        searchEl.addEventListener('input', function() { dropdown.style.display = 'block'; filterDropdown(this.value); });
                    }

                    function filterDropdown(q) {
                        q = q.toLowerCase().trim();
                        dropdown.querySelectorAll('.cryplink-dd-item').forEach(function(item) {
                            var match = !q || item.dataset.search.includes(q);
                            item.classList.toggle('hidden', !match);
                        });
                    }

                    // Click on dropdown item
                    if (dropdown) {
                        dropdown.querySelectorAll('.cryplink-dd-item').forEach(function(item) {
                            item.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                addCoin(item.dataset.ticker);
                            });
                        });
                    }

                    // Close dropdown when clicking outside
                    document.addEventListener('click', function(e) {
                        if (searchEl && !searchEl.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
                            closeDropdown();
                        }
                    });

                    updateBadge();
                })();
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
    function needs_setup()
    {
        if (empty($this->coins) || !is_array($this->coins)) {
            return true;
        }

        // Setup is complete when every selected coin either has its own wallet
        // address or the global fallback address is set.
        foreach ($this->coins as $ticker) {
            $w = trim($this->coin_wallets[$ticker] ?? '');
            if (!empty($w)) {
                return false; // at least one coin is fully configured
            }
        }

        return true;
    }

    public function get_icon()
    {

        $icon_url = esc_url('https://res.cloudinary.com/diica7at6/image/upload/v1774226418/cryplink-logo-blue_w6el8p.png');
        $icon = $this->show_branding ? '<img style="top: -5px; position:relative" width="120" src="' . $icon_url . '" alt="' . esc_attr($this->get_title()) . '" />' : '';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    function payment_fields()
    {
        try {
            $load_coins = self::load_coins();
        } catch (\Throwable $e) {
            ?>
            <div class="woocommerce-error">
                <?php echo __('Sorry, there has been an error.', 'woocommerce'); ?>
            </div>
            <?php
            return;
        }

        $enabled_coins = is_array($this->coins) ? $this->coins : [];
        ?>
        <div class="form-row form-row-wide cryplink-payment-fields">
            <?php if (!empty($this->description)) : ?>
            <p style="margin-bottom:10px;"><?php echo esc_html($this->description); ?></p>
            <?php endif; ?>

            <?php if (!empty($enabled_coins) && !empty($load_coins)) : ?>
            <label id="cryplink_coin_label" style="display:block;margin-bottom:6px;font-weight:600;">
                <?php esc_html_e('Select Cryptocurrency', 'cryplink'); ?>
            </label>

            <!-- Native select (hidden, keeps WC form/validation compatibility) -->
            <select name="cryplink_coin" id="payment_cryplink_coin"
                    aria-hidden="true"
                    style="display:none;"
                    class="input-control">
                <option value="none"><?php esc_html_e('Please select a Cryptocurrency', 'cryplink'); ?></option>
                <?php
                $session_coin = (function_exists('WC') && WC()->session) ? WC()->session->get('cryplink_coin') : '';
                foreach ($enabled_coins as $ticker) {
                    if (!isset($load_coins[$ticker])) continue;
                    $coin_data   = $load_coins[$ticker];
                    $crypto_name = is_array($coin_data) ? ($coin_data['name'] ?? strtoupper($ticker)) : $coin_data;
                    $logo        = is_array($coin_data) ? ($coin_data['logo'] ?? '') : '';
                    $sel_attr    = ($session_coin === $ticker) ? 'selected' : '';
                    ?>
                    <option data-image="<?php echo esc_url($logo); ?>"
                            value="<?php echo esc_attr($ticker); ?>"
                            <?php echo $sel_attr; ?>>
                        <?php echo esc_html($crypto_name . ' (' . strtoupper($ticker) . ')'); ?>
                    </option>
                    <?php
                }
                ?>
            </select>

            <!-- Custom dropdown -->
            <?php
            $none_sel = empty($session_coin) || $session_coin === 'none';
            ?>
            <div id="cryplink_dropdown_wrap" style="position:relative;width:100%;max-width:360px;">

                <!-- Trigger button -->
                <button type="button"
                        id="cryplink_dropdown_btn"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-labelledby="cryplink_coin_label cryplink_dropdown_btn"
                        style="
                            display:flex;align-items:center;gap:10px;
                            width:100%;padding:9px 12px;
                            border:1px solid #ddd;border-radius:6px;
                            background:#fff;cursor:pointer;
                            font-size:14px;color:#333;
                            box-shadow:0 1px 3px rgba(0,0,0,.06);
                            transition:border-color .15s,box-shadow .15s;
                        ">
                    <span id="cryplink_dd_logo" style="flex-shrink:0;width:24px;height:24px;display:flex;align-items:center;justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#aaa" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                    </span>
                    <span id="cryplink_dd_label" style="flex:1;text-align:left;color:#999;">
                        <?php esc_html_e('Please select a Cryptocurrency', 'cryplink'); ?>
                    </span>
                    <svg id="cryplink_dd_arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2.5" style="flex-shrink:0;transition:transform .2s;"><polyline points="6 9 12 15 18 9"/></svg>
                </button>

                <!-- Dropdown list -->
                <ul id="cryplink_dropdown_list"
                    role="listbox"
                    aria-labelledby="cryplink_coin_label"
                    tabindex="-1"
                    style="
                        display:none;
                        position:absolute;top:calc(100% + 4px);left:0;right:0;
                        background:#fff;border:1px solid #d0d0d0;border-radius:6px;
                        box-shadow:0 6px 20px rgba(0,0,0,.12);
                        max-height:260px;overflow-y:auto;
                        z-index:9999;padding:4px 0;margin:0;list-style:none;
                    ">
                    <?php foreach ($enabled_coins as $ticker) :
                        if (!isset($load_coins[$ticker])) continue;
                        $coin_data   = $load_coins[$ticker];
                        $crypto_name = is_array($coin_data) ? ($coin_data['name'] ?? strtoupper($ticker)) : $coin_data;
                        $logo        = is_array($coin_data) ? ($coin_data['logo'] ?? '') : '';
                        $active      = (!$none_sel && $session_coin === $ticker);
                    ?>
                    <li role="option"
                        aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                        data-ticker="<?php echo esc_attr($ticker); ?>"
                        data-name="<?php echo esc_attr($crypto_name); ?>"
                        data-logo="<?php echo esc_url($logo); ?>"
                        style="
                            display:flex;align-items:center;gap:10px;
                            padding:8px 12px;cursor:pointer;
                            background:<?php echo $active ? '#f0f6fd' : 'transparent'; ?>;
                            font-size:14px;color:#333;
                            transition:background .1s;
                        ">
                        <?php if ($logo) : ?>
                        <img src="<?php echo esc_url($logo); ?>"
                             width="24" height="24"
                             style="border-radius:50%;object-fit:contain;flex-shrink:0;"
                             alt="<?php echo esc_attr($crypto_name); ?>"
                             onerror="this.style.display='none'" />
                        <?php else : ?>
                        <span style="width:24px;height:24px;border-radius:50%;background:#e9eef5;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#555;flex-shrink:0;">
                            <?php echo esc_html(strtoupper(substr($ticker,0,2))); ?>
                        </span>
                        <?php endif; ?>
                        <span style="line-height:1.3;">
                            <strong style="display:block;font-size:13px;"><?php echo esc_html(strtoupper($ticker)); ?></strong>
                            <span style="font-size:11px;color:#888;"><?php echo esc_html($crypto_name); ?></span>
                        </span>
                        <?php if ($active) : ?>
                        <svg style="margin-left:auto;flex-shrink:0;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2271b1" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <style>
            #cryplink_dropdown_btn:hover,
            #cryplink_dropdown_btn:focus { border-color:#2271b1 !important; box-shadow:0 0 0 2px rgba(34,113,177,.15) !important; outline:none; }
            #cryplink_dropdown_list li:hover,
            #cryplink_dropdown_list li.focused { background:#f0f6fd !important; }
            #cryplink_dropdown_list::-webkit-scrollbar { width:6px; }
            #cryplink_dropdown_list::-webkit-scrollbar-thumb { background:#ddd; border-radius:3px; }
            </style>
            <script>
            (function() {
                var btn    = document.getElementById('cryplink_dropdown_btn');
                var list   = document.getElementById('cryplink_dropdown_list');
                var select = document.getElementById('payment_cryplink_coin');
                var ddLogo = document.getElementById('cryplink_dd_logo');
                var ddLbl  = document.getElementById('cryplink_dd_label');
                var arrow  = document.getElementById('cryplink_dd_arrow');
                if (!btn || !list || !select) return;

                var items = Array.prototype.slice.call(list.querySelectorAll('li[role="option"]'));
                var focusedIdx = -1;

                function openList() {
                    list.style.display = 'block';
                    btn.setAttribute('aria-expanded', 'true');
                    arrow.style.transform = 'rotate(180deg)';
                    focusedIdx = items.findIndex(function(i){ return i.getAttribute('aria-selected') === 'true'; });
                    if (focusedIdx < 0) focusedIdx = 0;
                    setFocused(focusedIdx);
                    list.focus();
                }

                function closeList() {
                    list.style.display = 'none';
                    btn.setAttribute('aria-expanded', 'false');
                    arrow.style.transform = 'rotate(0deg)';
                    items.forEach(function(i){ i.classList.remove('focused'); });
                }

                function setFocused(idx) {
                    items.forEach(function(i,n){ i.classList.toggle('focused', n === idx); });
                    if (items[idx]) items[idx].scrollIntoView({ block:'nearest' });
                }

                function selectItem(item) {
                    var ticker = item.dataset.ticker;
                    var name   = item.dataset.name;
                    var logo   = item.dataset.logo;

                    // Update button display
                    if (logo) {
                        ddLogo.innerHTML = '<img src="' + logo + '" width="24" height="24" style="border-radius:50%;object-fit:contain;" onerror="this.style.display=\'none\'" alt="' + name + '" />';
                    } else {
                        ddLogo.innerHTML = '<span style="width:24px;height:24px;border-radius:50%;background:#e9eef5;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#555;">' + ticker.substring(0,2).toUpperCase() + '</span>';
                    }
                    ddLbl.textContent = ticker.toUpperCase() + ' — ' + name;
                    ddLbl.style.color = '#333';

                    // Update checkmark + aria-selected in list
                    items.forEach(function(i) {
                        var on = i.dataset.ticker === ticker;
                        i.setAttribute('aria-selected', on ? 'true' : 'false');
                        i.style.background = on ? '#f0f6fd' : 'transparent';
                        var chk = i.querySelector('svg.chk');
                        if (on && !chk) {
                            var svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                            svg.setAttribute('class','chk');
                            svg.setAttribute('style','margin-left:auto;flex-shrink:0;');
                            svg.setAttribute('width','14'); svg.setAttribute('height','14');
                            svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('fill','none');
                            svg.setAttribute('stroke','#2271b1'); svg.setAttribute('stroke-width','3');
                            svg.innerHTML = '<polyline points="20 6 9 17 4 12"/>';
                            i.appendChild(svg);
                        } else if (!on && chk) {
                            chk.remove();
                        }
                    });

                    // Sync hidden select & trigger WC
                    select.value = ticker;
                    var ev = document.createEvent('Event');
                    ev.initEvent('change', true, true);
                    select.dispatchEvent(ev);
                    if (typeof jQuery !== 'undefined') jQuery(select).trigger('change');

                    closeList();
                    btn.focus();
                }

                btn.addEventListener('click', function() {
                    list.style.display === 'none' ? openList() : closeList();
                });

                btn.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowDown' || e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault(); openList();
                    }
                });

                list.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        focusedIdx = Math.min(focusedIdx + 1, items.length - 1);
                        setFocused(focusedIdx);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        focusedIdx = Math.max(focusedIdx - 1, 0);
                        setFocused(focusedIdx);
                    } else if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        if (items[focusedIdx]) selectItem(items[focusedIdx]);
                    } else if (e.key === 'Escape' || e.key === 'Tab') {
                        closeList(); btn.focus();
                    }
                });

                items.forEach(function(item) {
                    item.addEventListener('click', function() { selectItem(item); });
                    item.addEventListener('mouseenter', function() {
                        focusedIdx = items.indexOf(item);
                        setFocused(focusedIdx);
                    });
                });

                // Close on outside click
                document.addEventListener('click', function(e) {
                    var wrap = document.getElementById('cryplink_dropdown_wrap');
                    if (wrap && !wrap.contains(e.target)) closeList();
                });

                // Sync on page load if a coin is pre-selected
                var current = select.value;
                if (current && current !== 'none') {
                    var presel = items.find(function(i){ return i.dataset.ticker === current; });
                    if (presel) selectItem(presel);
                }
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    function validate_fields()
    {
        $coin = sanitize_text_field($_POST['cryplink_coin'] ?? '');
        $load_coins = self::load_coins();
        return ($coin !== 'none') && array_key_exists($coin, $load_coins);
    }

    function process_payment($order_id)
    {
        global $woocommerce;

        $selected = sanitize_text_field($_POST['cryplink_coin'] ?? '');

        if (empty($selected) || $selected === 'none') {
            wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('Please choose a cryptocurrency.', 'cryplink'), 'error');
            return null;
        }

        // Security: validate $selected against the known coin list to prevent
        // arbitrary values being used in downstream API calls or dynamic property access.
        $valid_coins = self::load_coins();
        if (!array_key_exists($selected, $valid_coins)) {
            wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('Invalid cryptocurrency selected.', 'cryplink'), 'error');
            return null;
        }


        // Use the token-specific wallet address if set, otherwise fall back to the global default.
        $addr = trim($this->coin_wallets[$selected] ?? '');

        if (empty($addr)) {
            wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('No wallet address configured for this token. Please contact the store owner.', 'cryplink'), 'error');
            return null;
        }
        $nonce = $this->generate_nonce();
        $nonce_hash = hash('sha256', (string) $nonce);

        // IMPORTANT (API change 2026-04):
        // CrypLink /invoice rejects callback_url that contains a "nonce" query param.
        // Also, CrypLink may append order_id/json=1 to the callback_url itself.
        // To avoid duplicated query params, keep callback_url minimal here.
        // - callback_url contains only wc-api
        // - order_id is sent as required field in the /invoice JSON body
        // - nonce is sent as a separate field in the /invoice JSON body
        $callback_url = add_query_arg(array(
            'wc-api' => 'WC_Gateway_CrypLink',
        ), trailingslashit(home_url('')));

        try {
            $order = new \WC_Order($order_id);

            // WooCommerce Subscriptions support
            if (in_array('woocommerce-subscriptions/woocommerce-subscriptions.php', apply_filters('active_plugins', get_option('active_plugins'))) && class_exists('WC_Subscriptions_Order')) {
                if (wcs_order_contains_subscription($order_id)) {
                    // Low-severity bugfix: avoid double-counting subscription totals.
                    // get_total_initial_payment() typically includes sign-up fees in modern WCS.
                    $total = (float) (\WC_Subscriptions_Order::get_total_initial_payment($order) ?: 0);
                    if ($total <= 0) {
                        $total = (float) $order->get_total('edit');
                    }

                    if ($total == 0) {
                        $order->add_meta_data('cryplink_currency', $selected);
                        $order->save_meta_data();
                        $order->payment_complete();
                        $woocommerce->cart->empty_cart();

                        return array(
                            'result'   => 'success',
                            'redirect' => $this->get_return_url($order)
                        );
                    }
                }
            }

            $total    = $order->get_total('edit');
            // Prefer order currency (supports multi-currency setups)
            $currency = $order->get_currency() ?: get_woocommerce_currency();

            // Normalise fiat amount for API payload (no locale separators)
            $fiat_amount = (string) $total;
            if (function_exists('wc_get_price_decimals') && function_exists('wc_format_decimal')) {
                $fiat_amount = (string) wc_format_decimal($total, wc_get_price_decimals());
            }

            $info = \CrypLink\Utils\Api::get_info($selected);
            if (empty($info)) {
                wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('Could not retrieve cryptocurrency information. Please try again.', 'cryplink'), 'error');
                return null;
            }
            $min_tx = \CrypLink\Utils\Helper::sig_fig($info->minimum_transaction_coin, 8);

            $crypto_total = \CrypLink\Utils\Api::get_conversion($currency, $selected, $total, $this->disable_conversion);

            // Guard: null means all conversion attempts failed (unsupported pair, network error, etc.)
            if ($crypto_total === null) {
                $api_err = \CrypLink\Utils\Api::$last_error;
                if (!empty($api_err)) {
                    $notice = $api_err;
                } else {
                    /* translators: shown to customer when fiat→crypto conversion fails */
                    $notice = __('Could not convert order value to cryptocurrency. Please try again or contact the store owner.', 'cryplink');
                }
                error_log("CrypLink process_payment: conversion failed for order currency={$currency} coin={$selected} total={$total}. API error: " . ($api_err ?? 'none'));
                wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . $notice, 'error');
                return null;
            }

            if ((float) $crypto_total < (float) $min_tx) {
                wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('Value too low, minimum is', 'cryplink') . ' ' . $min_tx . ' ' . strtoupper($selected), 'error');
                return null;
            }

            $ca = new \CrypLink\Utils\Api($selected, $addr, $callback_url, [
                'order_id'       => (string) $order_id,
                // Required by CrypLink /invoice endpoint
                'fiat_amount'    => $fiat_amount,
                'fiat_currency'  => (string) $currency,
                // Sent as a separate field (NOT in callback_url) to satisfy new API validation.
                'nonce'          => (string) $nonce,
            ], true);

            try {
                $addr_in = $ca->get_address();
            } catch (\Throwable $e) {
                wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . $e->getMessage(), 'error');
                return null;
            }

            if (empty($addr_in)) {
                // Surface the real API error (e.g. "Host not in allowlist") if available
                $api_error = \CrypLink\Utils\Api::$last_error;
                if (!empty($api_error)) {
                    error_log("CrypLink payment address error for order {$order_id}: {$api_error}");
                    $notice = __('Payment error:', 'woocommerce') . ' ' . $api_error;
                } else {
                    $notice = __('Payment error:', 'woocommerce') . ' ' . __('Could not generate payment address. Please try again later.', 'cryplink');
                }
                wc_add_notice($notice, 'error');
                return null;
            }

            // Store the API-normalised callback URL for /logs lookup (may include json=1, order_id, etc.)
            $api_callback_url = null;
            try {
                $api_callback_url = $ca->get_callback_url_api();
            } catch (\Throwable $e) {
                $api_callback_url = null;
            }

            $qr_code_data_value = \CrypLink\Utils\Api::get_static_qrcode($addr_in, $selected, $crypto_total, $this->qrcode_size);
            $qr_code_data       = \CrypLink\Utils\Api::get_static_qrcode($addr_in, $selected, '', $this->qrcode_size);

            $order->add_meta_data('cryplink_version', CRYPLINK_PLUGIN_VERSION);
            $order->add_meta_data('cryplink_php_version', PHP_VERSION);
            // Store ONLY a hash of the nonce (do not keep plaintext in order meta).
            $order->add_meta_data('cryplink_nonce_hash', $nonce_hash);
            $order->add_meta_data('cryplink_address', $addr_in);
            $order->add_meta_data('cryplink_total', \CrypLink\Utils\Helper::sig_fig($crypto_total, 8));
            // Lock the initial total to avoid race conditions (do not allow decreasing the required amount later).
            $order->add_meta_data('cryplink_total_initial', \CrypLink\Utils\Helper::sig_fig($crypto_total, 8));
            $order->add_meta_data('cryplink_total_fiat', $total);
            $order->add_meta_data('cryplink_currency', $selected);

            if ($qr_code_data_value && isset($qr_code_data_value['qr_code'])) {
                $order->add_meta_data('cryplink_qr_code_value', $qr_code_data_value['qr_code']);
            }
            if ($qr_code_data && isset($qr_code_data['qr_code'])) {
                $order->add_meta_data('cryplink_qr_code', $qr_code_data['qr_code']);
            }

            $order->add_meta_data('cryplink_last_price_update', time());
            $order->add_meta_data('cryplink_cancelled', '0');
            $order->add_meta_data('cryplink_min', $min_tx);
            $order->add_meta_data('cryplink_history', json_encode([]));
            $order->add_meta_data('cryplink_tolerance', $this->tolerance);
            $order->add_meta_data('cryplink_callback_url', !empty($api_callback_url) ? $api_callback_url : $callback_url);
            $order->add_meta_data('cryplink_last_checked', $order->get_date_created()->getTimestamp());
            $order->save_meta_data();

            $load_coins = self::load_coins();
            $coin_label = $load_coins[$selected]['name'] ?? strtoupper($selected);

            $order->update_status('on-hold', __('Awaiting payment', 'cryplink') . ': ' . $coin_label . ' (' . strtoupper($selected) . ')');
            $woocommerce->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order)
            );

        } catch (\Throwable $e) {
            wc_add_notice(__('Payment error:', 'cryplink') . ' ' . $e->getMessage(), 'error');
            return null;
        }
    }

    function validate_payment()
    {
        try {
            $this->send_nocache_headers();

            // Hard gate: allowlist callback IPs (recommended with LiteSpeed/WAF setups)
            // Always record the last callback IP so admins can one-click allowlist it.
            $remote_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!empty($remote_ip)) {
                update_option('cryplink_last_callback_ip', ['ip' => $remote_ip, 'time' => time()], false);
            }
            if (!$this->is_allowed_callback_ip()) {
                $this->cryplink_log('warning', 'Callback rejected: IP not in allowlist', [
                    'ip' => $remote_ip,
                ]);
                die("*ok*");
            }

            $data = \CrypLink\Utils\Api::process_callback($_GET);
            
            if (empty($data['order_id'])) {
                error_log('CrypLink callback: missing order_id. Query=' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
                die("No order_id");
            }
            
            $order = wc_get_order($data['order_id']);
            
            if (!$order) {
                error_log('CrypLink callback: order not found. order_id=' . $data['order_id']);
                die("Order not found");
            }

            if ($order->is_paid() || $order->get_status() === 'cancelled') {
                die("*ok*");
            }

            // API change: callback_url can no longer include nonce, therefore callbacks may arrive
            // without nonce. We treat callbacks as a trigger only and always confirm via /logs.
            // Still, if nonce is provided, verify it (defense in depth).
            $stored_hash = (string) $order->get_meta('cryplink_nonce_hash');
            $req_nonce   = (string) ($data['nonce'] ?? '');
            if ($req_nonce !== '' && $stored_hash !== '') {
                $calc_hash = hash('sha256', $req_nonce);
                if (!hash_equals($stored_hash, $calc_hash)) {
                    $this->cryplink_log('warning', 'Callback rejected: nonce mismatch', [
                        'order_id' => (string) $data['order_id'],
                    ]);
                    die("*ok*");
                }
            }

            // Signature is best-effort: some CDNs/WAF strip custom headers.
            // Do not throw if pubkey endpoint is unavailable.
            $sig_ok = false;
            try {
                $sig_ok = $this->verify_signature($_SERVER);
            } catch (\Throwable $e) {
                $sig_ok = false;
            }

            $has_sig = !empty($_SERVER['HTTP_X_CA_SIGNATURE']) || !empty($_SERVER['REDIRECT_HTTP_X_CA_SIGNATURE']);
            if ($has_sig && !$sig_ok) {
                // Enforce signature when present: if a signature header exists but fails verification,
                // reject immediately (prevents forged requests with a bad signature).
                $this->cryplink_log('warning', 'Callback rejected: signature invalid', [
                    'order_id' => (string) $data['order_id'],
                    'uri'      => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                ]);
                die("*ok*");
            }
            if (!$has_sig) {
                // Expected on some LiteSpeed/WAF setups: header may be stripped.
                $this->cryplink_log('debug', 'Callback signature header missing (likely stripped); will confirm via /logs', [
                    'order_id' => (string) $data['order_id'],
                ]);
            }

            $order->update_meta_data('cryplink_last_checked', time());
            $order->save_meta_data();

            // Security: never trust callback query parameters for paid status.
            // Always re-fetch payment details from the CrypLink /logs API (source of truth).
            $callbacks = \CrypLink\Utils\Api::check_logs(
                (string) $order->get_meta('cryplink_callback_url'),
                (string) $order->get_meta('cryplink_currency')
            );

            if ($callbacks) {
                foreach ($callbacks as $cb) {
                    $uuid = (string) ($cb->uuid ?? ($cb->txid_in ?? ''));
                    if (empty($uuid)) {
                        continue;
                    }
                    $pending = 0;
                    $need_conf = 1;
                    if (isset($cb->confirmations)) {
                        $pending = ((int) $cb->confirmations < $need_conf) ? 1 : 0;
                    } elseif (isset($cb->result)) {
                        $pending = ((string) $cb->result === 'done') ? 0 : 1;
                    }

                    $payload = [
                        'order_id'           => (string) $order->get_id(),
                        'uuid'               => $uuid,
                        'txid_in'            => (string) ($cb->txid_in ?? ''),
                        'txid_out'           => (string) ($cb->txid_out ?? ''),
                        'value_coin'         => (float)  ($cb->value_coin ?? 0),
                        'value_coin_convert' => '',
                        // force to saved coin to avoid alias mismatches (e.g. polygon_pol)
                        'coin'               => strtolower((string) $order->get_meta('cryplink_currency')),
                        'pending'            => (int) $pending,
                        'address_in'         => (string) $order->get_meta('cryplink_address'),
                    ];

                    $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];
                    if (empty($history[$uuid]) || ((int)($history[$uuid]['pending'] ?? 0) === 1 && (int)$payload['pending'] === 0)) {
                        // Basic sanity check: reject absurd amounts (possible API corruption)
                        $initial = (float) $order->get_meta('cryplink_total_initial');
                        if ($initial > 0 && (float)$payload['value_coin'] > ($initial * 100)) {
                            $this->cryplink_log('warning', 'Callback logs rejected: suspiciously high value_coin', [
                                'order_id' => (string) $order->get_id(),
                                'value_coin' => (string) $payload['value_coin'],
                            ]);
                            continue;
                        }
                        $this->process_callback_data($payload, $order, true);
                    }

                    if ($order->is_paid()) {
                        break;
                    }
                }
                die("*ok*");
            }

            // No logs found yet: do nothing (CrypLink may retry).
            die("*ok*");
        } catch (\Throwable $e) {
            die($e->getMessage());
        }
    }

    static function load_pubkey() {
        $transient = get_transient('cryplink_pubkey');

        if (!empty($transient)) {
            $pubkey = $transient;
        } else {
            $pubkey = \CrypLink\Utils\Api::get_pubkey();
            // Reduce TTL so key rotation doesn't break signature verification for too long.
            set_transient('cryplink_pubkey', $pubkey, 7200);

            // Do not throw — some CrypLink backends do not expose a pubkey endpoint.
            // Signature verification becomes best-effort and we rely on nonce+logs.
        }

        return $pubkey;
    }

    function verify_signature($server) {
        $pubkey = $this->load_pubkey();
        if (empty($pubkey)) {
            return false;
        }

        // Some servers/proxies expose custom headers under different keys.
        $sig_b64 =
            $server['HTTP_X_CA_SIGNATURE'] ??
            $server['REDIRECT_HTTP_X_CA_SIGNATURE'] ??
            $server['HTTP_X_CA_SIGNATURE'.''] ?? // no-op for safety
            null;

        if (empty($sig_b64)) {
            return false;
        }

        $signature = base64_decode((string) $sig_b64);

        $algo = OPENSSL_ALGO_SHA256;

        $home_url = home_url('');
        $request_uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '';
        // IMPORTANT: the signed payload is the absolute URL (home_url + REQUEST_URI).
        // Normalise to avoid double slashes if home_url ends with '/'.
        $data = rtrim($home_url, '/') . $request_uri;

        return (bool) openssl_verify($data, $signature, $pubkey, $algo);
    }

    function order_status()
    {
        $order_id = absint($_REQUEST['order_id'] ?? 0);
        $req_order_key = sanitize_text_field((string) ($_REQUEST['order_key'] ?? ''));
        $allow_history = true;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit (transient-based): mitigate brute force on order_key and reduce API load.
        $rl_key = 'cryplink_rl_' . md5($ip . '|' . (string) $order_id);
        $count = (int) get_transient($rl_key);
        if ($count >= 60) { // 60 requests / 60s per IP per order
            $this->send_nocache_headers();
            wp_send_json(['status' => 'error', 'error' => 'Rate limited']);
        }
        set_transient($rl_key, $count + 1, 60);

        if (empty($order_id)) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid order_id']);
            die();
        }

        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                echo json_encode(['status' => 'error', 'error' => 'Not a valid order_id']);
                die();
            }

            // Security: verify the current user owns this order (or is an admin/shop manager).
            if (!current_user_can('manage_woocommerce')) {
                $current_user_id = get_current_user_id();
                if ($current_user_id) {
                    // Logged-in user: must be the order customer
                    if ((int)$order->get_customer_id() !== $current_user_id) {
                        echo json_encode(['status' => 'error', 'error' => 'Forbidden']);
                        die();
                    }
                } else {
                    // Guest fallback:
                    // - Preferred: verify order key (stable, works even when caching breaks WC session)
                    // - Fallback: verify via WC session (order_awaiting_payment)
                    if (!empty($req_order_key) && is_callable([$order, 'get_order_key']) && hash_equals((string) $order->get_order_key(), $req_order_key)) {
                        // allowed, but do not expose full payment history to a bearer of the order key
                        $allow_history = false;
                    } else {
                        $wc_session      = function_exists('WC') ? WC()->session : null;
                        $session_order_id = ($wc_session && is_callable([$wc_session, 'get']))
                            ? (int) $wc_session->get('order_awaiting_payment')
                            : 0;
                        if ($session_order_id !== $order_id) {
                            echo json_encode(['status' => 'error', 'error' => 'Forbidden']);
                            die();
                        }
                    }
                }
            }

            // Fallback confirmation:
            // If inbound callbacks are blocked by hosting/CDN/WAF, poll CrypLink logs
            // (same logic as admin "Check for Callbacks") but only for the order owner.
            if (!$order->is_paid() && $order->get_status() !== 'cancelled') {
                $last_checked = (int) $order->get_meta('cryplink_last_checked');
                $now = time();
                // Throttle to avoid hammering the API during JS polling (payment.js polls every ~2s).
                if ($now - $last_checked >= 30) {
                    try {
                        // Extra guard: do not trigger /logs polling for guests that only present an order_key.
                        // They may have the order link from email, but we avoid API spam via brute force.
                        if (!$allow_history && !current_user_can('manage_woocommerce')) {
                            // still return status info below, but skip external polling
                        } else {
                        $callbacks = \CrypLink\Utils\Api::check_logs(
                            (string) $order->get_meta('cryplink_callback_url'),
                            (string) $order->get_meta('cryplink_currency')
                        );

                        $order->update_meta_data('cryplink_last_checked', $now);
                        $order->save_meta_data();

                        if ($callbacks) {
                            foreach ($callbacks as $callback) {
                                $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];
                                $uuid = (string) ($callback->uuid ?? ($callback->txid_in ?? ''));
                                if (empty($uuid)) {
                                    continue;
                                }
                                $pending = 0;
                                $need_conf = 1;
                                if (isset($callback->confirmations)) {
                                    $pending = ((int) $callback->confirmations < $need_conf) ? 1 : 0;
                                } elseif (isset($callback->result)) {
                                    $pending = ((string) $callback->result === 'done') ? 0 : 1;
                                }

                                $cb_data = [
                                    'order_id'           => (string) $order->get_id(),
                                    'uuid'               => $uuid,
                                    'txid_in'            => (string) ($callback->txid_in ?? ''),
                                    'txid_out'           => (string) ($callback->txid_out ?? ''),
                                    'value_coin'         => (float)  ($callback->value_coin ?? 0),
                                    'value_coin_convert' => '',
                                    'coin'               => strtolower((string) $order->get_meta('cryplink_currency')),
                                    'pending'            => (int) $pending,
                                    'address_in'         => (string) $order->get_meta('cryplink_address'),
                                ];

                                // Process if new OR was pending and is now confirmed
                                if (empty($history[$uuid]) || ((int)($history[$uuid]['pending'] ?? 0) === 1 && (int)($cb_data['pending'] ?? 0) === 0)) {
                                    $this->process_callback_data($cb_data, $order, true);
                                }
                            }
                        }
                        }
                    } catch (\Throwable $e) {
                        // Best-effort only; ignore to keep the status endpoint responsive.
                    }
                }
            }
            $counter_calc = (int)$order->get_meta('cryplink_last_price_update') + (int)$this->refresh_value_interval - time();

            if (!$order->is_paid()) {
                if ($counter_calc <= 0) {
                    $updated = $this->refresh_value($order);

                    if ($updated) {
                        $order = new \WC_Order($order_id);
                        $counter_calc = (int)$order->get_meta('cryplink_last_price_update') + (int)$this->refresh_value_interval - time();
                    }
                }
            }

            $showMinFee = '0';

            $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];

            $cryplink_total = $order->get_meta('cryplink_total');
            $order_total = $order->get_total('edit');

            $calc = $this->calc_order($history, $cryplink_total, $order_total);

            $already_paid = $calc['already_paid'];
            $already_paid_fiat = $calc['already_paid_fiat'];

            $min_tx = (float)$order->get_meta('cryplink_min');

            $remaining_pending = $calc['remaining_pending'];
            $remaining_fiat = $calc['remaining_fiat'];

            $cryplink_pending = 0;

            if ($remaining_pending <= 0 && !$order->is_paid()) {
                $cryplink_pending = 1;
            }

            if ($remaining_pending <= $min_tx && $remaining_pending > 0) {
                $remaining_pending = $min_tx;
                $showMinFee = 1;
            }

            // Some currency symbols are stored as HTML entities (e.g. &#8363; for VND).
            $fiat_symbol = html_entity_decode((string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');

            // Suggest a polling interval to reduce admin-ajax requests.
            // JS will still stop immediately once is_paid/cancelled is true.
            $age = 0;
            try {
                $age = time() - (int) $order->get_date_created()->getTimestamp();
            } catch (\Throwable $e) {
                $age = 0;
            }
            if ($order->is_paid() || (int)$order->get_meta('cryplink_cancelled') === 1) {
                $poll_interval_ms = 0;
            } elseif ($already_paid > 0 || $cryplink_pending === 1) {
                $poll_interval_ms = 3000;
            } elseif ($age > 120) {
                $poll_interval_ms = 10000;
            } elseif ($age > 30) {
                $poll_interval_ms = 5000;
            } else {
                $poll_interval_ms = 2000;
            }

            $data = [
                'is_paid' => $order->is_paid(),
                'is_pending' => $cryplink_pending,
                // Hardening: only allow base64 PNG payloads (and cap size) in AJAX responses.
                // Prevents excessive payloads and avoids any potential data-URI edge cases.
                'qr_code_value' => $this->sanitize_qr_base64($order->get_meta('cryplink_qr_code_value')),
                'cancelled' => (int)$order->get_meta('cryplink_cancelled'),
                'coin' => strtoupper($order->get_meta('cryplink_currency')),
                'show_min_fee' => $showMinFee,
                'order_history' => $allow_history ? $history : [],
                'counter' => (string)$counter_calc,
                'crypto_total' => (float)$order->get_meta('cryplink_total'),
                'already_paid' => $already_paid,
                'remaining' => (float)$remaining_pending <= 0 ? 0 : $remaining_pending,
                'fiat_remaining' => (float)$remaining_fiat <= 0 ? 0 : $remaining_fiat,
                'already_paid_fiat' => (float)$already_paid_fiat <= 0 ? 0 : $already_paid_fiat,
                'fiat_symbol' => $fiat_symbol,
                'poll_interval_ms' => $poll_interval_ms,
            ];

            // Prevent caches from storing order status responses.
            $this->send_nocache_headers();
            wp_send_json($data);

        } catch (\Throwable $e) {
            //
        }

        $this->send_nocache_headers();
        wp_send_json(['status' => 'error', 'error' => 'Not a valid order_id']);
    }

    function validate_logs()
    {
        // Security: only admins/shop managers may trigger log validation.
        if (!current_user_can('manage_woocommerce')) {
            die();
        }

        $order_id = absint($_REQUEST['order_id'] ?? 0);
        if (empty($order_id)) {
            die();
        }

        // Fix #4: verify nonce format and value (not just isset)
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'cryplink_validate_logs_' . $order_id)) {
            die();
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            die();
        }

        try {

            $callbacks = \CrypLink\Utils\Api::check_logs($order->get_meta('cryplink_callback_url'), $order->get_meta('cryplink_currency'));

            $order->update_meta_data('cryplink_last_checked', time());
            $order->save_meta_data();

            if ($callbacks) {
                foreach ($callbacks as $callback) {
                    $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];
                    $uuid = (string) ($callback->uuid ?? ($callback->txid_in ?? ''));
                    if (empty($uuid)) {
                        continue;
                    }

                    $pending = 0;
                    $need_conf = 1;
                    if (isset($callback->confirmations)) {
                        $pending = ((int) $callback->confirmations < $need_conf) ? 1 : 0;
                    } elseif (isset($callback->result)) {
                        $pending = ((string) $callback->result === 'done') ? 0 : 1;
                    }

                    $data = [
                        'order_id'           => (string) $order->get_id(),
                        'uuid'               => $uuid,
                        'txid_in'            => (string) ($callback->txid_in ?? ''),
                        'txid_out'           => (string) ($callback->txid_out ?? ''),
                        'value_coin'         => (float)  ($callback->value_coin ?? 0),
                        'value_coin_convert' => '',
                        'coin'               => strtolower((string) $order->get_meta('cryplink_currency')),
                        'pending'            => (int) $pending,
                        'address_in'         => (string) $order->get_meta('cryplink_address'),
                    ];

                    if (empty($history[$uuid]) || ((int)($history[$uuid]['pending'] ?? 0) === 1 && (int)$data['pending'] === 0)) {
                        $this->process_callback_data($data, $order, true);
                    }
                }
            }
            die();
        } catch (\Throwable $e) {
            //
        }
        die();
    }

    function process_callback_data($data, $order, $validation = false)
    {
        // Normalise tickers because API may send upper/lowercase
        $coin = sanitize_text_field(strtolower((string) ($data['coin'] ?? '')));
        $txid_in = sanitize_text_field((string) ($data['txid_in'] ?? ''));
        $uuid = (string) ($data['uuid'] ?? '');

        // Store last TXIDs for debugging / support
        if (!empty($txid_in)) {
            $order->update_meta_data('cryplink_txid_in', (string) $txid_in);
        }
        if (!empty($data['txid_out'])) {
            $order->update_meta_data('cryplink_txid_out', sanitize_text_field((string) $data['txid_out']));
        }

        $saved_coin = strtolower((string) $order->get_meta('cryplink_currency'));

        $paid = (float)($data['value_coin'] ?? 0);

        $min_tx = (float)$order->get_meta('cryplink_min');

        $crypto_coin = strtoupper($order->get_meta('cryplink_currency'));

        $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];

        if (!empty($coin) && $coin !== $saved_coin) {
            $order->add_order_note(
                sanitize_text_field('[MISSMATCHED PAYMENT] Registered a ' . $paid . ' ' . strtoupper($coin) . '. Order not confirmed because requested currency is ' . $crypto_coin . '. If you wish, you may confirm it manually. (Funds were already forwarded to you).')
            );
            if (!$validation) {
                die("*ok*");
            }
            return false;
        }

        if (!$uuid) {
            // Some callback variants may not include uuid; fall back to txid as a stable key
            if (!empty($txid_in)) {
                $uuid = (string) $txid_in;
            }
            if (!$validation) {
                die("*ok*");
            } else {
                return false;
            }
        }

        if (empty($history[$uuid])) {
            $conversion = json_decode(stripcslashes($data['value_coin_convert'] ?? '{}'), true) ?: [];

            // If CrypLink callback/logs did not include fiat conversion map,
            // estimate fiat paid amount proportionally to the order totals.
            $order_currency = strtoupper((string) $order->get_currency());
            $paid_fiat = $conversion[$order_currency] ?? null;
            if ($paid_fiat === null || $paid_fiat === 0 || $paid_fiat === '0') {
                $fiat_total  = (string) $order->get_meta('cryplink_total_fiat');
                $crypto_total = (string) $order->get_meta('cryplink_total');
                if (!empty($fiat_total) && !empty($crypto_total) && (float)$crypto_total > 0) {
                    // ratio = paid_crypto / crypto_total
                    $ratio = bcdiv(\CrypLink\Utils\Helper::sig_fig((string) $paid, 8), \CrypLink\Utils\Helper::sig_fig((string) $crypto_total, 8), 8);
                    $paid_fiat = (string) bcmul($ratio, (string) $fiat_total, 2);
                } else {
                    $paid_fiat = 0;
                }
            }

            $history[$uuid] = [
                'timestamp' => time(),
                'value_paid' => \CrypLink\Utils\Helper::sig_fig($paid, 8),
                'value_paid_fiat' => $paid_fiat,
                'pending' => $data['pending'] ?? 0
            ];
        } else {
            $history[$uuid]['pending'] = $data['pending'] ?? 0;
        }

        $order->update_meta_data('cryplink_history', json_encode($history));
        $order->save_meta_data();

        $calc = $this->calc_order(
            $history,
            (float)$order->get_meta('cryplink_total') * (1 - (int) $order->get_meta('cryplink_tolerance') / 100), // Calculate based on the tolerance. If tolerance is 0, no tolerance is applied.,
            (float)$order->get_meta('cryplink_total_fiat')
        );

        $remaining = $calc['remaining'];
        $remaining_pending = $calc['remaining_pending'];

        $order_notes = $this->get_private_order_notes($order);

        $has_pending = false;
        $has_confirmed = false;

        foreach ($order_notes as $note) {
            $note_content = $note['note_content'] ?? '';

            if ($txid_in && strpos((string)$note_content, 'PENDING') !== false && strpos((string)$note_content, $txid_in) !== false) {
                $has_pending = true;
            }

            if ($txid_in && strpos((string)$note_content, 'CONFIRMED') !== false && strpos((string)$note_content, $txid_in) !== false) {
                $has_confirmed = true;
            }
        }

        if (!$has_pending && $txid_in) {
            $order->add_order_note(
                '[PENDING] ' .
                __('User sent a payment of', 'cryplink') . ' ' .
                $paid . ' ' . $crypto_coin .
                '. TXID: ' . $txid_in
            );
        }

        if (!$has_confirmed && (int)($data['pending'] ?? 0) === 0 && $txid_in) {
            $order->add_order_note(
                '[CONFIRMED] ' . __('User sent a payment of', 'cryplink') . ' ' .
                $paid . ' ' . $crypto_coin .
                '. TXID: ' . $txid_in
            );

            if ($remaining > 0) {
                if ($remaining <= $min_tx) {
                    $order->add_order_note(__('Payment detected and confirmed. Customer still need to send', 'cryplink') . ' ' . $min_tx . ' ' . $crypto_coin, false);
                } else {
                    $order->add_order_note(__('Payment detected and confirmed. Customer still need to send', 'cryplink') . ' ' . $remaining . ' ' . $crypto_coin, false);
                }
            }
        }

        if ($remaining <= 0) {
            /**
             * Changes the order Status to Paid
             */
            $order->payment_complete($data['address_in']);

            if ($this->virtual_complete) {
                $count_products = count($order->get_items());
                $count_virtual = 0;
                foreach ($order->get_items() as $order_item) {
                    $item = wc_get_product($order_item->get_product_id());
                    $item_obj = $item->get_type() === 'variable' ? wc_get_product($order_item['variation_id']) : $item;

                    if ($item_obj->is_virtual()) {
                        $count_virtual += 1;
                    }
                }
                if ($count_virtual === $count_products) {
                    $order->update_status('completed');
                }
            }

            // Optional: force Completed for all paid orders (including shippable products)
            if ($this->auto_complete_all) {
                $order->update_status('completed');
            }

            $order->save();

            if (!$validation) {
                die("*ok*");
            } else {
                return;
            }
        }

        /**
         * Refreshes the QR Code. If payment is marked as completed, it won't get here.
         */
        $qr_val = $remaining <= $min_tx ? $min_tx : $remaining_pending;
        $new_qr = \CrypLink\Utils\Api::get_static_qrcode($order->get_meta('cryplink_address'), $order->get_meta('cryplink_currency'), $qr_val, $this->qrcode_size);
        
        if ($new_qr && isset($new_qr['qr_code'])) {
            $order->update_meta_data('cryplink_qr_code_value', $new_qr['qr_code']);
        }

        $order->save();

        if (!$validation) {
            die("*ok*");
        }
    }

    function thankyou_page($order_id)
    {
        if (WC_CrypLink_Gateway::$HAS_TRIGGERED) {
            return;
        }
        WC_CrypLink_Gateway::$HAS_TRIGGERED = true;

        $order = new \WC_Order($order_id);
        // run value conversion
        $updated = $this->refresh_value($order);

        if ($updated) {
            $order = new \WC_Order($order_id);
        }

        $total = $order->get_total();
        $coins = self::load_coins();
        $currency_symbol = get_woocommerce_currency_symbol();
        $address_in = $order->get_meta('cryplink_address');
        $crypto_value = $order->get_meta('cryplink_total');
        $crypto_coin = $order->get_meta('cryplink_currency');
        $qr_code_img_value = $this->sanitize_qr_base64($order->get_meta('cryplink_qr_code_value'));
        $qr_code_img = $this->sanitize_qr_base64($order->get_meta('cryplink_qr_code'));
        $qr_code_setting = $this->get_option('qrcode_setting');
        $color_scheme = $this->get_option('color_scheme');
        $min_tx = $order->get_meta('cryplink_min');

        $ajax_url = add_query_arg(array(
            'action'     => 'cryplink_order_status',
            'order_id'   => $order_id,
            // Include order_key so guests can poll status even if WC session is broken by caching/CDN
            'order_key'  => $order->get_order_key(),
        ), home_url('/wp-admin/admin-ajax.php'));

        wp_enqueue_script('ca-payment', CRYPLINK_PLUGIN_URL . 'static/payment.js', array(), CRYPLINK_PLUGIN_VERSION, true);
        wp_add_inline_script('ca-payment', "jQuery(function() {let ajax_url = '{$ajax_url}'; setTimeout(function(){check_status(ajax_url)}, 500)})");
        wp_enqueue_style('ca-loader-css', CRYPLINK_PLUGIN_URL . 'static/cryplink.css', false, CRYPLINK_PLUGIN_VERSION);

        $allowed_to_value = array(
            'btc',
            'eth',
            'bch',
            'ltc',
            'miota',
            'xmr',
        );

        $crypto_allowed_value = false;

        $conversion_timer = ((int)$order->get_meta('cryplink_last_price_update') + (int)$this->refresh_value_interval) - time();
        $cancel_timer = $order->get_date_created()->getTimestamp() + (int)$this->order_cancelation_timeout - time();

        if (in_array($crypto_coin, $allowed_to_value, true)) {
            $crypto_allowed_value = true;
        }

        ?>
        <div class="ca_payment-panel <?php echo esc_attr($color_scheme) ?>">
            <div class="ca_payment_details">
                <?php
                if ($total > 0) {
                    ?>
                    <div class="ca_payments_wrapper">
                        <div class="ca_qrcode_wrapper" style="<?php
                        if ($this->qrcode_default) {
                            echo 'display: block';
                        } else {
                            echo 'display: none';
                        }
                        ?>; width: <?php echo (int)$this->qrcode_size + 20; ?>px;">
                            <?php
                            if ($crypto_allowed_value == true) {
                                ?>
                                <div class="inner-wrapper">
                                    <figure>
                                        <?php
                                        if ($qr_code_setting != 'hide_ammount') {
                                            ?>
                                            <img class="ca_qrcode no_value" <?php
                                            if ($qr_code_setting == 'ammount') {
                                                echo 'style="display:none;"';
                                            }
                                            ?> src="data:image/png;base64,<?php echo esc_attr($qr_code_img); ?>"
                                                 alt="<?php echo esc_attr(__('QR Code without value', 'cryplink')); ?>"/>
                                            <?php
                                        }
                                        if ($qr_code_setting != 'hide_without_ammount') {
                                            ?>
                                            <img class="ca_qrcode value" <?php
                                            if ($qr_code_setting == 'without_ammount') {
                                                echo 'style="display:none;"';
                                            }
                                            ?> src="data:image/png;base64,<?php echo esc_attr($qr_code_img_value); ?>"
                                                 alt="<?php echo esc_attr(__('QR Code with value', 'cryplink')); ?>"/>
                                            <?php
                                        }
                                        ?>
                                        <div class="ca_qrcode_coin">
                                            <?php echo esc_attr(strtoupper($coins[$crypto_coin]['name'])); ?>
                                        </div>
                                    </figure>
                                    <?php
                                    if ($qr_code_setting != 'hide_ammount' && $qr_code_setting != 'hide_without_ammount') {
                                        ?>
                                        <div class="ca_qrcode_buttons">
                                        <?php
                                        if ($qr_code_setting != 'hide_without_ammount') {
                                            ?>
                                            <button class="ca_qrcode_btn no_value <?php
                                            if ($qr_code_setting == 'without_ammount') {
                                                echo " active";
                                            }
                                            ?>"
                                                    aria-label="<?php echo esc_attr(__('Show QR Code without value', 'cryplink')); ?>">
                                                <?php echo esc_attr(__('ADDRESS', 'cryplink')); ?>
                                            </button>
                                            <?php
                                        }
                                        if ($qr_code_setting != 'hide_ammount') {
                                            ?>
                                            <button class="ca_qrcode_btn value<?php
                                            if ($qr_code_setting == 'ammount') {
                                                echo " active";
                                            }
                                            ?>"
                                                    aria-label="<?php echo esc_attr(__('Show QR Code with value', 'cryplink')); ?>">
                                                <?php echo esc_attr(__('WITH AMOUNT', 'cryplink')); ?>
                                            </button>
                                            </div>
                                            <?php
                                        }
                                    }
                                    ?>
                                </div>
                                <?php
                            } else {
                                ?>
                                <div class="inner-wrapper">
                                    <figure>
                                        <img class="ca_qrcode no_value"
                                             src="data:image/png;base64,<?php echo esc_attr($qr_code_img); ?>"
                                             alt="<?php echo esc_attr(__('QR Code without value', 'cryplink')); ?>"/>
                                        <div class="ca_qrcode_coin">
                                            <?php echo esc_attr(strtoupper($coins[$crypto_coin]['name'])); ?>
                                        </div>
                                    </figure>
                                    <div class="ca_qrcode_buttons">
                                        <button class="ca_qrcode_btn no_value active"
                                                aria-label="<?php echo esc_attr(__('Show QR Code without value', 'cryplink')); ?>">
                                            <?php echo esc_attr(__('ADDRESS', 'cryplink')); ?>
                                        </button>
                                    </div>
                                </div>

                                <?php
                            }
                            ?>
                        </div>
                        <div class="ca_details_box">
                            <div class="ca_details_text">
                                <?php echo esc_attr(__('PLEASE SEND', 'cryplink')) ?>
                                <button class="ca_copy ca_details_copy"
                                        data-tocopy="<?php echo esc_attr($crypto_value); ?>">
                                    <span><b class="ca_value"><?php echo esc_attr($crypto_value) ?></b></span>
                                    <span><b><?php echo strtoupper(esc_attr($crypto_coin)) ?></b></span>
                                    <span class="ca_tooltip ca_copy_icon_tooltip tip"><?php echo esc_attr(__('COPY', 'cryplink')); ?></span>
                                    <span class="ca_tooltip ca_copy_icon_tooltip success"
                                          style="display: none"><?php echo esc_attr(__('COPIED!', 'cryplink')); ?></span>
                                </button>
                                <strong>(<?php echo esc_attr($currency_symbol) . " <span class='ca_fiat_total'>" . esc_attr($total) . "</span>"; ?>
                                    )</strong>
                            </div>
                            <div class="ca_payment_notification ca_notification_payment_received"
                                 style="display: none;">
                                <?php echo sprintf(esc_attr(__('So far you sent %1s. Please send a new payment to complete the order, as requested above', 'cryplink')),
                                    '<strong><span class="ca_notification_ammount"></span></strong>'
                                ); ?>
                            </div>
                            <div class="ca_payment_notification ca_notification_remaining" style="display: none">
                                <?php echo '<strong>' . esc_attr(__('Notice', 'cryplink')) . '</strong>: ' . sprintf(esc_attr(__('For technical reasons, the minimum amount for each transaction is %1s, so we adjusted the value by adding the remaining to it.', 'cryplink')),
                                        $min_tx . ' ' . esc_attr(strtoupper($coins[$crypto_coin]['name'])),
                                        '<span class="ca_notification_remaining"></span>'
                                    ); ?>
                            </div>
                            <?php
                            if ((int)$this->refresh_value_interval != 0) {
                                ?>
                                <div class="ca_time_refresh">
                                    <?php echo sprintf(esc_attr(__('The %1s conversion rate will be adjusted in', 'cryplink')),
                                        esc_attr(strtoupper($coins[$crypto_coin]['name']))
                                    ); ?>
                                    <span class="ca_time_seconds_count"
                                          data-soon="<?php echo esc_attr(__('a moment', 'cryplink')); ?>"
                                          data-seconds="<?php echo esc_attr($conversion_timer); ?>"><?php echo esc_attr(date('i:s', $conversion_timer)); ?></span>
                                </div>
                                <?php
                            }
                            ?>
                            <div class="ca_send_warning">
                                <?php echo esc_attr(__('Note: If using an exchange please add the exchange fee to the sent amount. Exchanges usually deduct the fee from the sent amount.', 'cryplink')); ?>
                            </div>
                            <div class="ca_details_input">
                                <span><?php echo esc_attr($address_in) ?></span>
                                <button class="ca_copy ca_copy_icon" data-tocopy="<?php echo esc_attr($address_in); ?>">
                                    <span class="ca_tooltip ca_copy_icon_tooltip tip"><?php echo esc_attr(__('COPY', 'cryplink')); ?></span>
                                    <span class="ca_tooltip ca_copy_icon_tooltip success"
                                          style="display: none"><?php echo esc_attr(__('COPIED!', 'cryplink')); ?></span>
                                </button>
                                <div class="ca_loader"></div>
                            </div>
                        </div>
                        <?php
                        if ((int)$this->order_cancelation_timeout !== 0) {
                            ?>
                            <span class="ca_notification_cancel"
                                  data-text="<?php echo __('Order will be cancelled in less than a minute.', 'cryplink'); ?>">
                                    <?php echo sprintf(esc_attr(__('This order will be valid for %s', 'cryplink')), '<strong><span class="ca_cancel_timer" data-timestamp="' . $cancel_timer . '">' . date('H:i', $cancel_timer) . '</span></strong>'); ?>
                                </span>
                            <?php
                        }
                        ?>
                        <div class="ca_buttons_container">
                            <a class="ca_show_qr" href="#"
                               aria-label="<?php echo esc_attr(__('Show the QR code', 'cryplink')); ?>">
                                <span class="ca_show_qr_open <?php
                                if (!$this->qrcode_default) {
                                    echo " active";
                                }
                                ?>"><?php echo __('Open QR CODE', 'cryplink'); ?></span>
                                <span class="ca_show_qr_close <?php
                                if ($this->qrcode_default) {
                                    echo " active";
                                }
                                ?>"><?php echo esc_attr(__('Close QR CODE', 'cryplink')); ?></span>
                            </a>
                        </div>
                        <?php
                        if ($this->show_branding) {
                            ?>
                            <div class="ca_branding">
                                <a href="https://cryplink.xyz/" target="_blank">
                                    <span>Powered by</span>
                                    <img width="94" class="img-fluid"
                                         src="<?php echo esc_attr('https://res.cloudinary.com/diica7at6/image/upload/v1774226418/cryplink-logo-blue_w6el8p.png') ?>"
                                         alt="Cryptapi Logo"/>
                                </a>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    <?php
                }
                if ($total === 0) {
                    ?>
                    <style>
                        .ca_payment_confirmed {
                            display: block !important;
                            height: 100% !important;
                        }
                    </style>
                    <?php
                }
                ?>
                <div class="ca_payment_processing" style="display: none;">
                    <div class="ca_payment_processing_icon">
                        <div class="ca_loader_payment_processing"></div>
                    </div>
                    <h2><?php echo esc_attr(__('Your payment is being processed!', 'cryplink')); ?></h2>
                    <h5><?php echo esc_attr(__('Processing can take some time depending on the blockchain.', 'cryplink')); ?></h5>
                </div>

                <div class="ca_payment_confirmed" style="display: none;">
                    <div class="ca_payment_confirmed_icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                            <path fill="#66BB6A"
                                  d="M504 256c0 136.967-111.033 248-248 248S8 392.967 8 256 119.033 8 256 8s248 111.033 248 248zM227.314 387.314l184-184c6.248-6.248 6.248-16.379 0-22.627l-22.627-22.627c-6.248-6.249-16.379-6.249-22.628 0L216 308.118l-70.059-70.059c-6.248-6.248-16.379-6.248-22.628 0l-22.627 22.627c-6.248 6.248-6.248 16.379 0 22.627l104 104c6.249 6.249 16.379 6.249 22.628.001z"></path>
                        </svg>
                    </div>
                    <h2><?php echo esc_attr(__('Your payment has been confirmed!', 'cryplink')); ?></h2>
                </div>

                <div class="ca_payment_cancelled" style="display: none;">
                    <div class="ca_payment_cancelled_icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                            <path fill="#c62828"
                                  d="M504 256c0 136.997-111.043 248-248 248S8 392.997 8 256C8 119.083 119.043 8 256 8s248 111.083 248 248zm-248 50c-25.405 0-46 20.595-46 46s20.595 46 46 46 46-20.595 46-46-20.595-46-46-46zm-43.673-165.346l7.418 136c.347 6.364 5.609 11.346 11.982 11.346h48.546c6.373 0 11.635-4.982 11.982-11.346l7.418-136c.375-6.874-5.098-12.654-11.982-12.654h-63.383c-6.884 0-12.356 5.78-11.981 12.654z"></path>
                        </svg>
                    </div>
                    <h2><?php echo esc_attr(__('Order has been cancelled due to lack of payment. Please don\'t send any payment to the address.', 'cryplink')); ?></h2>
                </div>
                <div class="ca_history" style="display: none;">
                    <table class="ca_history_fill">
                        <tr class="ca_history_header">
                            <th><strong><?php echo esc_attr(__('Time', 'cryplink')); ?></strong></th>
                            <th><strong><?php echo esc_attr(__('Value Paid', 'cryplink')); ?></strong></th>
                            <th><strong><?php echo esc_attr(__('FIAT Value', 'cryplink')); ?></strong></th>
                        </tr>
                    </table>
                </div>
                <?php
                if ($total > 0) {
                    ?>
                    <div class="ca_progress">
                        <div class="ca_progress_icon waiting_payment done">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M49.2188 25C49.2188 38.3789 38.3789 49.2188 25 49.2188C11.6211 49.2188 0.78125 38.3789 0.78125 25C0.78125 11.6211 11.6211 0.78125 25 0.78125C38.3789 0.78125 49.2188 11.6211 49.2188 25ZM35.1953 22.1777L28.125 29.5508V11.7188C28.125 10.4199 27.0801 9.375 25.7812 9.375H24.2188C22.9199 9.375 21.875 10.4199 21.875 11.7188V29.5508L14.8047 22.1777C13.8965 21.2305 12.3828 21.2109 11.4551 22.1387L10.3906 23.2129C9.47266 24.1309 9.47266 25.6152 10.3906 26.5234L23.3398 39.4824C24.2578 40.4004 25.7422 40.4004 26.6504 39.4824L39.6094 26.5234C40.5273 25.6055 40.5273 24.1211 39.6094 23.2129L38.5449 22.1387C37.6172 21.2109 36.1035 21.2305 35.1953 22.1777V22.1777Z"
                                      fill="#0B4B70"/>
                            </svg>
                            <p><?php echo esc_attr(__('Waiting for payment', 'cryplink')); ?></p>
                        </div>
                        <div class="ca_progress_icon waiting_network">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M46.875 15.625H3.125C1.39912 15.625 0 14.2259 0 12.5V6.25C0 4.52412 1.39912 3.125 3.125 3.125H46.875C48.6009 3.125 50 4.52412 50 6.25V12.5C50 14.2259 48.6009 15.625 46.875 15.625ZM42.1875 7.03125C40.8931 7.03125 39.8438 8.08057 39.8438 9.375C39.8438 10.6694 40.8931 11.7188 42.1875 11.7188C43.4819 11.7188 44.5312 10.6694 44.5312 9.375C44.5312 8.08057 43.4819 7.03125 42.1875 7.03125ZM35.9375 7.03125C34.6431 7.03125 33.5938 8.08057 33.5938 9.375C33.5938 10.6694 34.6431 11.7188 35.9375 11.7188C37.2319 11.7188 38.2812 10.6694 38.2812 9.375C38.2812 8.08057 37.2319 7.03125 35.9375 7.03125ZM46.875 31.25H3.125C1.39912 31.25 0 29.8509 0 28.125V21.875C0 20.1491 1.39912 18.75 3.125 18.75H46.875C48.6009 18.75 50 20.1491 50 21.875V28.125C50 29.8509 48.6009 31.25 46.875 31.25ZM42.1875 22.6562C40.8931 22.6562 39.8438 23.7056 39.8438 25C39.8438 26.2944 40.8931 27.3438 42.1875 27.3438C43.4819 27.3438 44.5312 26.2944 44.5312 25C44.5312 23.7056 43.4819 22.6562 42.1875 22.6562ZM35.9375 22.6562C34.6431 22.6562 33.5938 23.7056 33.5938 25C33.5938 26.2944 34.6431 27.3438 35.9375 27.3438C37.2319 27.3438 38.2812 26.2944 38.2812 25C38.2812 23.7056 37.2319 22.6562 35.9375 22.6562ZM46.875 46.875H3.125C1.39912 46.875 0 45.4759 0 43.75V37.5C0 35.7741 1.39912 34.375 3.125 34.375H46.875C48.6009 34.375 50 35.7741 50 37.5V43.75C50 45.4759 48.6009 46.875 46.875 46.875ZM42.1875 38.2812C40.8931 38.2812 39.8438 39.3306 39.8438 40.625C39.8438 41.9194 40.8931 42.9688 42.1875 42.9688C43.4819 42.9688 44.5312 41.9194 44.5312 40.625C44.5312 39.3306 43.4819 38.2812 42.1875 38.2812ZM35.9375 38.2812C34.6431 38.2812 33.5938 39.3306 33.5938 40.625C33.5938 41.9194 34.6431 42.9688 35.9375 42.9688C37.2319 42.9688 38.2812 41.9194 38.2812 40.625C38.2812 39.3306 37.2319 38.2812 35.9375 38.2812Z"
                                      fill="#0B4B70"/>
                            </svg>
                            <p><?php echo esc_attr(__('Waiting for network confirmation', 'cryplink')); ?></p>
                        </div>
                        <div class="ca_progress_icon payment_done">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M45.0391 12.5H7.8125C6.94922 12.5 6.25 11.8008 6.25 10.9375C6.25 10.0742 6.94922 9.375 7.8125 9.375H45.3125C46.1758 9.375 46.875 8.67578 46.875 7.8125C46.875 5.22363 44.7764 3.125 42.1875 3.125H6.25C2.79785 3.125 0 5.92285 0 9.375V40.625C0 44.0771 2.79785 46.875 6.25 46.875H45.0391C47.7754 46.875 50 44.7725 50 42.1875V17.1875C50 14.6025 47.7754 12.5 45.0391 12.5ZM40.625 32.8125C38.8994 32.8125 37.5 31.4131 37.5 29.6875C37.5 27.9619 38.8994 26.5625 40.625 26.5625C42.3506 26.5625 43.75 27.9619 43.75 29.6875C43.75 31.4131 42.3506 32.8125 40.625 32.8125Z"
                                      fill="#0B4B70"/>
                            </svg>
                            <p><?php echo esc_attr(__('Payment confirmed', 'cryplink')); ?></p>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     *  Cronjob
     */
    function ca_cronjob()
    {
        // Mutex: prevent concurrent runs (WP-Cron can overlap under load)
        if (get_transient('cryplink_cron_lock')) {
            return;
        }
        set_transient('cryplink_cron_lock', 1, 90);

        // 0) Auto refresh token list every 24h (same effect as ↺ Refresh Token List)
        // This avoids requiring manual admin clicks and keeps "live/Maintenance" state fresh.
        try {
            $last_refresh = (int) get_option('cryplink_last_coins_refresh', 0);
            if ($last_refresh <= 0 || (time() - $last_refresh) >= 86400) {
                $this->reset_load_coins();
            }
        } catch (\Throwable $e) {
            // best-effort; ignore
        }

        // 1) Best-effort: sync on-hold orders from CrypLink logs
        // This avoids relying on inbound callbacks or frontend JS polling,
        // which are often broken by cache/CDN/WAF.
        try {
            $orders_to_sync = wc_get_orders([
                'status'         => ['wc-on-hold'],
                'payment_method' => 'cryplink',
                'limit'          => 20,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            foreach ($orders_to_sync as $order) {
                if (!$order || $order->is_paid() || $order->get_status() === 'cancelled') {
                    continue;
                }

                $last_checked = (int) $order->get_meta('cryplink_last_checked');
                $now = time();
                // Throttle per order (cron runs every 60s)
                if ($now - $last_checked < 60) {
                    continue;
                }

                $order->update_meta_data('cryplink_last_checked', $now);
                $order->save_meta_data();

                $coin = strtolower((string) $order->get_meta('cryplink_currency'));
                $cb   = (string) $order->get_meta('cryplink_callback_url');
                if (empty($coin) || empty($cb)) {
                    continue;
                }

                // Try a few callback variants, because some API backends store the URL
                // without certain query params.
                $variants = [$cb];
                $parts = wp_parse_url($cb);
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $q);
                    // Variant: remove nonce
                    if (isset($q['nonce'])) {
                        unset($q['nonce']);
                        $variants[] = (string) add_query_arg($q, ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/'));
                    }
                    // Variant: path only (no query)
                    $variants[] = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/');
                }
                $variants = array_values(array_unique(array_filter($variants)));

                $callbacks = false;
                foreach ($variants as $try_cb) {
                    $callbacks = \CrypLink\Utils\Api::check_logs($try_cb, $coin);
                    if ($callbacks) {
                        break;
                    }
                }

                if ($callbacks) {
                    foreach ($callbacks as $callback) {
                        $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];
                        $uuid = (string) ($callback->uuid ?? ($callback->txid_in ?? ''));
                        if (empty($uuid)) {
                            continue;
                        }

                        $pending = 0;
                        $need_conf = 1;
                        if (isset($callback->confirmations)) {
                            $pending = ((int) $callback->confirmations < $need_conf) ? 1 : 0;
                        } elseif (isset($callback->result)) {
                            $pending = ((string) $callback->result === 'done') ? 0 : 1;
                        }

                        $data = [
                            'order_id'           => (string) $order->get_id(),
                            'uuid'               => $uuid,
                            'txid_in'            => (string) ($callback->txid_in ?? ''),
                            'txid_out'           => (string) ($callback->txid_out ?? ''),
                            'value_coin'         => (float)  ($callback->value_coin ?? 0),
                            'value_coin_convert' => '',
                            'coin'               => strtolower((string) $order->get_meta('cryplink_currency')),
                            'pending'            => (int) $pending,
                            'address_in'         => (string) $order->get_meta('cryplink_address'),
                        ];

                        if (empty($history[$uuid]) || ((int)($history[$uuid]['pending'] ?? 0) === 1 && (int)($data['pending'] ?? 0) === 0)) {
                            $this->process_callback_data($data, $order, true);
                        }
                        if ($order->is_paid()) {
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // best-effort; ignore
        } finally {
            delete_transient('cryplink_cron_lock');
        }

        // 2) Cancel unpaid orders after timeout
        $order_timeout = (int)$this->order_cancelation_timeout;

        if ($order_timeout === 0) {
            return;
        }

        $orders = wc_get_orders(array(
            'status' => array('wc-on-hold'),
            'payment_method' => 'cryplink',
            'date_created' => '<' . (time() - $order_timeout),
        ));

        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order) {
            $order->update_status('cancelled', __('Order cancelled due to lack of payment.', 'cryplink'));
            $order->update_meta_data('cryplink_cancelled', '1');
            $order->save();
        }
    }

    function calc_order($history, $total, $total_fiat)
    {
        $already_paid = 0;
        $already_paid_fiat = 0;
        $remaining = $total;
        $remaining_pending = $total;
        $remaining_fiat = $total_fiat;

        if (!empty($history)) {
            foreach ($history as $uuid => $item) {
                if ((int)$item['pending'] === 0) {
                    $remaining = bcsub(\CrypLink\Utils\Helper::sig_fig($remaining, 8), $item['value_paid'], 8);
                }

                $remaining_pending = bcsub(\CrypLink\Utils\Helper::sig_fig($remaining_pending, 8), $item['value_paid'], 8);
                $remaining_fiat = bcsub(\CrypLink\Utils\Helper::sig_fig($remaining_fiat, 8), $item['value_paid_fiat'], 8);

                $already_paid = bcadd(\CrypLink\Utils\Helper::sig_fig($already_paid, 8), $item['value_paid'], 8);
                $already_paid_fiat = bcadd(\CrypLink\Utils\Helper::sig_fig($already_paid_fiat, 8), $item['value_paid_fiat'], 8);
            }
        }

        return [
            'already_paid' => (float)$already_paid,
            'already_paid_fiat' => (float)$already_paid_fiat,
            'remaining' => (float)$remaining,
            'remaining_pending' => (float)$remaining_pending,
            'remaining_fiat' => (float)$remaining_fiat
        ];
    }

    /**
     * WooCommerce Subscriptions Integration
     */
    function scheduled_subscription_mail($amount, $renewal_order)
    {

        $order = $renewal_order;

        try {
            $customer_id = get_post_meta($order->get_id(), '_customer_user', true);
            $customer = new \WC_Customer($customer_id);

            if (empty($order->get_meta('cryplink_paid'))) {
                $mailer = WC()->mailer();

                $recipient = $customer->get_email();

                $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Please renew your subscription', 'cryplink'));
                $headers = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>' . "\r\n";

                $content = wc_get_template_html('emails/renewal-email.php', array(
                    'order' => $order,
                    'email_heading' => get_bloginfo('name'),
                    'sent_to_admin' => false,
                    'plain_text' => false,
                    'email' => $mailer
                ), plugin_dir_path(dirname(__FILE__)), plugin_dir_path(dirname(__FILE__)));

                $mailer->send($recipient, $subject, $content, $headers);

                $order->add_meta_data('cryplink_paid', '1');
                $order->save_meta_data();
            }
        } catch (\Throwable $e) {
            // Log error if needed
        }
    }

    /**
     * Fired by wcs_create_pending_renewal hook.
     * Sends a renewal email to the customer when a subscription renewal is pending.
     */
    function subscription_send_email($subscription)
    {
        try {
            $customer_id = $subscription->get_user_id();
            $customer    = new \WC_Customer($customer_id);

            $mailer    = WC()->mailer();
            $recipient = $customer->get_email();

            if (empty($recipient)) {
                return;
            }

            $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Please renew your subscription', 'cryplink'));
            $headers = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>' . "\r\n";

            $content = wc_get_template_html('emails/renewal-email.php', array(
                'order'         => $subscription,
                'email_heading' => get_bloginfo('name'),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $mailer,
            ), plugin_dir_path(dirname(__FILE__)), plugin_dir_path(dirname(__FILE__)));

            $mailer->send($recipient, $subject, $content, $headers);
        } catch (\Throwable $e) {
            // Silently fail — renewal email is best-effort
        }
    }

    private function generate_nonce($len = 32)
    {
        // Use a cryptographically secure nonce for callbacks.
        // 32 chars ~= 190 bits of entropy (depending on charset).
        if (function_exists('wp_generate_password')) {
            return wp_generate_password((int) $len, false, false);
        }

        try {
            // random_bytes length chosen so final hex length is at least $len
            $hex = bin2hex(random_bytes((int) ceil($len / 2)));
            return substr($hex, 0, (int) $len);
        } catch (\Throwable $e) {
            // Do NOT fall back to weak PRNG on shared hosting.
            // Fail closed so the store owner is alerted and no low-entropy nonce is used.
            throw new \Exception('Secure random generator is unavailable on this server.');
        }
    }

    function handling_fee()
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $chosen_payment_id = WC()->session->get('chosen_payment_method');

        if ($chosen_payment_id != 'cryplink') {
            return;
        }

        $total_fee = $this->get_option('fee_order_percentage') === 'none' ? 0 : (float)$this->get_option('fee_order_percentage');

        $fee_order = 0;

        if ($total_fee !== 0 || $this->add_blockchain_fee) {

            if ($total_fee !== 0) {
                $fee_order = (float)WC()->cart->subtotal * $total_fee;
            }

            $selected = WC()->session->get('cryplink_coin');

            if ($selected === 'none') {
                return;
            }

            if (!empty($selected) && $selected != 'none' && $this->add_blockchain_fee) {
                try {
                    $est = \CrypLink\Utils\Api::get_estimate($selected);
                    if ($est && isset($est->{get_woocommerce_currency()})) {
                        $fee_order += (float)$est->{get_woocommerce_currency()};
                    }
                } catch (\Throwable $e) {
                    // Ignore estimation error
                }
            }

            if (empty($fee_order)) {
                return;
            }

            WC()->cart->add_fee(__('Service Fee', 'cryplink'), $fee_order, true);
        }
    }

    function refresh_checkout()
    {
        if (WC_CrypLink_Gateway::$HAS_TRIGGERED) {
            return;
        }
        WC_CrypLink_Gateway::$HAS_TRIGGERED = true;
        if (is_checkout()) {
            wp_register_script('cryplink-checkout', '');
            wp_enqueue_script('cryplink-checkout');
            wp_add_inline_script('cryplink-checkout', "jQuery(function ($) { $('form.checkout').on('change', 'input[name=payment_method], #payment_cryplink_coin', function () { $(document.body).trigger('update_checkout');});});");
        }
    }

    function chosen_currency_value_to_wc_session($posted_data)
    {
        parse_str($posted_data, $fields);

        if (isset($fields['cryplink_coin'])) {
            $coin = sanitize_text_field((string) $fields['cryplink_coin']);
            // Only allow known tickers to be stored in session (defense in depth).
            $coins = self::load_coins();
            if (!empty($coin) && $coin !== 'none' && is_array($coins) && array_key_exists($coin, $coins)) {
                WC()->session->set('cryplink_coin', $coin);
            } else {
                WC()->session->set('cryplink_coin', 'none');
            }
        }
    }

    public function process_admin_options()
    {
        // Sanitize the multiselect coins array; default to empty array when nothing is selected
        $field_key = $this->get_field_key('coins');
        $coins_raw = isset($_POST[$field_key]) ? (array) $_POST[$field_key] : [];
        $coins     = array_map('sanitize_text_field', $coins_raw);
        $coins     = array_values(array_unique(array_filter($coins)));

        // Validate tokens against the live ticker cache to avoid saving stale/unknown values.
        $all_coins = self::load_coins();
        if (!empty($all_coins) && is_array($all_coins)) {
            $valid = [];
            foreach ($coins as $t) {
                $t = strtolower(trim((string) $t));
                if ($t !== '' && $t !== 'none' && array_key_exists($t, $all_coins)) {
                    $valid[] = $t;
                }
            }
            $coins = array_values(array_unique($valid));
        } else {
            // If we cannot load the list, fail safe (do not save arbitrary values).
            $coins = [];
        }
        parent::update_option('coins', $coins);

        // Save per-token wallet addresses submitted from the card UI
        // Input name: cryplink_coin_wallet[ticker] = address
        $wallets_raw = isset($_POST['cryplink_coin_wallet']) ? (array) $_POST['cryplink_coin_wallet'] : [];
        $wallets = [];
        foreach ($wallets_raw as $ticker => $addr) {
            $clean_ticker = sanitize_text_field($ticker);
            $clean_addr   = sanitize_text_field($addr);
            // Only save for coins that are actually selected to keep storage clean
            if (!empty($clean_ticker) && in_array($clean_ticker, $coins, true)) {
                $wallets[$clean_ticker] = $clean_addr;
            }
        }
        parent::update_option('coin_wallets', $wallets);

        parent::process_admin_options();
        $this->reset_load_coins();
    }

    function add_email_link($order, $sent_to_admin, $plain_text, $email)
    {
        if ($order->get_meta('_cryplink_email_link_added') === 'yes') {
            return;
        }

        if ($email->id == 'customer_on_hold_order') {
            echo '<a style="display:block;text-align:center;margin: 40px auto; font-size: 16px; font-weight: bold;" href="' . esc_url($order->get_checkout_order_received_url()) . '" target="_blank">' . __('Check your payment status', 'cryplink') . '</a>';

            $order->update_meta_data('_cryplink_email_link_added', 'yes');
            $order->save();
        }
    }

    function add_order_link($actions, $order)
    {
        if ($order->has_status('on-hold') && $order->get_payment_method() === 'cryplink') {
            $action_slug = 'cryplink_payment_url';

            $actions[$action_slug] = array(
                'url' => esc_url($order->get_checkout_order_received_url()),
                'name' => __('Pay', 'cryplink'),
            );
        }

        return $actions;
    }

    function get_private_order_notes($order)
    {
        $results = wc_get_order_notes([
            'order_in'  => $order->get_id(),
            'order__in' => $order->get_id(),
        ]);

        $order_note = []; // Fix: initialize before loop — PHP 8 fatal if no notes exist

        foreach ($results as $note) {
            if (!$note->customer_note) {
                $order_note[] = array(
                    'note_id'      => $note->id,
                    'note_date'    => $note->date_created,
                    'note_content' => $note->content,
                );
            }
        }

        return $order_note;
    }

    function order_detail_validate_logs($order)
    {
        if (WC_CrypLink_Gateway::$HAS_TRIGGERED) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        if ($order->get_payment_method() !== 'cryplink') {
            return;
        }

        $ajax_url = add_query_arg(array(
            'action'   => 'cryplink_validate_logs',
            'order_id' => $order->get_ID(),
            '_wpnonce' => wp_create_nonce('cryplink_validate_logs_' . $order->get_ID()),
        ), home_url('/wp-admin/admin-ajax.php'));
        ?>
        <p class="form-field form-field-wide wc-customer-user">
            <small style="display: block;">
                <?php echo sprintf(esc_attr(__('If the order is not being updated, your ISP is probably blocking our IPs (%1$s and %2$s): please try to get them whitelisted and feel free to contact us anytime to get support (link to our contact page). In the meantime you can refresh the status of any payment by clicking this button below:', 'cryplink')), '51.77.105.132', '135.125.112.47'); ?>
            </small>
        </p>
        <a style="margin-top: 1rem;margin-bottom: 1rem;" id="validate_callbacks" class="button action" href="#">
            <?php echo esc_attr(__('Check for Callbacks', 'cryplink')); ?>
        </a>
        <script>
            jQuery(function () {
                const validate_button = jQuery('#validate_callbacks')

                validate_button.on('click', function (e) {
                    e.preventDefault()
                    validate_callbacks()
                    validate_button.html('<?php echo esc_attr(__('Checking', 'cryplink'));?>')
                })

                function validate_callbacks() {
                    jQuery.getJSON('<?php echo esc_js($ajax_url)?>').always(function () {
                        window.location.reload()
                    })
                }
            })
        </script>
        <?php
        WC_CrypLink_Gateway::$HAS_TRIGGERED = true;
    }

    function refresh_value($order)
    {
        $value_refresh = (int)$this->refresh_value_interval;

        if ($value_refresh === 0) {
            return false;
        }

        $woocommerce_currency = get_woocommerce_currency();
        $last_price_update = $order->get_meta('cryplink_last_price_update');
        $min_tx = (float)$order->get_meta('cryplink_min');
        $history = json_decode($order->get_meta('cryplink_history'), true) ?: [];
        $cryplink_total = $order->get_meta('cryplink_total');
        $cryplink_total_initial = (string) $order->get_meta('cryplink_total_initial');
        $order_total = $order->get_total('edit');

        // Hardening: if initial quote is missing (older orders), do not refresh to avoid lowering required amount.
        if (empty($cryplink_total_initial)) {
            return false;
        }

        $calc = $this->calc_order($history, $cryplink_total, $order_total);
        $remaining = $calc['remaining'];
        $remaining_pending = $calc['remaining_pending'];

        if ((int)$last_price_update + $value_refresh < time() && !empty($last_price_update) && $remaining === $remaining_pending && $remaining_pending > 0) {
            $cryplink_coin = $order->get_meta('cryplink_currency');

            $raw_conversion = \CrypLink\Utils\Api::get_conversion($woocommerce_currency, $cryplink_coin, $order_total, $this->disable_conversion);
            if ($raw_conversion === null) {
                // Conversion API failed; keep the existing rate rather than zeroing the order.
                return false;
            }
            $crypto_total = \CrypLink\Utils\Helper::sig_fig((string) $raw_conversion, 8);
            // Locking: never allow decreasing the required amount below the initial quote
            // (prevents race-condition where refresh reduces required total right before confirmation).
            if (!empty($cryplink_total_initial) && (float) $crypto_total < (float) $cryplink_total_initial) {
                $crypto_total = \CrypLink\Utils\Helper::sig_fig((string) $cryplink_total_initial, 8);
            }
            $order->update_meta_data('cryplink_total', $crypto_total);

            $calc_cron = $this->calc_order($history, $crypto_total, $order_total);
            $crypto_remaining_total = $calc_cron['remaining_pending'];

            if ($remaining_pending <= $min_tx && !$remaining_pending <= 0) {
                $qr_code_data_value = \CrypLink\Utils\Api::get_static_qrcode($order->get_meta('cryplink_address'), $cryplink_coin, $min_tx, $this->qrcode_size);
            } else {
                $qr_code_data_value = \CrypLink\Utils\Api::get_static_qrcode($order->get_meta('cryplink_address'), $cryplink_coin, $crypto_remaining_total, $this->qrcode_size);
            }

            $order->update_meta_data('cryplink_qr_code_value', $qr_code_data_value['qr_code']);

            $order->update_meta_data('cryplink_last_price_update', time());
            $order->save_meta_data();

            return true;
        }

        return false;
    }
}
