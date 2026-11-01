<?php
/**
 * Woo_Swish_API class
 *
 * @class         Woo_Swish_API
 * @since         1.7.0
 * @package       Woocommerce_Swish/Classes/Api
 * @category      Class
 * @author        BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Woo_Swish_API_Functions', false)) {
    require_once WCSW_PATH . 'classes/api/woo-swish-api-functions.php';
}

if (!class_exists('Woo_Swish_API_Connection', false)) {
    class Woo_Swish_API_Connection extends Woo_Swish_API_Functions
    {

        public $account_uuid;

        public $refresh_token;

        public $service_url;

        public function __construct($gateway = null)
        {
            parent::__construct($gateway);
            $this->account_uuid = $this->gateway->account_uuid;
            $this->refresh_token = $this->gateway->get_option('swish_refresh_token');
            $this->service_url = $this->gateway->service_url;
        }

        private function access_token_transient_key()
        {
            // Preserve the existing cache for the ordinary Swish gateway.
            return $this->gateway->id === 'swish' ? 'swish_access_token' : 'swish_access_token_' . $this->gateway->id;
        }

        public function get_access_token()
        {

            $time_now = time();
            $access_token = get_transient($this->access_token_transient_key());

            if (is_string($this->refresh_token) && $this->refresh_token !== '' && !$access_token) {

                $body = array(
                    'grant_type' => 'refresh_token',
                    'account_uuid' => $this->account_uuid,
                    'refresh_token' => $this->refresh_token,
                    'version' => $this->gateway->version,
                    'callback_url' => get_rest_url(null, 'swish/callback'),
                );

                $args = array(
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ),
                    'timeout' => 20,
                    'body' => $body,
                );

                $url = 'https://' . $this->service_url . '/token';

                $this->gateway->logger->add('execute: Refreshing Swish service access credentials.');

                $response = wp_safe_remote_post($url, $args);

                if (is_wp_error($response)) {

                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message is escaped before output
                    throw new Woo_Swish_API_Exception(esc_html(__('Could not refresh Swish service credentials', 'woo-swish-e-commerce')), 0, null, $url);

                } else {

                    $response_body = json_decode(wp_remote_retrieve_body($response));

                    if (($http_code = wp_remote_retrieve_response_code($response)) != 200) {

                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error is escaped before output
                        throw new Woo_Swish_API_Exception(esc_html(__('Swish service credential refresh was rejected', 'woo-swish-e-commerce')), (int) $http_code, null, $url);

                    }

                    if (!is_object($response_body) || !is_string($response_body->access_token ?? null) || trim($response_body->access_token) === ''
                        || !is_string($response_body->refresh_token ?? null) || trim($response_body->refresh_token) === ''
                        || !(is_int($response_body->expires_in ?? null) || (is_string($response_body->expires_in ?? null) && ctype_digit($response_body->expires_in)))
                        || (int) $response_body->expires_in <= 0) {
                        throw new Woo_Swish_API_Exception(esc_html(__('Swish service returned invalid credentials', 'woo-swish-e-commerce')), 0, null, $url);
                    }

                    if ($this->gateway->id === 'swish') {
                        // Ordinary gateway: atomic, locked rotation of woocommerce_swish_settings + swish_access_token.
                        try {
                            WC_SEC()->store_legacy_refresh_token($this->account_uuid, $this->refresh_token, $response_body->refresh_token, $response_body->access_token, (int) $response_body->expires_in);
                        } catch (Throwable $error) {
                            throw new Woo_Swish_API_Exception(esc_html(__('Swish connection changed during credential refresh', 'woo-swish-e-commerce')), 0, null, $url);
                        }
                    } else {
                        // Other gateways (recurring) keep their own settings and access token cache.
                        if ($this->gateway->get_option('swish_refresh_token') !== $this->refresh_token) {
                            throw new Woo_Swish_API_Exception(esc_html(__('Swish connection changed during credential refresh', 'woo-swish-e-commerce')), 0, null, $url);
                        }
                        $this->gateway->update_option('swish_refresh_token', $response_body->refresh_token);
                        set_transient($this->access_token_transient_key(), $response_body->access_token, (int) $response_body->expires_in);
                    }
                    $access_token = $response_body->access_token;
                    $this->refresh_token = $response_body->refresh_token;

                }

            }

            return $access_token;
        }

        /**
         * post function.
         *
         * Performs an API POST request
         *
         * @access public
         * @return object
         */
        public function post($path, $form = array(), $return_raw = false)
        {
            // Start the request and return the response
            return $this->execute('POST', $path, $form, $return_raw);
        }

        /**
         * post function.
         *
         * Performs an API POST request
         *
         * @access public
         * @return object
         */
        public function put($path, $form = array(), $return_raw = false)
        {
            // Start the request and return the response
            return $this->execute('PUT', $path, $form, $return_raw);
        }

        /**
         * get function.
         *
         * Performs an API GET request
         *
         * @access public
         * @return object
         */
        public function get($path, $params, $return_raw = false)
        {
            // Start the request and return the response
            return $this->execute('GET', $path, $params, $return_raw);
        }

        /**
         * patch function.
         *
         * Performs an API PATCH request
         *
         * @access public
         * @return object
         */
        public function patch($path, $form = array(), $return_raw = false)
        {
            // Start the request and return the response
            return $this->execute('PATCH', $path, $form, $return_raw);
        }

        public function execute($method, $path, $body = null, $return_raw = false)
        {

            $url = 'https://' . $this->api_url . $path;

            if (is_array($body) && !empty($body)) {

                if ('GET' == $method || 'DELETE' == $method) {
                    $url .= '?' . preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', http_build_query($body, '', '&'));
                    $body = null;
                } else {

                    $body_data = $body; // Preserve original data for error reporting
                    $body = json_encode($body, JSON_UNESCAPED_SLASHES);

                    if ($body === false) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- URL and body data are used for internal logging only
                        throw new Woo_Swish_API_Exception(esc_html(__('Error when creating Swish request', 'woo-swish-e-commerce')), 999, null, $url, wp_json_encode($body_data));
                    }

                }

            }

            if (is_array($body) && empty($body)) {
                $body = null;
            }

            $args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Content-Length' => is_string($body) ? strlen($body) : 0,
                    'Authorization' => 'Bearer ' . $this->get_access_token(),
                ),
                'body' => $body,
                'method' => $method,
                'timeout' => 20,
            );

            $this->gateway->logger->add(sprintf('execute: Sending %s Swish service request.', $method));

            $response = wp_safe_remote_request($url, $args);

            if (is_wp_error($response)) {

                $code = $response->get_error_code();
                $this->gateway->logger->add($code);
                $error = $response->get_error_message($code);
                $this->gateway->logger->add($error);
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new Woo_Swish_API_Exception(esc_html($error), is_numeric($code) ? (int) $code : 0, null, $url);

            } else {

                $response_body_json = wp_remote_retrieve_body($response);
                $http_code = wp_remote_retrieve_response_code($response);

                // Missing/invalid or informational codes do not confirm acceptance.
                // Keep writes uncertain (code 0), rather than treating them as rejection.
                if (!is_scalar($http_code) || !preg_match('/^[0-9]{3}$/D', (string) $http_code) || (int) $http_code < 200) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    throw new Woo_Swish_API_Exception(esc_html(__('Swish response did not confirm an HTTP success status', 'woo-swish-e-commerce')), 0, null, $url, wp_json_encode($body), $response_body_json);
                }

                // Handle errors first - always try to decode JSON for error responses
                if ($http_code > 299) {
                    // Try to decode JSON to get error message, even for raw requests
                    $decoded_response = json_decode($response_body_json);
                    
                    // Extract error message safely
                    $error_message = $response_body_json; // Default to raw response
                    if (is_object($decoded_response) && isset($decoded_response->message)) {
                        $error_message = $decoded_response->message;
                    } elseif (is_array($decoded_response) && !empty($decoded_response) && isset($decoded_response[0]->errorCode)) {
                        $swish_error = $decoded_response[0];
                        $error_message = $swish_error->errorCode . ' - ' . Woo_Swish_Helper::error_code($swish_error->errorCode);
                    }

                    if ($http_code == 422 || $http_code == 403) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        throw new Woo_Swish_API_Exception(esc_html($error_message), $http_code, null, $url, wp_json_encode($body), $response_body_json);
                    } elseif ($http_code == 401 || $http_code == 402) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        throw new Woo_Swish_API_Exception(esc_html($error_message), $http_code, null, $url, wp_json_encode($body), $response_body_json);
                    } else {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        throw new Woo_Swish_API_Exception(esc_html(__('Unknown error occurred when communicating with Swish', 'woo-swish-e-commerce')), $http_code, null, $url, wp_json_encode($body), $response_body_json);
                    }
                }

                // Success - return raw or decoded response based on request
                if ($return_raw) {
                    return $response_body_json;
                }
                return json_decode($response_body_json);

            }

        }

        /**
         * retreive function.
         *
         * Retreives a payment via the API
         *
         * @access public
         * @param  string $order
         * @return object
         * @throws Woo_Swish_API_Exception
         */
        public function retreive($order_url)
        {

            if (false === strpos($order_url, 'https')) {
                $order_url = 'https://cpc.getswish.net/swish-cpcapi/api/v1/paymentrequests/' . $order_url;
            }

            $payment = $this->get('/swish-cpcapi/api/v1/retreive', array('order_url' => $order_url));
            return $payment;

        }

    }

}
