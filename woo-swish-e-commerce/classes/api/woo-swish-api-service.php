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

if (!class_exists('Woo_Swish_API_Connection', false)) {
    require_once WCSW_PATH . 'classes/api/woo-swish-api-connection.php';
}

if (!class_exists('Woo_Swish_API_Service', false)) {
    class Woo_Swish_API_Service extends Woo_Swish_API_Connection
    {

        public function __construct()
        {
            parent::__construct();
            $this->api_url = $this->service_url;
        }

    }

}
