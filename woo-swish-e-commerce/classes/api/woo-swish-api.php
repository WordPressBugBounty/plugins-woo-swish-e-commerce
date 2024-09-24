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

class Woo_Swish_API
{

    public function create($order, $payer_alias, $payee_alias, $uuid, $callback)
    {
        throw new Woo_Swish_API_Exception("Connection not configured", 900);
    }

    public function refund($payment_reference, $order, $merchant_alias, $amount, $callback, $reason = '')
    {
        throw new Woo_Swish_API_Exception("Connection not configured", 900);
    }

}
