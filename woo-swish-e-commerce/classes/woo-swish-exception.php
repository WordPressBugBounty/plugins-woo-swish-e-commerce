<?php

/**
 * Woo_Swish_Exception class
 *
 * @class       Woo_Swish_Exception
 * @version     1.0.0
 * @package     Woocommerce_Swish/Classes
 * @category    Class
 * @author      BjornTech
 */

defined('ABSPATH') || exit;

class Woo_Swish_Exception extends Exception
{
    /**
     * Contains a log object instance
     * @access protected
     */
    protected $logger;

    /**
     * Contains the curl object instance
     * @access protected
     */
    protected $curl_request_data;

    /**
     * Contains the curl url
     * @access protected
     */
    protected $curl_request_url;

    /**
     * Contains the curl response data
     * @access protected
     */
    protected $curl_response_data;

    /**
     * __Construct function.
     *
     * Redefine the exception so message isn't optional
     *
     * @access public
     * @return void
     */
    public function __construct($message, $code = 0, Exception $previous = null, $curl_request_url = '', $curl_request_data = '', $curl_response_data = '')
    {
        // make sure everything is assigned properly
        parent::__construct($message, $code, $previous);

        $this->logger = new Woo_Swish_Log(false);

        $this->curl_request_data = $curl_request_data;
        $this->curl_request_url = $curl_request_url;
        $this->curl_response_data = $curl_response_data;
    }

    /**
     * write_to_logs function.
     *
     * Stores the exception dump in the WooCommerce system logs
     *
     * @access public
     * @return void
     */
    public function write_to_logs()
    {
        $this->logger->separator();
        $this->logger->add('Swish Exception file: ' . $this->getFile());
        $this->logger->add('Swish Exception line: ' . $this->getLine());
        $this->logger->add('Swish Exception code: ' . $this->getCode());
        $this->logger->add('Swish Exception message: ' . $this->getMessage());
        $this->logger->separator();
    }

    /**
     * write_standard_warning function.
     *
     * Prints out a standard warning
     *
     * @access public
     * @return void
     */
    public function write_standard_warning()
    {
        printf(
            wp_kses(
                __("An error occured. For more information check out the <strong>%s</strong> logs inside <strong>WooCommerce -> System Status -> Logs</strong>.", 'woo-swish-e-commerce'), array('strong' => array())
            ),
            $this->logger->get_domain()
        );
    }
}

class Woo_Swish_API_Exception extends Woo_Swish_Exception
{
    /**
     * write_to_logs function.
     *
     * Stores the exception dump in the WooCommerce system logs
     *
     * @access public
     * @return void
     */
    public function write_to_logs()
    {
        $this->logger->separator();
        $this->logger->add('Swish API Exception file: ' . $this->getFile());
        $this->logger->add('Swish API Exception line: ' . $this->getLine());
        $this->logger->add('Swish API Exception code: ' . $this->getCode());
        $this->logger->add('Swish API Exception message: ' . $this->getMessage());

        if (!empty($this->curl_request_url)) {
            $this->logger->add('Swish API Exception Request URL: ' . $this->curl_request_url);
        }

        if (!empty($this->curl_request_data)) {
            $this->logger->add('Swish API Exception Request DATA: ' . $this->curl_request_data);
        }

        if (!empty($this->curl_response_data)) {
            $this->logger->add('Swish API Exception Response DATA: ' . $this->curl_response_data);
        }

        $this->logger->separator();

    }
}
