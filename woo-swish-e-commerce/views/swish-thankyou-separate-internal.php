<?php
/**
 * Swish Thankyou code
 */

defined('ABSPATH') || exit;

?>
    <body style="display: flex; justify-content: center; align-items: center; margin: 0; padding: 0; height: 100%;">
        <div class="swish_container">
            <div class="swish-messages-internal swish-centered-internal">
                <h1><p class="swish-status-new" id="swish-status"><?php echo $swish_status; ?></p></h1>
                    <div class="swish-circle swish-centered-internal swish-circle-internal">
                        <img class="swish-loader swish-centered-internal" src="<?php echo $swish_circle; ?>" />
                    </div>
                    <div class="swish-logo swish-centered-internal swish-logo-internal">
                        <img id="swish-logo-id" class="swish-centered-internal" src="<?php echo $swish_logo_text; ?>" />
                    </div>
                    <?php do_action('swish_ecommerce_after_swish_logo', $order_id);?>
            </div>

            <div class="swish-completed" style="display: none;"></div>
        </div>
    </body>
<?php
