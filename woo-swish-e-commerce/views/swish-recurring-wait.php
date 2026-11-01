<?php
/** Swish automatic-payment authorization waiting content. */
defined('ABSPATH') || exit;
?>
<div class="swish-recurring-wait swish-messages-internal swish-centered-internal">
    <h1 class="swish-status-new"><?php esc_html_e('Authorize automatic Swish payments', 'woo-swish-e-commerce'); ?></h1>
    <div class="swish-logo swish-centered-internal swish-logo-internal">
        <img src="<?php echo esc_url(WCSW_URL . 'assets/images/Swish_Logo_Primary_RGB.png'); ?>" alt="Swish Logo" />
    </div>
    <p class="swish-recurring-instructions">
        <?php if ($qr_url !== '') : ?>
            <?php esc_html_e('Open your Swish app and scan the QR code to authorize recurring payments.', 'woo-swish-e-commerce'); ?>
        <?php else : ?>
            <?php esc_html_e('Open your Swish app to authorize recurring payments.', 'woo-swish-e-commerce'); ?>
        <?php endif; ?>
    </p>
    <?php if ($url !== '' && $qr_url === '') : ?>
        <div class="swish-button-internal">
            <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Open Swish to authorize automatic payments', 'woo-swish-e-commerce'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($qr_url !== '') : ?>
        <div id="swish-recurring-qr" role="img" aria-label="<?php esc_attr_e('Swish consent QR', 'woo-swish-e-commerce'); ?>" data-swish-qr-url="<?php echo esc_attr($qr_url); ?>"></div>
    <?php endif; ?>
    <p id="swish-recurring-status" role="status" aria-live="polite"><?php esc_html_e('Waiting for Swish authorization and payment…', 'woo-swish-e-commerce'); ?></p>
</div>
