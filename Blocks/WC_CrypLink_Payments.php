<?php

namespace CrypLink\Blocks;

use CrypLink\Controllers\WC_CrypLink_Gateway;
use CrypLink\Utils\Helper;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;

class WC_CrypLink_Payments extends AbstractPaymentMethodType {
    /**
     * @var WC_CrypLink_Gateway
     */
    private $gateway;

    /**
     * @var string
     */
    protected $name = 'cryplink';

    /**
     * @var array<string,mixed>
     */
    protected $settings = [];

    /**
     * @var string
     */
    private string $scriptId = '';

    /**
     * @return void
     */
    public function __construct()
    {
        add_action('woocommerce_blocks_enqueue_checkout_block_scripts_after', [$this, 'register_style']);
    }

    /**
     * @return void
     */
    public function initialize(): void
    {
        try {
            $this->settings = get_option("woocommerce_{$this->name}_settings", []);
            $gateways = WC()->payment_gateways->payment_gateways();
            $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
        } catch (\Throwable $e) {
            // Silently fail or log
        }
    }

    /**
     * @return bool
     */
    public function is_active() {
        return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        $load_coins = \CrypLink\Controllers\WC_CrypLink_Gateway::load_coins();
        $output_coins = [];

        if ($load_coins) {
            $coins_setting = $this->get_setting('coins');
            if (is_array($coins_setting)) {
                foreach ($coins_setting as $coin) {
                    if (isset($load_coins[$coin])) {
                        $output_coins[] = array_merge(
                            ['ticker' => $coin],
                            $load_coins[$coin]
                        );
                    }
                }
            }
        }

        return [
            'name'     => $this->name,
            'label'    => $this->get_setting('title'),
            'icons'    => $this->get_payment_method_icons(),
            'content'  => $this->get_setting('description'),
            'button'   => $this->get_setting('order_button_text'),
            'description'   => $this->get_setting('description'),
            'coins' => $output_coins,
            'show_branding' => $this-> get_setting('show_branding') === 'yes',
            'show_crypto_logos' => $this-> get_setting('show_crypto_logos') === 'yes',
            'add_blockchain_fee' => $this-> get_setting('add_blockchain_fee') === 'yes',
            'fee_order_percentage' => (float) $this-> get_setting('fee_order_percentage'),
            'supports' => $this->gateway ? array_filter($this->gateway->supports, [$this->gateway, 'supports']) : [],
            'translations' => [
                'please_select_cryptocurrency' => __('Please select a Cryptocurrency', 'cryplink'),
                'error_ocurred' => __('There was an error with the payment. Please try again.', 'cryplink'),
                'cart_must_be_higher' => __('The cart total must be higher to use this cryptocurrency.', 'cryplink')
            ],
        ];
    }

    /**
     * @return array<array<string,string>>
     */
    public function get_payment_method_icons(): array
    {
        $ver = defined('CRYPLINK_PLUGIN_VERSION') ? CRYPLINK_PLUGIN_VERSION : time();
        return [
            [
                'id'  => $this->name,
                'alt' => $this->get_setting('title'),
                'src' => $this->get_setting('show_crypto_logos') === 'yes'
                    ? esc_url('https://res.cloudinary.com/diica7at6/image/upload/v1774226418/cryplink-logo-blue_w6el8p.png') . '?ver=' . rawurlencode((string) $ver)
                    : ''
            ]
        ];
    }

    /**
     * @return array<string>
     */
    public function get_payment_method_script_handles(): array
    {
        if (!$this->is_active()) {
            return [];
        }

        $handle = 'cryplink-' . str_replace(['.js', '_', '.'], ['', '-', '-'], 'blocks.js');

        $version = defined('CRYPLINK_PLUGIN_VERSION') ? CRYPLINK_PLUGIN_VERSION : false;

        wp_register_script($handle, CRYPLINK_PLUGIN_URL . 'static/' . 'blocks.js', [
            'wc-blocks-registry',
            'wc-blocks-checkout',
            'wp-element',
            'wp-i18n',
            'wp-components',
            'wp-blocks',
            'wp-hooks',
            'wp-data',
            'wp-api-fetch'
        ], $version, true);
        wp_localize_script($handle, 'cryplinkData', [
            'nonce' => wp_create_nonce('wp_rest'),
        ]);

        return [
            $this->scriptId = $handle
        ];
    }

    /**
     * @return string
     */
    public function register_style(): string
    {
        $handle = 'cryplink-' . str_replace(['.css', '_', '.'], ['', '-', '-'], 'blocks-styles.css');
        $version = defined('CRYPLINK_PLUGIN_VERSION') ? CRYPLINK_PLUGIN_VERSION : false;

        wp_register_style(
            $handle,
            CRYPLINK_PLUGIN_URL . 'static/' . 'blocks-styles.css',
            [],
            $version
        );
        wp_enqueue_style($handle);

        return $handle;
    }
}


