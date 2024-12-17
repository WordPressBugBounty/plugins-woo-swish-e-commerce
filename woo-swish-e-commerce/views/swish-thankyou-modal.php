<?php
/**
 * Swish Modal admin code
 */

defined('ABSPATH') || exit;

?>
    <div id="swish-modal-id" class="swish-modal">
        <div class="swish-modal-content">
            <div class="swish-close" style="display: none;">
                <span class="swish-close">&times;</span>
            </div>
            <div class="swish-messages swish-centered">
                <h1><p id="swish-status"><?php echo $swish_status; ?></p></h1>
            </div>
            <div class="swish-logo swish-centered">
                <img src="<?php echo $swish_full_logo; ?>" />
            </div>
            <?php do_action('swish_ecommerce_after_swish_logo', $order_id);?>
        </div>
        <script>document.addEventListener('DOMContentLoaded', waitForPaymentModal);</script>
    </div>
<?php
