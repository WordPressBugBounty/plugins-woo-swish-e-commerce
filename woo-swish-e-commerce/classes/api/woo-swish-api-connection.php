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

        public function __construct()
        {
            parent::__construct();
            $this->account_uuid = WC_SEC()->account_uuid;
            $this->refresh_token = WC_SEC()->get_option('swish_refresh_token');
            $this->service_url = WC_SEC()->service_url;
        }

        public function get_access_token()
        {

            $time_now = time();
            $access_token = get_site_transient('swish_access_token');

            if ($this->refresh_token && !$access_token) {

                $body = array(
                    'grant_type' => 'refresh_token',
                    'account_uuid' => $this->account_uuid,
                    'refresh_token' => $this->refresh_token,
                    'version' => WC_SEC()->version,
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

                WC_SEC()->logger->add(sprintf('execute: Posting to "%s" using ""%s" as args', $url, json_encode($args)));

                $response = wp_safe_remote_post($url, $args);

                if (is_wp_error($response)) {

                    $code = $response->get_error_code();

                    $error = $response->get_error_message($code);

                    throw new Woo_Swish_API_Exception($error, 0, null, $url, $body);

                } else {

                    $response_body = json_decode(wp_remote_retrieve_body($response));

                    if (($http_code = wp_remote_retrieve_response_code($response)) != 200) {

                        throw new Woo_Swish_API_Exception($response_body->error, $http_code, null, $url, $body, $response);

                    }

                    $access_token = $response_body->access_token;
                    $this->refresh_token = $response_body->refresh_token;

                    set_site_transient('swish_access_token', $response_body->access_token, $response_body->expires_in);
                    WC_SEC()->update_option('swish_refresh_token', $this->refresh_token);

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
        public function post($path, $form = array())
        {
            // Start the request and return the response
            return $this->execute('POST', $path, $form);
        }

        /**
         * post function.
         *
         * Performs an API POST request
         *
         * @access public
         * @return object
         */
        public function put($path, $form = array())
        {
            // Start the request and return the response
            return $this->execute('PUT', $path, $form);
        }

        /**
         * get function.
         *
         * Performs an API GET request
         *
         * @access public
         * @return object
         */
        public function get($path, $params)
        {
            // Start the request and return the response
            return $this->execute('GET', $path, $params);
        }

        public function execute($method, $path, $body = null)
        {

            $url = 'https://' . $this->api_url . $path;

            if (is_array($body) && !empty($body)) {

                if ('GET' == $method || 'DELETE' == $method) {
                    $url .= '?' . preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', http_build_query($body, '', '&'));
                    $body = null;
                } else {

                    $body = json_encode($body, JSON_UNESCAPED_SLASHES);

                    if ($body === false) {
                        throw new Woo_Swish_API_Exception(__('Error when creating Swish request', 'woo-swish-e-commerce'), 999, null, $url, print_r($body, true));
                    }

                }

            }

            $args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Content-Length' => strlen($body),
                    'Authorization' => 'Bearer ' . $this->get_access_token(),
                ),
                'body' => $body,
                'method' => $method,
                'timeout' => 20,
            );

            WC_SEC()->logger->add(sprintf('execute: Requesting from "%s" using "%s" as args', $url, json_encode($args)));

            $response = wp_safe_remote_request($url, $args);

            if (is_wp_error($response)) {

                $code = $response->get_error_code();
                WC_SEC()->logger->add(print_r($code, true));
                $error = $response->get_error_message($code);
                WC_SEC()->logger->add(print_r($error, true));
                throw new Woo_Swish_API_Exception($error, $code, null, $url);

            } else {

                $response_body_json = wp_remote_retrieve_body($response);
                $response_body = json_decode($response_body_json);

                if (($http_code = wp_remote_retrieve_response_code($response)) > 299) {

                    if ($http_code == 422 || $http_code == 403) {

                        if (is_array($response_body)) {
                            $swish_error = $response_body[0];
                            throw new Woo_Swish_API_Exception($swish_error->errorCode . ' - ' . Woo_Swish_Helper::error_code($swish_error->errorCode), $http_code, null, $url, print_r($body, true), $response_body_json);
                        } else {
                            throw new Woo_Swish_API_Exception($response_body->message, $http_code, null, $url, print_r($body, true), $response_body_json);
                        }

                    } elseif ($http_code == 401 || $http_code == 402) {
                        throw new Woo_Swish_API_Exception($response_body->message, $http_code, null, $url, print_r($body, true), $response_body_json);
                    } else {
                        throw new Woo_Swish_API_Exception(__('Unknown error occured when communicating with Swish', 'woo-swish-e-commerce'), $http_code, null, $url, print_r($body, true), $response_body_json);
                    }
                }
                return $response_body;

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
