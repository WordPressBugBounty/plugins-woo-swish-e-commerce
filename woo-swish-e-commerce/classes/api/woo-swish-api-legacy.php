<?php
/**
 * Woo_Swish_API_Legacy class
 *
 * @class         Woo_Swish_API_Legacy
 * @since         1.7.0
 * @package       Woocommerce_Swish/Classes/Api
 * @category      Class
 * @author        BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Woo_Swish_API_Functions', false)) {
    require_once WCSW_PATH . 'classes/api/woo-swish-api-functions.php';
}

if (!class_exists('Woo_Swish_API_Legacy', false)) {
    class Woo_Swish_API_Legacy extends Woo_Swish_API_Functions
    {

        /**
         * Contains cURL instance
         * @access protected
         */
        public $ch;

        /**
         * Contains a resource data object
         * @access protected
         */
        public $resource_data;
        public $merchant_certificate = null;
        public $private_key_password = null;

        /**
         * __construct function.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            parent::__construct();
            add_action('shutdown', array($this, 'shutdown'));

            $this->merchant_certificate = WC_SEC()->get_option('merchant_certificate');
            $this->private_key_password = WC_SEC()->get_option('private_key_password');
            $this->api_url = 'https://cpc.getswish.net';

            // Instantiate an empty object ready for population
            $this->resource_data = new stdClass();
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

            // Instantiate a new instance
            $this->remote_instance();

            // Set the request params
            $this->set_url($path);

            // Start the request and return the response
            return $this->execute('POST', $form);
        }

        /**
         * put function.
         *
         * Performs an API PUT request
         *
         * @access public
         * @return object
         */
        public function put($path, $form = array())
        {

            // Instantiate a new instance
            $this->remote_instance();

            // Set the request params
            $this->set_url($path);

            // Start the request and return the response
            return $this->execute('PUT', $form);
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

            // Instantiate a new instance
            $this->remote_instance();

            // Set the request params
            $this->set_url($path);

            // Start the request and return the response
            return $this->execute('GET', $path, $params);
        }

        public function additional_curlopt($curlopt_option, $key)
        {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, $key, $curlopt_option);
        }

        /**
         * execute function.
         *
         * Executes the API request
         *
         * @access public
         * @param  string $request_type
         * @param  array  $form
         * @return object
         * @throws Woo_Swish_API_Exception
         */
        public function execute($request_type, $form = array())
        {

            // Require TLS 1.2
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

            // Filter to handle additional curlopt
            if (!empty($curlopt_array = apply_filters('swish_additional_curlopt', array())) && is_array($curlopt_array)) {
                array_walk($curlopt_array, array($this, 'additional_curlopt'));
            }

            // Set the HTTP request type
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $request_type);

            // Prepare to post the data string
            $data_string = json_encode($form, JSON_UNESCAPED_SLASHES);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data_string);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );
            // Execute the request and decode the response to JSON
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Legacy API requires cURL
            $curlResponse = curl_exec($this->ch);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Legacy API requires cURL
            $response_code = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            $response_data = stripslashes($curlResponse);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Legacy API requires cURL
            $curl_request_url = curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Legacy API requires cURL
            $curl_fullinfo = curl_getinfo($this->ch);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- Legacy API requires cURL
            if ($curlError = curl_error($this->ch)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Data parameters are used for internal logging only
                throw new Woo_Swish_API_Exception(esc_html($curlError), 900, null, $curl_request_url, $data_string, $response_data);
            } else {
                $this->resource_data = json_decode($curlResponse);
            }

            // Retrieve the HTTP response code

            // If the HTTP response code is higher than 299, the request failed.
            // Throw an exception to handle the error
            if ($response_code > 299) {
                if ($response_code == 422) {
                    $swish_error = json_decode($curlResponse)[0];
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error code is sanitized by Swish API
                    throw new Woo_Swish_API_Exception(esc_html($swish_error->errorCode) . ' - ' . esc_html(Woo_Swish_Helper::error_code($swish_error->errorCode)), $response_code, null, $curl_request_url, $data_string, $response_data);
                } else {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Response data is sanitized before output
                    throw new Woo_Swish_API_Exception(esc_html($response_data), $response_code, null, $curl_request_url, $data_string, $response_data);
                }
            }

            return $this->resource_data;
        }

        /**
         * set_url function.
         *
         * Takes an API request string and appends it to the API url
         *
         * @access public
         * @return void
         */
        public function set_url($params)
        {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
            curl_setopt($this->ch, CURLOPT_URL, $this->api_url . $params);
        }

        /**
         * remote_instance function.
         *
         * Create a cURL instance if none exists already
         *
         * @access public
         * @return cURL object
         */
        protected function remote_instance()
        {

            if ($this->ch === null) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Legacy API requires cURL
                $this->ch = curl_init();
                //  curl_setopt($this->ch, CURLOPT_HEADER, true);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
                curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
                curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, true);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
                curl_setopt($this->ch, CURLOPT_SSLCERT, $this->merchant_certificate);
                if (strtoupper(substr($this->merchant_certificate, -3)) == 'P12') {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
                    curl_setopt($this->ch, CURLOPT_SSLCERTTYPE, "P12");
                }
                if (strlen($this->private_key_password) > 0) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Legacy API requires cURL
                    curl_setopt($this->ch, CURLOPT_SSLKEYPASSWD, $this->private_key_password);
                }
            }

            return $this->ch;
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
                $order_url = '/swish-cpcapi/api/v1/paymentrequests/' . $order_url;
            }

            $payment = $this->get($order_url, array());
            return $payment;

        }

        /**
         * shutdown function.
         *
         * Closes the current cURL connection
         *
         * @access public
         * @return void
         */

        public function shutdown()
        {
            if (!empty($this->ch)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- Legacy API requires cURL
                curl_close($this->ch);
            }
        }
    }
}
