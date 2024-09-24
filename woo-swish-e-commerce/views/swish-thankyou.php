<?php
/**
 * Swish Thankyou code
 */

defined('ABSPATH') || exit;

?>
    <body>
        <div class="swish-messages swish-centered">
            <h1><p id="swish-status"><?php echo $swish_status; ?></p></h1>
        </div>
        <div class="swish-circle swish-centered">
            <img class="swish-loader swish-notwaiting swish-centered" src="<?php echo $swish_circle; ?>" />
        </div>
        <div class="swish-logo swish-centered">
            <img id="swish-logo-id" class="swish-centered" src="<?php echo $swish_logo_text; ?>" />
        </div>
        <?php do_action('swish_ecommerce_after_swish_logo', $order_id);?>

        <div class="swish-completed" style="display: none;">
            <?php do_action('woocommerce_thankyou', $order_id);?>
            <?php remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);?>
        </div>

        <script>document.addEventListener('DOMContentLoaded', waitForPayment);</script>
    </body>
<?php
