<?php

namespace CrypLink\Utils;

use Exception;

class Api {
    private static $base_url = "https://payme.cryplink.xyz";

    /** Stores the last raw API error so callers can surface it */
    public static $last_error = null;

    private $own_address = null;
    private $payment_address = null;
    private $callback_url = null;
    private $callback_url_api = null;
    private $coin = null;
    private $parameters = [];

    public function __construct($coin, $own_address, $callback_url, $parameters = [], $pending = false)
    {
        $this->own_address  = $own_address;
        $this->callback_url = $callback_url;
        $this->coin         = $coin;
        $this->parameters   = $parameters;
    }

    public function get_address()
    {
        self::$last_error = null;

        $fiat_amount   = (string) ($this->parameters['fiat_amount'] ?? '');
        $fiat_currency = (string) ($this->parameters['fiat_currency'] ?? '');
        $nonce         = (string) ($this->parameters['nonce'] ?? '');

        if (empty($this->coin) || empty($this->callback_url) || empty($this->own_address) || $fiat_amount === '' || $fiat_currency === '') {
            $msg = "CrypLink: Missing parameters — coin={$this->coin}, callback={$this->callback_url}, address={$this->own_address}, fiat_amount={$fiat_amount}, fiat_currency={$fiat_currency}";
            error_log($msg);
            self::$last_error = $msg;
            return null;
        }

        // IMPORTANT:
        // Do NOT blindly append all parameters to the callback URL.
        // - $this->callback_url is already built by the gateway and may already contain a query string.
        // - Appending with "?" again breaks the URL (and breaks /logs lookup and inbound callbacks).
        // Keep the callback URL exactly as provided by the gateway.
        $callback_url = $this->callback_url;

        // Normalise fields to match CrypLink API expectations
        $ticker = strtolower(trim((string) $this->coin));
        $merchant_address = trim((string) $this->own_address);
        $order_id = (string) ($this->parameters['order_id'] ?? '');
        // Ensure fiat_amount is a plain number (no thousands separators)
        $fiat_amount_clean = preg_replace('/[^0-9.\\-]/', '', (string) $fiat_amount);
        $fiat_amount_num = is_numeric($fiat_amount_clean) ? (float) $fiat_amount_clean : 0.0;
        $fiat_currency = strtoupper(trim((string) $fiat_currency));

        // Ensure callback_url is a valid URL string
        if (function_exists('esc_url_raw')) {
            $callback_url = esc_url_raw((string) $callback_url);
        }

        $body = [
            'ticker'           => $ticker,
            'merchant_address' => $merchant_address,
            'order_id'         => $order_id,
            'callback_url'     => $callback_url,
            // Newer API rejects nonce in callback_url; send it as a dedicated field instead.
            // This is optional from the API side, but our gateway will provide it.
            'nonce'            => $nonce,
            // Required by current CrypLink API (otherwise returns: "Missing required field: fiat_amount/fiat_currency")
            'fiat_amount'      => $fiat_amount_num,
            'fiat_currency'    => $fiat_currency,
        ];

        $response = self::_request(null, 'invoice', $body, false, 'POST');

        if ($response && isset($response->status) && $response->status === 'success') {
            $this->payment_address = $response->address_in;
            // The API may normalise/append query params (e.g. order_id, json=1).
            // Store the exact callback_url as the API sees it so /logs lookup matches.
            if (isset($response->callback_url) && is_string($response->callback_url)) {
                $this->callback_url_api = $response->callback_url;
            }
            return $response->address_in;
        }

        // Surface API-level error message if available
        if ($response && isset($response->error)) {
            self::$last_error = (string) $response->error;
        } elseif ($response && isset($response->message)) {
            self::$last_error = (string) $response->message;
        } elseif ($response && isset($response->status)) {
            self::$last_error = "API status: " . (string) $response->status;
        }

        return null;
    }

    /**
     * Returns the callback URL as returned by the API (if provided).
     * This is important because /invoice may append parameters, and /logs expects that exact value.
     */
    public function get_callback_url_api()
    {
        return $this->callback_url_api;
    }

    public static function check_logs($callback, $coin)
    {
        if (empty($coin) || empty($callback)) {
            return false;
        }

        self::$last_error = null;
        $params = [
            'ticker'   => $coin,
            'callback' => $callback,
        ];

        $response = self::_request(null, 'logs', $params);

        if ($response && isset($response->status) && $response->status === 'success') {
            return $response->callbacks ?? [];
        }

        if ($response && isset($response->error)) {
            self::$last_error = (string) $response->error;
        } elseif ($response && isset($response->message)) {
            self::$last_error = (string) $response->message;
        } elseif ($response && isset($response->status)) {
            self::$last_error = "API status: " . (string) $response->status;
        }
        return false;
    }

    public static function get_static_qrcode($address, $coin, $value, $size = 300)
    {
        if (empty($address) || empty($coin)) {
            return null;
        }

        $params = [
            'ticker'  => $coin,
            'address' => $address,
            'size'    => $size,
        ];

        if (!empty($value)) {
            $params['value'] = $value;
        }

        $response = self::_request(null, 'qrcode', $params);

        if ($response && isset($response->qr_code)) {
            return ['qr_code' => $response->qr_code, 'uri' => $response->payment_uri ?? ''];
        }

        return null;
    }

    public static function get_supported_coins()
    {
        $response = self::_request(null, 'tickers');

        if ($response && isset($response->status) && $response->status === 'success') {
            $tickers = (array) $response->tickers;
            $coins   = [];
            foreach ($tickers as $ticker => $data) {
                // Try to detect whether a token is temporarily unavailable.
                // The API payload may vary by backend version, so we support multiple fields.
                $state = 'live';
                try {
                    if (is_object($data)) {
                        if (isset($data->active) && $data->active === false) {
                            $state = 'maintenance';
                        } elseif (isset($data->maintenance) && (bool) $data->maintenance) {
                            $state = 'maintenance';
                        } elseif (isset($data->enabled) && $data->enabled === false) {
                            $state = 'maintenance';
                        } elseif (isset($data->status)) {
                            $s = strtolower(trim((string) $data->status));
                            if (in_array($s, ['maintenance', 'disabled', 'offline', 'down'], true)) {
                                $state = 'maintenance';
                            } elseif (in_array($s, ['live', 'online', 'active', 'enabled', 'up'], true)) {
                                $state = 'live';
                            }
                        }
                    } elseif (is_array($data)) {
                        if ((isset($data['active']) && $data['active'] === false) || !empty($data['maintenance']) || (isset($data['enabled']) && $data['enabled'] === false)) {
                            $state = 'maintenance';
                        } elseif (!empty($data['status'])) {
                            $s = strtolower(trim((string) $data['status']));
                            if (in_array($s, ['maintenance', 'disabled', 'offline', 'down'], true)) {
                                $state = 'maintenance';
                            } elseif (in_array($s, ['live', 'online', 'active', 'enabled', 'up'], true)) {
                                $state = 'live';
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $state = 'live';
                }

                $name = is_object($data) ? ($data->name ?? (string) $ticker) : (is_array($data) ? ($data['name'] ?? (string) $ticker) : (string) $ticker);
                $logo = is_object($data) ? ($data->logo ?? '') : (is_array($data) ? ($data['logo'] ?? '') : '');

                $coins[$ticker] = [
                    'name'  => $name,
                    'logo'  => $logo,
                    'state' => $state, // 'live' | 'maintenance'
                ];
            }
            return $coins;
        }

        return null;
    }

    /**
     * Fetch the current list of CrypLink callback IPs from the API.
     * Endpoint: GET /ips
     *
     * @return array<string>|null
     */
    public static function get_callback_ips()
    {
        self::$last_error = null;
        $resp = self::_request(null, 'ips', [], true, 'GET');

        if (!is_array($resp)) {
            return null;
        }

        $ips = [];
        foreach ($resp as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Convert a fiat amount to a crypto amount via the CrypLink /convert API.
     *
     * IMPORTANT:
     * The CrypLink API expects `ticker` to be the CrypLink ticker key itself
     * (e.g. `btc`, `eth`, `bep20/usdt`, `sol/usdc`) — NOT a pair like `btc/usdc`.
     *
     * @param string $from    WooCommerce currency code ('VND', 'EUR', 'USD', …)
     * @param string $coin    CrypLink ticker key ('bep20_usdt', 'sol', 'btc', …)
     * @param string $value   Amount in $from currency
     * @param bool   $disable When true, skip conversion and return $value unchanged
     *
     * @return string|null Crypto amount as string, or null on any API failure
     */
    public static function get_conversion($from, $coin, $value, $disable = false)
    {
        if ($disable) {
            return (string) $value;
        }

        $from  = strtoupper(trim((string) $from));
        // Tickers coming from /tickers are lowercase and may include '/'
        $coin  = strtolower(trim((string) $coin));
        $value = (string) $value;

        // ── Direct conversion: /convert?ticker=<coin>&from=<FIAT> ─────────
        self::$last_error = null;
        $resp = self::_request(null, 'convert', [
            'ticker' => $coin,
            'value'  => $value,
            'from'   => $from,
        ]);

        // Support both object and array responses
        if (is_object($resp) && isset($resp->value_coin) && (isset($resp->status) ? $resp->status === 'success' : true)) {
            return (string) $resp->value_coin;
        }
        if (is_array($resp) && isset($resp['value_coin']) && (isset($resp['status']) ? $resp['status'] === 'success' : true)) {
            return (string) $resp['value_coin'];
        }

        // Some backends may return a plain number/string
        if (is_scalar($resp) && is_numeric($resp)) {
            return (string) $resp;
        }

        // Ensure we have a meaningful error message to surface upstream
        if (empty(self::$last_error) && is_object($resp)) {
            if (isset($resp->error)) self::$last_error = (string) $resp->error;
            elseif (isset($resp->message)) self::$last_error = (string) $resp->message;
            elseif (isset($resp->status)) self::$last_error = (string) $resp->status;
        }

        $err = self::$last_error ?? 'Conversion API returned an unexpected response';
        error_log("CrypLink get_conversion failed (ticker={$coin} from={$from} value={$value}): {$err}");
        return null;
    }

    public static function get_info($coin)
    {
        if (empty($coin)) return null;

        $response = self::_request(null, 'info', ['ticker' => $coin]);

        if ($response && isset($response->status) && $response->status === 'success') {
            return $response;
        }

        return null;
    }

    /**
     * Estimate blockchain fee (optional feature used by handling_fee()).
     *
     * The API response format may vary by backend version; we return the decoded
     * object so callers can read currency fields dynamically (e.g. $resp->USD).
     *
     * @param string $coin CrypLink ticker (e.g. 'btc', 'eth', 'bep20_usdt')
     * @return object|null
     */
    public static function get_estimate($coin)
    {
        if (empty($coin)) {
            return null;
        }

        self::$last_error = null;
        $response = self::_request(null, 'estimate', ['ticker' => (string) $coin]);

        if ($response && isset($response->status) && $response->status === 'success') {
            // Some backends may nest values under "estimate"
            if (isset($response->estimate) && is_object($response->estimate)) {
                return $response->estimate;
            }
            return $response;
        }

        return null;
    }

    public static function process_callback($get_data)
    {
        $params = [
            'address_in'         => (string) ($get_data['address_in']         ?? ''),
            'address_out'        => (string) ($get_data['address_out']        ?? ''),
            'txid_in'            => (string) ($get_data['txid_in']            ?? ''),
            'txid_out'           => (string) ($get_data['txid_out']           ?? ''),
            'value_coin'         => (float)  ($get_data['value_coin']         ?? 0),
            'value_coin_convert' => (string) ($get_data['value_coin_convert'] ?? ''),
            'coin'               => (string) ($get_data['coin']               ?? ''),
            'pending'            => (int)    ($get_data['pending']            ?? 0),
            'uuid'               => (string) ($get_data['uuid']               ?? ''),
            'order_id'           => (string) ($get_data['order_id']           ?? ''),
            'nonce'              => (string) ($get_data['nonce']              ?? ''),
        ];

        foreach ($get_data as $key => $value) {
            if (!isset($params[$key])) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Test API connectivity. Returns true on success, or the raw error string on failure.
     * Uses the /pubkey endpoint (lightweight, no domain check).
     * Then tests /invoice with a dummy payload to detect "Host not in allowlist".
     */
    public static function test_connection()
    {
        self::$last_error = null;

        // First check basic connectivity
        $pubkey = self::_request(null, 'pubkey');
        if ($pubkey === null) {
            return self::$last_error ?? 'Cannot reach payme.cryplink.xyz — check server connectivity.';
        }

        // Now test the invoice endpoint with a dummy payload to detect domain allowlist
        $dummy_body = [
            'ticker'           => 'usdt',
            'merchant_address' => 'test',
            'order_id'         => 'connection-test',
            'callback_url'     => home_url('/'),
        ];
        self::$last_error = null;
        $resp = self::_request(null, 'invoice', $dummy_body, false, 'POST');

        // "success" or a known API-level error (bad address etc) = domain is allowed
        if ($resp !== null) {
            return true;
        }

        // null + last_error set = non-JSON response = domain blocked
        if (!empty(self::$last_error)) {
            return self::$last_error;
        }

        return true; // timeout or transient issue — don't block admin
    }

    public static function get_pubkey()
    {
        $response = self::_request(null, 'pubkey');

        if ($response && isset($response->pubkey)) {
            return $response->pubkey;
        }

        return null;
    }

    /**
     * Make an HTTP request to the CrypLink API.
     * On JSON parse failure the raw body is logged AND stored in self::$last_error
     * so callers can surface the real API message (e.g. "Host not in allowlist").
     */
    private static function _request($coin, $endpoint, $params = [], $assoc = false, $method = 'GET')
    {
        $url = self::$base_url . '/' . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $max_retries = ($endpoint === 'tickers') ? 1 : 2;

        for ($y = 0; $y < $max_retries; $y++) {
            $args = [
                'timeout'    => ($endpoint === 'tickers') ? 8 : 15,
                'method'     => $method,
                'user-agent' => 'CrypLink-WooCommerce/' . (defined('CRYPLINK_PLUGIN_VERSION') ? CRYPLINK_PLUGIN_VERSION : '1.0'),
                'sslverify'  => true,
            ];

            if ($method === 'POST') {
                $args['headers'] = ['Content-Type' => 'application/json'];
                $args['body']    = json_encode($params);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $msg = $response->get_error_message();
                error_log("CrypLink API WP_Error [{$endpoint}]: {$msg} — URL: {$url}");
                self::$last_error = $msg;
                continue;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body      = wp_remote_retrieve_body($response);

            if ($http_code >= 400) {
                error_log("CrypLink API HTTP {$http_code} [{$endpoint}]: {$body} — URL: {$url}");
            }

            $decoded = json_decode($body, $assoc);

            if ($decoded !== null) {
                // Log failed API-level responses (status != success) for easier debugging
                if (is_object($decoded) && isset($decoded->status) && $decoded->status !== 'success') {
                    $api_msg = $decoded->error ?? $decoded->message ?? $decoded->status ?? 'unknown';
                    error_log("CrypLink API [{$endpoint}] status={$decoded->status} msg={$api_msg} — URL: {$url}");
                    self::$last_error = (string) $api_msg;
                } elseif (is_object($decoded) && (isset($decoded->error) || isset($decoded->message)) && empty(self::$last_error)) {
                    // Some endpoints return { "error": "..." } without a status field.
                    $api_msg = $decoded->error ?? $decoded->message ?? 'unknown';
                    self::$last_error = (string) $api_msg;
                }
                return $decoded;
            }

            // Non-JSON body (e.g. "Host not in allowlist") — log and store for surfacing
            $raw = trim($body);
            error_log("CrypLink API non-JSON [{$endpoint}]: {$raw} — URL: {$url}");
            self::$last_error = $raw ?: "Empty response from API (HTTP {$http_code})";
        }

        return null;
    }
}
