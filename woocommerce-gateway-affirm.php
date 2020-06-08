<?php
/**
 * Plugin Name: WooCommerce Affirm Gateway
 * Plugin URI: https://woocommerce.com/products/woocommerce-gateway-affirm/
 * Description: Receive payments using the Affirm payments provider.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Version: 1.1.10
 * WC tested up to: 3.9
 * WC requires at least: 2.6
 * Woo: 1474706:b271ae89b8b86c34020f58af2f4cbc81
 *
 * Copyright (c) 2020 WooCommerce
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */

if (! defined('ABSPATH') ) {
    exit;
}


/**
 * Required functions and classes
 */
if (! function_exists('woothemes_queue_update') ) {
    include_once 'woo-includes/woo-functions.php';
}


/**
 * Constants
 */

define('WC_GATEWAY_AFFIRM_VERSION', '1.1.9');


/**
 * Plugin updates
 */
woothemes_queue_update(
    plugin_basename(__FILE__), 
    'b271ae89b8b86c34020f58af2f4cbc81', 
    '1474706'
);

/**
 * Class WC_Affirm_Loader
 * Load Affirm
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
class WC_Affirm_Loader
{

    /**
     * The reference the *Singleton* instance of this class.
     *
     * @var WC_Affirm_Loader
     */
    private static $_instance;

    /**
     * Whether or not we've already embedded the affirm script loader.
     *
     * @deprecated Since 1.1.0
     *
     * @var bool
     */
    private $_loader_has_been_embedded = false;

    /**
     * Instance of WC_Gateway_Affirm.
     *
     * @var WC_Gateway_Affirm
     */
    private $gateway = false;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function getInstance()
    {
        if (null === self::$_instance ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Private clone method to prevent cloning of the instance of the
     * *Singleton* instance.
     *
     * @return void
     */
    private function __clone()
    {
    }

    /**
     * Private unserialize method to prevent unserializing of the *Singleton*
     * instance.
     *
     * @return void
     */
    private function __wakeup()
    {
    }

    /**
     * Protected constructor to prevent creating a new instance of the
     * *Singleton* via the `new` operator from outside of this class.
     */
    protected function __construct()
    {

        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__), 
            array( $this, 'pluginActionLinks' )
        );
        add_action(
            'plugins_loaded', 
            array( $this, 'initGateway' ), 
            0
        );

        // Order actions.
        add_filter(
            'woocommerce_order_actions', 
            array( $this, 'possiblyAddCaptureToOrderActions' )
        );
        add_action(
            'woocommerce_order_action_wc_affirm_capture_charge', 
            array( $this, 'possiblyCaptureCharge' )
        );
        add_action(
            'woocommerce_order_status_pending_to_cancelled', 
            array( $this, 'possiblyVoidCharge' )
        );
        add_action(
            'woocommerce_order_status_processing_to_cancelled', 
            array( $this, 'possiblyRefundCapturedCharge' )
        );
        add_action(
            'woocommerce_order_status_completed_to_cancelled', 
            array( $this, 'possiblyRefundCapturedCharge' )
        );

        // Bulk capture.
        add_action(
            'admin_footer-edit.php', 
            array( $this, 'possiblyAddCaptureChargeBulkOrderAction' )
        );
        add_action(
            'load-edit.php', 
            array( $this, 'possiblyCaptureChargeBulkOrderAction' )
        );
        add_action(
            'admin_notices', 
            array( $this, 'customBulkAdminNotices' )
        );

        // As low as.
        add_action(
            'wp_head', 
            array( $this, 'affirmJsRuntimeScript' )
        );
        add_action(
            'wp_enqueue_scripts', 
            array( $this, 'possiblyEnqueueScripts' )
        );
        add_action(
            'woocommerce_after_shop_loop_item', 
            array( $this, 'woocommerceAfterShopLoopItem' )
        );
        // Uses priority 15 to get the as-low-as to appear after the product price.
        add_action(
            'woocommerce_single_product_summary', 
            array( $this, 'woocommerceSingleProductSummary' ), 
            15
        );
        add_action(
            'woocommerce_cart_totals_after_order_total', 
            array( $this, 'woocommerceCartTotalsAfterOrderTotal' )
        );
        add_action(
            'woocommerce_thankyou', 
            array($this, 'wcAffirmCheckoutAnalytics')
        );

        // Checkout Button -  Changes Place Order Button to Continue with Affirm
        add_action(
            'woocommerce_review_order_after_submit', 
            array($this, 'woocommerceOrderButtonText' )
        );

        // Display merchant order fee
        add_action(
            'woocommerce_admin_order_totals_after_total', 
            array( $this, 'displayOrderFee' )
        );

        // Register Endpoint    
        add_action( 
            'wc_ajax_wc_affirm_start_checkout', 
            array( $this, 'startCheckout' ) 
        );
    }


    /**
     * Initialize the gateway.
     *
     * @return void
     * @since  1.0.0
     */
    function initGateway()
    {
        if (! class_exists('WC_Payment_Gateway') ) {
            return;
        }

        include_once dirname(__FILE__) . '/includes/class-wc-affirm-privacy.php';
        include_once plugin_basename('includes/class-wc-gateway-affirm.php');
        load_plugin_textdomain(
            'woocommerce-gateway-affirm', 
            false, 
            trailingslashit(
                dirname(
                    plugin_basename(__FILE__)
                )
            )
        );
        add_filter('woocommerce_payment_gateways', array( $this, 'addGateway' ));
    }


    /**
     * Adds plugin action links.
     *
     * @param array $links Plugin action links.
     *
     * @return array Plugin action links.
     */
    public function pluginActionLinks( $links )
    {

        $settings_url = add_query_arg(
            array(
            'page' => 'wc-settings',
            'tab' => 'checkout',
            'section' => 'wc_gateway_affirm',
            ),
            admin_url('admin.php')
        );

        $plugin_links = array(
        '<a href="' . $settings_url . '">' .
        __('Settings', 'woocommerce-gateway-affirm') . '</a>',
        '<a href="http://docs.woothemes.com/document/woocommerce-gateway-affirm/">' . 
        __('Docs', 'woocommerce-gateway-affirm') . '</a>',
        '<a href="http://support.woothemes.com/">' . 
        __('Support', 'woocommerce-gateway-affirm') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * Return an instance of the gateway for those loader functions that need it
     * so we don't keep creating it over and over again.
     *
     * @return object
     * @since  1.0.0
     */
    public function getGateway()
    {
        if (! $this->gateway ) {
            $this->gateway = new WC_Gateway_Affirm();
        }
        return $this->gateway;
    }

    /**
     * Helper method to check the payment method and authentication.
     *
     * @param WC_Order $order Order object.
     *
     * @since  1.0.7
     * @return bool Returns true if the payment 
     * method is affirm and the 
     * auth flag is set. False otherwise.
     */
    private function _checkPaymentMethodAndAuthFlag( $order )
    {
        $payment_method = version_compare(
            WC_VERSION, '3.0', '<'
        ) ? 
        $order->payment_method : 
        $order->get_payment_method();

        return 'affirm' === $payment_method && 
        $this->getGateway()->issetOrderAuthOnlyFlag($order);
    }

    /**
     * Possibly add the means to capture an order with Affirm to the order actions
     * This was added here and not in WC_Gateway_Affirm because that class' construct
     * is not called until after this filter is fired.
     *
     * @param array $actions Order actions.
     *
     * @return array Order actions.
     */
    public function possiblyAddCaptureToOrderActions( $actions )
    {

        if (! isset($_REQUEST['post']) ) {
            return $actions;
        }

        $order = wc_get_order($_REQUEST['post']);

        if (! $this->_checkPaymentMethodAndAuthFlag($order) ) {
            return $actions;
        }

        $actions['wc_affirm_capture_charge'] = __(
            'Capture Charge', 
            'woocommerce-gateway-affirm'
        );

        return $actions;
    }


    /**
     * Possibly capture the charge.  
     * Used by woocommerce_order_action_wc_affirm_capture_charge hook / 
     * possiblyAddCaptureToOrderActions
     *
     * @param object $order order
     * 
     * @return bool
     * @since  1.0.0
     */
    public function possiblyCaptureCharge( $order )
    {
        if (! is_object($order) ) {
            $order = wc_get_order($order);
        }

        if (! $this->_checkPaymentMethodAndAuthFlag($order) ) {
            return false;
        }

        return $this->getGateway()->captureCharge($order);
    }

    /**
     * Possibly void the charge.
     *
     * @param int|WC_Order $order Order ID or Order object
     *
     * @return bool Returns true when succeed
     */
    public function possiblyVoidCharge( $order )
    {
        if (! is_object($order) ) {
            $order = wc_get_order($order);
        }

        if (! $this->_checkPaymentMethodAndAuthFlag($order) ) {
            return false;
        }

        return $this->getGateway()->voidCharge($order);
    }

    /**
     * Possibly refund captured charge of an order when it's cancelled.
     *
     * Hooked into order transition action from processing or completed to
     * cancelled.
     *
     * @param int|WC_Order $order Order ID or Order object
     *
     * @return bool Returns true when succeed
     */
    public function possiblyRefundCapturedCharge( $order )
    {
        if (! is_object($order) ) {
            $order = wc_get_order($order);
        }

        if (! $this->_checkPaymentMethodAndAuthFlag($order) ) {
            return false;
        }

        $order_id = version_compare(WC_VERSION, '3.0', '<') ? 
            $order->id() : 
            $order->get_id();

        return $this->getGateway()->process_refund(
            $order_id, 
            null, 
            __(
                'Order is cancelled', 
                'woocommerce-gateway-affirm'
            )
        );
    }

    /**
     * Possibly add capture charge bulk order action
     * Surprisingly, WP core doesn't really make this easy.
     * See http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
     * and https://www.skyverge.com/blog/add-custom-bulk-action/
     *
     * @return string
     * @since  1.0.0
     */
    public function possiblyAddCaptureChargeBulkOrderAction()
    {

        global $post_type, $post_status;

        if ('shop_order' === $post_type && 'trash' !== $post_status ) {
            ?>
                <script type="text/javascript">
                    jQuery( document ).ready( function ( $ ) {
                        if ( 0 == 
                        $( 'select[name^=action] option[value=wc_capture_charge_affirm]' )
                        .size() ) {
                            $( 'select[name^=action]' ).append(
                                $( '<option>' ).val( 
                                    'wc_capture_charge_affirm' )
                                    .text( 
                                        '<?php esc_attr_e(
                                            'Capture Charge (Affirm)', 
                                            'woocommerce-gateway-affirm'
                                        ); 
                                            ?>' 
                                    )
                            );
                        }
                    });
                </script>
            <?php
        }
    }


    /**
     * Handle the capture bulk order action
     *
     * @return void
     * @since  1.0.0
     */
    public function possiblyCaptureChargeBulkOrderAction()
    {

        global $typenow;

        if ('shop_order' == $typenow ) {

            // Get the action (I'm not entirely happy with 
            // using this internal WP function, 
            // but this is the only way presently)
            // See https://core.trac.wordpress.org/ticket/16031
            $wp_list_table = _get_list_table('WP_Posts_List_Table');
            $action        = $wp_list_table->current_action();

            // Bail if not processing a capture
            if ('wc_capture_charge_affirm' !== $action ) {
                return;
            }

            // Security check
            check_admin_referer('bulk-posts');

            // Make sure order IDs are submitted
            if (isset($_REQUEST['post']) ) {
                $order_ids = array_map('absint', $_REQUEST['post']);
            }

            $sendback = remove_query_arg(
                array( 
                    'captured', 
                    'untrashed', 
                    'deleted', 
                    'ids' 
                ), 
                wp_get_referer()
            );
            if (! $sendback ) {
                $sendback = admin_url("edit.php?post_type=$post_type");
            }

            $capture_count = 0;

            if (! empty($order_ids) ) {
                // Give ourselves an unlimited timeout if possible
                set_time_limit(0);

                foreach ( $order_ids as $order_id ) {

                    $order = wc_get_order($order_id);
                    $capture_successful = $this->possiblyCaptureCharge($order);

                    if ($capture_successful ) {
                        $capture_count++;
                    }
                }
            }

            $sendback = add_query_arg(
                array( 'captured' => $capture_count ), 
                $sendback
            );
            $sendback = remove_query_arg(
                array( 
                    'action', 
                    'action2', 
                    'tags_input', 
                    'post_author', 
                    'comment_status', 
                    'ping_status', 
                    '_status', 
                    'post', 
                    'bulk_edit', 
                    'post_view' 
                ), 
                $sendback
            );
            wp_redirect($sendback);
            exit();

        } // End if().

    }


    /**
     * Tell the user how much the capture bulk order action did
     *
     * @since  1.0.0
     * @return void
     */
    function customBulkAdminNotices()
    {
        global $post_type, $pagenow;

        if ('edit.php' === $pagenow  
            && 'shop_order' === $post_type  
            && isset($_REQUEST['captured']) 
        ) {
            $capture_count = (int) $_REQUEST['captured'];

            if (0 >= $capture_count ) {
                $message = __(
                    'Affirm: No charges were able to be captured.', 
                    'woocommerce-gateway-affirm'
                );
            } else {
                $message = sprintf(
                    /* translators: 1: number of charge(s) captured */
                    _n(
                        'Affirm: %s charge was captured.', 
                        'Affirm: %s charges were captured.', 
                        $_REQUEST['captured'], 
                        'woocommerce-gateway-affirm'
                    ),
                    number_format_i18n($_REQUEST['captured'])
                );
            }

            ?>
                <div class='updated'>
                    <p>
            <?php echo esc_html($message); ?>
                    </p>
                </div>
            <?php
        }
    }


    /**
     * Add the gateway to WooCommerce
     *
     * @param array $methods methods
     *
     * @return array
     * @since  1.0.0
     */
    public function addGateway( $methods )
    {
        $methods[] = 'WC_Gateway_Affirm';
        return $methods;
    }


    /**
     * Loads front side script when viewing product and cart pages.
     *
     * @since   1.0.0
     * @version 1.1.0
     * @return  void
     */
    function possiblyEnqueueScripts()
    {
        if (! is_product() && ! is_cart() ) {
            return;
        }

        if (! $this->getGateway()->isValidForUse() ) {
            return;
        }

        if (! $this->getGateway()->enabled ) {
            return;
        }

        // See https://docs.affirm.com/Partners/Email_Service_Providers/Monthly_Payment_Messaging_API#Collect_the_loan_details
        // for maximum and minimum amounts.
        $options = array(
        'minimum' => 5000,    // $50 in cents.
        'maximum' => 3000000, // $30000 in cents.
        );

        $options = apply_filters('wc_gateway_affirm_as_low_as_data', $options);

        wp_register_script(
            'affirm_as_low_as', 
            plugins_url('assets/js/affirm-as-low-as.js', __FILE__), 
            array( 'jquery' )
        );
        wp_localize_script(
            'affirm_as_low_as', 
            'affirmOptions', 
            $options
        );
        wp_enqueue_script('affirm_as_low_as');
    }

    /**
     * Add Affirm's monthly payment messaging to single product page.
     *
     * @since   1.0.0
     * @version 1.1.0
     *
     * @return string
     */
    public function woocommerceSingleProductSummary()
    {
        if ($this->getGateway()->productALA ) {
            global $product;

            // Only do this for simple, variable, and composite products. This
            // gateway does not (yet) support subscriptions.
            $supported_types = apply_filters(
                'wc_gateway_affirm_supported_product_types', 
                array( 
                    'simple', 
                    'variable', 
                    'grouped', 
                    'composite' 
                )
            );

            if (!$product->is_type($supported_types) ) {
                return;
            }
            $price = $product->get_price() ? $product->get_price() : 0;

            // For intial messaging in grouped product, use the most low-priced one.
            if ($product->is_type('grouped') ) {
                $price = $this->getGroupedProductPrice($product);
            }


            $this->renderAffirmMonthlyPaymentMessaging(
                floatval($price * 100), 
                'product'
            );
        }
    }

    /**
     * Get grouped product price by returning the most low-priced child.
     *
     * @param WC_Product $product Product instance.
     *
     * @return float Price.
     */
    protected function getGroupedProductPrice( $product )
    {
        $children = array_filter(
            array_map(
                'wc_get_product', 
                $product->get_children()
            ), array( 
                $this, 'filterVisibleGroupChild' 
            )
        );
        uasort($children, array( $this, 'orderGroupedProductByPrice' ));

        return reset($children)->get_price();
    }

    /**
     * Filter visible child in grouped product.
     *
     * @param WC_Product $product Child product of grouped product.
     *
     * @since   1.1.0
     * @version 1.1.0
     *
     * @return bool True if it's visible group child.
     */
    public function filterVisibleGroupChild( $product )
    {
        return $product && 
            is_a(
                $product, 
                'WC_Product'
            ) && 
            ( 'publish' === $product->get_status() || 
            current_user_can(
                'edit_product', 
                $product->get_id()
            ) 
            );
    }

    /**
     * Sort callback to sort grouped product child based on price, from low to
     * high
     *
     * @param object $a Product A.
     * @param object $b Product B.
     *
     * @since   1.1.0
     * @version 1.1.0
     * @return  int
     */
    public function orderGroupedProductByPrice( $a, $b )
    {
        if ($a->get_price() === $b->get_price() ) {
            return 0;
        }
        return ( $a->get_price() < $b->get_price() ) ? -1 : 1;
    }

    /**
     * Add Affirm's monthly payment messaging below the cart total.
     *
     * @return string
     */
    public function woocommerceCartTotalsAfterOrderTotal()
    {
        if (class_exists('WC_Subscriptions_Cart')  
            && WC_Subscriptions_Cart::cart_contains_subscription() 
        ) {
            return;
        }

        ?>
        <tr>
            <th></th>
            <td>
        <?php if ($this->getGateway()->cartALA ) {
            $this->renderAffirmMonthlyPaymentMessaging(
                floatval(WC()->cart->total) * 100, 
                'cart'
            );
        }
        ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render Affirm monthly payment messaging.
     *
     * @param float  $amount         Total amount to be passed to Affirm.
     * @param string $affirmPageType type
     *
     * @since  1.1.0
     * @return void
     */
    protected function renderAffirmMonthlyPaymentMessaging( 
        $amount, $affirmPageType 
    ) {
        $attrs = array(
        'amount'         => $amount,
        'promo-id'       => $this->getGateway()->promo_id,
        'affirm-color'   => $this->getGateway()->affirm_color,
        'learnmore-show' => $this->getGateway()->show_learnmore ? 'true' : 'false',
            'page-type'      => $affirmPageType
        );

        $data_attrs = '';
        foreach ( $attrs as $attr => $val ) {
            if (! $val ) {
                continue;
            }
            $data_attrs .= sprintf(' data-%s="%s"', $attr, esc_attr($val));
        }

        $affirm_message = '<p id="learn-more" class="affirm-as-low-as"' . 
        $data_attrs . '></p>';

        if (($amount > $this->getGateway()->min*100)  
            && ($amount < $this->getGateway()->max*100) 
            && ('cart' === $attrs['page-type'])
        ) {
            echo $affirm_message;
        } elseif ('product' == $attrs['page-type']  
            || 'category' == $attrs['page-type']
        ) {
            echo $affirm_message;
        }
    }

    /**
     * Render script tag for Affirm JS runtime in the head.
     *
     * @since  1.1.0
     * @return string
     */
    public function affirmJsRuntimeScript()
    {
        if (! $this->getGateway()->isValidForUse() ) {
            return;
        }

        if (! $this->getGateway()->enabled ) {
            return;
        }

        $public_key = $this->getGateway()->public_key;
        $testmode   = $this->getGateway()->testmode;

        if ($testmode ) {
            $script_url = 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js';
        } else {
            $script_url = 'https://cdn1.affirm.com/js/v2/affirm.js';
        }

        wp_register_script(
            'affirm_js', 
            plugins_url(
                'assets/js/affirmjs.js', 
                __FILE__
            ) 
        );
        wp_localize_script('affirm_js', 'url', $script_url);
        wp_localize_script('affirm_js', 'pubkey', $public_key);
        wp_enqueue_script('affirm_js');

    }

    /**
     * Embed Affirm's JavaScript loader.
     *
     * @since 1.0.0
     *
     * @deprecated Since 1.1.0
     * @return     boolean
     */
    public function embed_script_loader()
    {
        _deprecated_function(__METHOD__, '1.1.0', '');

        if ($this->loader_has_been_embedded ) {
            return;
        }

        $this->affirmJsRuntimeScript();

        $this->loader_has_been_embedded = true;
    }

    /**
     * Add Tracking Code to the Thank You Page
     *
     * @param string $order_id order id
     *
     * @return string
     */
    public function wcAffirmCheckoutAnalytics( $order_id )
    {
        if (! $this->getGateway()->enhanced_analytics ) {
            return;
        }
        $order = new WC_Order($order_id);
        $total = floor(100 * $order->get_total());
        $order_id = trim(str_replace('#', '', $order->get_id()));
        $payment_type = $order->get_payment_method();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items_data [] = array(
                'name' => $product->get_name(),
                'productId' => $product->get_sku(),
                'quantity' => $item->get_quantity(),
                'price' => floor(100 * $item->get_total())
            );
        }
        ?>
        <script>
            affirm.ui.ready(function () {
                affirm.analytics.trackOrderConfirmed({
                        "orderId": "<?php echo esc_js($order_id); ?>",
                        "total": "<?php echo esc_js($total); ?>",
                        "paymentMethod": "<?php echo esc_js($payment_type); ?>"
                    },
                    <?php echo json_encode($items_data)?>
                );
            });

        </script>
        <?php
    }

    /**
     * ALA messaging
     *
     * @return string
     */
    public function woocommerceAfterShopLoopItem()
    {

        if ($this->getGateway()->categoryALA ) {
            global $product;

            // Only do this for simple, variable, and composite products. This
            // gateway does not (yet) support subscriptions.
            $supported_types = apply_filters(
                'wc_gateway_affirm_supported_product_types', 
                array( 
                    'simple', 
                    'variable', 
                    'grouped', 
                    'composite' 
                )
            );

            if (!$product->is_type($supported_types) ) {
                return;
            }
            $price = $product->get_price() ? $product->get_price() : 0;

            // For intial messaging in grouped product, use the most low-priced one.
            if ($product->is_type('grouped') ) {
                $price = $this->getGroupedProductPrice($product);
            }

            $this->renderAffirmMonthlyPaymentMessaging(
                floatval($price * 100), 
                'category'
            );
        }
    }

    /**
     * Replace WooCommerce place order button with Continue to Affirm button
     *
     * @return string
     */
    public function woocommerceOrderButtonText()
    {
        wp_register_script(
            'affirm_checkout_button', 
            plugins_url(
                'assets/js/affirm-checkout-button.js', 
                __FILE__
            ), 
            array( 
                'jquery' 
            )
        );
        wp_enqueue_script('affirm_checkout_button');
        wp_localize_script(
            'affirm_checkout_button', 
            'affirm_checkout_url', 
            WC_AJAX::get_endpoint('wc_affirm_start_checkout')
        );
        wp_register_style(
            'affirm_css', 
            plugins_url(
                'assets/css/affirm-checkout.css', 
                __FILE__
            )
        );
        wp_enqueue_style('affirm_css');

        echo "<button class='button alt' type='button' id='affirm-place-order' onclick=affirmClick()>".
            "Continue with Affirm</button>";
        
    }

    /**
     * Displays the Affirm fee
     *
     * @param int $order_id The ID of the order.
     *
     * @return string HTML
     */
    public function displayOrderFee($order_id)
    {
        if (! $this->getGateway()->show_fee ) {
            return;
        }

        if (! empty($this->getGateway()->getOrderMeta($order_id, 'fee_amount')) ) {
            $fee_amount = $this->getGateway()->getOrderMeta($order_id, 'fee_amount');
        } else {
            return;
        }
        ?>

        <tr>
            <td class="label affirm-fee">
                <?php echo wc_help_tip(
                    'This is the portion of the'. 
                    'captured amount that represents'. 
                    'the mertchant fee for the transaction.'
                ); ?>
                Affirm Fee:
            </td>
            <td width="1%"></td>
            <td class="total">
                -&nbsp;<?php echo wc_price(0.01 * $fee_amount); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Starts checkout when Continue to Affirm is clicked
     *
     * @since  1.0.0
     * @return void
     */
    public function startCheckout( )
    {
        add_action(
            'woocommerce_after_checkout_validation', 
            array($this, 'checkoutFormValidation'), 
            10,
            2 
        );
        WC()->checkout->process_checkout();
    }

    /**
     * Form valdiation
     * Returns true if there are no errors
     * and false if there are error on the form
     * 
     * @param data   $data   object
     * @param errors $errors object
     *
     * @since  1.0.0
     * @return string form errors
     */
    public function checkoutFormValidation( $data , $errors)
    {
        $error_messages = $errors->get_error_messages();
        if (empty($error_messages) ) {
            include_once 'includes/class-wc-get-checkout-object.php';
            $checkoutObject = new Affirm_Checkout_Object();
            $checkoutData =  $checkoutObject->getCheckoutObject();
            wp_send_json_success(
                array('valid' => true , 'checkoutObject' => $checkoutData)
            );
        } else {
            wp_send_json_error( 
                array( 'messages' => $error_messages ) 
            );   
        }
        exit;
    }

}

$GLOBALS['wc_affirm_loader'] = WC_Affirm_Loader::getInstance();

