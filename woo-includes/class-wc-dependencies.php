<?php
/**
 * * WC_Gateway_Affirm_Charge_API
 *
 * WC_Gateway_Affirm_Charge_API connects to the affirm API to do all charge actions ie capture, return void, auth
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
class WC_Dependencies
{

    private static $active_plugins;
    /**
     * Init
     *
     * Checks if WooCommerce is enabled
     *
     * @return void
     */
    public static function init()
    {

        self::$active_plugins = (array) get_option('active_plugins', array());

        if (is_multisite() ) {
            self::$active_plugins = array_merge(self::$active_plugins, get_site_option('active_sitewide_plugins', array()));
        }
    }

    /**
     * WC Dependency Checker
     *
     * Checks if WooCommerce is enabled
     *
     * @return boolean
     */
    public static function woocommerce_active_check()
    {

        if (! self::$active_plugins ) {
            self::init();
        }

        return in_array('woocommerce/woocommerce.php', self::$active_plugins) || array_key_exists('woocommerce/woocommerce.php', self::$active_plugins);
    }

}

