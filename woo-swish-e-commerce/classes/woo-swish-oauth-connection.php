<?php
/** One-use OAuth connection state and atomic credential updates. */
defined('ABSPATH') || exit;

class Woo_Swish_OAuth_Connection
{
    private $gateway;

    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }

    private function option_name()
    {
        return 'woocommerce_swish_settings';
    }

    /** Read authoritative settings, not a possibly stale gateway or object cache. */
    private function settings()
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->option_name()));
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not read Swish connection settings.');
        }
        return $raw === null ? array() : (array) maybe_unserialize($raw);
    }

    private function save($expected, $next)
    {
        global $wpdb;
        $name = $this->option_name();
        if ($expected !== $next) {
            $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize($next), $name, maybe_serialize($expected)));
            if ($changed !== 1) {
                throw new RuntimeException('Swish settings changed or could not be saved. Restart the connection.');
            }
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $this->gateway->settings = $next;
        $this->gateway->account_uuid = $next['swish_account_uuid'] ?? '';
        $this->gateway->refresh_token = $next['swish_refresh_token'] ?? '';
        $this->gateway->connection_type = $next['connection_type'] ?? '_service';
        $this->gateway->connection_class = 'Woo_Swish_API' . $this->gateway->connection_type;
    }

    /** Unique-index mutex; never take over a killed worker's unfinished operation. */
    private function locked($operation)
    {
        global $wpdb;
        $name = 'swish_oauth_connection_lock';
        $owner = wp_generate_uuid4();
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $owner));
        if ($inserted !== 1) {
            throw new RuntimeException('Swish connection is already processing.');
        }
        try {
            return $operation();
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $name, $owner));
            wp_cache_delete($name, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function nonempty_string($value)
    {
        return is_string($value) && trim($value) !== '';
    }

    private function assert_admin()
    {
        if (!current_user_can('manage_woocommerce')) {
            throw new RuntimeException('Swish connection changes require store administrator permission.');
        }
    }

    public function start_legacy($send, $ttl)
    {
        $this->assert_admin();
        return $this->locked(function () use ($send, $ttl) {
            $settings = $this->settings();
            $next = $settings;
            unset($next['swish_oauth_pending']);
            $this->save($settings, $next);
            $settings = $next;
            delete_transient('handle_swish_account');
            delete_transient('swish_connect_result');
            $nonce = bin2hex(random_bytes(32));
            $expires = time() + $ttl;
            $response = $send($nonce);
            $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200
                || wp_remote_retrieve_response_code($response) > 299
                || !is_object($body) || !self::nonempty_string($body->account_uuid ?? null)) {
                throw new RuntimeException('The Swish connection request was not accepted.');
            }
            $next = $settings;
            $next['connection_type'] = '_service';
            $next['swish_account_uuid'] = $body->account_uuid;
            $next['swish_oauth_pending'] = array('nonce' => $nonce, 'uuid' => $body->account_uuid, 'expires' => $expires);
            $this->save($settings, $next);
            set_transient('handle_swish_account', $nonce, $ttl);
            return true;
        });
    }

    public function complete_legacy($body)
    {
        if (!is_object($body) || !self::nonempty_string($body->nonce ?? null)
            || !self::nonempty_string($body->uuid ?? null) || !self::nonempty_string($body->refresh_token ?? null)) {
            return false;
        }
        return $this->locked(function () use ($body) {
            $settings = $this->settings();
            $pending = $settings['swish_oauth_pending'] ?? null;
            $uuid = $settings['swish_account_uuid'] ?? null;
            if (($settings['connection_type'] ?? '') !== '_service'
                || !is_array($pending) || !self::nonempty_string($pending['nonce'] ?? null)
                || !is_int($pending['expires'] ?? null) || $pending['expires'] <= time()
                || !self::nonempty_string($uuid) || $body->uuid !== $uuid || ($pending['uuid'] ?? null) !== $uuid
                || !hash_equals($pending['nonce'], $body->nonce)) {
                return false;
            }
            $next = $settings;
            unset($next['swish_oauth_pending']);
            $next['swish_refresh_token'] = $body->refresh_token;
            // Consuming state and storing the credential have one atomic commit point.
            $this->save($settings, $next);
            delete_transient('swish_access_token');
            // Keep the UI polling marker until it observes the successful result.
            set_transient('swish_connect_result', 'success', MINUTE_IN_SECONDS);
            return true;
        });
    }

    public function rotate_legacy_refresh_token($account, $expected_token, $token, $access_token, $expires)
    {
        return $this->locked(function () use ($account, $expected_token, $token, $access_token, $expires) {
            $settings = $this->settings();
            if (!in_array($settings['connection_type'] ?? '', array('_service', '_test'), true) || !self::nonempty_string($token)
                || !self::nonempty_string($access_token) || !is_int($expires) || $expires <= 0
                || ($settings['swish_account_uuid'] ?? null) !== $account
                || ($settings['swish_refresh_token'] ?? null) !== $expected_token) {
                throw new RuntimeException('Swish connection changed during credential refresh.');
            }
            $next = $settings;
            $next['swish_refresh_token'] = $token;
            $this->save($settings, $next);
            set_transient('swish_access_token', $access_token, $expires);
        });
    }

    public function disconnect($send)
    {
        $this->assert_admin();
        return $this->locked(function () use ($send) {
            $settings = $this->settings();
            $response = $send($settings);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) > 299) {
                throw new RuntimeException('Could not disconnect Swish.');
            }
            $next = $settings;
            unset($next['swish_oauth_pending']);
            $next['swish_account_uuid'] = '';
            $next['swish_refresh_token'] = '';
            $this->save($settings, $next);
            delete_transient('handle_swish_account');
            delete_transient('swish_connect_result');
            delete_transient('swish_access_token');
        });
    }
}
