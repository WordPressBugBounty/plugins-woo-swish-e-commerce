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

    private function format_swish_amount($amount)
    {
        $amount = trim((string) $amount);
        $amount = str_replace(',', '.', $amount);
        $amount = preg_replace('/[^0-9.\-]/', '', $amount);

        if ($amount === '' || $amount === '-' || $amount === '.' || $amount === '-.' || $amount === '.-' || strpos($amount, '-') > 0) {
            throw new Woo_Swish_API_Exception(__('Invalid amount for Swish.', 'woo-swish-e-commerce'), 902);
        }

        // If multiple dots exist, treat the last as decimal separator.
        if (substr_count($amount, '.') > 1) {
            $last = strrpos($amount, '.');
            $amount = str_replace('.', '', substr($amount, 0, $last)) . '.' . substr($amount, $last + 1);
        }

        $value = (float) $amount;
        if ($value < 0) {
            throw new Woo_Swish_API_Exception(__('Amount cannot be negative.', 'woo-swish-e-commerce'), 903);
        }

        // Round up to 2 decimals.
        $value = ceil($value * 100) / 100;
        if ($value < 0.01 || $value > 999999999999.99) {
            throw new Woo_Swish_API_Exception(__('Invalid amount for Swish.', 'woo-swish-e-commerce'), 904);
        }

        return number_format($value, 2, '.', '');
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

        $customer_number = apply_filters('woo_swish_ecommerce_user_id', $order->get_user_id(), $order);

        if ($this->text_on_transaction != '') {
            $placeholders = array(
                '{order_number}' => (string) $order->get_order_number(),
                '{customer_number}' => (string) $customer_number,
            );

            $placeholders = apply_filters('woo_swish_transaction_placeholders', $placeholders, $order);

            $transaction_textarray[] = strtr((string) $this->text_on_transaction, $placeholders);
        }

        if ($this->customer_on_transaction) {
            $transaction_textarray[] = sprintf(__('Customer number %s', 'woo-swish-e-commerce'), $customer_number);
        }

        $transaction_text = mb_substr(preg_replace("/[^a-zA-Z0-9åäöÅÄÖ :;.,?!()]+/", "", implode(', ', $transaction_textarray)), 0, 49);

        $amount = apply_filters('woo_swish_format_amount', $order->get_total(), $order);
        if (wc_string_to_bool(WC_SEC()->get_option('swish_format_amount'))) {
            $amount = $this->format_swish_amount($amount);
        }

        $params = array(
            'payeePaymentReference' => (string) apply_filters('swish_payee_payment_reference', $this->clean_payee_payment_reference($order->get_order_number('edit')),$order),
            'callbackUrl' => (string) $callback,
            'payeeAlias' => (string) $payee_alias,
            'amount' => $amount,
            'currency' => (string) $order->get_currency(),
            'message' => (string) apply_filters('swish_payment_message', strlen($payer_alias) < 8 ? $payer_alias : $transaction_text, $order),
        );

        if ($payer_alias != '') {
            $params['payerAlias'] = (string) strlen($payer_alias) < 8 ? '4671234768' : $payer_alias;
            $params['message'] = (string) apply_filters('swish_payment_message', $transaction_text, $order);
        }

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
     * Sends a 'refund' request to the Swish API (v2)
     *
     * @access public
     * @param  string $payment_reference The paymentReference of the original payment
     * @param  WC_Order $order
     * @param  string $merchant_alias The Swish number of the merchant
     * @param  float $amount The amount to refund
     * @param  string $callback HTTPS URL for Swish to notify about the outcome
     * @param  string $instruction_uuid Unique identifier for this refund request
     * @param  string $reason Optional message about the refund
     * @return object
     * @throws Woo_Swish_API_Exception
     */
    public function refund($payment_reference, $order, $merchant_alias, $amount, $callback, $instruction_uuid, $reason = '')
    {
        if ($amount === null) {
            $amount = $order->get_total();
        }

        $transaction_text = mb_substr(preg_replace("/[^a-zA-Z0-9åäöÅÄÖ :;.,?!()]+/", "", $reason), 0, 49);

        $amount_formatted = apply_filters('woo_swish_format_amount', $amount, $order);
        if (wc_string_to_bool(WC_SEC()->get_option('swish_format_amount'))) {
            $amount_formatted = $this->format_swish_amount($amount_formatted);
        }

        $params = array(
            'originalPaymentReference' => (string) $payment_reference,
            'callbackUrl' => (string) $callback,
            'payerAlias' => (string) $merchant_alias,
            'amount' => $amount_formatted,
            'currency' => (string) $order->get_currency(),
        );

        // Optional: Payment reference supplied by the merchant (1-35 chars, alphanumeric)
        $payer_payment_reference = $this->clean_payee_payment_reference($order->get_order_number());
        if (!empty($payer_payment_reference)) {
            $params['payerPaymentReference'] = (string) $payer_payment_reference;
        }

        // Optional: Message about the refund (max 50 chars)
        if (!empty($transaction_text)) {
            $params['message'] = (string) apply_filters('swish_refund_message', $transaction_text, $order);
        }

        // Optional: Callback identifier for extra validation (32-36 alphanumeric chars)
        // Using a generated UUID for each request as recommended by Swish
        $callback_identifier = apply_filters('swish_refund_callback_identifier', wp_generate_uuid4(), $order);
        if (!empty($callback_identifier)) {
            $params['callbackIdentifier'] = (string) $callback_identifier;
        }

        $payment = $this->put('/swish-cpcapi/api/v2/refunds/' . $instruction_uuid, $params);

        return $payment;
    }

    /**
     * cancel function.
     *
     * Cancels an existing payment request via the API
     *
     * @access public
     * @param  string $payment_uuid
     * @param  WC_Order $order
     * @return object
     * @throws Woo_Swish_API_Exception
     */
    public function cancel($payment_uuid, $order = null)
    {
        $params = array(
            array(
                'op' => 'replace',
                'path' => '/status',
                'value' => 'cancelled'
            )
        );

        $payment = $this->patch('/swish-cpcapi/api/v1/paymentrequests/' . $payment_uuid, $params);
        
        // Clear the payment UUID from the order after successful cancellation
        if ($order) {
            Woo_Swish_Helper::set_payment_uuid($order, '');
            $order->save();
        }
        
        return $payment;
    }

    public function check_for_messsages($params)
    {

        $messages = $this->get('/swish-cpcapi/api/v1/check', $params);
        return $messages;

    }

    /**
     * create_qr_code function.
     *
     * Creates a QR code via the Swish API
     *
     * @access public
     * @param  string $token
     * @return string
     * @throws Woo_Swish_API_Exception
     */
    public function create_qr_code($token)
    {
        $params = array(
            'format' => 'svg',
            'token' => $token,
            'border' => 4
        );

        $response = $this->post('/qrg-swish/api/v1/commerce', $params, true);

        return $response;
    }

}
