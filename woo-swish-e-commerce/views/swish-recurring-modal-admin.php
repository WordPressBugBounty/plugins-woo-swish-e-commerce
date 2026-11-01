<?php
defined('ABSPATH') || exit;
?>
<div id="swish-recurring-modal" class="swish-modal-admin" style="display: none" role="dialog" aria-modal="true" aria-labelledby="swish-recurring-status">
    <div class="swish-modal-content swish-centered">
        <button type="button" class="swish-close" aria-label="<?php echo esc_attr__('Close', 'woo-swish-e-commerce'); ?>">&times;</button>
        <div class="swish-messages swish-centered">
            <h1 id="swish-recurring-status" aria-live="polite"></h1>
        </div>
        <div class="bjorntech-logo swish-centered">
            <img class="swish-centered" alt="BjornTech" src="<?php echo esc_url($bjorntech_logo); ?>" />
        </div>
    </div>
</div>
