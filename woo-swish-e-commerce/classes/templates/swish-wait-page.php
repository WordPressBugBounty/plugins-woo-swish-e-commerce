<?php
/**
 * Template Name: Custom Wait Page Template
 */

 ?>

<!DOCTYPE html>
<html style="height: 100%;" <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php 
        // Register and enqueue stylesheets
        wp_register_style('swish-ecommerce', WCSW_URL . 'assets/stylesheets/swish.css', array(), WC_SEC()->version);
        wp_enqueue_style('swish-ecommerce');

        // Register and enqueue scripts
        wp_register_script('waiting-for-swish-callback', WCSW_URL . 'assets/javascript/swish.js', array('jquery'), WC_SEC()->version);
        wp_enqueue_script('waiting-for-swish-callback');

        // Localize the script
        wp_localize_script('waiting-for-swish-callback', 'swish', array(
            'logo' => WCSW_URL . 'assets/images/Swish_Logo_Primary_Light-BG_SVG.svg',
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ajax_swish'),
            'message' => __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce')
        ));
    ?>
    <script>

        function initSwish() {

            if (typeof waitForPaymentSeparateInternal !== 'function') {
                setTimeout(initSwish, 1000);
                console.log('waitForPaymentSeparateInternal does not exist - waiting...');
                return;
            }

            console.log('waitForPaymentSeparateInternal exists - calling...');

            if (document.readyState !== 'loading') {
                console.log('Document is ready - calling waitForPaymentSeparateInternal');
                waitForPaymentSeparateInternal();
            } else {
                console.log('Document is not ready - adding event listener');
                document.addEventListener('DOMContentLoaded', waitForPaymentSeparateInternal);
            }
        }

        initSwish();
    </script>

    <?php wp_head(); ?>
</head>

<?php


WC_SEC()->logger->add('Using template for internal wait page');

echo do_shortcode('[bjorntech_swish_wait_page]');

?>