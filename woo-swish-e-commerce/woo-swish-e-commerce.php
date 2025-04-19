<?php

/**
 * Plugin Name: Woo Swish e-commerce
 *
 * Plugin URI: https://wordpress.org/plugins/woo-swish-e-commerce/
 * Description: Integrates <a href="https://www.getswish.se/foretag/vara-erbjudanden/#foretag_two" target="_blank">Swish e-commerce</a> into your WooCommerce installation.
 * Version: 3.7.4
 * Author: BjornTech
 * Author URI: https://bjorntech.com/sv/swish-handel?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product
 *
 * Text Domain: woo-swish-e-commerce
 *
 * WC requires at least: 4.0
 * WC tested up to: 9.8
 *
 * Copyright:         2018-2020 BjornTech AB
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 */

defined('ABSPATH') || exit;

define('WCSW_VERSION', '3.7.4');
define('WCSW_URL', plugins_url(__FILE__));
define('WCSW_PATH', plugin_dir_path(__FILE__));
define('WCSW_SERVICE_URL', 'swish.finnvid.net/v1');

class Swish_Commerce_Payments
{

    /**
     * $instance.
     *
     * @var mixed
     * @access public
     * @static
     */
    public static $instance = null;

    public static $initialized = false;

    public static function init()
    {
        // Swish Payments gateway class.
        add_action('plugins_loaded', array(__CLASS__, 'includes'), 200);

        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'blocks_init'));

        // Make the Swish Payments gateway available to WC.
        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_payment_gateway'));

        // Add action links.
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'add_action_links'));

        // Declare HPOS compatible
        add_action('before_woocommerce_init', array(__CLASS__, 'declare_hpos_compatible'));

        // Upgrade hook
        add_action('upgrader_process_complete', array(__CLASS__, 'swish_upgrade_completed'), 10, 2);

        // Activation hook
        register_activation_hook(__FILE__, array(__CLASS__, 'woocommerce_swish_integration_activate'));

    }

    /*public static function circumvent_cache() {

        add_action('litespeed_init', array(__CLASS__, 'circumvent_litespeed_cache'));

        add_action('template_redirect', array(__CLASS__, 'circumvent_404'));
    }

    public static function circumvent_litespeed_cache() {
        Woo_Swish_Helper::circumvent_litespeed_cache();
    }

    public static function circumvent_404() {
        Woo_Swish_Helper::circumvent_404();
    }*/


    /**
     * get_instance.
     *
     * Returns a new instance of self, if it does not already exist.
     *
     * @access public
     * @static
     * @return WC_Payment_Gateway_Swish
     */
    public static function get_instance()
    {

        if (null === self::$instance) {
            require_once WCSW_PATH . 'classes/woo-swish-payment-gateway.php';
            self::$instance = new WC_Payment_Gateway_Swish();
            if (did_action('init')) {
                self::$instance->hooks_and_filters();
            } else {
                add_action('init', function() {
                    self::$instance->hooks_and_filters();
                }, 200);
            }
        }

        return self::$instance;

    }

    public static function add_payment_gateway($methods)
    {
        $methods[] = 'WC_Payment_Gateway_Swish';
        return $methods;
    }

    /**
     * add_action_links function.
     *
     * Adds action links inside the plugin overview
     *
     * @access public static
     * @return array
     */
    public static function add_action_links($links)
    {
        $links = array_merge(array(
            '<a href="' . static::get_settings_page_url() . '">' . __('Settings', 'woo-swish-e-commerce') . '</a>',
        ), $links);

        return $links;
    }

    /**
     * Returns the link to the gateway settings page.
     *
     * @return mixed
     */
    public static function get_settings_page_url()
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=swish');
    }

    /*public static function blocks_init()
    {
        self::includes(true);
    }*/

    public static function blocks_init()
    {
        require_once WCSW_PATH . 'classes/blocks/class-wc-swish-payments-blocks.php';
        // Registers WooCommerce Blocks integration.
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_Swish_Blocks_Support());
                }
            );
        }
    }

    public static function includes()
    {

        if (!class_exists('WooCommerce')) {
            return;
        }

        require_once WCSW_PATH . 'classes/woo-swish-uuid.php';
        require_once WCSW_PATH . 'classes/api/woo-swish-api.php';
        require_once WCSW_PATH . 'classes/api/woo-swish-api-service.php';
        require_once WCSW_PATH . 'classes/api/woo-swish-api-test.php';
        require_once WCSW_PATH . 'classes/api/woo-swish-api-legacy.php';
        require_once WCSW_PATH . 'classes/woo-swish-exception.php';
        require_once WCSW_PATH . 'classes/woo-swish-log.php';
        require_once WCSW_PATH . 'classes/woo-swish-helper.php';
        require_once WCSW_PATH . 'classes/woo-swish-settings.php';
        require_once WCSW_PATH . 'classes/woo-swish-notices.php';
        require_once WCSW_PATH . 'classes/woo-swish-product-config.php';
        require_once WCSW_PATH . 'classes/woo-swish-mobile-detection.php';
        if (class_exists('WC_Payment_Gateway')) {
            self::get_instance();
        }

    }
    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_url()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_abspath()
    {
        return trailingslashit(plugin_dir_path(__FILE__));
    }

    public static function declare_hpos_compatible()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Activation activities to be performed then the plugin is activated
     */
    public static function woocommerce_swish_integration_activate()
    {

        /**
         * Log the activation time in a transient
         */
        set_site_transient('swish_activation_time', date('c'));

        /**
         * Set transient to always force the plugin to ask for credentials when activated
         */
        set_site_transient('swish_activated', 1);
        delete_site_transient('swish_activated_or_upgraded');

    }

    /**
     * Upgrade activities to be performed when the plugin is upgraded
     */
    public static function swish_upgrade_completed($upgrader_object, $options)
    {
        $our_plugin = plugin_basename(__FILE__);

        if ($options['action'] == 'update' && $options['type'] == 'plugin' && isset($options['plugins'])) {
            foreach ($options['plugins'] as $plugin) {
                if ($plugin == $our_plugin) {

                    /**
                     * Log the activation time in a transient
                     */
                    set_site_transient('swish_upgraded_time', date('c'));

                    /**
                     * Set transient to always force the plugin to ask for credentials when activated
                     */
                    set_site_transient('swish_upgraded', 1);

                    /**
                     * Delete transient containing the date for activation or upgrade
                     */
                    delete_site_transient('swish_activated_or_upgraded');
                }
            }
        }
    }

}

Swish_Commerce_Payments::init();

/**
 * Make the object available for later use
 *
 * @return WC_Payment_Gateway_Swish
 */
function WC_SEC()
{
    return Swish_Commerce_Payments::get_instance();
}
