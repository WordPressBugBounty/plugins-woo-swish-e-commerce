jQuery(function ($) {
  var modal = $('#swish-recurring-modal')
  var button = $('#swish-recurring-connect')
  var disconnect = $('#swish-recurring-disconnect')
  var activeButton = button
  var timer
  var generation = 0

  function close() {
    generation++
    clearTimeout(timer)
    modal.hide()
    activeButton.removeAttr('aria-disabled').trigger('focus')
  }

  function show(message) {
    $('#swish-recurring-status').text(message)
    modal.show()
  }

  function request(action, data, current) {
    var nonce = action === 'swish_subscriptions_disconnect' ? swish_recurring_admin.disconnect_nonce : swish_recurring_admin.nonce
    $.post(ajaxurl, $.extend({ action: action, _wpnonce: nonce }, data), function (response) {
      if (current !== generation) return
      if (!response || !response.data) {
        show(swish_recurring_admin.error)
        return
      }
      show(response.data.message || swish_recurring_admin.error)
      if (!response.success) return
      if (response.data.status === 'success') {
        window.location.reload()
      } else if (response.data.status === 'waiting') {
        timer = setTimeout(function () { request('swish_subscriptions_connection_status', {}, current) }, 1000)
      }
    }).fail(function () {
      if (current === generation) show(swish_recurring_admin.error)
    })
  }

  button.on('click', function (event) {
    event.preventDefault()
    if (button.attr('aria-disabled')) return
    activeButton = button
    button.attr('aria-disabled', 'true')
    show(swish_recurring_admin.starting)
    modal.find('button').trigger('focus')
    request('swish_subscriptions_connect', {
      merchant_alias: $('#woocommerce_swish_subscriptions_merchant_alias').val(),
      user_email: $('#woocommerce_swish_subscriptions_swish_user_email').val()
    }, ++generation)
  })
  disconnect.on('click', function (event) {
    event.preventDefault()
    if (disconnect.attr('aria-disabled') || !window.confirm(swish_recurring_admin.disconnect)) return
    activeButton = disconnect
    disconnect.attr('aria-disabled', 'true')
    clearTimeout(timer)
    show(swish_recurring_admin.disconnecting)
    modal.find('button').trigger('focus')
    request('swish_subscriptions_disconnect', {}, ++generation)
  })
  modal.find('.swish-close').on('click', close)
  modal.on('keydown', function (event) {
    if (event.key === 'Escape') close()
    if (event.key === 'Tab') {
      event.preventDefault()
      modal.find('button').trigger('focus')
    }
  })
})
