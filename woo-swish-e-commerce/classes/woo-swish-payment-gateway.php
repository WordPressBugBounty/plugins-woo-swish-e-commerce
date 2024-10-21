<?php

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Swish extends WC_Payment_Gateway
{

    /**
     * $payer_alias.
     *
     * @var string
     * @access public
     */
    public $payer_alias;

    /**
     * @var bool
     */
    private static $shutdown_called = false;

    /**
     * @var Woo_Swish_Log
     * @access public
     */

    public $logger;

    public $service_url;

    public $merchant_alias;

    public $version;

    public $connection_type;

    public $custom_swish_wait_url;

    public $account_uuid;

    public $connection_class;

    public $refresh_token;

    public $instructions;

    public $enable_for_methods = array();

    public $form_fields = array();

    /**
     * __construct function.
     *
     * The class construct
     *
     * @access public
     * @return void
     */
    public function __construct()
    {
        $this->id = 'swish';
        $this->method_title = __('Swish', 'woo-swish-e-commerce');
        $this->method_description = __('Receive payments using Swish e-commerce.', 'woo-swish-e-commerce');
        $this->icon = '';
        $this->has_fields = true;
        $this->version = WCSW_VERSION;

        $this->supports = array(
            'products',
            'refunds',
        );

        // Load the form fields and settings
        $this->logger = new Woo_Swish_Log(!wc_string_to_bool($this->get_option('debug_log')));
        $this->init_settings();
        $this->init_form_fields();

        // Get gateway variables
        $this->title = $this->get_option('title');
        $this->description = Woo_Swish_Helper::is_m_payment($this) ? $this->get_option('mobile_description', __('Press proceed to start the payment in the Swish App. You will be redirected back to the shop after the payment is completed.', 'woo-swish-e-commerce')) : $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->enable_for_methods = $this->get_option('enable_for_methods', array());

        $this->custom_swish_wait_url = get_site_url() . '/wait-for-swish/';

        $this->account_uuid = $this->get_option('swish_account_uuid');
        $this->service_url = ($service_url = $this->get_option('swish_service_url')) ? $service_url : WCSW_SERVICE_URL;
        $this->connection_type = $this->get_option('connection_type');

        if ('_test' == $this->connection_type) {
            $this->merchant_alias = '1234679304';
        } else {
            $this->merchant_alias = $this->get_option('merchant_alias');
        }

        if (!get_site_transient('swish_activated_or_upgraded')) {

            $this->logger->add(sprintf('Swish activated or upgraded connection type = %s, merchant alias = %s', $this->connection_type, $this->merchant_alias));

            if ($this->merchant_alias && !$this->connection_type) {
                $this->connection_type = '_legacy';
                $this->update_option('connection_type', $this->connection_type);
                $this->logger->add('Swish connection type changed to _legacy');
            }

            delete_site_transient('swish_access_token');

            set_site_transient('swish_activated_or_upgraded', date('c'));

        }

        $this->connection_class = 'Woo_Swish_API' . $this->connection_type;

        $nss_database = $this->get_option('swish_nssdatabase');
        if ($nss_database != '') {
            putenv("SSL_DIR=" . $nss_database);
        }

    }

    /**
     * hooks_and_filters function.
     *
     * Applies plugin hooks and filters
     *
     * @access public
     * @return void
     */
    public function hooks_and_filters()
    {

        add_action('admin_init', array($this, 'authorize_processing'));
        add_action('woocommerce_api_swish_admin', array($this, 'admin_handler'));
        add_action('woocommerce_api_swish', array($this, 'callback_handler'));
        add_action('wp_ajax_nopriv_wait_for_payment', array($this, 'ajax_wait_for_payment'));
        add_action('wp_ajax_wait_for_payment', array($this, 'ajax_wait_for_payment'));
        add_action('swish_ecommerce_after_swish_logo', array($this, 'show_goto_swish_button'));
        add_action('swish_ecommerce_after_swish_logo', array($this, 'redirect_to_swish'), 11);
        add_filter('woocommerce_gateway_icon', array($this, 'apply_gateway_icons'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'add_styles_and_scripts'));
        add_filter('learndash_woocommerce_manual_payment_methods', array($this, 'learndash_filter'));
        add_filter('woocommerce_payment_complete_order_status', array($this, 'payment_complete_order_status'), 20, 3);
        add_action('before_woocommerce_pay', array($this, 'checkout_form_init'));
        add_action('rest_api_init', array($this, 'register_rest_api'));
        add_action('swish_retrieve_payment_info', array($this, 'retrieve_payment_info'));
        add_action('swish_retrieve_payment_info_delayed', array($this, 'retrieve_payment_info'));

        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'add_admin_styles_and_scripts'));
            add_action('wp_ajax_swish_clear_notice', array($this, 'ajax_clear_notice'));
            add_action('admin_notices', array($this, 'generate_messages'), 50);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('in_admin_header', array($this, 'swish_modal_admin'));
            add_action('wp_ajax_wait_for_admin', array($this, 'ajax_wait_for_admin'));
            add_action('wp_ajax_connect_swish_service', array($this, 'ajax_connect_swish_service'));
            add_action('wp_ajax_disconnect_swish_service', array($this, 'ajax_disconnect_swish_service'));
            add_action('wp_ajax_swish_retrieve_transaction', array($this, 'ajax_swish_retrieve_transaction'));
            add_filter('swish_shipping_options', array($this, 'swish_shipping_options'));
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 10, 2);
        }

        add_filter('woocommerce_thankyou_order_received_text', array($this, 'swish_thankyou_text'), 20, 2);

        if (!($checkout_type = $this->get_option('swish_checkout_type'))) {
            add_action('woocommerce_thankyou_swish', array($this, 'swish_thankyou'), 100);
        } elseif ('modal' == $checkout_type) {
            add_action('woocommerce_thankyou_swish', array($this, 'swish_thankyou_modal'), 100);
        } elseif ('legacy' == $checkout_type) {
            add_action('woocommerce_thankyou_swish', array($this, 'swish_thankyou_legacy'));
        } elseif ($checkout_type == 'seperate_internal') {
            add_filter('template_include', array($this, 'swish_wait_page_template'));
            add_filter('block_core_navigation_render_inner_blocks', array($this, 'exclude_wait_for_swish_from_fse_nav_bar'), 10, 1);
            add_filter('wp_get_nav_menu_items', array($this, 'exclude_wait_for_swish_from_menu_items'), 10, 3);
            add_shortcode('bjorntech_swish_wait_page', array($this, 'swish_wait_page_content'));
            add_action('wp_enqueue_scripts', array($this, 'remove_all_theme_styles'), 9999);
            add_filter('get_pages', array($this,'log_get_pages'), 10, 2);
        } elseif ($checkout_type == 'seperate_internal_v2') {
            add_filter('init', array($this, 'swish_wait_page_template_v2'));
            add_shortcode('bjorntech_swish_wait_page', array($this, 'swish_wait_page_content'));
        }

        add_filter('init', array($this, 'maybe_insert_wait_for_swish_page'));

        add_action('admin_init', array($this, 'display_northmill_notice'));

        if (wc_string_to_bool(WC_SEC()->get_option('product_age_limits'))) {
            require_once WCSW_PATH . 'classes/woo-swish-product-age-limit.php';
        }

        if (wc_string_to_bool(WC_SEC()->get_option('swish_alias_mirror_billing_phone'))) {
            add_action('wp_footer', array($this, 'swish_alias_mirror_billing_phone'));
        }

        if ($site_age_limit = WC_SEC()->get_option('site_age_limit')) {
            require_once WCSW_PATH . 'classes/woo-swish-site-age-limit.php';
            new WC_Swish_Site_Age_Limit($site_age_limit);
        }

        do_action('bjorntech_swish_gateway_initiated');

    }

    public function display_northmill_notice() {
        if (!(get_option('swish_northmill_notice_displayed') == 'yes')) {
            $notice =   __('<h2>Swish - Cheap and easy</h2>
                        <p>BjornTech can now offer an attractive Swish Handel solution through our partnership with Northmill Bank. Through this offer you\'ll get these benefits:</p>
                        <ul>
                            <li>• Low fees (1,75 SEK per transaction and 59 SEK per month if you sign up through our link)</li>
                            <li>• Easy bookkeeping - the money will be paid out to your account in batches with accounting information attached - making accounting much easier</li>
                            <li>• No need to switch bank. You use Northmill along with your current bank</li>
                            <li>• Get started fast - within 48 hours</li>
                        </ul>
                        <p><a href="https://bjorntech.com/kb/bjorntech-and-northmill/">Click here to learn more</a></p>', 'woo-swish-e-commerce');

            $id = SW_Notice::add($notice, 'warning', true, 'swish_northmill_notice');

            update_option('swish_northmill_notice_displayed', 'yes');

            $this->log('Updated Swish northmill notice displayed to yes');
        }

    }

    public function swish_wait_page_template_v2() {
        $current_url = home_url(add_query_arg(null, null));
        $wait_for_swish_path = '/wait-for-swish';

        if (strpos($current_url, $wait_for_swish_path) === false) {
            return;
        }

        $order_id = isset($_GET['bt_swish_order_id']) ? intval($_GET['bt_swish_order_id']) : 0;

        $order = wc_get_order($order_id);

        $swish_url = Woo_Swish_Helper::generate_swish_url(Woo_Swish_Helper::get_m_payment_reference($order), $this->generate_redirect_url($order));

        if ($order_id) {
            // Check if separate_internal_v2 is selected
            if ($this->get_option('swish_checkout_type') === 'seperate_internal_v2' && $this->get_option('swish_enable_react_wait_page','yes') == 'yes') {
                $this->log(sprintf('swish_wait_page_template_v2(%s): React wait page is enabled', $order_id));
                // Output the HTML structure
                ?>
                <!DOCTYPE html>
                <html style="height: 100%;" <?php language_attributes(); ?>>
                    <head>
                        <meta charset="<?php bloginfo('charset'); ?>">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <?php 
                            wp_head(); 
                        
                            wp_enqueue_script('wp-element');
                            wp_enqueue_script('wp-components');
                            wp_enqueue_script('wp-i18n');

                            $script_asset_path = plugins_url('assets/js/frontend2/wait-page.asset.php', dirname(__FILE__));
                            $script_asset = file_exists($script_asset_path) ? require($script_asset_path) : array('dependencies' => array(), 'version' => '1.0');
                            // Enqueue scripts and styles
                            wp_enqueue_script(
                                'swish-wait-page-react', 
                                plugins_url('assets/js/frontend2/wait-page.js', dirname(__FILE__)), 
                                $script_asset['dependencies'],
                                $script_asset['version']
                            );

                            $script_variables = array(
                                'orderId' => $order_id,
                                'ajaxUrl' => admin_url('admin-ajax.php'),
                                'nonce' => wp_create_nonce('ajax_swish'),
                                'swishLogoText' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Text.png',
                                'swishCircle' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Circle.png',
                                'initialMessage' => __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce'),
                                'showGotoSwishButton' => $this->show_goto_swish_button_in_react($order),
                                'swishRedirectUrl' => $swish_url,
                                'willRedirect' => $this->get_option('swish_redirect_on_mobile') == 'yes' && wp_is_mobile() && !Woo_Swish_Helper::is_redirected_from_swish() && !Woo_Swish_Helper::is_non_standard_client() && !Woo_Swish_Helper::is_swish_declined_or_paid($order),
                                'showGotoSwishButtonText' => __('Open Swish', 'woo-swish-e-commerce')
                            );

                            wp_localize_script('swish-wait-page-react', 'swishWaitPageData', $script_variables);
                        
                        ?>
                        <style>
                            body {
                                font-family: Arial, sans-serif;
                                background-color: #f0f0f0;
                                margin: 0;
                                padding: 0;
                                min-height: 100vh;
                                display: flex;
                                justify-content: center;
                                align-items: center;
                            }
                            #swish-wait-page-root {
                                width: 100%;
                                max-width: 600px;
                                margin: 0 auto;
                                padding: 20px;
                                background-color: #ffffff;
                                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                            }

                            @media (max-width: 600px) {
                                body {
                                    background-color: #ffffff;
                                }
                                #swish-wait-page-root {
                                    box-shadow: none;
                                    padding: 20px;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div id="swish-wait-page-root"></div>
                        <?php wp_footer(); ?>
                    </body>
                </html>
                <?php
                die;
            } else {
                $this->log(sprintf('swish_wait_page_template_v2(%s): React wait page is disabled', $order_id));
                // Use the existing template for other options
                include WCSW_PATH . 'classes/templates/swish-wait-page.php';
                die;
            }
        }
    }

    public function log($message) {
        if (!isset($this->logger)) {
            $this->logger = new Woo_Swish_Log(!wc_string_to_bool($this->get_option('debug_log')));
        }

        $this->logger->add($message);
    }

    /**
     * add_styles_and_scripts function.
     *
     * Applies styles and scripts
     *
     * @access public
     * @return void
     */
    public function add_styles_and_scripts()
    {

        wp_register_style('swish-ecommerce', Swish_Commerce_Payments::plugin_url() . '/assets/stylesheets/swish.css', array(), $this->version);
        wp_enqueue_style('swish-ecommerce');

        wp_register_script('waiting-for-swish-callback', Swish_Commerce_Payments::plugin_url() . '/assets/javascript/swish.js', array('jquery'), $this->version);
        wp_enqueue_script('waiting-for-swish-callback');
        wp_localize_script('waiting-for-swish-callback', 'swish', array(
            'logo' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Primary_Light-BG_SVG.svg',
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ajax_swish'),
            'message' => __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce')));

        /*if ($this->get_option('swish_checkout_type') === 'seperate_internal_v2') {
            wp_enqueue_script('swish-wait-page-react', Swish_Commerce_Payments::plugin_url() . '/assets/js/frontend2/wait-page.js', array(), $this->version, true);
            wp_localize_script('swish-wait-page-react', 'swishWaitPageData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ajax_swish'),
                'swishLogo' => Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Primary_Light-BG_SVG.svg',
                'initialMessage' => __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce')
            ));
        }*/
    }

    /**
     * add_admin_styles_and_scripts function.
     *
     * Applies styles and scripts to admin pages
     *
     * @access public
     * @return void
     */
    public function add_admin_styles_and_scripts($hook)
    {

        wp_register_style('swish-ecommerce', Swish_Commerce_Payments::plugin_url() . '/assets/stylesheets/swish.css', array(), $this->version);
        wp_enqueue_style('swish-ecommerce');

        wp_register_script('swish-ecommerce-admin', Swish_Commerce_Payments::plugin_url() . '/assets/javascript/swish-admin.js', array('jquery'), $this->version);
        wp_enqueue_script('swish-ecommerce-admin');
        wp_localize_script('swish-ecommerce-admin', 'swish_admin', array(
            'nonce' => wp_create_nonce('ajax_swish_admin'),
            'connect' => __('I agree to the BjornTech Privacy Policy', 'woo-swish-e-commerce'),
            'disconnect' => __('You are about to disconnect BjornTech as Technical Supplier', 'woo-swish-e-commerce'),
        ));
    }

    /**
     * Set custom custom order state after payment if configured
     *
     * @access public
     *
     * @since 3.0.0
     *
     * @param string $status
     * @param string $order_id
     * @param WC_Order $order
     *
     * @return string $status
     */
    public function payment_complete_order_status($status, $order_id = 0, $order = false)
    {
        if ($order && 'swish' == $order->get_payment_method() && ($preferred_status = $this->get_option('swish_order_state'))) {
            $status = $preferred_status;
        }
        return $status;
    }

    public function ajax_connect_swish_service()
    {

        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_die();
        }

        $response = array();
        $merchant_alias = $this->get_option('merchant_alias');
        $swish_user_email = $this->get_option('swish_user_email');

        $home_url = WC()->api_request_url('swish_admin', true);

        if (strpos($home_url, 'https') === false) {
            $response = array(
                'result' => 'error',
                'message' => __('The site must be configured with https to work with Swish.', 'woo-swish-e-commerce'),
            );
        } elseif (strpos($home_url, 'localhost') !== false) {
            $response = array(
                'result' => 'error',
                'message' => __('Swish is using a callback and the server must be reachable from internet to work.', 'woo-swish-e-commerce'),
            );
        } elseif ($merchant_alias == '' || $merchant_alias != $_POST['merchant_alias']) {
            $response = array(
                'result' => 'error',
                'message' => __('Enter your "Swish Handel" number and save the page before connecting.', 'woo-swish-e-commerce'),
            );
        } elseif (strlen($merchant_alias) != 10 || strpos($merchant_alias, '123') !== 0) {
            $response = array(
                'result' => 'error',
                'message' => __('The "Swish Handel" number must start with 123, be 10 digits long and not contain any spaces.', 'woo-swish-e-commerce'),
            );
        } elseif ($swish_user_email == '' || ($swish_user_email != $_POST['user_email'])) {
            $response = array(
                'result' => 'error',
                'message' => __('Enter the address to where the verification mail is sent and save the page before connecting.', 'woo-swish-e-commerce'),
            );
        } else {
            $ts_verify_body = json_encode(array(
                'merchant_alias' => $merchant_alias,
            ));

            $ts_response = wp_safe_remote_post('https://' . $this->service_url . '/ts-connect-verify', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30,
                'body' => $ts_verify_body,
            ));


            if (is_wp_error($ts_response)) {
                $response = array(
                    'result' => 'error',
                    'message' => __('Something went wrong when trying to verify the connection to the BjornTech service.', 'woo-swish-e-commerce'),
                );

                wp_send_json($response);
                return;
            } else {
                $ts_response_body = json_decode(wp_remote_retrieve_body($ts_response), true);

                $isConnected = rest_sanitize_boolean($ts_response_body['isConnected']);
                //Log
                if (!$isConnected) {

                    $response_header = sprintf('<h1>%s</h1>', __('BjornTech is not set as technical supplier', 'woo-swish-e-commerce'));

                    $response_body = sprintf('<div>%s</div>', sprintf(__('<p>BjornTech is not set as technical supplier for number %s</p>
                        
                        <p>Please contact your Bank and ask them to set BjornTech as technical supplier for your number</p>
                        
                        <p>Remember to check that the number is a Swish Handel number and that it is connected to technical supplier BjornTech AB (with ID 9872884581)</p>

                        <p>Click <a href="https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product" target="_blank">here</a> for more information</p>', 'woo-swish-e-commerce'), $merchant_alias));

                    $response = array(
                        'result' => 'not_connected',
                        'message' => $response_header . $response_body,
                    );

                    wp_send_json($response);
                    return;
                }
            }

            $this->update_option('connection_type', '_service');

            $nonce = wp_create_nonce('handle_swish_account');
            set_site_transient('handle_swish_account', $nonce, WEEK_IN_SECONDS);

            

            $body = json_encode(array(
                'email' => $swish_user_email,
                'version' => $this->version,
                'merchant_alias' => $merchant_alias,
                'nonce' => $nonce,
                'redirect_uri' => $home_url,
            ));

            $response = wp_safe_remote_post('https://' . $this->service_url . '/connect', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30,
                'body' => $body,
            ));

            if (is_wp_error($response)) {

                $code = $response->get_error_code();
                $error = $response->get_error_message($code);
                $response_body = json_decode(wp_remote_retrieve_body($response));
                $this->logger->add(print_r($code, true));
                $this->logger->add(print_r($error, true));
                $this->logger->add(print_r($response_body, true));
                $response = array(
                    'result' => 'error',
                    'message' => __('Something went wrong when trying to connect to the BjornTech service.', 'woo-swish-e-commerce'),
                );
                $this->logger->add('Failed connecing to BjornTech service');

            } else {

                $response_body = json_decode(wp_remote_retrieve_body($response));
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_body->account_uuid) {
                    $this->account_uuid = $response_body->account_uuid;
                    $this->update_option('swish_account_uuid', $this->account_uuid);
                    $this->logger->add(sprintf('BjornTech account uuid %s was created', $this->account_uuid));
                    $response = array(
                        'result' => 'success',
                    );
                } else {
                    $response = array(
                        'result' => 'error',
                        'message' => __('Something went wrong when trying to connect to the BjornTech service.', 'woo-swish-e-commerce'),
                    );
                    $this->logger->add(sprintf('Failed to create BjornTech account, error %s', $response_body->response));
                }
            }
        }

        wp_send_json($response);

    }

    public function ajax_disconnect_swish_service()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_die();
        }
        $auth_url = 'https://' . $this->service_url . '/disconnect';
        $site_params = array(
            'email' => $this->get_option('swish_user_email'),
            'version' => $this->version,
            'merchant_alias' => $this->get_option('merchant_alias'),
            'nonce' => wp_create_nonce('handle_swish_account'),
            'uuid' => $this->account_uuid,
        );
        $encoded_params = base64_encode(json_encode($site_params));
        $home_url = home_url();

        $url = $auth_url . '?redirect_uri=' . $home_url . '&state=' . $encoded_params;
        $response = wp_safe_remote_get($url, array('timeout' => 20));

        if (is_wp_error($response)) {
            $this->logger->add(__('Something went wrong when trying to disconnect from the certificate service', 'woo-swish-e-commerce'));
        } else {
            $this->account_uuid = false;
            $this->update_option('swish_account_uuid', '');
            $this->update_option('swish_refresh_token', '');
            delete_site_transient('swish_access_token');
            $this->logger->add(__('Succcessfully disconnected from the certificate service', 'woo-swish-e-commerce'));
        }

        $response = array(
            'response' => 'success',
        );

        wp_send_json($response);

    }

    public function checkout_form_init()
    {
        if (isset($_GET['pay_for_order'], $_GET['key'])) {
            try {
                $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
                $order_id = wc_get_order_id_by_order_key($order_key);
                $order = wc_get_order($order_id);
                if ('swish' == $order->get_payment_method('edit')) {
                    $transaction_status = $order->get_meta('_swish_transaction_status', true);
                    if ($transaction_status && 'PAID' != $transaction_status) {
                        wc_print_notice(Woo_Swish_Helper::error_code($transaction_status), 'error');
                    }
                }
            } catch (Exception $e) {
                wc_print_notice(Woo_Swish_Helper::error_code(''), 'error');
            }
        }
    }

    public function swish_alias_mirror_billing_phone()
    {

        if (is_checkout()) {
            $target_id = apply_filters('swish_billing_number_mirror_element', '#billing_phone');
            ?>
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        var target_id = <?php echo json_encode($target_id); ?>;
                        var oldValue = $(target_id).val();

                        // Set the initial value
                        $("#swish-payer-alias").val(oldValue);

                        // Function to check for value changes and trigger input event
                        function checkValue() {
                            var $target = $(target_id);
                            if ($target.length) {
                                var newValue = $target.val();
                                if (newValue !== oldValue) {
                                    $("#swish-payer-alias").val(newValue);
                                    $target.trigger('input');
                                    oldValue = newValue;
                                }
                            }
                        }

                        // Poll for value changes every 100 ms
                        setInterval(checkValue, 100);

                        // Update on change
                        $(target_id).on('input', function() {
                            var phone = $(this).val();
                            $("#swish-payer-alias").val(phone);
                        });
                    });
                </script>
                <?php
}
    }

    public function swish_thankyou_text($text, $order)
    {
        $checkout_type = $this->get_option('swish_checkout_type');

        if ($checkout_type == 'seperate_internal' || $checkout_type == 'seperate_internal_v2') {
            return $text;
        }

        if (is_object($order) && $order->get_payment_method() == 'swish') {
            $text = __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce');
        }
        return $text;
    }

    public function learndash_filter($filter)
    {
        if (!in_array('swish', $filter)) {
            array_push($filter, 'swish');
        }
        return $filter;
    }

    public function retrieve_payment_info($order_id)
    {

        try {

            $order = wc_get_order($order_id);
            $payment_uuid = Woo_Swish_Helper::get_payment_uuid($order);
            $payment_body = $this->retreive_transaction($payment_uuid);

            $this->logger->add(sprintf('process_order (%s): Got %s from swish api', $order_id, json_encode($payment_body)));

            $this->process_order($order, $payment_body);

        } catch (Woo_Swish_API_Exception $e) {

            $this->logger->add(sprintf('retrieve_payment_info (%s): Failed to retrieve transaction uuid "%s"', $order_id, $payment_uuid));

        }

    }

    public function log_get_pages($pages, $r) {
        return array_filter($pages, function($page) {
            return $page->post_name !== 'wait-for-swish';
        });
    }

    public function add_payment_retrieve_process($order_id)
    {

        global $woocommerce;

        if (!wc_string_to_bool($this->get_option('poll_for_response', 'yes'))) {
            return;
        }

        if (!version_compare($woocommerce->version, '4.0', ">=")) {
            return;
        }

        if ($this->get_option('swish_improved_queue_handling', 'yes') == 'yes') {
            if (!as_has_scheduled_action('swish_retrieve_payment_info', array($order_id))) {
                // Check if the transient exists
                if (false === get_transient('swish_retrieve_payment_info')) {
                    $this->logger->add(sprintf('add_payment_retrieve_process (%s): Queuing payment for retrieving', $order_id));
                    as_enqueue_async_action('swish_retrieve_payment_info', array($order_id), 'swish_retrieve_payment_info', true);
            
                    // Set the transient to expire in 5 seconds
                    set_transient('swish_retrieve_payment_info', true, 5);
                }
            }
        } else {
            $scheduled_actions = as_get_scheduled_actions(
                array(
                    'hook' => 'swish_retrieve_payment_info',
                    'args' => array($order_id),
                    'status' => ActionScheduler_Store::STATUS_PENDING,
                    'claimed' => false,
                ),
                'ids'
            );
    
            if (empty($scheduled_actions)) {
    
                $this->logger->add(sprintf('add_payment_retrieve_process (%s): Queuing payment for retrieving', $order_id));
                as_schedule_single_action(as_get_datetime_object()->add(new DateInterval('PT10S'))->getTimestamp(), 'swish_retrieve_payment_info', array($order_id));
    
            }
        }

    }

    /**
     * ajax_wait_for_payment.
     *
     * Called from the javascript on the thankyou page
     *
     * @access public
     * @return void
     */
    public function ajax_wait_for_payment()
    {

        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish')) {
            wp_die();
        }

        $this->logger->add(sprintf('Waiting for payment sent url %s', $_POST['url']));

        preg_match('/\=(wc_order_[^&#]*)/', $_POST['url'], $key);

        if (($order_id = wc_get_order_id_by_order_key($key[1])) && ($order = wc_get_order($order_id))) {

            $this->add_payment_retrieve_process($order_id);

            $status = Woo_Swish_Helper::get_transaction_status($order);

            $this->logger->add(sprintf('ajax_wait_for_payment (%s): Waiting for payment %s returned status %s', $order->get_id(), $key[1], $status));

            $response = array(
                'status' => $status,
                'message' => Woo_Swish_Helper::error_code($status),
            );

            $checkout_type = $this->get_option('swish_checkout_type');

            if (($checkout_type == 'seperate_internal' || $checkout_type == 'seperate_internal_v2') && 'PAID' == $status) {
                $redirect = $this->get_return_url($order);
                $this->logger->add(sprintf('ajax_wait_for_payment (%s): Order is paid - passing along redirect %s', $order->get_id(), $redirect));
                $response['redirect_url'] = $this->get_return_url($order);
            }

            if (('WAITING' !== $status) && ('PAID' !== $status)) {

                $redirect_url = $order->get_checkout_payment_url();
                $this->logger->add(sprintf('ajax_wait_for_payment (%s): Redirecting to %s', $order->get_id(), $redirect_url));
                $response['redirect_url'] = $redirect_url;

            }

        } else {

            $response = array(
                'status' => 'ERROR',
                'message' => __('Error processing the payment. Contact the shop support', 'woo-swish-e-commerce'),
            );

        }

        $this->logger->add(sprintf('ajax_wait_for_payment (%s): Response %s', $order->get_id(), json_encode($response)));

        wp_send_json($response);

    }

    public function generate_messages()
    {

        if (('_legacy' == $this->connection_type) && ($swish_cert = WC_SEC()->get_option('merchant_certificate')) && ($cert_info = $this->certificate_info($swish_cert))) {

            $this->logger->add(sprintf('Found certificate for %s issued by %s(%s) at %s valid to %s', $cert_info->merchant_number, $cert_info->issuer, $cert_info->common_name, date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cert_info->valid_from), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cert_info->valid_to)));

            $now = time();

            if ($cert_info->valid_to < $now) {
                $message = sprintf(__('Your certificate %s expired %s. Take the opportunity to start using us as your Technical supplier. Read more <a href="https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product">here</a>', 'woo-swish-e-commerce'), $cert_info->merchant_number, date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cert_info->valid_to));
                $id = sw_notice::add($message, 'error', false, 'cert_expiry_error');
            } elseif ($cert_info->valid_to < $now + WEEK_IN_SECONDS) {
                $message = sprintf(__('Your certificate %s expires %s. Take the opportunity to start using us as your Technical supplier. Read more <a href="https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product">here</a>', 'woo-swish-e-commerce'), $cert_info->merchant_number, date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cert_info->valid_to));
                $id = sw_notice::add($message, 'warning', false, 'cert_expiry_warning');
            }

        }

        if (false !== get_site_transient('swish_activated')) {
            delete_site_transient('swish_activated');

            try {
                sw_notice::clear();
                delete_site_transient('swish_did_show_connection_info');
                delete_site_transient('swish_did_show_legacy_info');
                do_action('swish_force_connection');
            } catch (Woo_Swish_API_Exception $e) {
                $e->write_to_logs();
            }
        }

        if (false !== get_site_transient('swish_upgraded')) {
            delete_site_transient('swish_upgraded');
            try {
                sw_notice::clear();
                do_action('swish_force_connection');
                delete_site_transient('swish_did_show_connection_info');
                delete_site_transient('swish_did_show_legacy_info');
            } catch (Woo_Swish_API_Exception $e) {
                $e->write_to_logs();
            }
        }

        if (!$this->connection_type && !get_site_transient('swish_did_show_connection_info')) {
            $message = sprintf(__('Congratulations! You can now be live with Swish payments within minutes. Go to the <a href="%s">configuration page</a> and select BjornTech as Technical Supplier as your connection type.', 'woo-swish-e-commerce'), get_admin_url(null, 'admin.php?page=wc-settings&tab=checkout&section=swish'));
            $id = sw_notice::add($message, 'info');
            set_site_transient('swish_did_show_connection_info', $id);
        } elseif ($this->connection_type == '_legacy' && !get_site_transient('swish_did_show_legacy_info')) {
            $message = __('Congratulations! Your Swish-plugin is now upgraded. You can continue using it as normal. In this version you can use BjornTech as Technical Supplier, read more <a href="https://bjorntech.com/sv/swish-teknisk-leverantor?utm_source=wp-swish&utm_medium=plugin&utm_campaign=product">here</a>', 'woo-swish-e-commerce');
            $id = sw_notice::add($message, 'info');
            set_site_transient('swish_did_show_legacy_info', $id);
        }

    }

    public function ajax_clear_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_die();
        }

        if (isset($_POST['parents'])) {
            $id = substr($_POST['parents'], strpos($_POST['parents'], 'id-'));
            sw_notice::clear($id);
        }
        $response = array(
            'status' => 'success',
        );

        wp_send_json($response);
        exit;
    }

    /**
     * ajax_wait_for_admin.
     *
     * Called from the javascript on the init service page
     *
     * @access public
     * @return void
     */
    public function ajax_wait_for_admin()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_die();
        }

        $message = '';
        if (get_site_transient('handle_swish_account')) {
            if ($connected = get_site_transient('swish_connect_result')) {
                delete_site_transient('handle_swish_account');
                delete_site_transient('swish_connect_result');
                if ($connected == 'failure') {
                    $message = __('The activation of the account failed', 'woo-swish-e-commerce');
                }
            } else {
                $message = __('We have sent a mail with the activation link. Click on the link to activate the service.', 'woo-swish-e-commerce');
            }
        } else {
            $connected = 'failure';
            $message = __('The link has now expired, please connect again to get a new link.', 'woo-swish-e-commerce');
        }

        $response = array(
            'status' => $connected ? $connected : 'waiting',
            'message' => $message,
        );

        wp_send_json($response);

    }

    public function authorize_processing() {

        global $pagenow;

        if ($pagenow !== 'admin.php') {
            return;
        }
        
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }
        
        if (!isset($_GET['section']) || $_GET['section'] !== 'swish') {
            return;
        }

        $nonce = get_site_transient('handle_swish_account');

        if (array_key_exists('account_uuid', $_REQUEST) && array_key_exists('refresh_token', $_REQUEST)) {
            if (array_key_exists('nonce', $_REQUEST) && $nonce !== false && trim($_REQUEST['nonce']) === $nonce) {
                if ($this->account_uuid == $_REQUEST['account_uuid']) {
                    $this->update_settings_at_init();

                    $this->refresh_token = $_REQUEST['refresh_token'];
                    $this->update_option('swish_refresh_token', WC_SEC()->refresh_token);
                    delete_site_transient('swish_access_token');
                    $this->log(sprintf('Account uuid %s was authorized and got refresh token %s from service', WC_SEC()->account_uuid, WC_SEC()->refresh_token));

                    SW_Notice::add(
                        sprintf(__('Congratulations! The plugin is now connected to the BjornTech Swish service! Please follow the guide <a href="%s">here</a> to proceed!', 'woo-swish-e-commerce'), 'https://bjorntech.com/sv/kb/swish-teknisk-leverantor/'),
                        'success'
                    );

                    wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=swish'));
                    exit;
                } else {
                    $this->log(sprintf('Wrong account UUID %s, should have been %s', $_REQUEST['account_uuid'], WC_SEC()->account_uuid));
                    SW_Notice::add(
                        sprintf(__('Something went wrong when trying to connect the plugin to your Swish Handel, contact hello@bjorntech.com for assistance', 'woo-swish-e-commerce')),
                        'error'
                    );
                }
            } else {
                //$this->log('Nonce not verified at authorize_processing');
            }
        } elseif (array_key_exists('error', $_REQUEST)) {
            $this->log(sprintf('Error when connecting to Bjorntech Swish sevice'));
            SW_Notice::add(
                sprintf(__('Something went wrong when trying to connect the plugin to your Swish Handel, contact hello@bjorntech.com for assistance', 'woo-swish-e-commerce')),
                'error'
            );
        }

        return;

    }

    public function update_settings_at_init () {
        if (!get_option('swish_refresh_token')) {
            $this->update_option('debug_log', 'yes');
            $this->log('Updated debug log to yes');

            $this->log('Updating settings at init');
        } else {
            return;
        }

        $this->update_option('swish_enable_blocks_checkout', 'yes');
        $this->log('Updated Swish blocks checkout to yes');

        $this->update_option('swish_redirect_on_mobile', 'yes');
        $this->log('Updated Swish redirect on mobile to yes');    

        $this->update_option('swish_redirect_back', 'yes');
        $this->log('Updated Swish redirect back to yes');

        $this->update_option('swish_enable_react_wait_page', 'yes');
        $this->log('Updated Swish enable react wait page to yes');

    }

    public function show_goto_swish_button_in_react($order) {
        if (Woo_Swish_Helper::is_non_standard_client()) {
            return false;
        }

        if (Woo_Swish_Helper::is_redirected_from_swish()) {
            return false;
        }

        if (Woo_Swish_Helper::is_swish_declined_or_paid($order)) {
            return false;
        }

        $button_mode = $this->get_option('swish_show_button');

        if (!isset($button_mode)) {
            return false;
        }

        if ('all' !== $button_mode && !wp_is_mobile()) {
            return false;
        }

        return true;
    }

    /**
     * show_goto_swish_button.
     *
     * Called from the action 'swish_ecommerce_after_swish_logo'
     *
     * @access public
     * @param $order_id
     * @return void
     */
    public function show_goto_swish_button($order_id)
    {

        $order = wc_get_order($order_id);

        $swish_url = Woo_Swish_Helper::generate_swish_url(Woo_Swish_Helper::get_m_payment_reference($order), $this->generate_redirect_url($order));

        if (Woo_Swish_Helper::is_redirected_from_swish()) {
            return;
        }

        if (Woo_Swish_Helper::is_non_standard_client()) {
            return;
        }

        if ($this->get_option('swish_checkout_type') == 'seperate_internal' || $this->get_option('swish_checkout_type') == 'seperate_internal_v2') {
            $button_mode = $this->get_option('swish_show_button');
            if ('all' == $button_mode || ('mobile' == $button_mode) && wp_is_mobile()) {?>
                    <div class="swish-button-internal swish-notwaiting">
                        <a class="button gotoswish" onclick="window.location.href='<?php echo $swish_url; ?>';"  href="<?php echo $swish_url; ?>"><?php echo __('Open Swish', 'woo-swish-e-commerce'); ?></a>
                    </div>
                <?php }
        } else {
            $button_mode = $this->get_option('swish_show_button');
            if ('all' == $button_mode || ('mobile' == $button_mode) && wp_is_mobile()) {?>
                    <div class="swish-button swish-centered swish-notwaiting">
                        <a class="button gotoswish" onclick="window.location.href='<?php echo $swish_url; ?>';"  href="<?php echo $swish_url; ?>"><?php echo __('Start Swish app', 'woo-swish-e-commerce'); ?></a>
                    </div>
                <?php }
        }

    }

    public function redirect_to_swish($order_id)
    {

        $order = wc_get_order($order_id);
        
        if (Woo_Swish_Helper::is_redirected_from_swish()) {
            return;
        }

        if (Woo_Swish_Helper::is_swish_declined_or_paid($order)) {
            return;
        }

        $swish_url = Woo_Swish_Helper::generate_swish_url(Woo_Swish_Helper::get_m_payment_reference($order), $this->generate_redirect_url($order));

        if (wc_string_to_bool($this->get_option('swish_redirect_on_mobile'))) {

            if (!wc_string_to_bool($this->get_option('swish_redirect_back'))) {
                if ($order->is_paid()) {
                    return;
                }
            }

            if (wp_is_mobile()) {?>
                    <script>
                        if (document.readyState !== 'loading') {
                            let url = '<?php echo $swish_url; ?>';
                            window.location.href=url;
                        } else {
                            document.addEventListener('DOMContentLoaded', function () {
                                let url = '<?php echo $swish_url; ?>';
                                window.location.href=url;
                            });
                        }
                    </script>
                <?php }
        }
    }

    public function swish_modal_admin()
    {
        $bjorntech_logo = Swish_Commerce_Payments::plugin_url() . '/assets/images/BjornTech_logo_small.png';
        require_once WCSW_PATH . 'views/swish-modal-admin.php';
    }

    /**
     * swish_modal.
     *
     * creates the modal page that shows the wait for payment
     *
     * @access public
     * @return void
     */

    public function swish_thankyou_modal($order_id)
    {
        $this->logger->add('Using modal checkout page');
        $swish_logo_text = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Text.png';
        $swish_circle = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Circle.png';
        $swish_status = __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce');
        require_once WCSW_PATH . 'views/swish-thankyou-modal.php';
    }

    /**
     * swish_thankyou
     *
     * Replaces the ordinary thank-you-page
     * removes the order details and shows the Swish logo instead
     * Includes the javascript checking for payment
     *
     * @access public
     * @return void
     */
    public function swish_thankyou($order_id)
    {

        $this->logger->add('Using standard checkout page');
        $swish_logo_text = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Text.png';
        $swish_circle = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Circle.png';
        $swish_status = __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce');
        require_once WCSW_PATH . 'views/swish-thankyou.php';
    }

    /**
     * swish_thankyou_legacy
     *
     * Replaces the ordinary thank-you-page
     * removes the order details and shows the Swish logo instead
     * Includes the javascript checking for payment
     *
     * @access public
     * @return void
     */
    public function swish_thankyou_legacy($order_id)
    {
        $this->logger->add('Using legacy checkout page');
        remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
        $swish_logo_text = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Text.png';
        $swish_circle = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Circle.png';
        $swish_status = __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce');
        require_once WCSW_PATH . 'views/swish-thankyou-legacy.php';
    }

    public function swish_wait_page_content($atts)
    {
        $order_id = isset($_GET['bt_swish_order_id']) ? intval($_GET['bt_swish_order_id']) : 0;

        WC_SEC()->logger->add('Using standard checkout page for internal wait page - order ' . $order_id);
        $swish_logo_text = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Text.png';
        $swish_circle = Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Circle.png';
        $swish_status = __('Start your Swish App and authorize the payment', 'woo-swish-e-commerce');

        ob_start();

        require_once WCSW_PATH . 'views/swish-thankyou-separate-internal.php';
        $swish_thankyou_code = ob_get_clean();

        // Return the Swish Thankyou code as the content of the wait page
        return apply_filters('swish_thankyou_page_html_content', $swish_thankyou_code, $order_id);
    }

    public function remove_all_theme_styles()
    {
        if (is_page('wait-for-swish')) {
            global $wp_styles, $wp_scripts;

            $approved_styles = [
                'swish-ecommerce',
                'woocommerce-inline',
                'wp-block-library',
            ];

            foreach ($wp_styles->queue as $style) {

                if (in_array($style, $approved_styles)) {
                    continue;
                } else {
                    $handle = $wp_styles->registered[$style]->handle;
                    wp_deregister_style($handle);
                    wp_dequeue_style($handle);
                }

            }
        }
    }

    public function swish_wait_page_template($template)
    {

        if (is_page('wait-for-swish')) {
            $this->logger->add('Using Swish wait page template');
            $template = WCSW_PATH . 'classes/templates/swish-wait-page.php';
        }

        return $template;
    }

    /**
     * Check if the swish_checkout_type option is set to 'separate_internal'. If it is,
     * create or update the "Wait for Swish" page. If it's not, delete the page if it exists.
     */
    public function maybe_insert_wait_for_swish_page()
    {
        // Set the page slug
        $page_slug = 'wait-for-swish';

        // Check if the swish_checkout_type option is set to 'separate_internal'
        $checkout_type = $this->get_option('swish_checkout_type');

        if ($checkout_type == 'seperate_internal') {
            // Check if the page already exists
            $page = get_page_by_path($page_slug);

            if (is_null($page)) {

                // Set up the page data
                $page_data = array(
                    'post_title' => 'Wait for Swish',
                    'post_name' => $page_slug,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_content' => '',
                );

                // Insert the page into the database
                $page_id = wp_insert_post($page_data);

                // If the page was inserted successfully, update its permalink
                if ($page_id) {
                    update_post_meta($page_id, '_wp_page_template', 'page.php'); // Optional: set a custom page template
                    flush_rewrite_rules(); // Optional: flush the rewrite rules to ensure the new page's URL works
                }

                $this->logger->add(sprintf('Wait for Swish page (%s) created', $page_id));

            }

        } else {
            // Delete the page if it exists
            $page = get_page_by_path($page_slug);

            if ($page) {
                wp_delete_post($page->ID, true);

                $this->logger->add(sprintf('Wait for Swish page (%s) deleted', $page->ID));
            }
        }
    }

    public function filter_draft_pages_from_menu($items, $args)
    {

        $page = get_page_by_path('wait-for-swish');

        $page_id = $page->ID;

        foreach ($items as $ix => $obj) {
            if ($page_id == $obj->object_id) {
                unset($items[$ix]);
            }
        }
        return $items;
    }

    public function exclude_wait_for_swish_from_fse_nav_bar($inner_blocks)
    {
        $page = get_page_by_path('wait-for-swish');

        if (!$page || is_null($page)) {
            return $inner_blocks;
        }

        $page_id = $page->ID;

        foreach ($inner_blocks as $key => $inner_block) {

            if (!is_object($inner_block) || !property_exists($inner_block, 'parsed_block')) {
                continue;
            }

            $parsedblock = $inner_block->parsed_block;

            if (!is_array($parsedblock) || !key_exists('attrs', $parsedblock)) {
                continue;
            }

            $attrs = $parsedblock['attrs'];

            if (is_array($attrs) && key_exists('id', $attrs) && $attrs['id'] == $page_id) {
                unset($inner_blocks[$key]);
            }

        }

        return $inner_blocks;
    }

    /* Excludes pages from the navigation bar menu in the front end of site */
    public function exclude_wait_for_swish_from_menu_items($items, $menu, $args)
    {
        $page = get_page_by_path('wait-for-swish');

        $page_id = $page->ID;

        foreach ($items as $key => $item) {
            if ($item->object_id == $page_id) {
                unset($items[$key]);
            }
        }

        return $items;
    }

    /**
     * validate_fields.
     *
     * Validates the swish-number-field
     *
     * @access public
     * @return bool
     */
    public function validate_fields()
    {

        if (Woo_Swish_Helper::is_m_payment()) {
            return true;
        }

        $payer_alias_raw = isset($_POST[esc_attr($this->id) . '-payer-alias']) ? $_POST[esc_attr($this->id) . '-payer-alias'] : '';
        $payer_alias = preg_replace('/[^0-9]/', '', $payer_alias_raw);
        $number_lenght = strlen($payer_alias);
        if ($number_lenght == 0) {
            wc_add_notice(__('Swish number missing', 'woo-swish-e-commerce'), 'error');
            return false;
        }
        if ($payer_alias[0] == '0') {
            $payer_alias = '46' . substr($payer_alias, 1);
            $number_lenght = strlen($payer_alias);
        }

        if (substr($payer_alias, 0, 3) == '460') {
            $payer_alias = '46' . substr($payer_alias, 3);
            $number_lenght = strlen($payer_alias);
        }

        if ($number_lenght < 8) {
            wc_add_notice(__('Swish number must be at least 8 characters long', 'woo-swish-e-commerce'), 'error');
            return false;
        }
        if ($number_lenght > 15) {
            wc_add_notice(__('Swish number can be maximum 15 characters long', 'woo-swish-e-commerce'), 'error');
            return false;
        }
        $this->payer_alias = $payer_alias;
        return true;
    }

    /**
     * payment_fields.
     *
     * @access public
     * @return void
     */
    public function payment_fields()
    {
        if ($this->get_option('connection_type', '_legacy') == '_test') {
            echo wpautop(wptexturize(__("You are running Swish e-commerce in test mode, use 4671234768 to test a payment", 'woo-swish-e-commerce')));
        } else {
            if (!empty($this->description)) {
                echo wpautop(wptexturize($this->description));
            }
        }
        $this->form();
    }

    /**
     * Outputs fields for entering Swish information.
     * @since 1.0.0
     */
    public function form()
    {

        if (Woo_Swish_Helper::is_m_payment()) {
            return;
        }

        $number_text = $this->get_option('number_label', __('Swish number', 'woo-swish-e-commerce'));
        $number_placeholder = $this->get_option('number_placeholder', '');

        echo '<fieldset id="swish-cc-form" class="wc-swish-form wc-payment-form">';

        do_action('woocommerce_swish_form_start', $this->id);

        echo '<p class="form-row form-row-wide">';

        echo '<label for="swish-payer-alias">' . esc_html($number_text) . '<span class="required">*</span></label>';

        echo '<input id="swish-payer-alias" class="input-text wc-swish-form-payer-alias" type="text" autocomplete="off" name="swish-payer-alias" placeholder="' . esc_html($number_placeholder) . '" maxlength="17" />';

        echo '</p>';

        do_action('woocommerce_swish_form_end', $this->id);

        echo '<div class="clear"></div>';

        echo '</fieldset>';

    }

    /**
     * process_payment.
     *
     * Processing payments on checkout
     * @param $order_id
     * @return array|bool
     */
    public function process_payment($order_id)
    {

        try {

            $this->logger->add(sprintf('Processing payment for order %s using connection type %s', $order_id, $this->connection_class));
            $order = wc_get_order($order_id);
            $payment = new stdClass();
            $swish = new $this->connection_class();

            if (apply_filters('woo_swish_skip_process_payment', false, $order)) {
                $this->logger->add(sprintf('Skipping payment processing for order %s', $order_id));

                return array(
                    'result' => 'fail',
                    'redirect' => $order->get_checkout_payment_url(false),
                );
            }

            if ('_test' == $this->connection_type) {
                $payment_uuid = Woo_Swish_Helper::generate_test_id();
            } else {
                $payment_uuid = Woo_Swish_Helper::generate_production_id($this->merchant_alias);
            }

            Woo_Swish_Helper::note($order, sprintf(__('Transaction ID %s generated', 'woo-swish-e-commerce'), $payment_uuid));

            $callback = Woo_Swish_Helper::get_callback_url($order_id);

            if (Woo_Swish_Helper::is_m_payment()) {
                WC_SEC()->logger->add(sprintf('process_payment (%s): Creating m-payment', $order_id));
                $payment = $swish->create(
                    $order,
                    '',
                    $this->merchant_alias,
                    $payment_uuid,
                    $callback
                );
            } else {
                WC_SEC()->logger->add(sprintf('process_payment (%s): Creating e-payment', $order_id));
                $payment = $swish->create(
                    $order,
                    $this->payer_alias,
                    $this->merchant_alias,
                    $payment_uuid,
                    $callback
                );
            }

            if (Woo_Swish_Helper::is_m_payment()) {
                Woo_Swish_Helper::set_m_payment_reference($order, $payment->payment_request_token);
            }

            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

            if ($user_agent) {
                $this->log(sprintf('process_payment (%s): User agent %s', $order_id, $user_agent));
            } else {
                $this->log(sprintf('process_payment (%s): User agent not found', $order_id));
            }

            Woo_Swish_Helper::set_transaction_location($order, isset($payment->location) ? $payment->location : '');
            Woo_Swish_Helper::set_transaction_status($order, 'WAITING');
            Woo_Swish_Helper::note($order, sprintf(__('Payment %s initiated', 'woo-swish-e-commerce'), $payment_uuid));
            $order->update_status('pending');
            $order->save();

            $redirect_url = $this->generate_redirect_url($order);

            as_schedule_single_action(as_get_datetime_object()->add(new DateInterval('PT5M'))->getTimestamp(), 'swish_retrieve_payment_info_delayed', array($order_id));
            
            $this->logger->add(sprintf('process_payment (%s): Payment %s initiated successfully using callback %s and redirecting to %s', $order_id, $payment_uuid, $callback, $redirect_url));
            return array(
                'result' => 'success',
                'redirect' => $redirect_url,
            );

        } catch (Woo_Swish_API_Exception $e) {
            $e->write_to_logs();
            $message = $e->getMessage();

            if ($e->getCode() == 403 && $e->getMessage() == 'User is not authorized to access this resource with an explicit deny') {
                $message = __('The Swish service is not yet connected to the site. Please click the link in the activation email that was sent out when you started the connection process in the Swish plugin settings', 'woo-swish-e-commerce');
            }

            wc_add_notice($message, 'error');
            if ($this->get_option('swish_enable_blocks_checkout', 'no') == 'yes') {
                return array(
                    'result' => 'failure',
                    'errorMessage' => $message,
                    'redirect' => wc_get_checkout_url(),
                );
            } else {
                return false;
            }
        }

    }

    public function generate_redirect_url($order) {

        $order_id = $order->get_id();
        if ($this->get_option('swish_checkout_type') == 'seperate_internal' || $this->get_option('swish_checkout_type') == 'seperate_internal_v2') {
            $redirect_url = $this->custom_swish_wait_url . '?key=' . $order->get_order_key() . '&bt_swish_order_id=' . $order_id;
        } else {
            $redirect_url = $this->get_return_url($order);
        }

        WC_SEC()->logger->add(sprintf('generate_redirect_url(%s): %s', $order_id, $redirect_url));

        return $redirect_url;
    }

    /**
     * Process refunds
     *
     * @param  int $order_id
     * @param  float $amount
     * @param  string $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        try {

            $order = wc_get_order($order_id);

            if (!($payment_reference = Woo_Swish_Helper::get_payment_reference($order))) {
                throw new Woo_Swish_Exception(sprintf(__("Payment reference required for refund is missing on order %s", 'woo-swish-e-commerce'), $order_id));
            }

            $order_merchant_alias = Woo_Swish_Helper::get_order_merchant_alias($order) ? Woo_Swish_Helper::get_order_merchant_alias($order) : $this->merchant_alias;

            $payment = new stdClass();
            $swish = new $this->connection_class();

            $callback = Woo_Swish_Helper::get_callback_url($order_id);

            $payment = $swish->refund(
                $payment_reference,
                $order,
                $order_merchant_alias,
                $amount,
                $callback,
                $reason
            );

            $this->logger->add(sprintf('process_refund (%s): Refund "%s" initiated successfully using callback "%s"', $order_id, $payment_reference, $callback));

            return true;

        } catch (Woo_Swish_Exception $e) {
            $e->write_to_logs();
            wc_add_notice($e->getMessage(), 'error');
        } catch (Woo_Swish_API_Exception $e) {
            $e->write_to_logs();
            wc_add_notice($e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * admin_handler function.
     *
     * Is called by the Swish-service.
     *
     * @access public
     * @return void
     */

    public function admin_handler()
    {

        $request_body = file_get_contents("php://input");
        $body = json_decode($request_body);

        if ($body !== null && json_last_error() === JSON_ERROR_NONE) {

            $nonce = get_site_transient('handle_swish_account');

            if ($body->nonce == $nonce) {

                if ($body->uuid == $this->account_uuid) {

                    $this->refresh_token = $body->refresh_token;
                    $this->update_option('swish_refresh_token', $this->refresh_token);
                    delete_site_transient('swish_access_token');
                    $this->logger->add(sprintf('Account uuid %s was authorized and got refresh token %s from service', $this->account_uuid, $this->refresh_token));
                    set_site_transient('swish_connect_result', 'success', MINUTE_IN_SECONDS);

                } else {

                    $this->logger->add(sprintf('Wrong account UUID %s, should have been %s', $body->uuid, $this->account_uuid));
                    set_site_transient('swish_connect_result', 'failure', MINUTE_IN_SECONDS);
                    status_header(403, 'wrong_uuid');

                }

            } else {

                $this->logger->add(sprintf('Nonce not verified at admin_callback_handler, incoming nonce %s differs from existing %s', $body->nonce, $nonce));
                set_site_transient('swish_connect_result', 'failure', MINUTE_IN_SECONDS);
                status_header(403, 'nonce_error');

            }

        } else {

            $this->logger->add(sprintf('Failed decoding authorize json %s %s', print_r($request_body, true), json_last_error()));
            set_site_transient('swish_connect_result', 'failure', MINUTE_IN_SECONDS);
            status_header(403, 'invalid_json');

        }

    }

    public function certificate_info($swish_cert)
    {
        try {

            if (file_exists($swish_cert)) {
                $cert = file_get_contents($swish_cert);
                if ($cert_info = openssl_x509_parse($cert)) {
                    return (object) array(
                        'issuer' => $cert_info['issuer']['O'],
                        'common_name' => $cert_info['issuer']['CN'],
                        'valid_from' => $cert_info['validFrom_time_t'],
                        'valid_to' => $cert_info['validTo_time_t'],
                        'merchant_number' => $cert_info['subject']['CN'],
                    );
                }
            }

        } catch (Throwable $t) {
            WC_SEC()->logger->add(print_r($t, true));
        }
        return false;
    }

    public function register_rest_api()
    {
        register_rest_route(
            'swish',
            '/callback',
            [
                'methods' => 'POST',
                'callback' => array($this, 'received_webhook_call_rest'),
                'permission_callback' => array($this, 'verify_callback'),
            ]
        );

    }

    public function verify_callback(WP_REST_Request $request): bool
    {

        if (!isset($request['order_id'])) {
            $this->logger->add(sprintf('verify_callback: order id missning'));
            return false;
        }

        $order = wc_get_order($request['order_id']);

        if (isset($request['originalPaymentReference'])) {
            if (($payment_reference = Woo_Swish_Helper::get_payment_reference($order)) != $request['originalPaymentReference']) {
                $this->logger->add(sprintf('verify_callback (%s): Verification of refund failed (%s vs %s)', $request['order_id'], $payment_reference, $request['originalPaymentReference']));
                return false;
            };
        } else {
            if (($payment_uuid = Woo_Swish_Helper::get_payment_uuid($order)) != $request['id']) {
                $this->logger->add(sprintf('verify_callback (%s): Verification failed (%s vs %s)', $request['order_id'], $payment_uuid, $request['id']));
                return false;
            };
        }

        return true;
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function received_webhook_call_rest(WP_REST_Request $request): array
    {

        $this->registerShutdownHook($request);

        return ['status' => 200];

    }

    /**
     * @param string $payload
     */
    public function registerShutdownHook(WP_REST_Request $request): void
    {

        if (wc_string_to_bool($this->get_option('callback_uses_shutdown_processing', 'yes'))) {

            add_action(
                'shutdown',
                function () use ($request): void {
                    if (self::$shutdown_called) {
                        return;
                    }
                    $this->handle($request);
                    self::$shutdown_called = true;
                }
            );

        } else {

            $this->handle($request);

        }

    }

    /**
     * @param string $payload
     */
    private function handle(WP_REST_Request $request): void
    {

        try {

            $order = wc_get_order($request['order_id']);
            $request_body = $request->get_json_params();

            $this->logger->add(sprintf('process_order (%s): Got %s from callback', $request['order_id'], json_encode($request_body)));

            if (!wc_string_to_bool($this->get_option('use_callback', 'yes'))) {
                $this->logger->add(sprintf('process_order (%s): Ignoring callback', $request['order_id']));
                return;
            }

            $this->process_order($order, (object) $request_body, true);

        } catch (Throwable $throwable) {

            $this->logger->add(print_r($throwable, true));

        }

    }

    public function process_order($order, $request_body, $callback = false)
    {

        $current_status = Woo_Swish_Helper::get_transaction_status($order);
        $order_id = $order->get_id();
        $testmode = $this->connection_type == '_test';

        $this->logger->add(sprintf('process_order (%s): Got %s from Swish', $order_id, $request_body->status));

        // Check if the payment method is Swish
        if ($order->get_payment_method() !== 'swish') {
            // If the payment method is not Swish, log it and return nothing.
            $this->logger->add(sprintf('process_order (%s): Payment method is not Swish.', $order_id));
            if (!$order->get_meta('swish_paymentmethod_switched')) {
                Woo_Swish_Helper::note($order, sprintf(__('Payment method switched from Swish to %s', 'woo-swish-e-commerce'), $order->get_payment_method()));
                $this->logger->add(sprintf('process_order (%s): Payment method is not Swish. Added note in order.', $order_id));
                $order->update_meta_data('swish_paymentmethod_switched', true);
                $order->save();
            }
            return;
        }

        switch ($request_body->status) {
            case 'DECLINED':
                if ('DECLINED' != $current_status) {
                    Woo_Swish_Helper::set_transaction_status($order, 'DECLINED');
                    Woo_Swish_Helper::note($order, sprintf(__('%s declined by user', 'woo-swish-e-commerce'), $testmode ? __('Test-payment', 'woo-swish-e-commerce') : __('Payment', 'woo-swish-e-commerce')));
                    $order->update_status('failed');
                }
                break;
            case 'ERROR':
                if ($request_body->errorCode != $current_status) {
                    $this->logger->add(sprintf('process_order (%s): Got error %s from Swish', $order_id, $request_body->errorCode));
                    Woo_Swish_Helper::set_transaction_status($order, $request_body->errorCode);
                    Woo_Swish_Helper::note($order, $request_body->errorCode . ' - ' . Woo_Swish_Helper::error_code($request_body->errorCode));
                    $order->update_status('failed');
                }
                break;
            case 'DEBITED':
                if ('DEBITED' != $current_status) {
                    Woo_Swish_Helper::note($order, sprintf(__('Merchant account debited - %s ID: %s', 'woo-swish-e-commerce'), $testmode ? 'Test-transaction' : 'Transaction', $request_body->id));
                    Woo_Swish_Helper::set_transaction_status($order, 'DEBITED');
                }
                break;
            case 'PAID':
                if (isset($request_body->originalPaymentReference)) {
                    do_action('swish_ecommerce_refund_complete', $order);
                    Woo_Swish_Helper::note($order, sprintf(__('Refund to customer confirmed - %s ID: %s', 'woo-swish-e-commerce'), $testmode ? 'Test-transaction' : 'Transaction', $request_body->id));
                    Woo_Swish_Helper::set_refund_id($order, $request_body->paymentReference);
                } elseif (!Woo_Swish_Helper::get_payment_reference($order, true) && 'PAID' != $current_status) {
                    Woo_Swish_Helper::note($order, sprintf(__('Payment confirmed - %s ID: %s', 'woo-swish-e-commerce'), $testmode ? 'Test-transaction' : 'Transaction', $request_body->id));
                    Woo_Swish_Helper::set_transaction_status($order, 'PAID');
                    Woo_Swish_Helper::set_payment_reference($order, $request_body->paymentReference);
                    Woo_Swish_Helper::set_order_merchant_alias($order, $this->merchant_alias);

                    // Fix to handle error in WooCommerce checkout manager
                    if (function_exists('WOOCCM')) {
                        try {
                            $order->payment_complete();
                            do_action('swish_ecommerce_payment_complete', $order);
                        } catch (Throwable $t) {
                        }
                    } else {
                        $order->payment_complete();
                        do_action('swish_ecommerce_payment_complete', $order);
                    }
                }
                break;
        }

        $order->save();

    }

    /**
     * callback_handler function.
     *
     * Is called after a payment has been submitted in the Swish payment window.
     *
     * @access public
     * @return void
     */

    public function callback_handler()
    {

        $order_id = array_key_exists('order_id', $_REQUEST) ? trim($_REQUEST['order_id']) : false;
        if ($order_id === false) {
            $this->logger->add(sprintf('callback_handler: Order-id missning'));
            status_header(403);
            return;
        }

        $raw_request_body = file_get_contents("php://input");
        if (empty($raw_request_body)) {
            $this->logger->add(sprintf('callback_handler (%s): Request body missing', $order_id));
            status_header(403);
            return;
        }

        $request_body = json_decode($raw_request_body);
        if (empty($request_body)) {
            $this->logger->add(sprintf('callback_handler (%s): Unable to decode request body', $order_id));
            status_header(403);
            return;
        }

        $order = wc_get_order($order_id);
        if (isset($request_body->originalPaymentReference)) {
            if (($payment_reference = Woo_Swish_Helper::get_payment_reference($order)) != $request_body->originalPaymentReference) {
                $this->logger->add(sprintf('callback_handler (%s): Verification of refund failed (%s vs %s)', $order_id, $payment_reference, $request_body->id));
                status_header(403);
                return;
            };
        } else {
            if (($stored_id = Woo_Swish_Helper::get_payment_uuid($order)) != $request_body->id) {
                $this->logger->add(sprintf('callback_handler (%s): Verification failed (%s vs %s)', $order_id, $stored_id, $request_body->id));
                status_header(403);
                return;
            };
        }

        $this->process_order($order, $request_body);

    }

    public function generate_button_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'name' => '',
            'text' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'type' => 'button',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); ?></label>
                </th>
                <td class="forminp">
                    <fieldset>
                        <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                        <button class="button <?php echo esc_attr($data['class']); ?>" type="<?php echo esc_attr($data['type']); ?>" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" <?php disabled($data['disabled'], true);?> <?php echo $this->get_custom_attribute_html($data); ?> ><?php echo $data['text'] ?></button>
                        <?php echo $this->get_description_html($data); ?>
                    </fieldset>
                </td>
            </tr>
            <?php

        return ob_get_clean();
    }

    /**
     * add_meta_boxes function.
     *
     * Adds the action meta box inside the single order view.
     *
     * @access public
     * @return void
     */
    public function add_meta_boxes($screen_id, $post_or_order)
    {
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        } else {
            $hpos_enabled = false;
        }

        $screen = 'shop_order';

        if ($hpos_enabled) {
            $screen = 'woocommerce_page_wc-orders';
        }

        if ($screen_id !== $screen) {
            return;
        }

        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : wc_get_order($post_or_order->get_id());

        if (Woo_Swish_Helper::get_transaction_location($order)) {
            add_meta_box(
                'swish-payment-actions',
                __('Swish Payment', 'woo-swish-e-commerce'),
                [$this, 'meta_box_payment'],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * meta_box_payment function.
     *
     * Inserts the content of the API actions meta box - Payments
     *
     * @access public
     * @return void
     */
    public function meta_box_payment($post_or_order)
    {
        $name = ($post_or_order instanceof WP_Post) ? $post_or_order->ID : $post_or_order->get_id();
        echo '<a class="button swish_retrieve_button" id="swish_retrieve_button" name="' . $name . '">' . __('Retrieve payment', 'woo-swish-e-commerce') . '</a>';
    }

    public function retreive_transaction($transaction_location)
    {
        $swish = new $this->connection_class();
        return $swish->retreive($transaction_location);
    }

    /**
     * ajax_swish_retrieve_transaction.
     *
     * Called from the javascript on the init service page
     *
     * @access public
     * @return void
     */
    public function ajax_swish_retrieve_transaction()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax_swish_admin')) {
            wp_die();
        }

        $order = wc_get_order($_POST['name']);

        $transaction_location = Woo_Swish_Helper::get_transaction_location($order);
        $this->logger->add(sprintf('ajax_swish_retrieve_transaction: Transaction location is: %s', $transaction_location));

        try {

            $payment_body = $this->retreive_transaction($transaction_location);
            $this->process_order($order, $payment_body);
            Woo_Swish_Helper::note($order, __('Transaction retreived from Swish', 'woo-swish-e-commerce'));

        } catch (Woo_Swish_API_Exception $e) {

            $e->write_to_logs();
            Woo_Swish_Helper::note($order, __('Transaction failed to retreive from Swish', 'woo-swish-e-commerce'));

        }

        wp_send_json(array(
            'status' => 'success',
        ));

    }

    public function swish_shipping_options($options)
    {
        $options = array();
        $data_store = WC_Data_Store::load('shipping-zone');
        $raw_zones = $data_store->get_zones();

        foreach ($raw_zones as $raw_zone) {
            $zones[] = new WC_Shipping_Zone($raw_zone);
        }

        $zones[] = new WC_Shipping_Zone(0);

        foreach (WC()->shipping()->load_shipping_methods() as $method) {

            $options[$method->get_method_title()] = array();

            // Translators: %1$s shipping method name.
            $options[$method->get_method_title()][$method->id] = sprintf(__('Any &quot;%1$s&quot; method', 'woo-swish-e-commerce'), $method->get_method_title());

            foreach ($zones as $zone) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ($shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance) {

                    if ($shipping_method_instance->id !== $method->id) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf(__('%1$s (#%2$s)', 'woo-swish-e-commerce'), $shipping_method_instance->get_title(), $shipping_method_instance_id);

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf(__('%1$s &ndash; %2$s', 'woo-swish-e-commerce'), $zone->get_id() ? $zone->get_zone_name() : __('Other locations', 'woo-swish-e-commerce'), $option_instance_title);

                    $options[$method->get_method_title()][$option_id] = $option_title;
                }
            }
        }
        return $options;
    }

    /**
     * init_form_fields function.
     *
     * Initiates the plugin settings form fields
     *
     * @access public
     * @return void
     */
    public function init_form_fields()
    {
        $this->form_fields = Woo_Swish_Settings::get_fields($this);
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available()
    {
        $order = null;
        $needs_shipping = false;

        // Test if shipping is needed first.
        if (WC()->cart && WC()->cart->needs_shipping()) {
            $needs_shipping = true;
        } elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
            $order = wc_get_order($order_id);

            // Test if order needs shipping.
            if (0 < count($order->get_items())) {
                foreach ($order->get_items() as $item) {
                    $_product = $item->get_product();
                    if ($_product && $_product->needs_shipping()) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

        // Only apply if all packages are being shipped via chosen method, or order is virtual.
        if (!empty($this->enable_for_methods) && $needs_shipping) {
            $order_shipping_items = is_object($order) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

            if ($order_shipping_items) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids($order_shipping_items);
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids($chosen_shipping_methods_session);
            }

            if (!count($this->get_matching_rates($canonical_rate_ids))) {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
     * @return array $canonical_rate_ids    Rate IDs in a canonical format.
     */
    private function get_canonical_order_shipping_item_rate_ids($order_shipping_items)
    {

        $canonical_rate_ids = array();

        foreach ($order_shipping_items as $order_shipping_item) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
     * @return array $canonical_rate_ids  Rate IDs in a canonical format.
     */
    private function get_canonical_package_rate_ids($chosen_package_rate_ids)
    {

        $shipping_packages = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if (!empty($chosen_package_rate_ids) && is_array($chosen_package_rate_ids)) {
            foreach ($chosen_package_rate_ids as $package_key => $chosen_package_rate_id) {
                if (!empty($shipping_packages[$package_key]['rates'][$chosen_package_rate_id])) {
                    $chosen_rate = $shipping_packages[$package_key]['rates'][$chosen_package_rate_id];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    /**
     * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
     *
     * @since  3.4.0
     *
     * @param array $rate_ids Rate ids to check.
     * @return array
     */
    private function get_matching_rates($rate_ids)
    {
        // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
        return array_unique(array_merge(array_intersect($this->enable_for_methods, $rate_ids), array_intersect($this->enable_for_methods, array_unique(array_map('wc_get_string_before_colon', $rate_ids)))));
    }

    /**
     * Generate Title HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @since  1.0.0
     * @return string
     */
    public function generate_title_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'class' => '',
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?></table>
			    <h3 class="wc-settings-sub-title <?php echo esc_attr($data['class']); ?>" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
			    <?php if (!empty($data['description'])): ?>
				    <p><?php echo wp_kses_post($data['description']); ?></p>
			    <?php endif;?>
			<table class="form-table <?php echo esc_attr($data['class']); ?>"><?php

        return ob_get_clean();
    }

    /**
     * FILTER: apply_gateway_icons function.
     *
     * Sets gateway icons on frontend
     *
     * @access public
     * @return void
     */
    public function apply_gateway_icons($icon, $id = '')
    {
        if ($id == $this->id) {
            $icon_url = WC_HTTPS::force_https_url(Swish_Commerce_Payments::plugin_url() . '/assets/images/Swish_Logo_Secondary_Light-BG_SVG.svg');
            $icon = '<img src="' . $icon_url . '" alt="' . esc_attr($this->get_title()) . '" style="max-height:' . '30px' . '"/>';
        }
        return $icon;
    }

}