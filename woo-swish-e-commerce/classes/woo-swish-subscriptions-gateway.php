<?php
/** Automatic Swish recurring payments, isolated from the one-off Swish gateway. */
defined('ABSPATH') || exit;

/** Do not let inherited service diagnostics write bearer or refresh credentials to logs. */
class Woo_Swish_Recurring_Log extends Woo_Swish_Log
{
    public function add($message)
    {
        if (is_array($message) || is_object($message)) {
            $message = wp_json_encode($message);
        }
        $message = preg_replace('/(Bearer\s+)[^"\\\\\s]+/i', '$1[redacted]', (string) $message);
        $message = preg_replace('/(refresh_token(?:\\\\"|"|\\\\\":|":|\s*=>\s*))[^,}\s]+/i', '$1[redacted]', $message);
        $message = preg_replace('/("token"\s*:\s*")[^"]+/i', '$1[redacted]', $message);
        parent::add($message);
    }
}

class WC_Payment_Gateway_Swish_Subscriptions extends WC_Payment_Gateway
{
    const PREFIX = '_swish_recurring_';
    private static $registered = false;
    private static $authorized_renewals = array();
    private static $renewal_dispatch_count = 0;
    private static $renewal_dispatch_sequence = 0;
    private static $completing_children = array();
    private static $revoking_children = array();
    private static $leases = array();
    private static $refund_objects = array();
    public $logger;
    public $account_uuid;
    public $service_url;
    public $connection_type;
    public $merchant_alias;
    public $version;

    public function __construct()
    {
        $this->id = 'swish_subscriptions';
        $this->method_title = __('Swish recurring payments', 'woo-swish-e-commerce');
        $this->method_description = __('Automatic recurring Swish payments with a separate Swish Handel account.', 'woo-swish-e-commerce');
        $this->has_fields = true;
        $this->icon = WC_HTTPS::force_https_url(Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Secondary_Light-BG_SVG.svg');
        $this->supports = array('products', 'subscriptions', 'multiple_subscriptions', 'subscription_cancellation', 'subscription_amount_changes', 'refunds');
        $this->version = WCSW_VERSION;
        $this->init_form_fields();
        $this->init_settings();
        // Older recurring installs stored the internal host as an explicit value.
        // Show it as automatic without changing the effective endpoint or order context.
        if (isset($this->settings['swish_service_url']) && $this->settings['swish_service_url'] === WCSW_SERVICE_URL) {
            $this->settings['swish_service_url'] = '';
        }
        $this->init_form_fields();
        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', 'Swish recurring payments');
        $this->description = $this->get_option('description', 'Authorize automatic payments in Swish.');
        $this->logger = new Woo_Swish_Recurring_Log(!wc_string_to_bool($this->get_option('debug_log', 'no')));
        $this->connection_type = $this->get_option('connection_type', '_service');
        $this->merchant_alias = $this->connection_type === '_test' ? '1234679304' : $this->get_option('merchant_alias', '');
        $this->service_url = self::service_host($this->get_option('swish_service_url', ''));
        $this->account_uuid = $this->get_option('swish_account_uuid', '');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_swish_subscriptions', array($this, 'thankyou'));
    }

    public function init_form_fields()
    {
        $connected = $this->get_option('swish_refresh_token', '') !== '';
        $this->form_fields = array(
            'enabled' => array('title' => __('Enable', 'woo-swish-e-commerce'), 'type' => 'checkbox', 'label' => __('Enable Swish recurring payments', 'woo-swish-e-commerce'), 'default' => 'no'),
            'connect_info' => array('type' => 'title', 'title' => __('Select your preferred way to connect', 'woo-swish-e-commerce'), 'description' => __('Automatic recurring Swish payments require a separate Swish Handel account and a separate BjornTech technical supplier connection.', 'woo-swish-e-commerce')),
            'connection_type' => array('title' => __('Connection type', 'woo-swish-e-commerce'), 'type' => 'select', 'options' => array('_service' => __('BjornTech as Technical Supplier', 'woo-swish-e-commerce'), '_test' => __('Swish Simulator', 'woo-swish-e-commerce')), 'default' => '_service'),
            'merchant_alias' => array('title' => __('Your Swish-number', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('The Swish-number to receive payments. The number must be a Swish Handel account. A private Swish-number or a Swish Corporate number does not work.', 'woo-swish-e-commerce') . ' ' . __('A separate Handel number enabled for recurring payments is required.', 'woo-swish-e-commerce')),
            'swish_user_email' => array('title' => __('Account mail address', 'woo-swish-e-commerce'), 'type' => 'email', 'description' => __('The address where to send the confirmation mail address', 'woo-swish-e-commerce')),
            'service_setup' => array('type' => 'title', 'title' => $connected ? sprintf(__('You are using Swish handel "%s" with BjornTech as Technical Supplier', 'woo-swish-e-commerce'), esc_html($this->get_option('merchant_alias', ''))) : __('Connect to BjornTech as Technical Supplier', 'woo-swish-e-commerce'), 'description' => $connected ? sprintf(__('Swish Authorization Token: %s', 'woo-swish-e-commerce'), esc_html($this->get_option('swish_account_uuid', ''))) : ''),
            'shop_setup' => array('type' => 'title', 'title' => __('Shop setup', 'woo-swish-e-commerce')),
            'title' => array('title' => __('Title', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('This is the title which the user sees during checkout.', 'woo-swish-e-commerce'), 'default' => 'Swish recurring payments'),
            'number_label' => array('title' => __('Mobile number label', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('This is the label for the field where the customer enters their Swish (mobile) number.', 'woo-swish-e-commerce'), 'default' => __('Swish number', 'woo-swish-e-commerce')),
            'number_placeholder' => array('title' => __('Mobile number placeholder', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('This is the placeholder for the field where the customer enters their Swish (mobile) number.', 'woo-swish-e-commerce'), 'default' => '0731234567'),
            'description' => array('title' => __('Customer Message', 'woo-swish-e-commerce'), 'type' => 'textarea', 'description' => __('This is the description which the user sees during checkout.', 'woo-swish-e-commerce'), 'default' => 'Authorize automatic payments in Swish.'),
            'swish_redirect_back' => array('title' => __('Open Swish on mobile', 'woo-swish-e-commerce'), 'type' => 'checkbox', 'label' => __('Open Swish on mobile', 'woo-swish-e-commerce'), 'default' => 'yes'),
            'swish_qr_enabled' => array('title' => __('Show QR on desktop', 'woo-swish-e-commerce'), 'type' => 'checkbox', 'label' => __('Show QR on desktop', 'woo-swish-e-commerce'), 'default' => 'no'),
            'debug_log' => array('title' => __('Enable debug-log', 'woo-swish-e-commerce'), 'type' => 'checkbox', 'label' => __('Turn on logging for debug purposes.', 'woo-swish-e-commerce'), 'default' => 'no'),
            'show_advanced_options' => array('title' => __('Advanced options', 'woo-swish-e-commerce'), 'type' => 'checkbox', 'label' => __('Do NOT use unless instructed by BjornTech.', 'woo-swish-e-commerce'), 'default' => ''),
        );
        if (wc_string_to_bool($this->get_option('show_advanced_options', ''))) {
            $this->form_fields['swish_service_url'] = array('title' => __('BjornTech url', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('Do NOT use unless instructed by BjornTech.', 'woo-swish-e-commerce'), 'default' => '');
            $this->form_fields['swish_account_uuid'] = array('title' => __('BjornTech account UUID', 'woo-swish-e-commerce'), 'type' => 'text', 'description' => __('Provided by the separate BjornTech technical supplier connection.', 'woo-swish-e-commerce'));
            $this->form_fields['swish_refresh_token'] = array('title' => __('BjornTech refresh token', 'woo-swish-e-commerce'), 'type' => 'password', 'description' => __('Keep private. Use the separate recurring account token; never reuse the one-off account.', 'woo-swish-e-commerce'));
        }
    }

    /** Keep host/path identity stable for existing recurring orders; consumers prepend HTTPS. */
    public static function service_host($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return WCSW_SERVICE_URL;
        }
        if (stripos($value, 'https://') === 0) {
            $value = substr($value, 8);
        }
        if (!preg_match('~^[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[a-z0-9._/%\~-]+)*$~iD', $value) || strpos($value, '..') !== false) {
            throw new InvalidArgumentException('A valid HTTPS BjornTech URL is required.');
        }
        return rtrim($value, '/');
    }

    /** Generate the connection action only while rendering the admin form, not on checkout/renewals. */
    public function generate_title_html($key, $data)
    {
        if ($key === 'service_setup') {
            $connected = $this->get_option('swish_refresh_token', '') !== '';
            $action = $connected ? 'swish_subscriptions_disconnect' : 'swish_subscriptions_connect';
            $url = wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action);
            $text = $connected ? __('Disconnect', 'woo-swish-e-commerce') : __('Connect separate recurring Swish Handel account', 'woo-swish-e-commerce');
            $id = $connected ? 'swish-recurring-disconnect' : 'swish-recurring-connect';
            $data['description'] = ($data['description'] ? $data['description'] . '<br>' : '') . '<a id="' . $id . '" class="button" href="' . esc_url($url) . '">' . esc_html($text) . '</a>';
        }
        return parent::generate_title_html($key, $data);
    }

    /** Display saved legacy host/path values as URLs without changing their stored identity. */
    public function generate_text_html($key, $data)
    {
        if ($key !== 'swish_service_url') {
            return parent::generate_text_html($key, $data);
        }
        $saved = $this->get_option($key, '');
        $this->settings[$key] = $saved === '' ? '' : 'https://' . self::service_host($saved);
        try {
            return parent::generate_text_html($key, $data);
        } finally {
            $this->settings[$key] = $saved;
        }
    }

    public function process_admin_options()
    {
        $key = $this->get_field_key('swish_service_url');
        if (isset($_POST[$key])) {
            try {
                $host = self::service_host(sanitize_text_field(wp_unslash($_POST[$key])));
                $_POST[$key] = $host === WCSW_SERVICE_URL ? '' : $host;
            } catch (InvalidArgumentException $error) {
                $this->add_error(__('A valid HTTPS BjornTech URL is required.', 'woo-swish-e-commerce'));
                return false;
            }
        }
        return parent::process_admin_options();
    }

    public static function register_hooks()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action('woocommerce_api_swish_subscriptions', array(__CLASS__, 'callback'));
        add_action('woocommerce_api_swish_subscriptions_wait', array(__CLASS__, 'wait_handler'));
        add_action('woocommerce_api_swish_subscriptions_admin', array(__CLASS__, 'connection_callback'));
        add_action('admin_post_swish_subscriptions_connect', array(__CLASS__, 'connect_account'));
        add_action('wp_ajax_swish_subscriptions_connect', array(__CLASS__, 'connect_account'));
        add_action('admin_post_swish_subscriptions_disconnect', array(__CLASS__, 'disconnect_account'));
        add_action('wp_ajax_swish_subscriptions_disconnect', array(__CLASS__, 'disconnect_account'));
        add_action('wp_ajax_swish_subscriptions_connection_status', array(__CLASS__, 'connection_status'));
        add_action('wp_ajax_swish_subscriptions_status', array(__CLASS__, 'browser_status'));
        add_action('wp_ajax_nopriv_swish_subscriptions_status', array(__CLASS__, 'browser_status'));
        add_action('woocommerce_scheduled_subscription_payment_swish_subscriptions', array(__CLASS__, 'scheduled_payment'), 10, 2);
        add_action('woocommerce_scheduled_subscription_payment', array(__CLASS__, 'authorize_renewal'), -10, 1);
        add_action('all', array(__CLASS__, 'begin_renewal_dispatch'), PHP_INT_MIN, 2);
        add_action('woocommerce_scheduled_subscription_payment', array(__CLASS__, 'clear_renewal_authorization'), PHP_INT_MAX, 1);
        add_action('action_scheduler_failed_execution', array(__CLASS__, 'prune_renewal_authorizations'));
        add_action('action_scheduler_failed_validation', array(__CLASS__, 'prune_renewal_authorizations'));
        add_filter('woocommerce_subscription_payment_gateway_supports', array(__CLASS__, 'internal_status_support'), 10, 3);
        add_action('swish_subscriptions_reconcile', array(__CLASS__, 'reconcile_order'));
        add_action('swish_subscriptions_reconcile_consent', array(__CLASS__, 'reconcile_consent'), 10, 3);
        add_filter('woocommerce_order_actions', array(__CLASS__, 'admin_order_actions'), 10, 2);
        add_action('woocommerce_order_action_swish_subscriptions_reconcile', array(__CLASS__, 'admin_reconcile'));
        add_action('woocommerce_order_action_swish_subscriptions_retry_failed', array(__CLASS__, 'admin_retry_failed'));
        add_action('woocommerce_order_action_swish_subscriptions_reconcile_refund', array(__CLASS__, 'admin_reconcile_refund'));
        add_action('woocommerce_create_refund', array(__CLASS__, 'prepare_wc_refund'), 10, 2);
        add_action('woocommerce_refund_created', array(__CLASS__, 'finalize_wc_refund'), 10, 2);
        add_action('swish_subscriptions_revoke', array(__CLASS__, 'reconcile_revocation'));
        add_filter('wc_subscriptions_renewal_order_data', array(__CLASS__, 'strip_copy_data'), 10, 3);
        add_filter('wc_subscriptions_subscription_data', array(__CLASS__, 'strip_copy_data'), 10, 3);
        add_action('woocommerce_subscription_status_updated', array(__CLASS__, 'subscription_status_changed'), 10, 3);
        add_action('woocommerce_subscription_payment_method_updated', array(__CLASS__, 'payment_method_changed'), 10, 3);
    }

    private static function gateway()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['swish_subscriptions']) ? $gateways['swish_subscriptions'] : new self();
    }

    private function api()
    {
        return new Woo_Swish_API_Recurring($this);
    }

    /** Refund a charge, never a consent or a subscription container. MSS has no service refund route. */
    public function can_refund_order($order)
    {
        if (!$order || $order->get_payment_method() !== $this->id || $order->get_type() !== 'shop_order' || $order->get_currency() !== 'SEK' || (float) $order->get_total() <= 0 || $this->connection_type !== '_service' || !$this->get_option('swish_refresh_token') || !self::meta($order, 'secret') || self::meta($order, 'payment_state') !== 'PAID' || !preg_match('/^[A-F0-9]{32}$/D', (string) self::meta($order, 'payment_id'))) {
            return false;
        }
        try {
            $this->bind_context($order);
            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    /** Reserve under lock using WC's displayed accounting snapshot, before gateway execution. */
    public static function prepare_wc_refund($refund, $args)
    {
        $order = wc_get_order($args['order_id']);
        if (empty($args['refund_payment']) || !$order || $order->get_payment_method() !== 'swish_subscriptions') {
            return;
        }
        unset(self::$refund_objects[$order->get_id()]);
        $lock = self::lock($order->get_id());
        if (!$lock) {
            throw new RuntimeException('Another Swish operation is running. Try again later.');
        }
        try {
            $order = wc_get_order($order->get_id());
            $order->read_meta_data(true); // A worker may have loaded metadata before waiting for the lock.
            $gateway = self::gateway();
            if (!$gateway->can_refund_order($order)) {
                throw new RuntimeException(__('This order cannot be refunded via Swish. Check that it is paid and uses the original Swish account.', 'woo-swish-e-commerce'));
            }
            $pending = self::meta($order, 'refund_pending');
            // Core AJAX checks this snapshot BEFORE acquiring our lock. Check it again
            // here: two workers can both pass core's check before either accounts.
            // Non-AJAX integrations must explicitly supply the same stable snapshot.
            $snapshot = $args['swish_refunded_amount'] ?? (isset($_POST['action']) && $_POST['action'] === 'woocommerce_refund_line_items' ? ($_POST['refunded_amount'] ?? null) : null);
            if (!is_scalar($snapshot) || !is_numeric($snapshot) || !is_finite((float) $snapshot) || (float) $snapshot < 0) {
                throw new RuntimeException(__('Use Refund via Swish on the order to request this refund.', 'woo-swish-e-commerce'));
            }
            $snapshot = number_format((float) $snapshot, 2, '.', '');
            $amount = $refund->get_amount();
            if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0.01 || abs((float) $amount - round((float) $amount, 2)) > 0.000001) {
                throw new RuntimeException('Use a positive refund amount with at most two decimals.');
            }
            $amount = number_format((float) $amount, 2, '.', '');
            if ($pending) {
                if ($pending['state'] === 'ERROR') {
                    throw new RuntimeException(__('A previous Swish refund failed after it was accepted. Contact support to review it before refunding again.', 'woo-swish-e-commerce'));
                }
                if (!isset($pending['operation'], $pending['request'])) {
                    throw new RuntimeException(__('A previous Swish refund needs review. Contact support before refunding again.', 'woo-swish-e-commerce'));
                }
                if ($amount !== $pending['amount']) {
                    throw new RuntimeException(sprintf(__('A Swish refund of SEK %s is still unresolved. Retry the same amount to check its outcome.', 'woo-swish-e-commerce'), $pending['amount']));
                }
                $existing = !empty($pending['refund_id']) ? wc_get_order($pending['refund_id']) : false;
                if ($existing && $existing->get_id() !== $refund->get_id()) {
                    // WC saved its paid flag but the association hook may have
                    // been interrupted. Recover that association, not another
                    // accounting record or transfer. Never replay WC/WCS hooks.
                    if (self::finish_refund_accounting($order, $existing)) {
                        throw new RuntimeException(__('The previous Swish refund is already recorded. Reload the order and check its refund history. No new refund was sent.', 'woo-swish-e-commerce'));
                    }
                    self::note_refund_review($order, $pending, 'Existing WooCommerce refund has not completed accounting.');
                    throw new RuntimeException(__('A previous Swish refund needs review. Contact support before refunding again.', 'woo-swish-e-commerce'));
                }
                // A changed/forgotten reason must not strand reconciliation or rewrite accounting.
                $refund->set_reason($pending['reason']);
            } else {
                self::assert_refund_ledger($order);
                // WC already persisted calculated totals, but has NOT saved the
                // final negative requested total. CPT sums _refund_amount; HPOS
                // sums persisted total_amount (possibly zero or line-item totals).
                // Exclude the provisional ID, never subtract an in-memory amount.
                $accounted = self::refund_accounting_total($order->get_id(), $refund->get_id());
                if ($snapshot !== number_format($accounted, 2, '.', '')) {
                    throw new RuntimeException(__('The order’s refunds have changed. Reload the order and check its refund history before refunding again.', 'woo-swish-e-commerce'));
                }
                foreach ((array) (self::meta($order, 'refunds') ?: array()) as $entry) {
                    if (isset($entry['snapshot']) && $snapshot === $entry['snapshot']) {
                        throw new RuntimeException(__('This refund is already recorded. Reload the order and check its refund history before refunding again.', 'woo-swish-e-commerce'));
                    }
                }
                $refunded = 0;
                foreach ((array) (self::meta($order, 'refunds') ?: array()) as $entry) {
                    $refunded += (float) $entry['amount'];
                }
                if (round($refunded + (float) $amount, 2) > round((float) $order->get_total(), 2)) {
                    throw new RuntimeException('Refund amount exceeds the remaining original Swish charge.');
                }
                $pending = array('id' => Woo_Swish_Helper::generate_production_id($gateway->merchant_alias), 'operation' => strtoupper(bin2hex(random_bytes(16))), 'snapshot' => $snapshot, 'amount' => $amount, 'reason' => (string) $refund->get_reason(), 'state' => 'PREPARED');
                $pending['request'] = $gateway->api()->refund_parameters(self::meta($order, 'payment_id'), $order, $gateway->merchant_alias, $amount, self::callback_url($order, 'refund'), $pending['reason']);
                if (!isset($pending['request']['amount']) || !is_numeric($pending['request']['amount']) || (float) $pending['request']['amount'] !== (float) $amount) {
                    throw new RuntimeException(__('The refund amount could not be confirmed. No refund was sent.', 'woo-swish-e-commerce'));
                }
            }
            $pending['refund_id'] = $refund->get_id();
            $refund->update_meta_data(self::PREFIX . 'refund_operation_id', $pending['operation']);
            $refund->update_meta_data(self::PREFIX . 'refund_instruction_id', $pending['id']);
            self::assert_owned($order);
            $order->delete_meta_data(self::PREFIX . 'refund_operation'); // Retire legacy start-action authorization.
            self::save_meta($order, array('refund_pending' => $pending));
            self::$refund_objects[$order->get_id()] = $refund;
        } finally {
            self::unlock($lock);
        }
    }

    /** Match the authoritative WC store's aggregate, excluding this provisional ID. */
    private static function refund_accounting_total($order_id, $provisional_id)
    {
        global $wpdb;
        if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
            // Same fields/sign as OrdersTableDataStore::get_total_refunded().
            $total = $wpdb->get_var($wpdb->prepare("SELECT -SUM(total_amount) FROM {$table} WHERE type = %s AND parent_order_id = %d AND id <> %d", 'shop_order_refund', $order_id, $provisional_id));
        } else {
            // Same fields as WC_Order_Data_Store_CPT::get_total_refunded().
            $total = $wpdb->get_var($wpdb->prepare("SELECT SUM(postmeta.meta_value) FROM {$wpdb->postmeta} AS postmeta INNER JOIN {$wpdb->posts} AS posts ON (posts.post_type = %s AND posts.post_parent = %d) WHERE postmeta.meta_key = %s AND postmeta.post_id = posts.ID AND posts.ID <> %d", 'shop_order_refund', $order_id, '_refund_amount', $provisional_id));
        }
        if ($wpdb->last_error || ($total !== null && (!is_numeric($total) || !is_finite((float) $total)))) {
            throw new RuntimeException(__('Could not check the order’s refund history. Please try again. No new Swish refund was sent.', 'woo-swish-e-commerce'));
        }
        return (float) $total;
    }

    /** Bound to a WC refund object, not an amount/reason fingerprint or an unbound API call. */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $lock = self::lock($order_id);
        if (!$lock) {
            return new WP_Error('swish_refund_busy', __('Another Swish operation is running. Try again later.', 'woo-swish-e-commerce'));
        }
        try {
            $order = self::fresh_order($order_id);
            if ($order) {
                $order->read_meta_data(true);
            }
            if (!$this->can_refund_order($order)) {
                throw new RuntimeException(__('This order cannot be refunded via Swish. Check that it is paid and uses the original Swish account.', 'woo-swish-e-commerce'));
            }
            $wc_refund = self::$refund_objects[$order_id] ?? null;
            $pending = self::meta($order, 'refund_pending');
            if (!$wc_refund || !$wc_refund->get_id() || !$pending || !isset($pending['operation'], $pending['request']) || self::meta($wc_refund, 'refund_operation_id') !== $pending['operation'] || self::meta($wc_refund, 'refund_instruction_id') !== $pending['id'] || (!empty($pending['refund_id']) && (int) $pending['refund_id'] !== $wc_refund->get_id()) || !is_numeric($amount) || number_format((float) $amount, 2, '.', '') !== $pending['amount']) {
                throw new RuntimeException(__('Use Refund via Swish on the order to request this refund.', 'woo-swish-e-commerce'));
            }
            if (empty($pending['refund_id'])) {
                self::assert_owned($order);
                $pending['refund_id'] = $wc_refund->get_id();
                self::save_meta($order, array('refund_pending' => $pending));
            }
            if (!empty($pending['accepted']) || $pending['state'] === 'PAID') {
                return true; // Initiated but not accounted: never send another instruction.
            }
            $api = $this->api();
            try {
                $refund = $api->retreive('https://cpc.getswish.net/swish-cpcapi/api/v1/refunds/' . $pending['id']);
            } catch (Woo_Swish_API_Exception $error) {
                if ($error->getCode() !== 404) {
                    throw $error; // Timeouts, authorization errors and outages never authorize a write.
                }
                // Authoritative absence permits only the exact saved PUT under the SAME ID.
                $payment = $api->get_payment(self::meta($order, 'payment_id'));
                if (!isset($payment->paymentId, $payment->status) || $payment->paymentId !== self::meta($order, 'payment_id') || $payment->status !== 'PAID') {
                    throw new RuntimeException('Swish did not confirm the original paid charge. No refund was sent.');
                }
                self::assert_owned($order);
                $pending['state'] = 'UNCERTAIN';
                self::save_meta($order, array('refund_pending' => $pending));
                try {
                    $api->put('/swish-cpcapi/api/v2/refunds/' . $pending['id'], $pending['request']);
                    // Like the ordinary gateway, successful PUT means initiated, not PAID.
                    // The bridge's response metadata is not an authoritative settlement status.
                    $pending['accepted'] = true;
                    $pending['state'] = 'ACCEPTED';
                    self::assert_owned($order);
                    self::save_meta($order, array('refund_pending' => $pending));
                    return true;
                } catch (Woo_Swish_API_Exception $error) {
                    if ($error->getCode() >= 400 && $error->getCode() < 500 && !in_array($error->getCode(), array(408, 409), true)) {
                        self::assert_owned($order);
                        self::save_meta($order, array('refund_pending' => ''));
                        $order->add_order_note(sprintf(__('Swish refund request rejected: %s', 'woo-swish-e-commerce'), $error->getMessage()));
                    }
                    if ($error->getCode() !== 409) {
                        throw $error;
                    }
                }
                $refund = $api->retreive('https://cpc.getswish.net/swish-cpcapi/api/v1/refunds/' . $pending['id']);
            }
            if (!$this->save_refund_status($order, $pending, $refund)) {
                throw new RuntimeException('Swish refund failed: ' . ($refund->errorCode ?? 'ERROR'));
            }
            return true;
        } catch (Throwable $error) {
            return new WP_Error('swish_recurring_refund', $error->getMessage());
        } finally {
            self::unlock($lock);
        }
    }

    private function save_refund_status($order, $pending, $refund)
    {
        if (!isset($refund->id, $refund->originalPaymentReference, $refund->payerAlias, $refund->amount, $refund->currency, $refund->status) || $refund->id !== $pending['id'] || $refund->originalPaymentReference !== self::meta($order, 'payment_id') || (string) $refund->payerAlias !== $this->merchant_alias || $refund->currency !== 'SEK' || !is_numeric($refund->amount) || number_format((float) $refund->amount, 2, '.', '') !== $pending['amount']) {
            self::note_refund_review($order, $pending, 'Provider refund response does not match the saved refund identity, original payment, merchant, currency or amount.');
            throw new RuntimeException(__('Could not verify the Swish refund. Contact support before refunding again.', 'woo-swish-e-commerce'));
        }
        $known = in_array($refund->status, array('CREATED', 'DEBITED', 'PAID', 'ERROR'), true);
        if (!$known && empty($pending['accepted']) && $pending['state'] !== 'PAID') {
            self::note_refund_review($order, $pending, 'Provider returned an unrecognized status before refund acceptance.');
            throw new RuntimeException(__('Could not confirm the Swish refund. Contact support before refunding again.', 'woo-swish-e-commerce'));
        }
        self::assert_owned($order);
        if ($refund->status === 'ERROR' && empty($pending['accepted']) && $pending['state'] !== 'PAID') {
            self::save_meta($order, array('refund_pending' => ''));
            $order->add_order_note('Swish refund failed: ' . ($refund->errorCode ?? 'ERROR'));
            return false; // Verified unsuccessful operation, not an unchecked callback failure.
        }
        // Terminal outcomes cannot be downgraded by a delayed notification/GET.
        if (in_array($pending['state'], array('PAID', 'ERROR'), true) && $pending['state'] !== $refund->status) {
            self::note_refund_review($order, $pending, 'Provider response conflicts with the saved final refund status.');
            throw new RuntimeException(__('Could not confirm the Swish refund. Contact support before refunding again.', 'woo-swish-e-commerce'));
        }
        $previous = $pending['provider_status'] ?? '';
        $pending['accepted'] = true;
        $pending['provider_status'] = $refund->status;
        $pending['state'] = in_array($refund->status, array('PAID', 'ERROR'), true) ? $refund->status : ($known ? 'SETTLEMENT_PENDING' : 'SETTLEMENT_UNKNOWN');
        if ($refund->status === 'ERROR') {
            $pending['error_code'] = $refund->errorCode ?? 'ERROR';
        }
        $ledger = (array) (self::meta($order, 'refunds') ?: array());
        $accounted = false;
        foreach ($ledger as &$entry) {
            if ($entry['id'] === $pending['id']) {
                $entry = $pending;
                $accounted = true;
                break;
            }
        }
        unset($entry);
        self::save_meta($order, $accounted ? array('refunds' => $ledger) : array('refund_pending' => $pending));
        if ($previous !== $refund->status) {
            if ($refund->status === 'DEBITED') {
                $order->add_order_note('Merchant account debited - Transaction ID: ' . $pending['id']);
            } elseif ($refund->status === 'PAID') {
                $order->add_order_note('Refund to customer confirmed - Transaction ID: ' . $pending['id']);
            } elseif ($refund->status === 'ERROR') {
                $order->add_order_note('Swish refund ' . $pending['id'] . ' failed after accepted initiation: ' . $pending['error_code'] . '. WooCommerce refund accounting and its hooks were not undone. Investigate and resolve the existing refund record; do not send another transfer blindly.');
            } elseif (!$known) {
                $order->add_order_note('Swish refund ' . $pending['id'] . ' initiation was accepted, but settlement status is unknown: ' . $refund->status . '. Existing WooCommerce accounting is unchanged; investigate the original instruction, not another transfer.');
            }
        }
        return true;
    }

    /** WC calls this only after saving refunded_payment and completing refund accounting hooks. */
    public static function finalize_wc_refund($refund_id, $args)
    {
        $refund = wc_get_order($refund_id);
        if (empty($args['refund_payment']) || !$refund || !self::meta($refund, 'refund_operation_id')) {
            return;
        }
        $order_id = $refund->get_parent_id();
        $lock = self::lock($order_id);
        if (!$lock) {
            return; // Keep the durable accepted reservation; a callback/retry can associate it.
        }
        try {
            $order = wc_get_order($order_id);
            $order->read_meta_data(true);
            self::finish_refund_accounting($order, $refund);
        } finally {
            unset(self::$refund_objects[$order_id]);
            self::unlock($lock);
        }
    }

    private static function finish_refund_accounting($order, $refund)
    {
        $pending = self::meta($order, 'refund_pending');
        if (!$pending || (empty($pending['accepted']) && $pending['state'] !== 'PAID') || !$refund || $refund->get_type() !== 'shop_order_refund' || $refund->get_parent_id() !== $order->get_id() || !$refund->get_refunded_payment() || (int) $pending['refund_id'] !== $refund->get_id() || self::meta($refund, 'refund_operation_id') !== ($pending['operation'] ?? '') || self::meta($refund, 'refund_instruction_id') !== $pending['id'] || number_format((float) $refund->get_amount(), 2, '.', '') !== $pending['amount'] || (string) $refund->get_reason() !== $pending['reason']) {
            return false;
        }
        self::assert_owned($order);
        $ledger = (array) (self::meta($order, 'refunds') ?: array());
        $ledger[] = $pending;
        $order->delete_meta_data(self::PREFIX . 'refund_operation');
        self::save_meta($order, array('refunds' => $ledger, 'refund_pending' => ''));
        $order->add_order_note('Swish refund ' . $pending['id'] . ' initiation accepted and associated with WooCommerce refund #' . $refund->get_id() . '. Settlement status: ' . $pending['state'] . '.');
        return true;
    }

    /** A deleted/failed accounting record must not turn accepted initiation into new spending. */
    private static function assert_refund_ledger($order)
    {
        foreach ((array) (self::meta($order, 'refunds') ?: array()) as $entry) {
            if ($entry['state'] === 'ERROR') {
                throw new RuntimeException(__('A previous Swish refund failed after it was accepted. Contact support to review it before refunding again.', 'woo-swish-e-commerce'));
            }
            $refund = !empty($entry['refund_id']) ? wc_get_order($entry['refund_id']) : false;
            if (!$refund || $refund->get_type() !== 'shop_order_refund' || $refund->get_parent_id() !== $order->get_id() || !$refund->get_refunded_payment() || self::meta($refund, 'refund_operation_id') !== ($entry['operation'] ?? '') || self::meta($refund, 'refund_instruction_id') !== $entry['id'] || number_format((float) $refund->get_amount(), 2, '.', '') !== $entry['amount'] || (string) $refund->get_reason() !== $entry['reason']) {
                self::note_refund_review($order, $entry, 'Accepted refund has missing or inconsistent WooCommerce accounting. Check the refund record, type, parent order, refunded-payment flag, operation/instruction metadata, amount and reason. Repair the original record/association; do not create another transfer.');
                throw new RuntimeException(__('A previous Swish refund could not be matched to the order’s refund history. Contact support before refunding again.', 'woo-swish-e-commerce'));
            }
        }
    }

    /** Private diagnostic, without request data, callback secrets or customer details. */
    private static function note_refund_review($order, $entry, $detail)
    {
        $note = 'Swish refund review: instruction ' . $entry['id'] . ', WooCommerce refund #' . (int) ($entry['refund_id'] ?? 0) . '. ' . $detail;
        $fingerprint = hash('sha256', $note);
        if (self::meta($order, 'refund_review_diagnostic') !== $fingerprint) {
            self::assert_owned($order);
            $order->add_order_note($note);
            self::save_meta($order, array('refund_review_diagnostic' => $fingerprint));
        }
    }

    public function is_m_payment()
    {
        return $this->get_option('swish_redirect_back', 'yes') === 'yes' && function_exists('wp_is_mobile') && wp_is_mobile();
    }

    public function is_q_payment()
    {
        return !$this->is_m_payment() && $this->get_option('swish_qr_enabled') === 'yes';
    }

    public function is_available()
    {
        if (!class_exists('WC_Subscriptions_Cart') || !function_exists('wcs_get_subscriptions_for_order') || !parent::is_available() || get_woocommerce_currency() !== 'SEK' || !is_ssl()) {
            return false;
        }
        if (!$this->get_option('swish_refresh_token') || !$this->account_uuid || !preg_match('/^123[0-9]{7}$/D', $this->merchant_alias)) {
            return false;
        }
        // Let an initial failed checkout retry authorization/payment on order-pay.
        // Renewals remain exclusively charged by WCS, never from the browser.
        if (is_wc_endpoint_url('order-pay')) {
            $order = wc_get_order(absint(get_query_var('order-pay')));
            $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
            return $order && $key && hash_equals((string) $order->get_order_key(), $key) && $order->get_payment_method() === $this->id && $order->get_currency() === 'SEK' && $order->needs_payment() && !self::meta($order, 'parent_id') && !self::meta($order, 'completed') && !self::meta($order, 'manual_resolution') && !self::meta($order, 'completion_claim') && (bool) wcs_get_subscriptions_for_order($order, array('order_type' => 'parent'));
        }
        if ((function_exists('wcs_cart_contains_renewal') && wcs_cart_contains_renewal()) || (function_exists('wcs_cart_contains_resubscribe') && wcs_cart_contains_resubscribe()) || (function_exists('wcs_cart_contains_switches') && wcs_cart_contains_switches('any')) || (function_exists('wcs_cart_contains_early_renewal') && wcs_cart_contains_early_renewal())) {
            return false;
        }
        return WC_Subscriptions_Cart::cart_contains_subscription();
    }

    public function payment_fields()
    {
        echo wp_kses_post(wpautop($this->description));
        if ($this->is_m_payment() || $this->is_q_payment()) {
            return;
        }
        echo '<p class="form-row form-row-wide"><label for="swish-subscriptions-payer-alias">' . esc_html($this->get_option('number_label', __('Swish number', 'woo-swish-e-commerce'))) . ' <span class="required">*</span></label><input class="input-text" id="swish-subscriptions-payer-alias" name="swish-subscriptions-payer-alias" type="tel" autocomplete="tel" placeholder="' . esc_attr($this->get_option('number_placeholder', '0731234567')) . '" /></p>';
    }

    private static function phone($value)
    {
        $value = preg_replace('/\D/', '', (string) $value);
        if (strlen($value) === 9 && $value[0] === '7') {
            $value = '0' . $value;
        }
        if (substr($value, 0, 1) === '0') {
            $value = '46' . substr($value, 1);
        }
        return preg_match('/^[0-9]{8,15}$/D', $value) ? $value : '';
    }

    public function validate_fields()
    {
        if ($this->is_m_payment() || $this->is_q_payment()) {
            return true;
        }
        if (self::phone(isset($_POST['swish-subscriptions-payer-alias']) ? sanitize_text_field(wp_unslash($_POST['swish-subscriptions-payer-alias'])) : '')) {
            return true;
        }
        wc_add_notice(__('Enter a valid Swish number.', 'woo-swish-e-commerce'), 'error');
        return false;
    }

    private static function meta($object, $name)
    {
        return $object->get_meta(self::PREFIX . $name, true);
    }

    private static function save_meta($object, $values)
    {
        foreach ($values as $key => $value) {
            $object->update_meta_data(self::PREFIX . $key, $value);
        }
        $object->save();
    }

    /** Bypass option caches: leases and reservations are database authority. */
    private static function database_option($name)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    /** WP add_option can UPDATE a competing insert after a stale cache precheck. */
    private static function insert_reservation($name, $value)
    {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value));
        if ($result === false) {
            throw new RuntimeException('Recurring reservation database write failed.');
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        return $result === 1;
    }

    private static function fresh_order($id)
    {
        if (class_exists('Automattic\\WooCommerce\\Caches\\OrderCache') && function_exists('wc_get_container')) {
            wc_get_container()->get(Automattic\WooCommerce\Caches\OrderCache::class)->remove($id);
        }
        if (class_exists('WC_Data_Store')) {
            $store = WC_Data_Store::load('order');
            if (method_exists($store->get_current_class_name(), 'clear_cached_data')) {
                $store->clear_cached_data(array($id));
            }
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
        $order = wc_get_order($id);
        if ($order) {
            $order->read_meta_data(true);
        }
        return $order;
    }

    private static function fresh_children($subs)
    {
        $fresh = array();
        foreach ($subs as $sub) {
            $child = self::fresh_order($sub->get_id());
            if (!$child) {
                throw new RuntimeException('Linked subscription disappeared.');
            }
            $fresh[$child->get_id()] = $child;
        }
        return $fresh;
    }

    /** Immutable first instruction; explicit FAILED retries use database CAS. */
    private static function instruction($order, $retry = '')
    {
        $name = 'swish_recurring_instruction_' . $order->get_id();
        $id = self::meta($order, 'payment_id');
        $value = wp_json_encode(array('id' => $id ?: strtoupper(bin2hex(random_bytes(16))), 'history' => array()));
        self::insert_reservation($name, $value);
        $stored = self::database_option($name);
        $record = json_decode((string) $stored, true);
        if (!$record || empty($record['id']) || ($id && $record['id'] !== $id)) {
            throw new RuntimeException('Durable recurring instruction differs; manual review required.');
        }
        if ($id) {
            // Historical instructions may already have been sent before this ledger existed.
            self::insert_reservation('swish_recurring_send_' . $id, (string) $order->get_id());
        }
        if ($retry) {
            $record['history'][] = array('id' => $record['id'], 'status' => 'FAILED');
            $record['id'] = $retry;
            if (!self::compare_lock($name, $stored, wp_json_encode($record))) {
                throw new RuntimeException('Recurring retry reservation changed.');
            }
        }
        return $record['id'];
    }

    /** Original AS time deduplicates distinct action IDs for one billing event. */
    private static function renewal_occurrence($subscription)
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (($frame['function'] ?? '') === 'execute' && isset($frame['object']) && is_a($frame['object'], 'ActionScheduler_Action')) {
                $action = $frame['object'];
                $args = array_values($action->get_args());
                if ($action->get_hook() === 'woocommerce_scheduled_subscription_payment' && absint($args[0] ?? 0) === $subscription->get_id()) {
                    $date = $action->get_schedule()->get_date();
                    if ($date && $date->getTimestamp() > 0) {
                        return 'scheduled:' . $date->getTimestamp();
                    }
                }
            }
            if (($frame['class'] ?? '') === 'WCS_Admin_Meta_Boxes' && ($frame['function'] ?? '') === 'process_renewal_action_request') {
                $time = $subscription->get_time('next_payment', 'gmt');
                if ($time > 0) {
                    return 'scheduled:' . $time;
                }
            }
        }
        $identity = apply_filters('swish_recurring_renewal_occurrence', '', $subscription);
        if (!is_string($identity) || $identity === '' || strlen($identity) > 200) {
            throw new RuntimeException('Renewal has no stable billing occurrence; explicit operation identity required.');
        }
        return $identity;
    }

    private static function reserve_occurrence($order, $subs)
    {
        if ((float) $order->get_total() <= 0) {
            return;
        }
        $occurrences = self::meta($order, 'billing_occurrences');
        if (!$occurrences) {
            throw new RuntimeException('Renewal billing occurrence was not captured before preparation.');
        }
        foreach ($subs as $sub) {
            if (empty($occurrences[$sub->get_id()])) {
                throw new RuntimeException('Renewal billing occurrence child changed.');
            }
            $name = 'swish_recurring_occurrence_' . hash('sha256', $sub->get_id() . ':' . $occurrences[$sub->get_id()]);
            self::insert_reservation($name, (string) $order->get_id());
            $canonical = (int) self::database_option($name);
            if (!$canonical) {
                throw new RuntimeException('Billing occurrence reservation could not be read.');
            }
            if ($canonical !== $order->get_id()) {
                self::assert_owned($order);
                self::save_meta($order, array('duplicate_of' => $canonical, 'manual_resolution' => 'yes'));
                $order->add_order_note(sprintf(__('Swish billing occurrence belongs to order %d. No debit or payment completion on this duplicate; reconcile the canonical order and resolve duplicate accounting manually.', 'woo-swish-e-commerce'), $canonical));
                throw new RuntimeException('Billing occurrence already reserved by canonical order ' . $canonical);
            }
        }
    }

    private static function lock($id)
    {
        $name = 'swish_recurring_lock_' . absint($id);
        $owner = bin2hex(random_bytes(16));
        $value = wp_json_encode(array('owner' => $owner, 'expires' => time() + 120));
        // An immutable insert uses the option_name unique index, not WP's cache precheck/upsert.
        if (self::insert_reservation($name, $value)) {
            self::$leases[absint($id)] = array($name, $value);
            return array($name, $value);
        }
        $previous = self::database_option($name);
        $decoded = json_decode($previous);
        if ($decoded && isset($decoded->expires) && $decoded->expires < time() && self::compare_lock($name, $previous, $value)) {
            self::$leases[absint($id)] = array($name, $value);
            return array($name, $value);
        }
        return false;
    }

    /** Atomic compare-and-swap: a previous worker must never erase a successor's lease. */
    private static function compare_lock($name, $expected, $replacement = null)
    {
        global $wpdb;
        if ($replacement === null) {
            $affected = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $expected));
        } else {
            $affected = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $replacement, $name, $expected));
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        return $affected === 1;
    }

    private static function unlock($lease)
    {
        if ($lease) {
            $id = (int) substr($lease[0], strlen('swish_recurring_lock_'));
            if (isset(self::$leases[$id]) && self::$leases[$id] === $lease) {
                unset(self::$leases[$id]);
            }
            self::compare_lock($lease[0], $lease[1]);
        }
    }

    private static function assert_owned($order)
    {
        $lease = self::$leases[$order->get_id()] ?? null;
        $record = $lease ? self::database_option($lease[0]) : false;
        $decoded = is_string($record) ? json_decode($record) : null;
        if (!$lease || $record !== $lease[1] || !$decoded || $decoded->expires <= time()) {
            throw new RuntimeException('Recurring operation lost its order lock; no further writes are safe.');
        }
        $fresh = self::fresh_order($order->get_id());
        // WC retains a just-saved scalar's PHP type in memory, while both CPT
        // and HPOS reload integer metadata as strings. Compare the parent ID's
        // storage representation, without weakening the account/context guard.
        if ($fresh && ($fresh->get_total() !== $order->get_total() || $fresh->get_currency() !== $order->get_currency() || $fresh->get_customer_id() !== $order->get_customer_id() || self::meta($fresh, 'context') !== self::meta($order, 'context') || (string) self::meta($fresh, 'parent_id') !== (string) self::meta($order, 'parent_id'))) {
            throw new RuntimeException('Recurring order financial or account context changed during processing.');
        }
        if (!$fresh || $fresh->get_status() !== $order->get_status() || $fresh->get_payment_method() !== $order->get_payment_method() || self::meta($fresh, 'payment_id') !== self::meta($order, 'payment_id') || self::meta($fresh, 'consent_id') !== self::meta($order, 'consent_id') || self::meta($fresh, 'completed') !== self::meta($order, 'completed') || self::meta($fresh, 'completion_claim') !== self::meta($order, 'completion_claim') || self::meta($fresh, 'manual_resolution') !== self::meta($order, 'manual_resolution')) {
            throw new RuntimeException('Recurring order changed during processing.');
        }
    }

    private function context()
    {
        return array('account' => (string) $this->account_uuid, 'merchant' => (string) $this->merchant_alias, 'service' => (string) $this->service_url, 'environment' => (string) $this->connection_type);
    }

    private function bind_context($order, $create = false)
    {
        $stored = self::meta($order, 'context');
        if (!$stored && $create && !self::meta($order, 'consent_id') && !self::meta($order, 'payment_id')) {
            self::save_meta($order, array('context' => $this->context()));
            return;
        }
        if (is_array($stored) && isset($stored['service']) && is_string($stored['service'])) {
            try {
                // Only the endpoint representation may vary. Never relax account,
                // merchant, environment, or the shape of a stored context.
                $stored['service'] = self::service_host($stored['service']);
            } catch (InvalidArgumentException $error) {
                // Malformed historical contexts still require manual migration.
            }
        }
        if (!is_array($stored) || $stored !== $this->context()) {
            throw new RuntimeException('Recurring account settings changed; consent migration must be handled manually.');
        }
    }

    /** Validate every linked child before any charge or order completion. */
    private function eligible_children($order, $consent_id = '', $parent_id = 0)
    {
        $renewal = $parent_id !== 0;
        $subs = $renewal ? wcs_get_subscriptions_for_renewal_order($order) : wcs_get_subscriptions_for_order($order, array('order_type' => 'parent'));
        if (!$subs) {
            throw new RuntimeException('Order has no linked subscriptions.');
        }
        $subs = self::fresh_children($subs);
        foreach ($subs as $sub) {
            if (!$sub || $sub->get_payment_method() !== $this->id || $sub->get_customer_id() !== $order->get_customer_id() || !$sub->has_status(array('active', 'on-hold', 'pending'))) {
                throw new RuntimeException('Order contains an ineligible subscription.');
            }
            if ($renewal && (self::meta($sub, 'consent_id') !== $consent_id || (int) self::meta($sub, 'parent_id') !== $parent_id)) {
                throw new RuntimeException('Renewal subscription consent or parent differs.');
            }
            if (!$renewal && self::meta($sub, 'consent_id') && self::meta($sub, 'consent_id') !== $consent_id) {
                throw new RuntimeException('Child already belongs to a different recurring consent.');
            }
        }
        return $subs;
    }

    private function attach_children($order, $subs)
    {
        foreach ($subs as $sub) {
            self::save_meta($sub, array('consent_id' => self::meta($order, 'consent_id'), 'parent_id' => $order->get_id()));
        }
    }

    /** WCS prepare_renewal runs at priority 1 and moves active -> on-hold. */
    public static function authorize_renewal($subscription_id)
    {
        $dispatch = self::current_renewal_dispatch();
        if (!$dispatch || $dispatch['frame']['id'] !== absint($subscription_id) || !function_exists('wcs_get_subscription')) {
            return;
        }
        $sub = self::fresh_order(absint($subscription_id));
        $index = count(self::$authorized_renewals) - 1;
        self::$authorized_renewals[$index]['epoch'] = null;
        if ($sub && $sub->get_payment_method() === 'swish_subscriptions' && $sub->has_status('active') && self::meta($sub, 'consent_id')) {
            self::$authorized_renewals[$index]['epoch'] = (int) self::meta($sub, 'suspension_epoch');
            self::$authorized_renewals[$index]['occurrence'] = self::renewal_occurrence($sub);
        }
    }

    public static function clear_renewal_authorization($subscription_id)
    {
        $dispatch = self::current_renewal_dispatch();
        if ($dispatch && $dispatch['frame']['id'] === absint($subscription_id)) {
            array_pop(self::$authorized_renewals);
        }
    }

    /** Capture entry before ordinary hook callbacks, including callbacks before priority -10. */
    public static function begin_renewal_dispatch($hook, $subscription_id = 0)
    {
        if ($hook !== 'woocommerce_scheduled_subscription_payment') {
            return;
        }
        // WordPress's all hook receives the whole args array for do_action_ref_array.
        $subscription_id = is_array($subscription_id) ? ($subscription_id[0] ?? 0) : $subscription_id;
        $frames = self::prune_renewal_authorizations();
        if (!$frames || $frames[count($frames) - 1]['id'] !== absint($subscription_id)) {
            return;
        }
        // Frame signatures identify live ancestors, not new invocations at the
        // same call site. Every entry replaces its own slot with a fresh token.
        $index = count($frames) - 1;
        self::$authorized_renewals = array_slice(self::$authorized_renewals, 0, $index);
        while (count(self::$authorized_renewals) < $index) {
            $ancestor = count(self::$authorized_renewals);
            self::$authorized_renewals[] = array('frame' => $frames[$ancestor], 'token' => null, 'epoch' => null);
        }
        self::$renewal_dispatch_count = did_action($hook);
        self::$authorized_renewals[] = array('frame' => $frames[$index], 'token' => ++self::$renewal_dispatch_sequence, 'epoch' => null);
    }

    /** Absolute stack depth and call site stay stable while a nested dispatch runs. */
    private static function live_renewal_frames()
    {
        $hook = 'woocommerce_scheduled_subscription_payment';
        $trace = debug_backtrace(0);
        $frames = array();
        foreach ($trace as $index => $frame) {
            if (!isset($frame['function'], $frame['args'][0]) || $frame['args'][0] !== $hook || isset($frame['class'])) {
                continue;
            }
            if ($frame['function'] === 'do_action') {
                $id = isset($frame['args'][1]) ? absint($frame['args'][1]) : 0;
            } elseif ($frame['function'] === 'do_action_ref_array') {
                $id = isset($frame['args'][1][0]) ? absint($frame['args'][1][0]) : 0;
            } else {
                continue;
            }
            $frames[] = array('depth' => count($trace) - $index, 'function' => $frame['function'], 'file' => $frame['file'] ?? '', 'line' => $frame['line'] ?? 0, 'id' => $id);
        }
        return array_reverse($frames);
    }

    /** Also runs after AS catches an inner failure: retain only still-live ancestors. */
    public static function prune_renewal_authorizations()
    {
        $frames = self::live_renewal_frames();
        foreach (self::$authorized_renewals as $index => $dispatch) {
            if (!isset($frames[$index]) || $frames[$index] !== $dispatch['frame']) {
                self::$authorized_renewals = array_slice(self::$authorized_renewals, 0, $index);
                break;
            }
        }
        return $frames;
    }

    private static function current_renewal_dispatch()
    {
        $frames = self::prune_renewal_authorizations();
        $index = count($frames) - 1;
        // The counter is an entry-observation guard, NOT an outer frame's identity.
        // It detects a new invocation before our entry callback can capture it.
        if (!$frames || self::$renewal_dispatch_count !== did_action('woocommerce_scheduled_subscription_payment') || !isset(self::$authorized_renewals[$index]['token'])) {
            return null;
        }
        return self::$authorized_renewals[$index];
    }

    private static function has_renewal_authorization($subscription)
    {
        $dispatch = self::current_renewal_dispatch();
        if (!$dispatch) {
            return false;
        }
        $id = $subscription->get_id();
        return $dispatch['frame']['id'] === $id && $dispatch['epoch'] !== null && $dispatch['epoch'] === (int) self::meta($subscription, 'suspension_epoch');
    }

    /** WCS internal transitions only: do not advertise customer/admin suspension. */
    public static function internal_status_support($supported, $feature, $subscription)
    {
        if ($subscription->get_payment_method() !== 'swish_subscriptions') {
            return $supported;
        }
        $id = $subscription->get_id();
        $epoch = (int) self::meta($subscription, 'suspension_epoch');
        if ($feature === 'subscription_suspension' && $subscription->has_status('active') && (self::has_renewal_authorization($subscription) || self::has_revocation_scope($subscription))) {
            return true;
        }
        if ($feature === 'subscription_reactivation' && $subscription->has_status('on-hold') && isset(self::$completing_children[$id]) && self::$completing_children[$id] === $epoch) {
            return true;
        }
        // WCS completes zero-total renewals itself, without calling the gateway.
        if ($feature === 'subscription_reactivation' && $subscription->has_status('on-hold') && self::has_renewal_authorization($subscription) && function_exists('wcs_get_last_renewal_order')) {
            try {
                $order = wcs_get_last_renewal_order($subscription);
                if (!$order || (float) $order->get_total() !== 0.0 || $order->get_payment_method() !== 'swish_subscriptions') {
                    return $supported;
                }
                $gateway = self::gateway();
                $consent_id = self::meta($subscription, 'consent_id');
                $parent_id = (int) self::meta($subscription, 'parent_id');
                $parent = self::fresh_order($parent_id);
                if (!$parent || self::meta($parent, 'consent_disabled') || self::meta($parent, 'consent_id') !== $consent_id || $parent->get_customer_id() !== $order->get_customer_id()) {
                    return $supported;
                }
                $gateway->bind_context($parent);
                $subs = $gateway->eligible_children($order, $consent_id, $parent_id);
                self::authorized_children($order, $subs);
                $consent = $gateway->api()->get_consent($consent_id);
                return isset($consent->consentId, $consent->status, $consent->payee->alias) && $consent->consentId === $consent_id && $consent->status === 'ACTIVE' && (string) $consent->payee->alias === $gateway->merchant_alias && (!self::meta($parent, 'alias') || (isset($consent->payer->alias) && (string) $consent->payer->alias === self::meta($parent, 'alias')));
            } catch (Throwable $error) {
                return $supported; // No permission on an uncertain consent or changed child.
            }
        }
        return $supported;
    }

    /** Scope reactivation to verified completion, including asynchronous callbacks. */
    private static function complete_payment($order, $subs, $reference = '')
    {
        $previous = self::$completing_children;
        foreach ($subs as $sub) {
            self::$completing_children[$sub->get_id()] = (int) self::meta($sub, 'suspension_epoch');
        }
        try {
            if ($order->payment_complete($reference) === false) {
                // WC catches callback exceptions and returns false. Keep the durable
                // completion claim for review, never mark this interrupted call complete.
                self::assert_owned($order);
                self::save_meta($order, array('manual_resolution' => 'yes'));
                $order->add_order_note(__('Swish completion was interrupted. Verify the order and subscription manually before resolving or refunding; automatic completion is blocked.', 'woo-swish-e-commerce'));
                throw new RuntimeException('WooCommerce payment completion was interrupted.');
            }
        } finally {
            self::$completing_children = $previous;
        }
    }

    private static function authorized_children($order, $subs)
    {
        $authorized = self::meta($order, 'authorized_children');
        if (!$authorized) {
            // Only WCS's actual scheduled hook can create the authorization.
            if (!doing_action('woocommerce_scheduled_subscription_payment')) {
                throw new RuntimeException('Direct renewal hook has no prior active authorization.');
            }
            $authorized = array();
            $occurrences = array();
            foreach ($subs as $sub) {
                if (!self::has_renewal_authorization($sub)) {
                    throw new RuntimeException('Renewal was not active before WCS prepared it.');
                }
                $dispatch = self::current_renewal_dispatch();
                $authorized[$sub->get_id()] = $dispatch['epoch'];
                $occurrences[$sub->get_id()] = $dispatch['occurrence'] ?? '';
            }
            self::save_meta($order, array('authorized_children' => $authorized, 'billing_occurrences' => $occurrences));
        }
        if (count($authorized) !== count($subs)) {
            throw new RuntimeException('Authorized renewal children changed.');
        }
        foreach ($subs as $sub) {
            if (!array_key_exists($sub->get_id(), $authorized) || (int) $authorized[$sub->get_id()] !== (int) self::meta($sub, 'suspension_epoch')) {
                throw new RuntimeException('Subscription was suspended after renewal authorization.');
            }
        }
    }

    private static function callback_url($order, $kind)
    {
        return add_query_arg(array('order_id' => $order->get_id(), 'kind' => $kind, 'secret' => self::meta($order, 'secret')), WC()->api_request_url('swish_subscriptions', true));
    }

    private static function queue($order)
    {
        try {
            self::assert_owned($order);
        } catch (Throwable $error) {
            return; // An expired worker must not schedule or mutate retry counters.
        }
        if (self::meta($order, 'reconcile_exhausted') || self::meta($order, 'completed') || self::meta($order, 'manual_resolution')) {
            return;
        }
        if (wp_next_scheduled('swish_subscriptions_reconcile', array($order->get_id()))) {
            return;
        }
        $attempt = (int) self::meta($order, 'reconcile_attempts');
        if ($attempt >= 12) {
            self::save_meta($order, array('reconcile_exhausted' => 'yes'));
            $order->add_order_note(__('Automatic Swish verification exhausted its bounded retries. The payment is NOT confirmed or failed. Check Swish and use the order action to reconcile manually.', 'woo-swish-e-commerce'));
            return;
        }
        self::save_meta($order, array('reconcile_attempts' => $attempt + 1));
        wp_schedule_single_event(time() + min(900, 20 * (2 ** min($attempt, 6))), 'swish_subscriptions_reconcile', array($order->get_id()));
    }

    public static function admin_order_actions($actions, $order = null)
    {
        if ($order && $order->get_payment_method() === 'swish_subscriptions') {
            $actions['swish_subscriptions_reconcile'] = __('Reconcile Swish recurring payment', 'woo-swish-e-commerce');
            if (self::meta($order, 'payment_state') === 'PAID') {
                $actions['swish_subscriptions_reconcile_refund'] = __('Reconcile Swish recurring refund', 'woo-swish-e-commerce');
            }
            if (self::is_failed_renewal($order)) {
                $actions['swish_subscriptions_retry_failed'] = __('Retry failed Swish subscription payment', 'woo-swish-e-commerce');
            }
        }
        return $actions;
    }

    private static function is_failed_renewal($order)
    {
        return $order && $order->get_payment_method() === 'swish_subscriptions' && $order->needs_payment() && !self::meta($order, 'manual_resolution') && !self::meta($order, 'completed') && (int) self::meta($order, 'parent_id') > 0 && self::meta($order, 'payment_id') && self::meta($order, 'payment_state') === 'FAILED' && is_array(self::meta($order, 'authorized_children')) && function_exists('wcs_get_subscriptions_for_renewal_order') && (bool) wcs_get_subscriptions_for_renewal_order($order);
    }

    /** WooCommerce order actions are nonce-checked by its order-edit controller. */
    public static function admin_retry_failed($order)
    {
        if (!current_user_can('edit_shop_orders') || !$order) {
            return;
        }
        $order = self::fresh_order($order->get_id());
        if (!self::is_failed_renewal($order)) {
            return;
        }
        // scheduled_payment locks, checks every child/epoch/parent/account and
        // re-GETs the previous ID as FAILED before persisting a new attempt.
        self::scheduled_payment($order->get_total(), $order, true);
    }

    public static function admin_reconcile($order)
    {
        if (!$order || !current_user_can('edit_shop_orders') || $order->get_payment_method() !== 'swish_subscriptions') {
            return;
        }
        self::save_meta($order, array('reconcile_attempts' => 0, 'reconcile_exhausted' => ''));
        if (self::meta($order, 'consent_disabled') || self::meta($order, 'consent_reconcile_attempts') || self::meta($order, 'consent_reconcile_exhausted')) {
            self::save_meta($order, array('consent_reconcile_attempts' => 0, 'consent_reconcile_exhausted' => ''));
            self::reconcile_consent($order->get_id());
        }
        self::reconcile_order($order->get_id());
    }

    /** Read-only bank reconciliation. Never sends a PUT or fabricates WooCommerce accounting. */
    public static function admin_reconcile_refund($order)
    {
        if (!$order || !current_user_can('edit_shop_orders')) {
            return;
        }
        $lock = self::lock($order->get_id());
        if (!$lock) {
            return;
        }
        try {
            $order = self::fresh_order($order->get_id());
            $order->read_meta_data(true);
            $gateway = self::gateway();
            if (!$gateway->can_refund_order($order)) {
                throw new RuntimeException('The original production Swish account is required.');
            }
            $pending = self::meta($order, 'refund_pending');
            if (!$pending) {
                foreach ((array) (self::meta($order, 'refunds') ?: array()) as $entry) {
                    $remote = $gateway->api()->retreive('https://cpc.getswish.net/swish-cpcapi/api/v1/refunds/' . $entry['id']);
                    $gateway->save_refund_status($order, $entry, $remote);
                }
                self::assert_refund_ledger($order);
                $order->add_order_note('Recorded Swish refund statuses checked. Accepted initiation is already accounted; settlement does not require another refund or admin action.');
                return;
            }
            $remote = $gateway->api()->retreive('https://cpc.getswish.net/swish-cpcapi/api/v1/refunds/' . $pending['id']);
            if (!$gateway->save_refund_status($order, $pending, $remote)) {
                return;
            }
            $pending = self::meta($order, 'refund_pending');
            $refund = !empty($pending['refund_id']) ? wc_get_order($pending['refund_id']) : false;
            if (self::finish_refund_accounting($order, $refund)) {
                return;
            }
            $instruction = $refund ? 'Resolve the existing WooCommerce refund #' . $refund->get_id() . ' accounting record manually; do not create a second record or bank transfer.' : 'Retry Refund via Swish with this amount; the original reason will be restored.';
            $order->add_order_note('Swish refund ' . $pending['id'] . ': ' . $remote->status . ', SEK ' . $pending['amount'] . ', original reason: ' . $pending['reason'] . '. ' . $instruction);
        } catch (Throwable $error) {
            $pending = self::meta($order, 'refund_pending');
            $recovery = $pending ? ' Saved instruction ' . $pending['id'] . ', SEK ' . $pending['amount'] . ', original reason: ' . $pending['reason'] . '.' : '';
            if ($error instanceof Woo_Swish_API_Exception && $error->getCode() === 404) {
                $recovery .= ' Swish reports not found. Retry Refund via Swish for the same amount; only the exact saved request and instruction may be retransmitted after another authoritative 404. Existing provisional accounting records must first be resolved manually.';
            }
            $order->add_order_note('Swish refund reconciliation: ' . $error->getMessage() . $recovery);
        } finally {
            self::unlock($lock);
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !function_exists('wcs_get_subscriptions_for_order') || !wcs_get_subscriptions_for_order($order, array('order_type' => 'parent')) || $order->get_currency() !== 'SEK') {
            wc_add_notice(__('Automatic Swish is only available for subscription checkout in SEK.', 'woo-swish-e-commerce'), 'error');
            return array('result' => 'failure');
        }
        $lock = self::lock($order_id);
        if (!$lock) {
            wc_add_notice(__('Swish authorization is already processing. Please wait.', 'woo-swish-e-commerce'), 'error');
            return array('result' => 'failure');
        }
        try {
            $order = self::fresh_order($order_id);
            if (!$order || $order->get_payment_method() !== $this->id || $order->get_currency() !== 'SEK') {
                throw new RuntimeException('Recurring checkout order changed before authorization.');
            }
            if (!$order->needs_payment() && $order->get_total() > 0) {
                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }
            $this->eligible_children($order, self::meta($order, 'consent_id'));
            $this->bind_context($order, true);
            if (self::meta($order, 'reconcile_exhausted')) {
                self::save_meta($order, array('reconcile_attempts' => 0, 'reconcile_exhausted' => ''));
            }
            $id = self::meta($order, 'consent_id');
            // Only an explicit checkout attempt may replace a verified terminal
            // consent. Never replace a consent with a charge or completion in flight.
            if ($id && self::consent_failed($order) && !self::meta($order, 'payment_id') && !self::meta($order, 'completion_claim') && !self::meta($order, 'completed') && !self::meta($order, 'manual_resolution') && !self::meta($order, 'parent_id') && $order->has_status(array('pending', 'failed'))) {
                // Re-checkout: only re-check the old consent. WC has just set the
                // order to pending, so it must not be failed again (and re-emailed).
                $this->sync_consent($order, false, true);
                if (self::consent_failed($order)) {
                    self::assert_owned($order);
                    self::save_meta($order, array('consent_id' => '', 'consent_state' => '', 'consent_disabled' => '', 'token' => '', 'secret' => ''));
                    $id = '';
                }
            }
            if (!$id) {
                $alias = ($this->is_m_payment() || $this->is_q_payment()) ? '' : self::phone(isset($_POST['swish-subscriptions-payer-alias']) ? sanitize_text_field(wp_unslash($_POST['swish-subscriptions-payer-alias'])) : '');
                if ($alias === '' && !$this->is_m_payment() && !$this->is_q_payment()) {
                    throw new RuntimeException(__('Enter a valid Swish number.', 'woo-swish-e-commerce'));
                }
                $id = strtoupper(bin2hex(random_bytes(16)));
                self::save_meta($order, array('consent_id' => $id, 'secret' => bin2hex(random_bytes(32)), 'alias' => $alias, 'consent_state' => 'CREATING', 'consent_reconcile_attempts' => 0, 'consent_reconcile_exhausted' => ''));
                try {
                    $created = $this->api()->create_consent($id, self::callback_url($order, 'consent'), $alias);
                    if ($alias === '' && isset($created->token)) {
                        self::save_meta($order, array('token' => $created->token));
                    }
                } catch (Throwable $error) {
                    // Never create a second consent when a write may have reached Swish.
                    $this->logger->add('Recurring consent create uncertain for order ' . $order_id . ': ' . $error->getMessage());
                }
            }
            try {
                $this->sync_consent($order, true);
            } catch (Throwable $error) {
                // A just-created consent can take time to become visible to GET.
                $this->logger->add('Recurring consent pending ' . $order_id . ': ' . $error->getMessage());
            }
            self::queue($order);
            return array('result' => 'success', 'redirect' => $this->wait_url($order));
        } catch (Throwable $error) {
            $this->logger->add('Recurring checkout ' . $order_id . ': ' . $error->getMessage());
            wc_add_notice(__('Unable to begin Swish authorization. Please contact the shop.', 'woo-swish-e-commerce'), 'error');
            return array('result' => 'failure');
        } finally {
            self::unlock($lock);
        }
    }

    private function wait_url($order)
    {
        return add_query_arg(array('order_id' => $order->get_id(), 'key' => $order->get_order_key()), WC()->api_request_url('swish_subscriptions_wait', true));
    }

    /** Standalone, key-protected wait view; no theme or Blocks thank-you hook required. */
    public static function wait_handler()
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $order = wc_get_order($order_id);
        if (!$order || !$key || $order->get_payment_method() !== 'swish_subscriptions' || !hash_equals((string) $order->get_order_key(), $key)) {
            status_header(403);
            exit;
        }
        nocache_headers();
        $gateway = self::gateway();
        $state = self::wait_state($order);
        if ($state['redirect']) {
            wp_safe_redirect($state['redirect']);
            exit;
        }
        // Register scripts before wp_head emits them; thankyou rechecks the key.
        ob_start();
        $gateway->thankyou($order_id);
        $content = ob_get_clean();
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e('Swish authorization', 'woo-swish-e-commerce'); ?></title><?php wp_head(); ?></head>
        <body class="swish-recurring-wait-page"><main class="swish_container"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by gateway->thankyou with escaped values. ?></main><?php wp_footer(); ?></body>
        </html><?php
        exit;
    }

    private function sync_consent($order, $customer_attempt = false, $replacing = false)
    {
        $id = self::meta($order, 'consent_id');
        if (!$id) {
            return;
        }
        $this->bind_context($order);
        $consent = $this->api()->get_consent($id);
        if (!is_object($consent) || !isset($consent->consentId, $consent->status) || $consent->consentId !== $id || !isset($consent->payee->alias) || (string) $consent->payee->alias !== $this->merchant_alias || (self::meta($order, 'alias') && (!isset($consent->payer->alias) || (string) $consent->payer->alias !== self::meta($order, 'alias')))) {
            throw new RuntimeException('Recurring consent identity mismatch.');
        }
        self::assert_owned($order);
        self::save_meta($order, array('consent_state' => $consent->status));
        if (self::meta($order, 'consent_hold_review') && !in_array($consent->status, array('DELETED', 'TIMEOUT', 'DECLINED'), true)) {
            throw new RuntimeException('Swish revocation transition effects still require technical repair.');
        }
        if ($consent->status === 'ACTIVE') {
            // A deleted local mandate must never be revived by a delayed ACTIVE
            // observation, including a retry of an earlier activation callback.
            if (self::meta($order, 'consent_disabled')) {
                return;
            }
            $subs = $this->eligible_children($order, $id);
            if ((float) $order->get_total() <= 0) {
                $this->charge($order, $id);
                $this->eligible_children($order, $id);
                $this->attach_children($order, $subs);
            } else {
                if (self::meta($order, 'payment_state') !== 'RETRY_READY' || $customer_attempt) {
                    $new_retry_id = $customer_attempt && self::meta($order, 'payment_state') === 'FAILED' ? $this->prepare_retry($order) : '';
                    $this->charge($order, $id, $new_retry_id);
                }
            }
        } elseif (in_array($consent->status, array('DELETED', 'TIMEOUT', 'DECLINED'), true)) {
            // An aborted initial authorization is a failed checkout, just like
            // a declined one-off payment. Revocation must not fail a paid order
            // or a payment whose outcome is still unknown.
            if (!self::meta($order, 'payment_id') && !self::meta($order, 'completed') && !self::meta($order, 'completion_claim') && !self::meta($order, 'manual_resolution') && !self::meta($order, 'parent_id') && !$replacing && $order->has_status('pending')) {
                $order->update_status('failed', __('Swish recurring authorization was not completed.', 'woo-swish-e-commerce'));
            }
            if (!self::meta($order, 'consent_disabled')) {
                self::save_meta($order, array('consent_disabled' => 'yes'));
                $order->add_order_note(__('Recurring Swish authorization was not approved or was revoked. Automatic renewals require a new payment method.', 'woo-swish-e-commerce'));
            }
            // Always repair children, even if a previous attempt disabled the
            // parent but failed halfway through WCS's status hooks.
            $failed = false;
            $retryable = false;
            $review = false;
            if (function_exists('wcs_get_subscriptions_for_order')) {
                foreach (wcs_get_subscriptions_for_order($order, array('order_type' => 'parent')) as $sub) {
                    if (self::meta($sub, 'consent_hold_review') === $id) {
                        // Persisted status is not proof that all WCS effects ran.
                        // Technical repair must clear this marker explicitly.
                        $failed = $review = true;
                        continue;
                    }
                    if ($sub->get_payment_method() !== $this->id || !$sub->has_status(array('active', 'on-hold')) || self::meta($sub, 'consent_id') !== $id || (int) self::meta($sub, 'parent_id') !== $order->get_id() || $sub->get_parent_id() !== $order->get_id() || $sub->get_customer_id() !== $order->get_customer_id()) {
                        continue;
                    }
                    self::assert_owned($order);
                    try {
                        // Persist invalidation BEFORE attempting the hold. Even a
                        // throwing WCS hook cannot retain an in-flight authorization.
                        if (self::meta($sub, 'revoked_consent_id') !== $id) {
                            self::save_meta($sub, array('suspension_epoch' => (int) self::meta($sub, 'suspension_epoch') + 1, 'revoked_consent_id' => $id));
                        }
                        if ($sub->has_status('active')) {
                            self::hold_revoked_child($sub);
                        }
                    } catch (Throwable $error) {
                        $failed = true;
                        if ($sub->has_status('on-hold')) {
                            self::assert_owned($order);
                            $review = true;
                            self::save_meta($sub, array('consent_hold_review' => $id));
                            $sub->add_order_note(__('Swish revocation saved on-hold, but subscription transition effects were interrupted. Technical review must verify billing schedules and notifications; do not blindly replay status hooks. Clear the consent_hold_review marker only after repair.', 'woo-swish-e-commerce'));
                        } else {
                            $retryable = true;
                        }
                        $this->logger->add('Recurring consent hold ' . $sub->get_id() . ': ' . $error->getMessage());
                    }
                }
            }
            self::assert_owned($order);
            if ($review && self::meta($order, 'consent_hold_review') !== $id) {
                $order->add_order_note(__('Swish revocation needs technical review: a linked subscription is on-hold but its transition effects were interrupted. Automatic charges remain blocked. Repair the affected subscription effects before clearing its consent_hold_review marker and reconciling this mandate.', 'woo-swish-e-commerce'));
            }
            self::save_meta($order, array('consent_hold_review' => $review ? $id : '', 'consent_hold_retry' => $retryable ? 'yes' : ''));
            if ($failed) {
                throw new RuntimeException('Some revoked Swish subscriptions could not be held. Retry consent reconciliation.');
            }
        }
    }

    private static function has_revocation_scope($subscription)
    {
        $id = $subscription->get_id();
        return isset(self::$revoking_children[$id]) && self::$revoking_children[$id]['subscription'] === $subscription && self::$revoking_children[$id]['epoch'] === (int) self::meta($subscription, 'suspension_epoch');
    }

    /** Only the verified revocation's exact child can use WCS suspension. */
    private static function hold_revoked_child($subscription)
    {
        $previous = self::$revoking_children;
        self::$revoking_children[$subscription->get_id()] = array('subscription' => $subscription, 'epoch' => (int) self::meta($subscription, 'suspension_epoch'));
        $finished = false;
        $logged_error = false;
        // WCS swallows Exception in status_transition after saving the status.
        // Observe the end of its hook chain, not merely the persisted status.
        $sentinel = static function ($id, $from, $to, $child) use ($subscription, &$finished) {
            if ($child === $subscription && $from === 'active' && $to === 'on-hold') {
                $finished = true;
            }
        };
        $error_observer = static function ($message, $level, $context) use ($subscription, &$logged_error) {
            if ($level === 'error' && isset($context['order'], $context['error']) && $context['order'] === $subscription && $context['error'] instanceof Throwable) {
                $logged_error = true;
            }
            return $message;
        };
        // WC may suppress error logging by threshold or have no log handlers.
        // WCS still writes its catch-path diagnostic note. Observe only that
        // exact object's live status_transition, without changing the note.
        $error_note_observer = static function ($data, $args) use ($subscription, &$logged_error) {
            if (isset($args['order_id'], $data['comment_content']) && (int) $args['order_id'] === $subscription->get_id() && strpos($data['comment_content'], __('Error during subscription status transition.', 'woocommerce-subscriptions')) === 0) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (($frame['function'] ?? '') === 'status_transition' && ($frame['object'] ?? null) === $subscription) {
                        $logged_error = true;
                        break;
                    }
                }
            }
            return $data;
        };
        add_action('woocommerce_subscription_status_changed', $sentinel, PHP_INT_MAX, 4);
        add_filter('woocommerce_logger_log_message', $error_observer, PHP_INT_MAX, 3);
        add_filter('woocommerce_new_order_note_data', $error_note_observer, PHP_INT_MAX, 2);
        try {
            $subscription->update_status('on-hold', __('Swish recurring consent is no longer active.', 'woo-swish-e-commerce'));
            if (!$subscription->has_status('on-hold')) {
                throw new RuntimeException('Swish subscription hold was not saved.');
            }
            if (!$finished || $logged_error) {
                throw new RuntimeException('Swish subscription hold persisted but WCS transition effects were interrupted; technical repair required.');
            }
        } finally {
            remove_action('woocommerce_subscription_status_changed', $sentinel, PHP_INT_MAX);
            remove_filter('woocommerce_logger_log_message', $error_observer, PHP_INT_MAX);
            remove_filter('woocommerce_new_order_note_data', $error_note_observer, PHP_INT_MAX);
            self::$revoking_children = $previous;
        }
    }

    private function charge($order, $consent_id, $new_retry_id = '')
    {
        $this->bind_context($order);
        self::assert_owned($order);
        if (!$order->needs_payment() && (float) $order->get_total() > 0) {
            return;
        }
        if ((float) $order->get_total() <= 0) {
            if (!self::meta($order, 'completed')) {
                if ($order->has_status(array('processing', 'completed'))) {
                    self::save_meta($order, array('completed' => 'yes'));
                } else {
                    if (!$this->claim_completion($order, 'ZERO' . $order->get_id())) {
                        return;
                    }
                    self::assert_owned($order);
                    self::complete_payment($order, $this->eligible_children($order, $consent_id, (int) self::meta($order, 'parent_id')));
                    self::assert_owned($order);
                    self::save_meta($order, array('completed' => 'yes'));
                }
            }
            return;
        }
        $id = self::meta($order, 'payment_id');
        if ($new_retry_id && ($id !== $new_retry_id || self::meta($order, 'payment_state') !== 'CREATING')) {
            throw new RuntimeException('Retry charge identity changed before POST.');
        }
        if ($id && self::meta($order, 'payment_state') === 'FAILED') {
            // Only an explicit admin renewal or customer initial retry may replace a terminal failed ID.
            return;
        }
        $new_charge = !$id;
        if (!$id) {
            self::assert_owned($order);
            $id = self::instruction($order);
            self::save_meta($order, array('payment_id' => $id, 'payment_state' => 'CREATING'));
        }
        if ($new_retry_id || $new_charge) {
            try {
                self::assert_owned($order);
                $parent_id = (int) self::meta($order, 'parent_id');
                $subs = $this->eligible_children($order, $consent_id, $parent_id);
                $parent = $parent_id ? self::fresh_order($parent_id) : self::fresh_order($order->get_id());
                if (!$parent || self::meta($parent, 'consent_disabled') || self::meta($parent, 'consent_id') !== $consent_id) {
                    throw new RuntimeException('Recurring consent disabled or changed before send.');
                }
                $this->bind_context($parent);
                if ($parent_id) {
                    self::authorized_children($order, $subs);
                    self::reserve_occurrence($order, $subs);
                }
                // One local send ever per instruction, even if an expired worker
                // resumes. Uncertain sends are GET-only, not bank-idempotency guesses.
                if (self::insert_reservation('swish_recurring_send_' . $id, (string) $order->get_id())) {
                    self::assert_owned($order);
                    $this->api()->create_payment($id, $consent_id, number_format((float) $order->get_total(), 2, '.', ''), 'ORDER' . $order->get_id(), 'Order ' . $order->get_id(), self::callback_url($order, 'payment'));
                }
            } catch (Throwable $error) {
                $this->logger->add('Recurring charge create uncertain for order ' . $order->get_id() . ': ' . $error->getMessage());
            }
        }
        try {
            $this->sync_payment($order);
        } catch (Throwable $error) {
            // 201 and timeout alike mean only that the charge is pending verification.
            $this->logger->add('Recurring charge pending ' . $order->get_id() . ': ' . $error->getMessage());
        }
        self::queue($order);
    }

    /** Persist a new ID atomically only after consent and the old FAILED status are verified. */
    private function prepare_retry($order)
    {
        $old_id = self::meta($order, 'payment_id');
        if (!$old_id || self::meta($order, 'payment_state') !== 'FAILED') {
            return;
        }
        $this->bind_context($order);
        $confirmed = $this->api()->get_payment($old_id);
        if (!isset($confirmed->paymentId, $confirmed->status) || $confirmed->paymentId !== $old_id || $confirmed->status !== 'FAILED') {
            throw new RuntimeException('Old charge is not confirmed terminal FAILED; retry withheld.');
        }
        $ledger = (array) self::meta($order, 'attempts');
        $ledger[] = array('id' => $old_id, 'status' => 'FAILED');
        $next_id = strtoupper(bin2hex(random_bytes(16)));
        self::assert_owned($order);
        self::instruction($order, $next_id);
        self::save_meta($order, array('attempts' => $ledger, 'payment_id' => $next_id, 'payment_state' => 'CREATING'));
        return $next_id;
    }

    private function sync_payment($order)
    {
        $id = self::meta($order, 'payment_id');
        if (!$id || self::meta($order, 'completed') || self::meta($order, 'manual_resolution')) {
            return;
        }
        $this->bind_context($order);
        $payment = $this->api()->get_payment($id);
        if (!is_object($payment) || !isset($payment->paymentId, $payment->status) || $payment->paymentId !== $id) {
            throw new RuntimeException('Recurring payment identity mismatch.');
        }
        self::assert_owned($order);
        self::save_meta($order, array('payment_state' => $payment->status));
        if ($payment->status === 'PAID') {
            // Recheck the consent before honoring even an authenticated remote payment.
            $consent = $this->api()->get_consent(self::meta($order, 'consent_id'));
            if (!isset($consent->consentId, $consent->payee->alias) || $consent->consentId !== self::meta($order, 'consent_id') || (string) $consent->payee->alias !== $this->merchant_alias) {
                throw new RuntimeException('Recurring payment consent mismatch.');
            }
            $parent_id = (int) self::meta($order, 'parent_id');
            try {
                if (!isset($consent->status) || $consent->status !== 'ACTIVE') {
                    throw new RuntimeException('Paid payment has no active recurring consent.');
                }
                $subs = $this->eligible_children($order, self::meta($order, 'consent_id'), $parent_id);
                if ($parent_id) {
                    self::authorized_children($order, $subs);
                    $parent = self::fresh_order($parent_id);
                    if (!$parent || self::meta($parent, 'consent_disabled') || self::meta($parent, 'consent_id') !== self::meta($order, 'consent_id') || $parent->get_customer_id() !== $order->get_customer_id()) {
                        throw new RuntimeException('Renewal parent changed.');
                    }
                    $this->bind_context($parent);
                }
            } catch (Throwable $error) {
                // Never fire WCS payment-complete hooks for an ended/changed subscription.
                if (!self::meta($order, 'manual_resolution')) {
                    self::assert_owned($order);
                    $order->set_transaction_id($id);
                    self::save_meta($order, array('manual_resolution' => 'yes'));
                    $order->add_order_note(__('Swish paid after subscription became ineligible. Do not reactivate it; investigate and refund manually.', 'woo-swish-e-commerce'));
                }
                return;
            }
            if ($order->needs_payment()) {
                if (!$this->claim_completion($order, $id)) {
                    return;
                }
                self::assert_owned($order);
                self::complete_payment($order, $subs, $id);
            }
            self::assert_owned($order);
            self::save_meta($order, array('completed' => 'yes'));
            if (!$parent_id) {
                $this->attach_children($order, $subs);
            }
        } elseif ($payment->status === 'FAILED') {
            if (!$order->has_status('failed')) {
                $order->update_status('failed', __('Swish recurring charge failed.', 'woo-swish-e-commerce'));
            }
        }
    }

    /** Persist before WCS hooks: ambiguous partial completion requires human review. */
    private function claim_completion($order, $reference)
    {
        if (self::meta($order, 'completion_claim')) {
            if (!self::meta($order, 'manual_resolution')) {
                self::assert_owned($order);
                self::save_meta($order, array('manual_resolution' => 'yes'));
                $order->add_order_note(__('Swish completion was interrupted. Verify the order and subscription manually before resolving or refunding; automatic completion is blocked.', 'woo-swish-e-commerce'));
            }
            return false;
        }
        self::assert_owned($order);
        self::save_meta($order, array('completion_claim' => $reference, 'completion_claimed_at' => time()));
        return true;
    }

    public static function scheduled_payment($amount, $renewal_order, $admin_failed_retry = false)
    {
        if (!$renewal_order || $renewal_order->get_payment_method() !== 'swish_subscriptions' || !function_exists('wcs_get_subscriptions_for_renewal_order')) {
            return;
        }
        $lock = self::lock($renewal_order->get_id());
        if (!$lock) {
            return;
        }
        $order = null;
        $consent_failure = false; // 'inactive' | 'unverifiable': renewal order ends Failed.
        try {
            $gateway = self::gateway();
            $order = self::fresh_order($renewal_order->get_id());
            if (!$order || $order->get_payment_method() !== $gateway->id || self::meta($order, 'manual_resolution')) {
                return;
            }
            $subs = self::fresh_children(wcs_get_subscriptions_for_renewal_order($order));
            if (!$subs || !$order->needs_payment() || $order->get_currency() !== 'SEK') {
                return;
            }
            if (wc_format_decimal($amount, 2) !== wc_format_decimal($order->get_total(), 2)) {
                throw new RuntimeException('Renewal hook amount does not match the order total.');
            }
            self::authorized_children($order, $subs);
            $consent_id = '';
            $parent_id = 0;
            foreach ($subs as $sub) {
                if ($sub->get_payment_method() !== $gateway->id || !$sub->has_status(array('active', 'on-hold')) || !self::meta($sub, 'consent_id') || $sub->get_customer_id() !== $order->get_customer_id()) {
                    throw new RuntimeException('Renewal subscription has no eligible consent.');
                }
                if ($consent_id && ($consent_id !== self::meta($sub, 'consent_id') || $parent_id !== (int) self::meta($sub, 'parent_id'))) {
                    throw new RuntimeException('Cannot combine different consents or parents on one renewal.');
                }
                $consent_id = self::meta($sub, 'consent_id');
                $parent_id = (int) self::meta($sub, 'parent_id');
                $child_parent = self::fresh_order($parent_id);
                if (!$child_parent || self::meta($child_parent, 'consent_id') !== $consent_id || self::meta($child_parent, 'consent_disabled') || $child_parent->get_customer_id() !== $order->get_customer_id()) {
                    throw new RuntimeException('One renewal child has a mismatched parent.');
                }
                $gateway->bind_context($child_parent);
            }
            $gateway->eligible_children($order, $consent_id, $parent_id);
            self::reserve_occurrence($order, $subs);
            $parent = self::fresh_order($parent_id);
            if (!$parent || self::meta($parent, 'consent_id') !== $consent_id || $parent->get_customer_id() !== $order->get_customer_id()) {
                throw new RuntimeException('Renewal consent parent mismatch.');
            }
            if (!self::meta($order, 'secret')) {
                if (self::meta($order, 'payment_id') || self::meta($order, 'consent_id')) {
                    throw new RuntimeException('Renewal contains stale recurring identifiers.');
                }
                self::save_meta($order, array('secret' => bin2hex(random_bytes(32)), 'consent_id' => $consent_id, 'parent_id' => $parent_id, 'context' => $gateway->context()));
            }
            $gateway->bind_context($order);
            if (self::meta($order, 'consent_id') !== $consent_id || (int) self::meta($order, 'parent_id') !== $parent_id) {
                throw new RuntimeException('Renewal persisted relation changed.');
            }
            if (self::meta($order, 'payment_state') === 'FAILED' && !$admin_failed_retry) {
                return; // WCS automatic retry rules are not enabled for this gateway.
            }
            try {
                $consent = $gateway->api()->get_consent($consent_id);
            } catch (Throwable $error) {
                $consent_failure = 'unverifiable';
                throw $error;
            }
            if (!isset($consent->consentId, $consent->status, $consent->payee->alias) || $consent->consentId !== $consent_id || $consent->status !== 'ACTIVE' || (string) $consent->payee->alias !== $gateway->merchant_alias || (self::meta($parent, 'alias') && (!isset($consent->payer->alias) || (string) $consent->payer->alias !== self::meta($parent, 'alias')))) {
                $order->add_order_note(__('Swish recurring consent is no longer active. Automatic payment is disabled until a new method is supplied.', 'woo-swish-e-commerce'));
                foreach ($subs as $sub) {
                    if ($sub->has_status('active')) {
                        $sub->update_status('on-hold', __('Swish recurring consent is no longer active.', 'woo-swish-e-commerce'));
                    }
                }
                $consent_failure = 'inactive';
                throw new RuntimeException('Swish recurring consent is not active.');
            }
            if (self::meta($order, 'reconcile_exhausted')) {
                self::save_meta($order, array('reconcile_attempts' => 0, 'reconcile_exhausted' => ''));
            }
            $new_retry_id = $admin_failed_retry && self::meta($order, 'payment_state') === 'FAILED' ? $gateway->prepare_retry($order) : '';
            $gateway->charge($order, $consent_id, $new_retry_id);
        } catch (Throwable $error) {
            self::gateway()->logger->add('Recurring renewal ' . $renewal_order->get_id() . ': ' . $error->getMessage());
            if ($consent_failure === 'inactive') {
                $renewal_order->add_order_note(__("Automatic Swish renewal failed: the customer's Swish recurring consent is no longer active. No charge was issued; the customer must pay manually or set up a new consent.", 'woo-swish-e-commerce'));
            } elseif ($consent_failure === 'unverifiable') {
                $renewal_order->add_order_note(__('Automatic Swish renewal failed: the Swish recurring consent could not be verified. No charge was issued; the customer must pay manually or set up a new consent.', 'woo-swish-e-commerce'));
            } else {
                $renewal_order->add_order_note(__('Automatic Swish renewal could not be verified. Check the consent and retry manually; no new charge was issued.', 'woo-swish-e-commerce'));
            }
            if ($consent_failure && $order && $order->needs_payment() && !$order->has_status('failed')) {
                // WCS renewal-failure handling: subscription on-hold + failed renewal email with pay link.
                $order->update_status('failed');
            }
        } finally {
            self::unlock($lock);
        }
    }

    /** Separate from payment retries: a paid parent can still lose its mandate. */
    private static function queue_consent($order, $consent_id, $secret, $owned = false)
    {
        $args = array($order->get_id(), $consent_id, $secret);
        if (wp_next_scheduled('swish_subscriptions_reconcile_consent', $args)) {
            return;
        }
        $attempt = (int) self::meta($order, 'consent_reconcile_attempts');
        if ($owned) {
            self::assert_owned($order);
            if ($attempt >= 12) {
                if (!self::meta($order, 'consent_reconcile_exhausted')) {
                    self::save_meta($order, array('consent_reconcile_exhausted' => 'yes'));
                    $order->add_order_note(__('Swish consent verification or subscription hold exhausted its automatic retries. Check the mandate and linked subscriptions manually; payment status is unchanged.', 'woo-swish-e-commerce'));
                }
                return;
            }
            self::save_meta($order, array('consent_reconcile_attempts' => $attempt + 1));
        }
        // A contending callback cannot alter the lease owner's retry counters.
        wp_schedule_single_event(time() + min(900, 20 * (2 ** min($attempt, 6))), 'swish_subscriptions_reconcile_consent', $args);
    }

    /** False means unchecked/partially applied: callback must return HTTP 503. */
    public static function reconcile_consent($order_id, $consent_id = '', $secret = '')
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'swish_subscriptions') {
            return false;
        }
        $consent_id = $consent_id ?: self::meta($order, 'consent_id');
        $secret = $secret ?: self::meta($order, 'secret');
        if (!$consent_id || self::meta($order, 'consent_id') !== $consent_id || !hash_equals((string) self::meta($order, 'secret'), (string) $secret)) {
            return true; // An obsolete cron job must not act on a replacement mandate.
        }
        $lock = self::lock($order_id);
        if (!$lock) {
            self::queue_consent($order, $consent_id, $secret);
            return false;
        }
        try {
            $order = self::fresh_order($order_id);
            $order->read_meta_data(true);
            if ($order->get_payment_method() !== 'swish_subscriptions' || self::meta($order, 'consent_id') !== $consent_id || !hash_equals((string) self::meta($order, 'secret'), (string) $secret)) {
                return true;
            }
            self::gateway()->sync_consent($order);
            self::assert_owned($order);
            self::save_meta($order, array('consent_reconcile_attempts' => 0, 'consent_reconcile_exhausted' => ''));
            return true;
        } catch (Throwable $error) {
            self::gateway()->logger->add('Recurring consent reconciliation ' . $order_id . ': ' . $error->getMessage());
            try {
                if (!self::meta($order, 'consent_hold_review') || self::meta($order, 'consent_hold_retry')) {
                    self::queue_consent($order, $consent_id, $secret, true);
                }
            } catch (Throwable $lease_error) {
                // Do not mutate after lease loss; Swish still receives 503.
            }
            return false;
        } finally {
            self::unlock($lock);
        }
    }

    public static function reconcile_order($order_id, $kind = '')
    {
        if ($kind === 'consent') {
            return self::reconcile_consent($order_id);
        }
        $lock = self::lock($order_id);
        if (!$lock) {
            return;
        }
        try {
            $order = self::fresh_order($order_id);
            if (!$order || $order->get_payment_method() !== 'swish_subscriptions') {
                return;
            }
            $gateway = self::gateway();
            if (self::meta($order, 'payment_id')) {
                $gateway->sync_payment($order);
            } elseif (self::meta($order, 'consent_id')) {
                $gateway->sync_consent($order);
            }
            if (!self::meta($order, 'completed') && !self::meta($order, 'manual_resolution') && self::meta($order, 'payment_state') !== 'FAILED' && !in_array(self::meta($order, 'consent_state'), array('DELETED', 'TIMEOUT', 'DECLINED'), true)) {
                self::queue($order);
            }
        } catch (Throwable $error) {
            self::gateway()->logger->add('Recurring reconciliation ' . $order_id . ': ' . $error->getMessage());
            $order = self::fresh_order($order_id);
            if ($order) {
                self::queue($order);
            }
        } finally {
            self::unlock($lock);
        }
    }

    public static function callback()
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $kind = isset($_GET['kind']) ? sanitize_key(wp_unslash($_GET['kind'])) : '';
        $secret = isset($_GET['secret']) ? sanitize_text_field(wp_unslash($_GET['secret'])) : '';
        $order = wc_get_order($order_id);
        $body = json_decode(file_get_contents('php://input'));
        if (!self::valid_callback($order, $kind, $secret, $body)) {
            status_header(403);
            exit;
        }
        if ($kind === 'refund') {
            try {
                self::reconcile_refund($order_id, $body->id);
            } catch (Throwable $error) {
                self::gateway()->logger->add('Recurring refund callback ' . $order_id . ': ' . $error->getMessage());
                status_header(503); // Provider may redeliver; never acknowledge an unchecked outcome.
                exit;
            }
        } else {
            $checked = $kind === 'consent' ? self::reconcile_consent($order_id, $body->consentId, $secret) : self::reconcile_order($order_id, $kind);
            if ($checked === false) {
                status_header(503); // Consent GET/hold/lock failure: request redelivery too.
                exit;
            }
        }
        status_header(200);
        exit;
    }

    /** Read-only settlement sync; WC alone creates accounting and runs refund hooks. */
    public static function reconcile_refund($order_id, $instruction_id)
    {
        $lock = self::lock($order_id);
        if (!$lock) {
            throw new RuntimeException('Another Swish operation is running. Retry callback delivery.');
        }
        try {
            $order = self::fresh_order($order_id);
            $order->read_meta_data(true);
            $gateway = self::gateway();
            if (!$gateway->can_refund_order($order)) {
                throw new RuntimeException('The original production Swish account is required.');
            }
            $pending = self::meta($order, 'refund_pending');
            $operation = $pending && $pending['id'] === $instruction_id ? $pending : null;
            foreach ((array) (self::meta($order, 'refunds') ?: array()) as $entry) {
                if ($entry['id'] === $instruction_id) {
                    $operation = $entry;
                }
            }
            if (!$operation) {
                throw new RuntimeException('Unknown Swish refund instruction.');
            }
            $remote = $gateway->api()->retreive('https://cpc.getswish.net/swish-cpcapi/api/v1/refunds/' . $instruction_id);
            if (!$gateway->save_refund_status($order, $operation, $remote)) {
                return;
            }
            if ($pending && $pending['id'] === $instruction_id) {
                self::finish_refund_accounting($order, wc_get_order($pending['refund_id']));
            }
        } finally {
            self::unlock($lock);
        }
    }

    private static function valid_callback($order, $kind, $secret, $body)
    {
        if (!$order || $order->get_payment_method() !== 'swish_subscriptions' || !in_array($kind, array('consent', 'payment', 'refund'), true) || !$secret || !hash_equals((string) self::meta($order, 'secret'), $secret)) {
            return false;
        }
        if ($kind === 'refund') {
            $pending = self::meta($order, 'refund_pending');
            $ids = $pending ? array($pending['id']) : array();
            foreach ((array) (self::meta($order, 'refunds') ?: array()) as $refund) {
                $ids[] = $refund['id'];
            }
            return is_object($body) && isset($body->id, $body->originalPaymentReference) && in_array($body->id, $ids, true) && $body->originalPaymentReference === self::meta($order, 'payment_id');
        }
        $id_key = $kind === 'consent' ? 'consentId' : 'paymentId';
        $current_id = self::meta($order, $kind === 'consent' ? 'consent_id' : 'payment_id');
        return $current_id && is_object($body) && isset($body->$id_key) && hash_equals((string) $current_id, (string) $body->$id_key);
    }

    public static function browser_status()
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $order = self::fresh_order($order_id);
        if (!$order || !$key || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== 'swish_subscriptions') {
            wp_send_json_error(null, 403);
        }
        if (!self::meta($order, 'reconcile_exhausted')) {
            self::reconcile_order($order_id);
        }
        $order = wc_get_order($order_id);
        wp_send_json_success(self::wait_state($order));
    }

    private static function consent_failed($order)
    {
        return in_array(self::meta($order, 'consent_state'), array('DELETED', 'TIMEOUT', 'DECLINED'), true);
    }

    /** Share navigation between polling and returning/reloading the wait page. */
    private static function wait_state($order)
    {
        $review = self::meta($order, 'manual_resolution') || self::meta($order, 'reconcile_exhausted');
        $paid = !$review && !$order->needs_payment() && (bool) self::meta($order, 'completed');
        $status = $review ? 'MANUAL_REVIEW' : (self::meta($order, 'payment_state') ?: self::meta($order, 'consent_state'));
        $redirect = $paid ? $order->get_checkout_order_received_url() : '';
        if (!$paid && !$review && !self::meta($order, 'completed') && !self::meta($order, 'completion_claim') && !self::meta($order, 'parent_id') && $order->has_status(array('pending', 'failed')) && (self::meta($order, 'payment_state') === 'FAILED' || (!self::meta($order, 'payment_id') && self::consent_failed($order)))) {
            // WooCommerce cannot pay a zero-total trial on order-pay. Return to
            // checkout for a fresh consent instead; paid orders still go above.
            $redirect = (float) $order->get_total() > 0 ? $order->get_checkout_payment_url() : wc_get_checkout_url();
        }
        $authorization_pending = !$paid && !$review && !$order->has_status(array('cancelled', 'refunded'))
            && !self::meta($order, 'completed') && !self::meta($order, 'consent_disabled')
            && !self::meta($order, 'payment_id') && self::meta($order, 'consent_state') !== 'ACTIVE'
            && !in_array($status, array('ACTIVE', 'DELETED', 'TIMEOUT', 'DECLINED', 'FAILED'), true);
        return array('status' => $status, 'paid' => $paid, 'redirect' => $redirect, 'authorizationPending' => $authorization_pending);
    }

    public function thankyou($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id || !isset($_GET['key']) || !hash_equals($order->get_order_key(), sanitize_text_field(wp_unslash($_GET['key'])))) {
            return;
        }
        // This also runs on WooCommerce's final order-received page. Rendering
        // the wait poller there redirects a completed order back to itself.
        $state = self::wait_state($order);
        if ($state['paid']) {
            return;
        }
        $token = self::meta($order, 'token');
        $url = '';
        $qr_url = '';
        if (is_string($token) && $token !== '') {
            // Suppress another automatic app launch when Swish returns here.
            $return_url = add_query_arg(array('redirected_from_swish' => 'true'), $this->wait_url($order));
            $url = Woo_Swish_API_Recurring::consent_app_url($token, $return_url);
            if ($this->is_q_payment()) {
                // QR contains the recurring app URL without ret, not a one-time commerce payment token.
                $qr_url = Woo_Swish_API_Recurring::consent_app_url($token);
                $asset_path = WCSW_PATH . 'assets/js/frontend/recurring-qr.asset.php';
                if (file_exists($asset_path)) {
                    $asset = require $asset_path;
                    wp_enqueue_script('swish-recurring-qr', WCSW_URL . 'assets/js/frontend/recurring-qr.js', $asset['dependencies'], $asset['version'], true);
                    if (function_exists('wp_set_script_translations')) {
                        wp_set_script_translations('swish-recurring-qr', 'woo-swish-e-commerce', WCSW_PATH . 'languages/');
                    }
                }
            }
        }
        wp_enqueue_style('swish-ecommerce', WCSW_URL . 'assets/stylesheets/swish.css', array(), WCSW_VERSION);
        require WCSW_PATH . 'views/swish-recurring-wait.php';
        wp_enqueue_script('swish-recurring-wait', WCSW_URL . 'assets/javascript/swish-recurring-wait.js', array(), WCSW_VERSION, true);
        // Recurring has its own "Open Swish on mobile" setting. Do not borrow
        // the one-off gateway's settings or launch an already authorized token.
        $auto_open = $url !== '' && $this->is_m_payment()
            && Woo_Swish_Helper::is_mobile($this->get_option('swish_improved_mobile_detection', 'no'))
            && !Woo_Swish_Helper::is_non_standard_client()
            && !Woo_Swish_Helper::is_redirected_from_swish()
            && $state['authorizationPending'];
        wp_localize_script('swish-recurring-wait', 'swishRecurringWait', array('ajax' => admin_url('admin-ajax.php'), 'order' => $order_id, 'key' => $order->get_order_key(), 'redirect' => $order->get_checkout_order_received_url(), 'appUrl' => $url, 'autoOpen' => $auto_open));
    }

    public static function strip_copy_data($data, $to = null, $from = null)
    {
        foreach ($data as $key => $value) {
            if (strpos((string) $key, self::PREFIX) === 0) {
                unset($data[$key]);
            }
        }
        if (isset($data['meta_input']) && is_array($data['meta_input'])) {
            foreach ($data['meta_input'] as $key => $value) {
                if (strpos((string) $key, self::PREFIX) === 0) {
                    unset($data['meta_input'][$key]);
                }
            }
        }
        return $data;
    }

    public static function subscription_status_changed($subscription, $new_status, $old_status)
    {
        $id = $subscription->get_id();
        $epoch = (int) self::meta($subscription, 'suspension_epoch');
        $scheduled = self::has_renewal_authorization($subscription);
        $internal_hold = ($scheduled || self::has_revocation_scope($subscription)) && $new_status === 'on-hold' && $old_status === 'active';
        $internal_completion = $new_status === 'active' && $old_status === 'on-hold' && ($scheduled || (isset(self::$completing_children[$id]) && self::$completing_children[$id] === $epoch));
        if ($new_status !== $old_status && $subscription->get_payment_method() === 'swish_subscriptions' && !$internal_hold && !$internal_completion) {
            self::save_meta($subscription, array('suspension_epoch' => (int) self::meta($subscription, 'suspension_epoch') + 1));
        }
        if (in_array($new_status, array('cancelled', 'expired', 'switched'), true)) {
            self::maybe_revoke($subscription);
        }
    }

    public static function payment_method_changed($subscription, $new_method, $old_method)
    {
        if ($old_method === 'swish_subscriptions' && $new_method !== $old_method) {
            self::maybe_revoke($subscription);
        }
    }

    public function admin_options()
    {
        parent::admin_options();
        wp_enqueue_script('swish-recurring-admin', Swish_Commerce_Payments::plugin_url() . '/assets/javascript/swish-recurring-admin.js', array('jquery'), WCSW_VERSION, true);
        wp_localize_script('swish-recurring-admin', 'swish_recurring_admin', array(
            'nonce' => wp_create_nonce('swish_subscriptions_connect'),
            'disconnect_nonce' => wp_create_nonce('swish_subscriptions_disconnect'),
            'disconnect' => __('You are about to disconnect BjornTech as Technical Supplier for the separate recurring Swish account. Automatic recurring payments will be unavailable until you connect again.', 'woo-swish-e-commerce'),
            'disconnecting' => __('Disconnecting from BjornTech...', 'woo-swish-e-commerce'),
            'error' => __('Could not connect to BjornTech. Please try again.', 'woo-swish-e-commerce'),
            'starting' => __('Connecting to BjornTech...', 'woo-swish-e-commerce'),
        ));
        $bjorntech_logo = Swish_Commerce_Payments::plugin_url() . '/assets/images/BjornTech_logo_small.png';
        require WCSW_PATH . 'views/swish-recurring-modal-admin.php';
    }

    private static function connection_error($message)
    {
        if (wp_doing_ajax()) {
            wp_send_json_error(array('message' => $message));
        }
        wp_die(esc_html($message));
    }

    public static function connection_status()
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('swish_subscriptions_connect', '_wpnonce', false)) {
            wp_send_json_error(array('message' => __('Not authorized.', 'woo-swish-e-commerce')), 403);
            return;
        }
        if (get_transient('swish_subscriptions_connect_result') === 'success') {
            wp_send_json_success(array('status' => 'success', 'message' => __('The recurring Swish account is now connected.', 'woo-swish-e-commerce')));
        } elseif (get_transient('swish_subscriptions_connect_nonce')) {
            wp_send_json_success(array('status' => 'waiting', 'message' => __('We have sent a mail with the activation link. Click on the link to activate the service.', 'woo-swish-e-commerce')));
        } else {
            wp_send_json_success(array('status' => 'failure', 'message' => __('The link has now expired, please connect again to get a new link.', 'woo-swish-e-commerce')));
        }
    }

    public static function connect_account()
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('swish_subscriptions_connect')) {
            self::connection_error(__('Not authorized.', 'woo-swish-e-commerce'));
        }
        $gateway = self::gateway();
        $callback = WC()->api_request_url('swish_subscriptions_admin', true);
        $merchant = $gateway->get_option('merchant_alias');
        $email = $gateway->get_option('swish_user_email');
        if (wp_doing_ajax() && (!isset($_POST['merchant_alias'], $_POST['user_email']) || sanitize_text_field(wp_unslash($_POST['merchant_alias'])) !== $merchant || sanitize_text_field(wp_unslash($_POST['user_email'])) !== $email)) {
            self::connection_error(__('Enter your recurring Swish Handel number and connection email and save the page before connecting.', 'woo-swish-e-commerce'));
        }
        if (!is_ssl() || !preg_match('/^123[0-9]{7}$/D', $merchant) || !is_email($email)) {
            self::connection_error(__('Save a valid recurring Handel number and connection email first. HTTPS is required.', 'woo-swish-e-commerce'));
        }
        $nonce = bin2hex(random_bytes(32));
        $endpoint = 'https://' . $gateway->service_url;
        $verify = wp_safe_remote_post($endpoint . '/ts-recurring-connect-verify', array('timeout' => 30, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('merchant_alias' => $merchant))));
        $verified = is_wp_error($verify) ? null : json_decode(wp_remote_retrieve_body($verify));
        if (is_wp_error($verify) || wp_remote_retrieve_response_code($verify) !== 200 || !isset($verified->isConnected) || !$verified->isConnected) {
            self::connection_error(__('The recurring Handel number is not connected to BjornTech as technical supplier. Ask your bank to enable recurring payments and set BjornTech as technical supplier.', 'woo-swish-e-commerce'));
        }
        delete_transient('swish_subscriptions_connect_result');
        set_transient('swish_subscriptions_connect_nonce', $nonce, HOUR_IN_SECONDS);
        $response = wp_safe_remote_post($endpoint . '/connect', array('timeout' => 30, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('email' => $email, 'version' => $gateway->version, 'merchant_alias' => $merchant, 'nonce' => $nonce, 'redirect_uri' => $callback, 'admin_url' => admin_url('admin.php?page=wc-settings&tab=checkout&section=swish_subscriptions')))));
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || !isset($body->account_uuid)) {
            delete_transient('swish_subscriptions_connect_nonce');
            self::connection_error(__('Could not connect to BjornTech. Check the recurring account configuration.', 'woo-swish-e-commerce'));
        }
        $gateway->update_option('swish_account_uuid', sanitize_text_field($body->account_uuid));
        if (wp_doing_ajax()) {
            wp_send_json_success(array('status' => 'waiting', 'message' => __('We have sent a mail with the activation link. Click on the link to activate the service.', 'woo-swish-e-commerce')));
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=swish_subscriptions'));
        exit;
    }

    public static function disconnect_account()
    {
        if (!current_user_can('manage_woocommerce') || (wp_doing_ajax() ? !check_ajax_referer('swish_subscriptions_disconnect', '_wpnonce', false) : !check_admin_referer('swish_subscriptions_disconnect'))) {
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => __('Not authorized.', 'woo-swish-e-commerce')), 403);
                return;
            }
            self::connection_error(__('Not authorized.', 'woo-swish-e-commerce'));
        }
        $gateway = self::gateway();
        $uuid = $gateway->get_option('swish_account_uuid', '');
        $normal = get_option('woocommerce_swish_settings', array());
        // Never send a shared/legacy normal account identity to the service.
        if ($uuid && ((!empty($normal['swish_account_uuid']) && $uuid === $normal['swish_account_uuid']) || (!empty($normal['merchant_alias']) && $gateway->get_option('merchant_alias') === $normal['merchant_alias']))) {
            self::connection_error(__('The recurring account must be separate from the normal Swish account. Contact BjornTech before disconnecting shared credentials.', 'woo-swish-e-commerce'));
        }
        if ($uuid) {
            $params = array('email' => $gateway->get_option('swish_user_email'), 'version' => $gateway->version, 'merchant_alias' => $gateway->get_option('merchant_alias'), 'nonce' => wp_create_nonce('swish_subscriptions_disconnect'), 'uuid' => $uuid);
            $url = 'https://' . $gateway->service_url . '/disconnect?' . http_build_query(array('redirect_uri' => home_url(), 'state' => base64_encode(wp_json_encode($params))));
            $response = wp_safe_remote_get($url, array('timeout' => 20));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
                self::connection_error(__('Could not disconnect the recurring Swish account. Please try again.', 'woo-swish-e-commerce'));
            }
        }
        $gateway->update_option('swish_account_uuid', '');
        $gateway->update_option('swish_refresh_token', '');
        $gateway->account_uuid = '';
        delete_transient('swish_access_token_swish_subscriptions');
        delete_transient('swish_subscriptions_connect_nonce');
        delete_transient('swish_subscriptions_connect_result');
        if (wp_doing_ajax()) {
            wp_send_json_success(array('status' => 'success', 'message' => __('The recurring Swish account is now disconnected.', 'woo-swish-e-commerce')));
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=swish_subscriptions'));
        exit;
    }

    private static function maybe_revoke($subscription)
    {
        $id = self::meta($subscription, 'consent_id');
        $parent_id = (int) self::meta($subscription, 'parent_id');
        if (!$id || !$parent_id) {
            // Before initial payment succeeds the consent belongs only to the
            // parent order. Verify the real WCS relationship, never guess an ID.
            $parent_id = (int) $subscription->get_parent_id();
            $parent = wc_get_order($parent_id);
            if (!$parent || $parent->get_payment_method() !== 'swish_subscriptions' || $parent->get_customer_id() !== $subscription->get_customer_id() || !function_exists('wcs_get_subscriptions_for_order')) {
                return;
            }
            $related = wcs_get_subscriptions_for_order($parent, array('order_type' => 'parent'));
            $found = false;
            foreach ($related as $child) {
                if ($child->get_id() === $subscription->get_id()) {
                    $found = true;
                    break;
                }
            }
            if (!$found || !($id = self::meta($parent, 'consent_id'))) {
                return;
            }
            try {
                self::gateway()->bind_context($parent);
            } catch (Throwable $error) {
                $parent->add_order_note(__('Swish consent account changed. Review revocation manually.', 'woo-swish-e-commerce'));
                return;
            }
        }
        if (!$id || !$parent_id) {
            return;
        }
        self::save_meta($subscription, array('revocation_pending' => 'yes', 'revocation_id' => $id, 'revocation_parent' => $parent_id));
        self::reconcile_revocation($subscription->get_id());
    }

    public static function reconcile_revocation($subscription_id)
    {
        $subscription = wc_get_order($subscription_id);
        if (!$subscription || !self::meta($subscription, 'revocation_pending')) {
            return;
        }
        $id = self::meta($subscription, 'revocation_id');
        $parent_id = (int) self::meta($subscription, 'revocation_parent');
        $lock = self::lock($parent_id);
        if (!$lock) {
            self::queue_revocation($subscription_id);
            return;
        }
        try {
            self::revoke_under_lock($subscription, $id, $parent_id);
        } catch (Throwable $error) {
            self::gateway()->logger->add('Consent revocation pending for subscription ' . $subscription_id . ': ' . $error->getMessage());
            self::queue_revocation($subscription_id);
        } finally {
            self::unlock($lock);
        }
    }

    private static function queue_revocation($subscription_id)
    {
        if (!wp_next_scheduled('swish_subscriptions_revoke', array($subscription_id))) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'swish_subscriptions_revoke', array($subscription_id));
        }
    }

    private static function revoke_under_lock($subscription, $id, $parent_id)
    {
        $parent = self::fresh_order($parent_id);
        if (!$parent || !function_exists('wcs_get_subscriptions_for_order') || !$id || self::meta($parent, 'consent_id') !== $id) {
            throw new RuntimeException('Consent parent not verified for revocation.');
        }
        $gateway = self::gateway();
        $gateway->bind_context($parent);
        self::assert_owned($parent);
        $related = wcs_get_subscriptions_for_order($parent, array('order_type' => 'parent'));
        $found = false;
        foreach ($related as $other) {
            if ($other->get_id() === $subscription->get_id()) {
                $found = true;
            }
            if ($other->get_id() !== $subscription->get_id() && (!self::meta($other, 'consent_id') || self::meta($other, 'consent_id') === $id) && $other->get_payment_method() === 'swish_subscriptions' && !$other->has_status(array('cancelled', 'expired', 'switched'))) {
                $subscription->delete_meta_data(self::PREFIX . 'consent_id');
                $subscription->delete_meta_data(self::PREFIX . 'revocation_pending');
                $subscription->delete_meta_data(self::PREFIX . 'revocation_id');
                $subscription->delete_meta_data(self::PREFIX . 'revocation_parent');
                $subscription->save();
                return;
            }
        }
        if (!$found || $parent->get_customer_id() !== $subscription->get_customer_id()) {
            throw new RuntimeException('Subscription no longer belongs to the consent parent.');
        }
        $delete_error = null;
        try {
            $gateway->api()->revoke_consent($id);
        } catch (Woo_Swish_API_Exception $error) {
            // A repeated DELETE of an already terminal consent is rejected
            // (e.g. CONSENT_STATE_INVALID); let the GET below decide.
            if ((int) $error->getCode() !== 404) {
                $delete_error = $error;
            }
        }
        try {
            $state = $gateway->api()->get_consent($id);
            if (!isset($state->consentId, $state->status) || $state->consentId !== $id || !in_array($state->status, array('DELETED', 'TIMEOUT', 'DECLINED'), true)) {
                throw $delete_error ?: new RuntimeException('Consent DELETE is not yet confirmed terminal.');
            }
        } catch (Woo_Swish_API_Exception $error) {
            if ((int) $error->getCode() !== 404) {
                throw $error;
            }
        }
        foreach ($related as $other) {
            if (self::meta($other, 'consent_id') === $id && ($other->get_payment_method() !== 'swish_subscriptions' || $other->has_status(array('cancelled', 'expired', 'switched')))) {
                $other->delete_meta_data(self::PREFIX . 'consent_id');
            }
            if (self::meta($other, 'revocation_id') === $id) {
                $other->delete_meta_data(self::PREFIX . 'revocation_pending');
                $other->delete_meta_data(self::PREFIX . 'revocation_id');
                $other->delete_meta_data(self::PREFIX . 'revocation_parent');
            }
            $other->save();
        }
        self::assert_owned($parent);
        self::save_meta($parent, array('consent_disabled' => 'yes'));
    }

    public static function connection_callback()
    {
        $gateway = self::gateway();
        $body = json_decode(file_get_contents('php://input'));
        $nonce = get_transient('swish_subscriptions_connect_nonce');
        if (!$nonce || !isset($body->nonce, $body->uuid, $body->refresh_token) || !hash_equals($nonce, (string) $body->nonce) || !hash_equals((string) $gateway->account_uuid, (string) $body->uuid)) {
            status_header(403);
            exit;
        }
        $gateway->update_option('swish_refresh_token', $body->refresh_token);
        delete_transient('swish_access_token_swish_subscriptions');
        set_transient('swish_subscriptions_connect_result', 'success', HOUR_IN_SECONDS);
        delete_transient('swish_subscriptions_connect_nonce');
        status_header(200);
        exit;
    }
}
