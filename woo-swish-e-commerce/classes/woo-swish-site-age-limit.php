<?php

/**
 * WC_Swish_Site_Age_Limit class
 *
 * @class        WC_Swish_Site_Age_Limit
 * @since        1.0.0
 * @package      Woocommerce_Swish/Classes
 * @author       BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Swish_Site_Age_Limit', false)) {

    class WC_Swish_Site_Age_Limit
    {

        private $site_age_limit;

        public function __construct($site_age_limit)
        {

            $this->site_age_limit = $site_age_limit;
            add_filter('swish_age_limits', array($this, 'check_site_age_limit'), 2, 20);

        }

        public function check_site_age_limit($limit, $order)
        {

            if ($this->site_age_limit && is_numeric($this->site_age_limit) && (!$limit || $this->site_age_limit  < $limit)) {
                WC_SEC()->logger->add(sprintf('check_site_age_limit: Age limit %s is lower than the previous %s', $this->site_age_limit, $limit ?: 'n/a'));
                return (string) $this->site_age_limit;
            }

            return $limit;

        }

    }

}
