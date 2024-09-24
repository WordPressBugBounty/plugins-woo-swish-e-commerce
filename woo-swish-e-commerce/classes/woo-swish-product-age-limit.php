<?php

/**
 * WC_Swish_Product_Age_Limit class
 *
 * @class        WC_Swish_Product_Age_Limit
 * @since        1.0.0
 * @package      Woocommerce_Swish/Classes
 * @author       BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Swish_Product_Age_Limit', false)) {

    class WC_Swish_Product_Age_Limit
    {

        public function __construct()
        {

            add_filter('swish_age_limits', array($this, 'check_product_age_limits'), 2, 10);

        }

        private function get_product_age_limit($item)
        {

            if (($item_id = $item->get_id()) && (false !== ($product = $item->get_product()))) {
                $product_id = $product->get_id();
                $age = get_post_meta($product_id, '_swish_purchase_age_limit', true);
                if ($age) {
                    WC_SEC()->logger->add(sprintf('get_product_age_limit (%s): Age limit is %s', $product_id, $age));
                    return $age;
                } else {
                    WC_SEC()->logger->add(sprintf('get_product_age_limit (%s): No age limit on product', $product_id));
                    return false;
                }
            }

            WC_SEC()->logger->add('get_product_age_limit: No product to check limit on');

            return false;

        }

        public function check_product_age_limits($limit, $order)
        {

            $items = $order->get_items();
            if (false !== $items) {
                $ages = array_diff(array_map(array($this, 'get_product_age_limit'), $items), array(false, '', 0));
                $age = !empty($ages) ? min($ages) : false;
                if ($age && is_numeric($age) && (!$limit || $age < $limit)) {
                    WC_SEC()->logger->add(sprintf('check_product_age_limits: Age limit %s is lower than the previous %s', $age, $limit ?: 'n/a'));
                    return $age;
                }
            }

            return $limit;
        }

    }

    new WC_Swish_Product_Age_Limit();
}
