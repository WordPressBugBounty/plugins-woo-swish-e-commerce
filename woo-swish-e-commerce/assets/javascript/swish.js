jQuery(function ($) {

  $('.swish-close').on('click', function (e) {
    jQuery('.swish-modal').hide();
  });

});

/**
 * Swish Frontend Logger
 * 
 * Sends log messages to the backend when frontend logging is enabled.
 * Falls back to console.log when logging is disabled.
 */
var SwishLogger = (function() {
  
  /**
   * Send a log message to the backend
   * 
   * @param {string} message - The log message
   * @param {string} level - Log level (info, warning, error)
   * @param {string} context - Context identifier (e.g., 'checkout', 'wait-page')
   */
  function sendLog(message, level, context) {
    // Check if frontend logging is enabled
    if (typeof swish === 'undefined' || !swish.frontendLogging) {
      // Fall back to console.log when logging is disabled
      console.log('[Swish:' + (context || 'frontend') + '] ' + message);
      return;
    }

    jQuery.post(swish.ajaxurl, {
      action: 'swish_frontend_log',
      nonce: swish.nonce,
      message: message,
      level: level || 'info',
      context: context || 'swish.js'
    });
  }

  return {
    /**
     * Log an info message
     * @param {string} message - The log message
     * @param {string} context - Optional context identifier
     */
    log: function(message, context) {
      sendLog(message, 'info', context);
    },

    /**
     * Log a warning message
     * @param {string} message - The log message
     * @param {string} context - Optional context identifier
     */
    warn: function(message, context) {
      sendLog(message, 'warning', context);
    },

    /**
     * Log an error message
     * @param {string} message - The log message
     * @param {string} context - Optional context identifier
     */
    error: function(message, context) {
      sendLog(message, 'error', context);
    }
  };
})();


function waitForPayment() {

  jQuery(".entry-title").hide();

  SwishLogger.log('waitForPayment: Polling for payment status', 'checkout');

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }, function (response) {

    jQuery('#swish-status').html(response['message']);

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {

      SwishLogger.log('waitForPayment: Payment status changed to ' + response['status'], 'checkout');

      if (response['status'] == 'PAID') {
        SwishLogger.log('waitForPayment: Payment completed successfully', 'checkout');
        jQuery(".entry-title").show();
        jQuery(".swish-completed").show();
        jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      } else {
        SwishLogger.log('waitForPayment: Redirecting to ' + response['redirect_url'], 'checkout');
        window.location.href = response['redirect_url'];
      }

      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo.split('?')[0]);
      return;

    } else if (response['status'] === undefined) {
      SwishLogger.warn('waitForPayment: Unexpected response - status undefined', 'checkout');
      console.log('waitForPayment');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPayment() }, 1000);

  });

}

function waitForPaymentLegacy() {

  SwishLogger.log('waitForPaymentLegacy: Polling for payment status', 'checkout-legacy');

  var data = {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }

  jQuery.post(swish.ajaxurl, data, function (response) {

    jQuery('#swish-status').html(response['message']);

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {
      SwishLogger.log('waitForPaymentLegacy: Payment status changed to ' + response['status'], 'checkout-legacy');
      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo);
      jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      return;
    } else if (response['status'] === undefined) {
      SwishLogger.warn('waitForPaymentLegacy: Unexpected response - status undefined', 'checkout-legacy');
      console.log('waitForPaymentLegacy');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPaymentLegacy() }, 3000);

  })

}

function waitForPaymentModal() {

  SwishLogger.log('waitForPaymentModal: Polling for payment status', 'checkout-modal');

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }, function (response) {

    jQuery('#swish-status').html(response['message']);
    jQuery('.swish-modal').show();
    jQuery(".entry-title").hide();

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {

      SwishLogger.log('waitForPaymentModal: Payment status changed to ' + response['status'], 'checkout-modal');

      if (response['status'] == 'PAID') {
        SwishLogger.log('waitForPaymentModal: Payment completed successfully', 'checkout-modal');
        jQuery('.swish-modal').hide();
        jQuery(".entry-title").show();
        jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      } else {
        SwishLogger.log('waitForPaymentModal: Redirecting to ' + response['redirect_url'], 'checkout-modal');
        window.location.replace(response['redirect_url']);
      }

      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo);
      jQuery(".swish-close").show();
      return;

    } else if (response['status'] === undefined) {
      SwishLogger.warn('waitForPaymentModal: Unexpected response - status undefined', 'checkout-modal');
      console.log('waitForPaymentModal');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPaymentModal() }, 1000)

  })

}

function waitForPaymentSeparateInternal() {

  SwishLogger.log('waitForPaymentSeparateInternal: Polling for payment status', 'checkout-separate');

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }).done(function (response) {
    try {
      SwishLogger.log('waitForPaymentSeparateInternal: Response received', 'checkout-separate');

      jQuery('#swish-status').html(response['message']);
      jQuery('.swish-modal').show();
      jQuery(".entry-title").hide();

      if ((response['status'] !== undefined) && (response['status'] != 'WAITING') && (response['status'] != 'CREATED')) {

        SwishLogger.log('waitForPaymentSeparateInternal: Payment status changed to ' + response['status'], 'checkout-separate');

        if (response['status'] == 'PAID') {
          SwishLogger.log('waitForPaymentSeparateInternal: Payment completed, redirecting to checkout', 'checkout-separate');
          //jQuery('.swish-modal').hide();
          //jQuery(".entry-title").show();
          //jQuery('.woocommerce-thankyou-order-received').text(response['message']);

          setTimeout(swishRedirectToCheckout, 1000 ,response['redirect_url']);

        } else {
          SwishLogger.log('waitForPaymentSeparateInternal: Redirecting to ' + response['redirect_url'], 'checkout-separate');
          window.location.replace(response['redirect_url']);
        }

        jQuery('.swish-notwaiting').hide();
        //jQuery("#swish-logo-id").attr("src", swish.logo);
        jQuery(".swish-close").show();
        return;

      } else if (response['status'] === undefined) {
        SwishLogger.warn('waitForPaymentSeparateInternal: Unexpected response - status undefined', 'checkout-separate');
        console.log('waitForPaymentModal');
        console.log(swish);
        console.log(response);
      }

      setTimeout(function () { waitForPaymentSeparateInternal() }, 1000);

    } catch (error) {
      SwishLogger.error('waitForPaymentSeparateInternal: Error processing response - ' + error.message, 'checkout-separate');
      console.error('waitForPaymentSeparateInternal error:', error);
      // Continue polling despite the error
      setTimeout(function () { waitForPaymentSeparateInternal() }, 1000);
    }

  }).fail(function (jqXHR, textStatus, errorThrown) {
    SwishLogger.error('waitForPaymentSeparateInternal: AJAX request failed - ' + textStatus + ': ' + errorThrown, 'checkout-separate');
    console.error('waitForPaymentSeparateInternal AJAX error:', textStatus, errorThrown);
    // Continue polling despite the error
    setTimeout(function () { waitForPaymentSeparateInternal() }, 1000);
  });

}

function swishRedirectToCheckout($url) {
  window.location.replace($url);
}


