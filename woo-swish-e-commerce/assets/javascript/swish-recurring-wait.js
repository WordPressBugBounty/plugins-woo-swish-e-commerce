(function () {
    'use strict';
    if (!window.swishRecurringWait) return;
    var config = window.swishRecurringWait;
    var label = document.getElementById('swish-recurring-status');
    if (!label) return;
    var stopped = false;
    function poll() {
        if (stopped) return;
        var body = new URLSearchParams({ action: 'swish_subscriptions_status', order_id: String(config.order), key: config.key });
        fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: body, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result.success) return;
                if (result.data.paid) {
                    stopped = true;
                    label.textContent = 'Swish payment confirmed.';
                    if (result.data.redirect) {
                        // A stale wait view can survive navigation or be served
                        // on order-received. Never reload the confirmed page.
                        var destination = new URL(result.data.redirect, window.location.href);
                        var current = new URL(window.location.href);
                        destination.hash = '';
                        current.hash = '';
                        if (destination.href !== current.href) window.location.assign(result.data.redirect);
                    }
                } else if (['DELETED', 'TIMEOUT', 'DECLINED', 'FAILED', 'MANUAL_REVIEW'].indexOf(result.data.status) !== -1) {
                    stopped = true;
                    label.textContent = 'Swish authorization or payment was not completed. Contact the shop for help.';
                    if (result.data.status !== 'MANUAL_REVIEW' && result.data.redirect) window.location.replace(result.data.redirect);
                } else {
                    label.textContent = 'Waiting for Swish authorization and payment…';
                    // Check the current outcome before launching: a stale view
                    // must not reopen Swish after payment or cancellation.
                    if (config.autoOpen && config.appUrl && !config.appOpened && result.data.authorizationPending === true) {
                        config.appOpened = true;
                        window.location.assign(config.appUrl);
                    }
                }
            })
            .catch(function () { /* Keep waiting for network recovery. */ })
            .finally(function () { if (!stopped) window.setTimeout(poll, 5000); });
    }
    poll();
}());
