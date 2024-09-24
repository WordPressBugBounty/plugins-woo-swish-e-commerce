var show_admin_modal = false

jQuery(function ($) {
  $(document).ready(function() {
    var selected = $('#woocommerce_swish_connection_type').val();
    if (selected) { $('.' + selected).show(); }
  });

  $('#woocommerce_swish_btn_connect').on('click', function (e) {
    e.preventDefault()
    var merchant_alias = $('#woocommerce_swish_merchant_alias')
    var user_email = $('#woocommerce_swish_swish_user_email')
    $.post(ajaxurl, { action: 'connect_swish_service', merchant_alias: merchant_alias.val(), user_email: user_email.val(), nonce: swish_admin.nonce }, function (response) {
      if (response.result == 'error') {
        alert(response.message)
      } else if (response.result == 'success') {
        show_admin_modal = true
        waitForAdmin()
      } else if (response.result == 'not_connected') {
        show_admin_modal = true
        waitForAdmin(response.message)
      }
    })
  })

  $('#woocommerce_swish_btn_disconnect').on('click', function (e) {
    if (confirm(swish_admin.disconnect)) {
      e.preventDefault()
      $.post(ajaxurl, { action: 'disconnect_swish_service', nonce: swish_admin.nonce }, function (response) {
        window.location.reload()
      })
    }
  })

  $('#swish_retrieve_button').on('click', function (e) {
    e.preventDefault()
    $.post(ajaxurl, { action: 'swish_retrieve_transaction', name: e.target.name, nonce: swish_admin.nonce }, function (response) {
      window.location.reload()
    })
  })

  $('.sw-notice').on('click', '.notice-dismiss', function (e) {
    console.log(e)
    var is_sw_notice = $(e.target).parents('div').hasClass('sw-notice')
    if (is_sw_notice) {
      var parents = $(e.target).parent().prop('className')
      console.log(parents)
      $.post(ajaxurl, { action: 'swish_clear_notice', nonce: swish_admin.nonce, parents: parents }, function (response) { })
    }
  })

  $('#woocommerce_swish_connection_type').on('change', function (e) {
    $('.swishcontent').hide()
    var selected = $('#woocommerce_swish_connection_type').val()
    if (selected) { $('.' + selected).show() }
  })

  $(window).load(function () {
    $('.swishcontent').hide()
    var selected = $('#woocommerce_swish_connection_type').val()
    if (selected) { $('.' + selected).show() }
  })

  $('.swish-close').on('click', function (e) {
    var modal = document.getElementById('swish-modal-admin-id')
    if (modal) { modal.style.display = 'none' }
    show_admin_modal = false
  })
})

function waitForAdmin(show_error = false) {
  if (show_admin_modal) {
    var modal = document.getElementById('swish-modal-admin-id')
    if (modal) { modal.style.display = 'block' }

    if (show_error) {
      document.getElementById('swish-logo-id').style.display = 'none';
      document.getElementById('swish-status').innerHTML = show_error;
      return;
    }

    jQuery.post(ajaxurl, { 'action': 'wait_for_admin', 'nonce': swish_admin.nonce }, function (response) {
      var message = response.message
      document.getElementById('swish-status').innerHTML = message

      if (response.status == 'success') {
        var modal = document.getElementById('swish-modal-admin-id')
        if (modal) { modal.style.display = 'none' }
        show_admin_modal = false
        window.location.reload()
        return;
      } else if (response.status == 'failure') {
        return;
      } else {
        setTimeout(function () { waitForAdmin() }, 1000)
      }
    })
  }
}
