<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Swish Payments Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Gateway_Swish_Blocks_Support extends AbstractPaymentMethodType
{

    public function __construct()
    {
        
    }

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'swish';

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_swish_settings' , []);
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {
        $handle = 'wc-swish-payments-blocks';
        $script_url = Swish_Commerce_Payments::plugin_url() . '/assets/js/frontend/blocks.js';
        $script_asset_path = Swish_Commerce_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';

        $script_asset = file_exists($script_asset_path)
        ? require $script_asset_path
        : array(
            'dependencies' => array(),
            'version' => '10.0.0',
        );

        $result = wp_register_script(
            $handle,
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'woocommerce-gateway-swish', Swish_Commerce_Payments::plugin_abspath() . 'languages/');
        }

        return [$handle];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        $redirect_back = $this->get_setting('swish_redirect_back', 'yes');
        $improved_mobile_detection = $this->get_setting('swish_improved_mobile_detection', 'yes');
        $qr_enabled = $this->get_setting('swish_qr_enabled', '');
        $checkout_type = $this->get_setting('swish_checkout_type', 'seperate_internal_v2');
        $react_wait_page = $this->get_setting('swish_enable_react_wait_page', 'yes');

        $exclude_fb_insta = ($checkout_type === 'seperate_internal_v2' && $react_wait_page == 'yes');
        $is_m_payment = Woo_Swish_Helper::is_m_payment($redirect_back, $improved_mobile_detection, $exclude_fb_insta);
        $is_q_payment = Woo_Swish_Helper::is_q_payment($qr_enabled, $improved_mobile_detection, $checkout_type, $react_wait_page);

        return [
            'title' => $this->get_setting('title'),
            'description' => $is_m_payment ? $this->get_setting('mobile_description') : $this->get_setting('description'),
            'supports' => $this->get_supported_features(),
            'payeeAlias' => $this->get_setting('merchant_alias'),
            'm_payment' => $is_m_payment || $is_q_payment,
            'placeholder' => $this->get_setting('number_placeholder', '0731234567'),
            'label' => $this->get_setting('number_label'),
            'mirror_number' => $this->get_setting('swish_alias_mirror_billing_phone', '') === 'yes',
            'logoText' => WCSW_URL . 'assets/images/Swish_Logo_Text.png',
            'fullLogo' => WCSW_URL . 'assets/images/Swish_Logo_Primary_RGB.png',
            'logoImage' => WCSW_URL . 'assets/images/Swish_Logo_Circle.png',
            'icon' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Secondary_RGB.png',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ajax_swish'),
            'frontendLogging' => wc_string_to_bool($this->get_setting('frontend_logging', '')),
        ];
    }
}
