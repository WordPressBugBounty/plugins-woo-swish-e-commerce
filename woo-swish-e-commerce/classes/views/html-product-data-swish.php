<?php
/**
 * Adds Swish specific fields.
 *
 * @package WooCommerce\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}
echo '<div id="swish_product_data" class="panel woocommerce_options_panel">';

woocommerce_wp_text_input(
    array(
        'id' => '_swish_purchase_age_limit',
        'value' => get_post_meta($product_object->get_id(), '_swish_purchase_age_limit', true),
        'label' => '<abbr title="' . esc_attr__('Enter an age between 1 and 99', 'woo-izettle-integration') . '">' . esc_html__('Age limit', 'woo-izettle-integration') . '</abbr>',
        'desc_tip' => true,
        'data_type' => 'number',
        'description' => __('Set the limit (1-99) required for purchasing this product via Swish. Leave blank or set to 0 for no limit.', 'woo-izettle-integration'),
    )
);

echo '</div>';
