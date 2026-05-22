<?php
/**
 * 2022 CryptAPI
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@cryptapi.io so we can send you a copy immediately.
 *
 * @author CryptAPI <info@cryptapi.io>
 * @copyright  2022 CryptAPI
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
class CryptAPIHelper
{
    private static $base_url = 'https://api.cryptapi.io';
    private static $pro_url = 'https://api.blockbee.io';
    private static $pubkey_url = 'https://api.cryptapi.io/pubkey/';
    private static $pubkey_cache_ttl = 86400;
    private $own_address = null;
    private $payment_address = null;
    private $callback_url = null;
    private $coin = null;
    private $pending = false;
    private $parameters = [];
    private $api_key = null;

    public function __construct($coin, $own_address, $api_key, $callback_url, $parameters = [], $pending = false)
    {
        $this->own_address = $own_address;
        $this->callback_url = $callback_url;
        $this->api_key = $api_key;
        $this->coin = $coin;
        $this->pending = $pending ? 1 : 0;
        $this->parameters = $parameters;
    }

    public function get_address()
    {
        if (empty($this->coin) || empty($this->callback_url)) {
            return null;
        }

        $api_key = $this->api_key;

        if (empty($api_key) && empty($this->own_address)) {
            return null;
        }

        $callback_url = $this->callback_url;
        if (!empty($this->parameters)) {
            $req_parameters = http_build_query($this->parameters);
            $callback_url = "{$this->callback_url}?{$req_parameters}";
        }

        if (!empty($api_key) && empty($this->own_address)) {
            $ca_params = [
                'apikey' => $api_key,
                'callback' => $callback_url,
                'pending' => $this->pending,
            ];
        } elseif (empty($api_key) && !empty($this->own_address)) {
            $ca_params = [
                'callback' => $callback_url,
                'address' => $this->own_address,
                'pending' => $this->pending,
            ];
        } elseif (!empty($api_key) && !empty($this->own_address)) {
            $ca_params = [
                'apikey' => $api_key,
                'callback' => $callback_url,
                'address' => $this->own_address,
                'pending' => $this->pending,
            ];
        }

        $response = CryptAPIHelper::_request($this->coin, 'create', $ca_params);

        if ($response->status == 'success') {
            $this->payment_address = $response->address_in;

            return $response->address_in;
        }

        return null;
    }

    public function checklogs()
    {
        if (empty($this->coin) || empty($this->callback_url)) {
            return null;
        }

        $params = [
            'callback' => $this->callback_url,
        ];

        $response = CryptAPIHelper::_request($this->coin, 'logs', $params);

        if ($response->status == 'success') {
            return $response;
        }

        return null;
    }

    public function get_qrcode($value, $size)
    {
        if (empty($this->coin)) {
            return null;
        }

        if (empty($value)) {
            $params = [
                'address' => $this->payment_address,
                'size' => $size,
            ];
        } else {
            $params = [
                'address' => $this->payment_address,
                'value' => $value,
                'size' => $size,
            ];
        }

        $response = CryptAPIHelper::_request($this->coin, 'qrcode', $params);

        if ($response->status == 'success') {
            return ['qr_code' => $response->qr_code, 'uri' => $response->payment_uri];
        }

        return null;
    }

    public static function get_static_qrcode($address, $coin, $value, $size = 300)
    {
        if (empty($address)) {
            return null;
        }

        if (!empty($value)) {
            $params = [
                'address' => $address,
                'value' => $value,
                'size' => $size,
            ];
        } else {
            $params = [
                'address' => $address,
                'size' => $size,
            ];
        }

        $response = CryptAPIHelper::_request($coin, 'qrcode', $params);

        if ($response->status == 'success') {
            return ['qr_code' => $response->qr_code, 'uri' => $response->payment_uri];
        }

        return null;
    }

    public static function get_supported_coins()
    {
        $info = CryptAPIHelper::get_info(null, true);

        if (empty($info)) {
            return null;
        }

        unset($info['fee_tiers']);

        $coins = [];

        foreach ($info as $chain => $data) {
            $is_base_coin = in_array('ticker', array_keys($data));
            if ($is_base_coin) {
                $coins[$chain] = $data['coin'];
                continue;
            }

            $base_ticker = "{$chain}_";
            foreach ($data as $token => $subdata) {
                $chain_upper = strtoupper($chain);

                $coins[$base_ticker . $token] = "{$subdata['coin']} ({$chain_upper})";
            }
        }

        return $coins;
    }

    public static function get_info($coin = null, $assoc = false)
    {
        $params = [];

        if (empty($coin)) {
            $params['prices'] = '0';
        }

        $response = CryptAPIHelper::_request($coin, 'info', $params, $assoc);

        if (empty($coin) || $response->status == 'success') {
            return $response;
        }

        return null;
    }

    public static function process_callback($_get)
    {
        $params = [
            'address_in' => $_get['address_in'],
            'address_out' => $_get['address_out'],
            'txid_in' => $_get['txid_in'],
            'txid_out' => isset($_get['txid_out']) ? $_get['txid_out'] : null,
            'confirmations' => $_get['confirmations'],
            'value' => $_get['value'],
            'value_coin' => $_get['value_coin'],
            'value_forwarded' => isset($_get['value_forwarded']) ? $_get['value_forwarded'] : null,
            'value_forwarded_coin' => isset($_get['value_forwarded_coin']) ? $_get['value_forwarded_coin'] : null,
            'coin' => $_get['coin'],
            'pending' => isset($_get['pending']) ? $_get['pending'] : false,
        ];

        foreach ($_get as $k => $v) {
            if (isset($params[$k])) {
                continue;
            }
            $params[$k] = $_get[$k];
        }

        foreach ($params as &$val) {
            $val = is_string($val) ? trim($val) : null;
        }

        return $params;
    }

    public static function get_conversion($from, $to, $value, $disable_conversion)
    {
        if ($disable_conversion) {
            return $value;
        }

        $params = [
            'from' => $from,
            'to' => $to,
            'value' => $value,
        ];

        $response = CryptAPIHelper::_request('', 'convert', $params);

        if ($response->status == 'success') {
            return $response->value_coin;
        }

        return null;
    }

    public static function get_estimate($coin)
    {
        $params = [
            'addresses' => 1,
            'priority' => 'default',
        ];

        $response = CryptAPIHelper::_request($coin, 'estimate', $params);

        if ($response->status == 'success') {
            return $response->estimated_cost_currency;
        }

        return null;
    }

    public static function sig_fig($value, $digits)
    {
        $value = (string) $value;
        if (strpos($value, '.') !== false) {
            if ($value[0] != '-') {
                return bcadd($value, '0.' . str_repeat('0', $digits) . '5', $digits);
            }

            return bcsub($value, '0.' . str_repeat('0', $digits) . '5', $digits);
        }

        return $value;
    }

    public static function get_pubkey($force_refresh = false)
    {
        $cached = Configuration::get('cryptapi_pubkey');
        $fetched_at = (int) Configuration::get('cryptapi_pubkey_fetched_at');

        if (!$force_refresh && !empty($cached) && (time() - $fetched_at) < self::$pubkey_cache_ttl) {
            return $cached;
        }

        $curl = curl_init(self::$pubkey_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200 || empty($response)) {
            return !empty($cached) ? $cached : null;
        }

        $decoded = json_decode($response, true);
        $pubkey = isset($decoded['pubkey']) ? $decoded['pubkey'] : null;

        if (empty($pubkey)) {
            return !empty($cached) ? $cached : null;
        }

        Configuration::updateValue('cryptapi_pubkey', $pubkey);
        Configuration::updateValue('cryptapi_pubkey_fetched_at', time());

        return $pubkey;
    }

    public static function verify_callback_signature()
    {
        $sig_header = isset($_SERVER['HTTP_X_CA_SIGNATURE']) ? $_SERVER['HTTP_X_CA_SIGNATURE'] : '';
        if (empty($sig_header)) {
            return false;
        }

        $signature = base64_decode($sig_header, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $candidates = self::build_callback_verification_candidates();
        if (empty($candidates)) {
            return false;
        }

        $pubkey = self::get_pubkey();
        if (empty($pubkey)) {
            return false;
        }

        foreach ($candidates as $data) {
            if (openssl_verify($data, $signature, $pubkey, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        $fresh_pubkey = self::get_pubkey(true);
        if (!empty($fresh_pubkey) && $fresh_pubkey !== $pubkey) {
            foreach ($candidates as $data) {
                if (openssl_verify($data, $signature, $fresh_pubkey, OPENSSL_ALGO_SHA256) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function build_callback_verification_candidates()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return [file_get_contents('php://input')];
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        $schemes = [];
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $schemes[] = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        }
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            $schemes[] = 'https';
        } else {
            $schemes[] = 'http';
        }
        $schemes[] = 'https';
        $schemes[] = 'http';
        $schemes = array_values(array_unique(array_filter($schemes)));

        $hosts = [];
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $hosts[] = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0];
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hosts[] = $_SERVER['HTTP_HOST'];
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            $hosts[] = $_SERVER['SERVER_NAME'];
        }
        $hosts = array_values(array_unique(array_filter($hosts)));

        $hostVariants = [];
        foreach ($hosts as $host) {
            $hostVariants[] = $host;
            $bare = preg_replace('/:\d+$/', '', $host);
            if ($bare !== $host) {
                $hostVariants[] = $bare;
            }
            if (strpos($host, 'www.') === 0) {
                $hostVariants[] = substr($host, 4);
            } else {
                $hostVariants[] = 'www.' . $host;
            }
        }
        $hostVariants = array_values(array_unique(array_filter($hostVariants)));

        $uriVariants = [$request_uri];
        $qPos = strpos($request_uri, '?');
        if ($qPos !== false) {
            $path = substr($request_uri, 0, $qPos);
            $query = substr($request_uri, $qPos);
            $uriVariants[] = (substr($path, -1) === '/' ? rtrim($path, '/') : $path . '/') . $query;
        } else {
            $uriVariants[] = substr($request_uri, -1) === '/' ? rtrim($request_uri, '/') : $request_uri . '/';
        }
        $uriVariants = array_values(array_unique($uriVariants));

        $candidates = [];
        foreach ($schemes as $scheme) {
            foreach ($hostVariants as $host) {
                foreach ($uriVariants as $uri) {
                    $candidates[] = $scheme . '://' . $host . $uri;
                }
            }
        }

        return $candidates;
    }

    private static function _request($coin, $endpoint, $params = [], $assoc = false)
    {
        $base_url = CryptAPIHelper::$base_url;

        if (!empty($params['apikey']) && $endpoint !== 'info') {
            $base_url = CryptAPIHelper::$pro_url;
        }

        if (!empty($params)) {
            $data = http_build_query($params);
        }

        if (!empty($coin)) {
            $coin = str_replace('_', '/', $coin);
            $url = "{$base_url}/{$coin}/{$endpoint}/";
        } else {
            $url = "{$base_url}/{$endpoint}/";
        }

        if (!empty($data)) {
            $url .= "?{$data}";
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($curl);

        $json = [];

        if (curl_error($curl)) {
            $json['error'] = 'ERROR: ' . curl_errno($curl) . '::' . curl_error($curl);

            return $json;
        } elseif ($response) {
            return json_decode($response, $assoc);
        }
    }
}
