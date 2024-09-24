<?php
/**
 * This class handles notices to admin
 *
 * @package   Woo Swish e-commerce
 * @author    BjornTech <hello@bjorntech.com>
 * @license   GPL-3.0
 * @link      https://bjorntech.com?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product
 * @copyright 2017-2020 BjornTech - BjornTech AB
 */

defined('ABSPATH') || exit;

if (class_exists('SW_Notice', false)) {
    new SW_Notice();
    return;
}
class SW_Notice
{
    public function __construct()
    {
        add_action('admin_notices', array($this, 'check_displaylist'), 100);
        add_action('wp_ajax_swish_clear_notice', array($this, 'ajax_clear_notice'));
    }

    public static function add($message, $type = 'error', $dismiss = true, $id = false, $time = false)
    {

        $notices = get_site_transient('swish_notices');
        if (!$notices) {
            $notices = array();
        }
        $notice = array(
            'type' => $type,
            'valid_to' => $time === false ? false : $time,
            'messsage' => $message,
            'dismissable' => $dismiss,
        );

        $id = $id === false ? uniqid('id-') : 'id-' . esc_html($id);
        $notices[$id] = $notice;

        set_site_transient('swish_notices', $notices);

        return $id;
    }

    public static function clear($id = false)
    {
        $notices = get_site_transient('swish_notices');
        if ($id && isset($notices[$id])) {
            unset($notices[$id]);
        } else {
            $notices = array();
        }
        set_site_transient('swish_notices', $notices);
    }

    public static function display($message, $type = 'error', $dismiss = false, $id = '')
    {
        $dismissable = $dismiss ? 'is-dismissible' : '';
        echo '<div class="sw-notice ' . $dismissable . ' notice notice-' . $type . ' ' . $id . '"><p>' . $message . '</p></div>';
    }

    public function check_displaylist()
    {
        $notices = get_site_transient('swish_notices');

        if (false !== $notices && !empty($notices)) {
            foreach ($notices as $key => $notice) {
                self::display($notice['messsage'], $notice['type'], $notice['dismissable'], $key);
                if ($notice['valid_to'] !== false && $notice['valid_to'] < time()) {
                    unset($notices[$key]);
                }
            }
        }

        set_site_transient('swish_notices', $notices);
    }

    public function ajax_clear_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_send_json_error();
        }

        if (isset($_POST['parents'])) {
            $id = substr($_POST['parents'], strpos($_POST['parents'], 'id-'));
            sw_notice::clear($id);
        }

        $response = array(
            'status' => 'success',
        );

        wp_send_json($response);

    }

}

new SW_Notice();
