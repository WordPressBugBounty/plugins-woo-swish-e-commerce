jQuery(function ($) {

  $('.swish-close').on('click', function (e) {
    jQuery('.swish-modal').hide();
  });

});


function waitForPayment() {

  jQuery(".entry-title").hide();

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }, function (response) {

    jQuery('#swish-status').html(response['message']);

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {

      if (response['status'] == 'PAID') {
        jQuery(".entry-title").show();
        jQuery(".swish-completed").show();
        jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      } else {
        window.location.href = response['redirect_url'];
      }

      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo.split('?')[0]);
      return;

    } else if (response['status'] === undefined) {
      console.log('waitForPayment');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPayment() }, 1000);

  });

}

function waitForPaymentLegacy() {

  var data = {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }

  jQuery.post(swish.ajaxurl, data, function (response) {

    jQuery('#swish-status').html(response['message']);

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {
      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo);
      jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      return;
    } else if (response['status'] === undefined) {
      console.log('waitForPaymentLegacy');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPaymentLegacy() }, 3000);

  })

}

function waitForPaymentModal() {

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }, function (response) {

    jQuery('#swish-status').html(response['message']);
    jQuery('.swish-modal').show();
    jQuery(".entry-title").hide();

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {

      if (response['status'] == 'PAID') {
        jQuery('.swish-modal').hide();
        jQuery(".entry-title").show();
        jQuery('.woocommerce-thankyou-order-received').text(response['message']);
      } else {
        window.location.replace(response['redirect_url']);
      }

      jQuery('.swish-notwaiting').hide();
      jQuery("#swish-logo-id").attr("src", swish.logo);
      jQuery(".swish-close").show();
      return;

    } else if (response['status'] === undefined) {
      console.log('waitForPaymentModal');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPaymentModal() }, 1000)

  })

}

function waitForPaymentSeparateInternal() {

  jQuery.post(swish.ajaxurl, {
    action: 'wait_for_payment',
    url: window.location.href,
    nonce: swish.nonce
  }, function (response) {

    jQuery('#swish-status').html(response['message']);
    jQuery('.swish-modal').show();
    jQuery(".entry-title").hide();

    if ((response['status'] !== undefined) && (response['status'] != 'WAITING')) {

      if (response['status'] == 'PAID') {
        //jQuery('.swish-modal').hide();
        //jQuery(".entry-title").show();
        //jQuery('.woocommerce-thankyou-order-received').text(response['message']);

        setTimeout(swishRedirectToCheckout, 1000 ,response['redirect_url']);

      } else {
        window.location.replace(response['redirect_url']);
      }

      jQuery('.swish-notwaiting').hide();
      //jQuery("#swish-logo-id").attr("src", swish.logo);
      jQuery(".swish-close").show();
      return;

    } else if (response['status'] === undefined) {
      console.log('waitForPaymentModal');
      console.log(swish);
      console.log(response);
    }

    setTimeout(function () { waitForPaymentSeparateInternal() }, 1000)

  })

}

function swishRedirectToCheckout($url) {
  window.location.replace($url);
}


