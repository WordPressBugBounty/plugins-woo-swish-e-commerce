<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Blocks integration for the independently configured Swish subscriptions gateway.
 * Register this class alongside WC_Gateway_Swish_Blocks_Support in blocks_init().
 */
final class WC_Gateway_Swish_Subscriptions_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'swish_subscriptions';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_swish_subscriptions_settings', []);
    }

    private function get_gateway()
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways[$this->name] ?? null;

        return $gateway instanceof WC_Payment_Gateway_Swish_Subscriptions ? $gateway : null;
    }

    public function is_active()
    {
        if (!filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN)
            || !class_exists('WC_Subscriptions_Cart')
            || !WC()->cart
            || !WC_Subscriptions_Cart::cart_contains_subscription()) {
            return false;
        }

        $gateway = $this->get_gateway();

        return $gateway && $gateway->supports('subscriptions') && $gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        // Both integrations use one bundle. WordPress registers/enqueues a handle once.
        $handle = 'wc-swish-payments-blocks';
        $script_asset_path = Swish_Commerce_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : ['dependencies' => [], 'version' => '10.0.0'];

        wp_register_script(
            $handle,
            Swish_Commerce_Payments::plugin_url() . '/assets/js/frontend/blocks.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'woo-swish-e-commerce', Swish_Commerce_Payments::plugin_abspath() . 'languages/');
        }

        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = $this->get_gateway();
        if (!$gateway) {
            return ['available' => false];
        }

        // Core gateway contract: public is_m_payment() and is_q_payment() expose
        // its own checkout settings, not the one-time gateway's settings.
        $is_m_payment = $gateway->is_m_payment();
        $is_q_payment = $gateway->is_q_payment();

        return [
            'available' => $this->is_active(),
            'title' => $gateway->get_option('title', 'Swish recurring payments'),
            'description' => $is_m_payment ? $gateway->get_option('mobile_description', $gateway->get_option('description')) : $gateway->get_option('description'),
            'supports' => $gateway->supports,
            'm_payment' => $is_m_payment || $is_q_payment,
            'placeholder' => $gateway->get_option('number_placeholder', '0731234567'),
            'label' => $gateway->get_option('number_label', __('Swish number', 'woo-swish-e-commerce')),
            'mirror_number' => $gateway->get_option('swish_alias_mirror_billing_phone', '') === 'yes',
            'icon' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Secondary_RGB.png',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ajax_swish'),
            'frontendLogging' => wc_string_to_bool($gateway->get_option('frontend_logging', '')),
        ];
    }
}
