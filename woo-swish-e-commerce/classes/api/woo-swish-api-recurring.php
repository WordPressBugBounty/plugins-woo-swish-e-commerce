<?php
/**
 * Swish recurring API client. The caller owns identifiers and reconciliation.
 * The inherited refund($paymentId, $order, $merchantAlias, $amount, $callback,
 * $instructionId, $reason) uses the existing v2 refund API in production;
 * the service does not expose a v2 refund route under /mss in test mode.
 *
 * @package Woocommerce_Swish/Classes/Api
 */

defined('ABSPATH') || exit;

if (!class_exists('Woo_Swish_API_Connection', false)) {
    require_once WCSW_PATH . 'classes/api/woo-swish-api-connection.php';
}

if (!class_exists('Woo_Swish_API_Recurring', false)) {
    class Woo_Swish_API_Recurring extends Woo_Swish_API_Connection
    {
        private $on_behalf_of;

        /**
         * @param object      $gateway       Explicit gateway with service URL and token settings.
         * @param bool|null   $test          Null derives MSS mode from connection_type.
         * @param string|null $on_behalf_of  Merchant alias for partner certificates; empty string omits it.
         */
        public function __construct($gateway, $test = null, $on_behalf_of = null)
        {
            if (!$gateway) {
                throw new InvalidArgumentException('A Swish gateway is required.');
            }

            parent::__construct($gateway);
            $test = $test === null ? $gateway->connection_type === '_test' : $test;
            $this->api_url = $this->service_url . ($test ? '/mss' : '');
            $this->on_behalf_of = $on_behalf_of === null ? (string) $gateway->merchant_alias : (string) $on_behalf_of;

            if ($this->on_behalf_of !== '' && !preg_match('/^[0-9]{8,15}$/D', $this->on_behalf_of)) {
                throw new InvalidArgumentException('Invalid Swish onBehalfOf merchant alias.');
            }
        }

        private function id($id)
        {
            if (!is_string($id) || !preg_match('/^[A-F0-9]{32}$/D', $id)) {
                throw new InvalidArgumentException('Swish recurring IDs must be 32 uppercase hexadecimal characters.');
            }
            return $id;
        }

        private function callback($callback)
        {
            if (!self::is_https_url($callback)) {
                throw new InvalidArgumentException('A valid HTTPS Swish callback URL is required.');
            }
            return $callback;
        }

        private static function is_https_url($url)
        {
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }
            $parts = parse_url($url);
            return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['fragment']);
        }

        /**
         * Preserve HTTP code and raw response in Woo_Swish_API_Exception. A timeout or
         * 5xx on a write does not establish that Swish rejected the request: GET its ID.
         */
        private function request_recurring($method, $path, $body = null)
        {
            $url = 'https://' . $this->api_url . '/recurring/merchant/v1/' . $path;
            $token = $this->get_access_token();
            if (!is_string($token) || $token === '') {
                throw new Woo_Swish_API_Exception('Swish recurring authorization token is unavailable.', 0, null, $url);
            }
            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            );
            if ($this->on_behalf_of !== '') {
                $headers['onBehalfOf'] = $this->on_behalf_of;
            }

            $json = $body === null ? '' : wp_json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new InvalidArgumentException('Unable to encode Swish recurring request.');
            }

            $response = wp_safe_remote_request($url, array(
                'headers' => $headers,
                'body' => $json,
                'method' => $method,
                'timeout' => 20,
            ));

            if (is_wp_error($response)) {
                throw new Woo_Swish_API_Exception($response->get_error_message(), 0, null, $url, $json);
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            if ($status < 200 || $status >= 300) {
                $error = json_decode($raw);
                $message = is_object($error) && isset($error->code) ? $error->code : (is_object($error) && isset($error->message) ? $error->message : 'Swish recurring request failed');
                throw new Woo_Swish_API_Exception($message, $status, null, $url, $json, $raw);
            }

            // A 2xx acknowledges the HTTP request, not a completed consent or charge.
            // The caller must reconcile its assigned ID via GET before acting on it.
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Woo_Swish_API_Exception('Invalid Swish recurring response JSON.', $status, null, $url, $json, $raw);
            }
            return $decoded;
        }

        /** E-commerce consent when alias is given, otherwise M-commerce consent token. */
        public function create_consent($id, $callback, $payer_alias = '')
        {
            $id = $this->id($id);
            $body = array('callbackUrl' => $this->callback($callback));
            if ($payer_alias !== '') {
                if (!is_string($payer_alias) || !preg_match('/^[0-9]{8,15}$/D', $payer_alias)) {
                    throw new InvalidArgumentException('Invalid Swish payer alias.');
                }
                $body['payer'] = array('alias' => $payer_alias);
            }
            return $this->request_recurring('POST', 'consents/' . $id . ($payer_alias === '' ? '/token' : ''), $body);
        }

        public function get_consent($id)
        {
            return $this->request_recurring('GET', 'consents/' . $this->id($id));
        }

        public function revoke_consent($id)
        {
            return $this->request_recurring('DELETE', 'consents/' . $this->id($id));
        }

        /** Amount is a positive decimal string (up to two fractional digits), never a float. */
        public function create_payment($id, $consent_id, $amount, $reference, $message, $callback)
        {
            $id = $this->id($id);
            $consent_id = $this->id($consent_id);
            if (!is_string($amount) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount) || !preg_match('/[1-9]/', $amount)) {
                throw new InvalidArgumentException('Swish amount must be a positive decimal string with at most two decimals.');
            }
            if (!is_string($reference) || !preg_match('/^[A-Za-z0-9]{1,35}$/D', $reference)) {
                throw new InvalidArgumentException('Swish reference must be 1-35 alphanumeric characters.');
            }
            if (!is_string($message) || !preg_match('/^[-0-9a-zåäöA-ZÅÄÖ!? (),.:;]{0,50}$/Du', $message)) {
                throw new InvalidArgumentException('Invalid Swish payment message.');
            }

            return $this->request_recurring('POST', 'payments/single/' . $id, array(
                'amount' => array('value' => $amount, 'currency' => 'SEK'),
                'consentId' => $consent_id,
                'message' => $message,
                'payeePaymentReference' => $reference,
                'callbackUrl' => $this->callback($callback),
            ));
        }

        public function get_payment($id)
        {
            return $this->request_recurring('GET', 'payments/single/' . $this->id($id) . '/status');
        }

        /** The return URL is for the browser, not the Swish server-to-server callback. */
        public static function consent_app_url($token, $return_url = '')
        {
            // The response documents an opaque string, not the 32-hex consent ID format.
            if (!is_string($token) || $token === '') {
                throw new InvalidArgumentException('Swish consent token must be a nonempty string.');
            }
            if ($return_url !== '' && !self::is_https_url($return_url)) {
                throw new InvalidArgumentException('A valid HTTPS return URL is required.');
            }
            return 'https://app.swish.nu/1/r/sw/?sw=' . rawurlencode($token) . ($return_url === '' ? '' : '&ret=' . rawurlencode($return_url));
        }
    }
}
