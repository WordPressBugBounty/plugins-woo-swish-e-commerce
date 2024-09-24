<?php

/**
 * WC_Swish_Product_Tab class
 *
 * @class        WC_Swish_Product_Tab
 * @since        1.0.0
 * @package      Woocommerce_Swish/Classes
 * @author       BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Swish_Product_Tab', false)) {

    class WC_Swish_Product_Tab
    {
        public function __construct()
        {
            /**
             * WooCommerce actions and filters for the gui functions
             */

            add_action('woocommerce_product_data_panels', array($this, 'show_swish_fields'), 10);
            add_action('woocommerce_process_product_meta', array($this, 'save_product'), 10, 2);
            add_filter('woocommerce_product_data_tabs', array($this, 'product_data_tab'), 50, 1);

        }

        public function show_swish_fields()
        {
            global $post, $thepostid, $product_object;
            include 'views/html-product-data-swish.php';
        }

        public function save_product($product_id, $post)
        {

            update_post_meta($product_id, '_swish_purchase_age_limit', isset($_POST['_swish_purchase_age_limit']) ? wc_clean(wp_unslash($_POST['_swish_purchase_age_limit'])) : '');

        }

        public function product_data_tab($tabs)
        {
            $tabs['swish'] = array(
                'label' => __('Swish', 'woo-swish-integration'),
                'target' => 'swish_product_data',
                'class' => array('show_if_simple', 'show_if_variable'),
            );

            return $tabs;
        }

    }

    new WC_Swish_Product_Tab;
}
