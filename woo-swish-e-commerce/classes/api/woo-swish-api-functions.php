<?php
/**
 * Woo_Swish_API_Functions class
 *
 * @class         Woo_Swish_API_Functions
 * @since         1.7.0
 * @package       Woocommerce_Swish/Classes/Api
 * @category      Class
 * @author        BjornTech
 */

defined('ABSPATH') || exit;

class Woo_Swish_API_Functions
{

    public $text_on_transaction = null;
    public $customer_on_transaction = false;
    public $merchant_alias = null;
    public $api_url = null;

    public function __construct()
    {
        $this->text_on_transaction = WC_SEC()->get_option('text_on_transaction', "");
        $this->customer_on_transaction = wc_string_to_bool(WC_SEC()->get_option('customer_on_transaction'));
    }

    private function clean_payee_payment_reference($reference)
    {

        return mb_substr(preg_replace('/[^A-Za-z0-9\ \-\_\.\+\*\/]+/', '', $reference), 0, 35);

    }

    /**
     * create function.
     *
     * Creates a new payment via the API
     *
     * @access public
     * @param  WC_Order $order
     * @return object
     * @throws Woo_Swish_API_Exception
     */
    public function create($order, $payer_alias, $payee_alias, $payment_uuid, $callback)
    {

        $order_id = $order->get_id();

        $transaction_textarray = array();
        if ($this->text_on_transaction != '') {
            $transaction_textarray[] = $this->text_on_transaction;
        }

        if ($this->customer_on_transaction) {
            $customer_number = apply_filters('woo_swish_ecommerce_user_id', $order->get_user_id(), $order);
            $transaction_textarray[] = sprintf(__('Customer number %s', 'woo-swish-e-commerce'), $customer_number);
        }

        $transaction_text = mb_substr(preg_replace("/[^a-zA-Z0-9åäöÅÄÖ :;.,?!()]+/", "", implode(', ', $transaction_textarray)), 0, 49);

        $params = array(
            'payeePaymentReference' => (string) apply_filters('swish_payee_payment_reference', $this->clean_payee_payment_reference($order->get_order_number('edit')),$order),
            'callbackUrl' => (string) $callback,
            'payerAlias' => (string) strlen($payer_alias) < 8 ? '4671234768' : $payer_alias,
            'payeeAlias' => (string) $payee_alias,
            'amount' => (string) str_replace(',', '.', $order->get_total()),
            'currency' => (string) $order->get_currency(),
            'message' => (string) apply_filters('swish_payment_message', strlen($payer_alias) < 8 ? $payer_alias : $transaction_text, $order),
        );

        if (false !== ($age_limit = apply_filters('swish_age_limits', false, $order))) {
            $params['ageLimit'] = $age_limit;
        }

        $payment = $this->put('/swish-cpcapi/api/v2/paymentrequests/' . $payment_uuid, $params);

        Woo_Swish_Helper::set_payment_uuid($order, $payment_uuid);
        $order->save();

        return $payment;
    }

    /**
     * refund function.
     *
     * Sends a 'refund' request to the Swish API
     *
     * @access public
     * @param  int $payment_reference
     * @param  int $amount
     * @return void
     * @throws Woo_Swish_API_Exception
     */
    public function refund($payment_reference, $order, $merchant_alias, $amount, $callback, $reason = '')
    {
        if ($amount === null) {
            $amount = $order->get_total();
        }

        $transaction_text = mb_substr(preg_replace("/[^a-zA-Z0-9åäöÅÄÖ :;.,?!()]+/", "", $reason), 0, 49);

        $params = array(
            'payerPaymentReference' => (string) $order->get_order_number(),
            'originalPaymentReference' => (string) $payment_reference,
            'callbackUrl' => (string) $callback,
            'payerAlias' => (string) $merchant_alias,
            'amount' => (string) str_replace(',', '.', $amount),
            'currency' => (string) $order->get_currency(),
            'message' => (string) apply_filters('swish_refund_message', $transaction_text, $order),
        );

        $payment = $this->post('/swish-cpcapi/api/v1/refunds', $params);

        return $payment;
    }

    public function check_for_messsages($params)
    {

        $messages = $this->get('/swish-cpcapi/api/v1/check', $params);
        return $messages;

    }

}
