<?php
/**
 * Swish Thankyou code
 */

defined('ABSPATH') || exit;

?>
    <body style="display: flex; justify-content: center; align-items: center; margin: 0; padding: 0; height: 100%;">
        <div class="swish_container">
            <div class="swish-messages-internal swish-centered-internal">
                <h1 class="swish-status-new" id="swish-status"><?php echo $swish_status; ?></h1>
                <div class="swish-logo swish-centered-internal swish-logo-internal">
                    <img src="<?php echo $swish_full_logo; ?>" alt="Swish Logo" />
                </div>
                <?php do_action('swish_ecommerce_after_swish_logo', $order_id);?>
            </div>

            <div class="swish-completed" style="display: none;"></div>
        </div>
    </body>
<?php
