<?php
/**
 * Swish Thankyou code
 */

defined('ABSPATH') || exit;

?>
    <body>
        <div class="swish-messages swish-centered">
            <h1><p id="swish-status"><?php echo esc_html($swish_status); ?></p></h1>
        </div>
        <div class="swish-logo swish-centered">
            <img src="<?php echo esc_url($swish_full_logo); ?>" />
        </div>
        <?php do_action('swish_ecommerce_after_swish_logo', $order_id);?>

        <script>document.addEventListener('DOMContentLoaded', waitForPaymentLegacy);</script>
    </body>
<?php
