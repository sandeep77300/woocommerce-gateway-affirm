<?php
/**
 * Affirm Payment Gateway
 *
 * Provides a form based Affirm Payment Gateway.
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
class WC_Gateway_Affirm extends WC_Payment_Gateway
{

    /**
     * Transaction type constants
     */
    const TRANSACTION_MODE_AUTH_AND_CAPTURE = 'capture';
    const TRANSACTION_MODE_AUTH_ONLY = 'auth_only';

    /**
     * Checkout type constants
     */
    const CHECKOUT_MODE_MODAL = 'modal';
    const CHECKOUT_MODE_REDIRECT = 'redirect';

    /**
     * Cancel URL redirects
     */
    const CANCEL_TO_CART = 'cancel_to_cart';
    const CANCEL_TO_PAYMENT = 'cancel_to_payment';

    /**
     * Constructor
     */
    public function __construct()
    {

        $this->id = 'affirm';
        $this->icon = 'https://cdn-assets.affirm.com/images/blue_logo-transparent_bg.png';
        $this->has_fields = false;
        $this->method_title = __('Affirm', 'woocommerce-gateway-affirm');
        $this->method_description = sprintf(
            /* translators: 1: html starting code 2: html end code */
            __('Works by sending the customer to %1$sAffirm%2$s to enter their payment information.', 'woocommerce-gateway-affirm'),
            '<a href="http://affirm.com/">', '</a>'
        );
        $this->supports = array(
        'products',
        'refunds',
        );

        $this->initFormFields();
        $this->init_settings();

        $this->public_key     = $this->get_option('public_key');
        $this->private_key    = $this->get_option('private_key');
        $this->debug          = $this->get_option('debug') === 'yes';
        $this->title          = $this->get_option('title');
        $this->description    = $this->get_option('description');
        $this->testmode       = $this->get_option('testmode') === 'yes';
        $this->auth_only_mode = $this->get_option('transaction_mode') === self::TRANSACTION_MODE_AUTH_ONLY ? true : false;
        $this->checkout_mode  = $this->get_option('checkout_mode');
        $this->cancel_url     = $this->get_option('cancel_url');
        $this->promo_id       = $this->get_option('promo_id');
        $this->affirm_color   = $this->get_option('affirm_color', 'blue');
        $this->show_learnmore = $this->get_option('show_learnmore', 'yes') === 'yes';
        $this->enhanced_analytics = $this->get_option('enhanced_analytics', 'yes') === 'yes';
        $this->categoryALA = $this->get_option('categoryALA', 'yes') === 'yes';
        $this->productALA = $this->get_option('productALA', 'yes') === 'yes';
        $this->cartALA = $this->get_option('cartALA', 'yes') === 'yes';
        $this->min     = $this->get_option('min');
        $this->max     = $this->get_option('max');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        add_action('admin_notices', array( $this, 'adminNotices' ));
        add_action('admin_enqueue_scripts', array( $this, 'adminEnqueueScripts' ));

        if (! $this->isValidForUse() ) {
            return;
        }

        add_action('woocommerce_api_' . strtolower(get_class($this)), array( $this, 'handleWcApi' ));
        add_action('woocommerce_review_order_before_payment', array( $this, 'reviewOrderBeforePayment' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueueScripts' ));

    }

    /**
     * Check for the Affirm POST back.
     *
     * If the customer completes signing up for the loan, Affirm has the client browser POST to
     * https://{$domain}/wc-api/WC_Gateway_Affirm?action=complete_checkout
     *
     * The POST includes the checkout_token from affirm that the server can then use to complete
     * capturing the payment. By doing it this way, it "fits" with the Affirm way of working.
     *
     * @since  1.0.0
     * @return void
     */
    public function handleWcApi()
    {

        try {
            $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
            if ('complete_checkout' !== $action ) {
                throw new Exception(
                    __('Sorry, but that endpoint is not supported.', 'woocommerce-gateway-affirm')
                );
            }

            $checkout_token = isset($_POST['checkout_token']) ? wc_clean($_POST['checkout_token']) : '';
            if (empty($checkout_token) ) {
                throw new Exception(
                    __('Checkout failed. No token was provided by Affirm. You may wish to try a different payment method.', 'woocommerce-gateway-affirm')
                );
            }

            // In case there's an active request that still using session after
            // udpated to 1.0.4. Session fallback can be removed after two releases.
            $order_id = ( ! empty($_GET['order_id']) ) ? absint($_GET['order_id']) : WC()->session->order_awaiting_payment;

            $order = wc_get_order($order_id);
            if (! $order ) {
                throw new Exception(
                    __('Sorry, but that order is not available. Please try checking out again.', 'woocommerce-gateway-affirm')
                );
            }

            // TODO: After two releass from 1.0.4, makes order_key a required field.
            if (! empty($_GET['order_key']) && ! $order->key_is_valid($_GET['order_key']) ) {
                throw new Exception(
                    __('Sorry, but that order is not available. Please try checking out again.', 'woocommerce-gateway-affirm')
                );
            }

            $this->log(__FUNCTION__, "Processing payment for order {$order_id} with checkout token {$checkout_token}.");

            if ($this->testmode ) {
                $this->log(__FUNCTION__, 'Sandbox mode is enabled');
            } else {
                $this->log(__FUNCTION__, 'Production mode is enabled');
            }

            // Authenticate the token with Affirm
            include_once 'class-wc-gateway-affirm-charge-api.php';
            $charge_api = new WC_Gateway_Affirm_Charge_API($this, $order_id);

            $result = $charge_api->requestChargeIdForToken($checkout_token);
            if (is_wp_error($result) ) {
                $this->log(__FUNCTION__, 'Error in charge authorization: ' . $result->get_error_message());
                throw new Exception(
                    __('Checkout failed. Unable to exchange token with Affirm. Please try checking out again later, or try a different payment source.', 'woocommerce-gateway-affirm')
                );
            }

            $validates = $result['validates'];
            $charge_id = $result['charge_id'];
            $amount_validation = $result['amount_validation'];    
            $this->log(__FUNCTION__, "Received charge id {$charge_id} for order {$order_id}.");

            if (! $validates ) {
                $charge_api->voidCharge($charge_id);
                throw new Exception(
                    __('Checkout failed. Order mismatch for Affirm token. Please try checking out again later, or try a different payment source.', 'woocommerce-gateway-affirm')
                );
            }

            if (! $amount_validation ) {
                $charge_api->voidCharge($charge_id);
                $order->update_status( 'cancelled', __( 'Affirm total mismatch.', 'woocommerce-gateway-affirm' ) );
                throw new Exception(
                    __('Checkout failed. Your cart amount has changed since starting your Affirm application. Please try again.', 'woocommerce-gateway-affirm')
                );
            }

            if (! $order->needs_payment() ) {
                $charge_api->voidCharge($charge_id);
                throw new Exception(
                    __('Checkout failed. This order has already been paid.', 'woocommerce-gateway-affirm')
                );
            }

            // Save the charge ID on the order
            $this->updateOrderMeta($order_id, 'charge_id', $charge_id);

            // Auth and possibly capture the charge
            if ($this->auth_only_mode ) {

                $order->add_order_note(
                    sprintf(
                        /* translators: 1: charge amount 2: charge id */
                        __('Authorized charge of %1$s (charge ID %2$s)', 'woocommerce-gateway-affirm'),
                        wc_price($order->get_total()),
                        $charge_id
                    )
                );
                $order->set_transaction_id($charge_id);
                $this->setOrderAuthOnlyFlag($order);
                $order->update_status( 'on-hold' );
                $order->save();
                $this->log(__FUNCTION__, "Info: Auth completed successfully for order id $order_id with token $checkout_token and charge id $charge_id");

                wp_safe_redirect($this->get_return_url($order));
                exit;
            } else {
                if (! $this->captureCharge($order) ) {
                    throw new Exception(
                        __('Checkout failed. Unable to capture charge with Affirm. Please try checking out again later, or try a different payment source.', 'woocommerce-gateway-affirm')
                    );
                }

                wp_safe_redirect($this->get_return_url($order));
                exit;
            }
        } catch ( Exception $e ) {
            if (! empty($e) ) {
                $this->log(__FUNCTION__, $e->getMessage());
                wc_add_notice($e->getMessage(), 'error');
                wp_safe_redirect(WC()->cart->get_checkout_url());
            }
        } // End try().
    }


    /**
     * Captures a charge on an order
     *
     * @param object $order order
     *
     * @return boolean
     * @since  1.0.0
     */
    public function captureCharge( $order )
    {
        $order = wc_get_order($order);

        $this->clearOrderAuthOnlyFlag($order);

        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id() : $order->get_id();

        include_once 'class-wc-gateway-affirm-charge-api.php';
        $charge_api = new WC_Gateway_Affirm_Charge_API($this, $order_id);

        $charge_id = $this->getOrderMeta($order_id, 'charge_id');

        if (! $charge_api->captureCharge($charge_id) ) {
            $this->log(__FUNCTION__, "Error: Unable to capture charge with Affirm for order {$order_id} using charge id {$charge_id}");
            return false;
        }

        $amount = $order->get_total();

        $order->add_order_note(
            sprintf(
                /* translators: 1: charge price 2: charge id */
                __('Captured charge of %1$s (charge ID %2$s)', 'woocommerce-gateway-affirm'),
                wc_price($order->get_total()),
                $charge_id
            )
        );

        $order->payment_complete($charge_id);
        $this->log(__FUNCTION__, "Info: Successfully captured {$amount} for order {$order_id}");

        return true;
    }

    /**
     * Void a charge in an order.
     *
     * @param int|WC_Order $order Order ID or Order object
     *
     * @since  1.0.1
     * @return bool Returns true when succeed
     */
    public function voidCharge( $order )
    {
        if (! is_object($order) ) {
            $order = wc_get_order($order);
        }

        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id() : $order->get_id();

        include_once 'class-wc-gateway-affirm-charge-api.php';
        $charge_api = new WC_Gateway_Affirm_Charge_API($this, $order_id);

        $charge_id = $this->getOrderMeta($order_id, 'charge_id');

        if (! $charge_api->voidCharge($charge_id) ) {
            /* translators: 1: charge id */
            $order->add_order_note(sprintf(__('Unable to void charge %s', 'woocommerce-gateway-affirm')), $charge_id);

            $this->log(__FUNCTION__, "Error: Unable to void charge with Affirm for order {$order_id} using charge id {$charge_id}");
            return false;
        }

        $this->clearOrderAuthOnlyFlag($order);

        $order->add_order_note(
            sprintf(
                /* translators: 1: charge id */
                __('Authorized charge %s has been voided', 'woocommerce-gateway-affirm'),
                $charge_id
            )
        );

        $this->log(__FUNCTION__, "Info: Successfully voided {$charge_id} for order {$order_id}");

        return true;
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @return void
     * @since  1.0.0
     */
    public function initFormFields()
    {

        $this->form_fields = array(
        'enabled' => array(
        'title'       => __('Enable/Disable', 'woocommerce-gateway-affirm'),
        'label'       => __('Enable Affirm', 'woocommerce-gateway-affirm'),
        'type'        => 'checkbox',
        'description' => __('This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-affirm'),
        'default'     => 'yes',
        'desc_tip'    => true,
        ),
        'title' => array(
        'title'       => __('Title', 'woocommerce-gateway-affirm'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-affirm'),
        'default'     => __('Affirm Monthly Payments', 'woocommerce-gateway-affirm'),
        'desc_tip'    => true,
        ),
        'description' => array(
        'title'       => __('Description', 'woocommerce-gateway-affirm'),
        'type'        => 'text',
        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-gateway-affirm'),
        'default'     => __('You will be redirected to Affirm to securely complete your purchase. It\'s quick and easy—get a real-time decision!', 'woocommerce-gateway-affirm'),
        'desc_tip'    => true,
        ),
        'testmode' => array(
        'title'       => __('Affirm Sandbox', 'woocommerce-gateway-affirm'),
        'type'        => 'checkbox',
        'description' => __('Place the payment gateway in development mode.', 'woocommerce-gateway-affirm'),
        'default'     => 'yes',
        ),
        'public_key' => array(
        'title'       => __('Public API Key', 'woocommerce-gateway-affirm'),
        'type'        => 'text',
        'description' => sprintf(
            /* translators: 1: html starting code 2: html end code */
                    __('This is the public key assigned by Affirm and available from your %1$smerchant dashboard%2$s .', 'woocommerce-gateway-affirm'),
            '<a target="_blank" href="https://www.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
            '</a>',
            '<a target="_blank" href="https://sandbox.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
            '</a>'
        ),
        'default'     => '',
        ),
        'private_key' => array(
        'title'       => __('Private API Key', 'woocommerce-gateway-affirm'),
        'type'        => 'text',
        'description' => sprintf(
            /* translators: 1: html starting code 2: html end code */
                    __('This is the private key assigned by Affirm and available from your %1$smerchant dashboard%2$s.', 'woocommerce-gateway-affirm'),
            '<a target="_blank" href="https://www.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
            '</a>',
            '<a target="_blank" href="https://sandbox.affirm.com/dashboard/" class="woocommerce_affirm_merchant_dashboard_link">',
            '</a>'
        ),
        'default'     => '',
        ),
        'transaction_mode' => array(
        'title'       => __('Transaction Mode', 'woocommerce-gateway-affirm'),
        'type'        => 'select',
        'description' => __('Select how transactions should be processed.', 'woocommerce-gateway-affirm'),
        'default'     => self::TRANSACTION_MODE_AUTH_AND_CAPTURE,
        'options'     => array(
                    self::TRANSACTION_MODE_AUTH_AND_CAPTURE => __('Authorize and Capture', 'woocommerce-gateway-affirm'),
                    self::TRANSACTION_MODE_AUTH_ONLY        => __('Authorize Only', 'woocommerce-gateway-affirm'),
        ),
        ),

            'checkout_mode' => array(
                'title'       => __('Checkout Mode', 'woocommerce-gateway-affirm'),
                'type'        => 'select',
                'description' => __('Select redirect or modal as checkout mode experience.', 'woocommerce-gateway-affirm'),
                'default'     => self::CHECKOUT_MODE_MODAL,
                'options'     => array(
                    self::CHECKOUT_MODE_MODAL => __('Modal', 'woocommerce-gateway-affirm'),
                    self::CHECKOUT_MODE_REDIRECT        => __('Redirect', 'woocommerce-gateway-affirm'),
                ),
            ),
            'cancel_url' => array(
                'title'       => __('Cancel Affirm Page', 'woocommerce-gateway-affirm'),
                'type'        => 'select',
                'description' => __('Choose to send user to the cart or payment page if Affirm payment is cancelled', 'woocommerce-gateway-affirm'),
                'default'     => self::CANCEL_TO_CART,
                'options'     => array(
                    self::CANCEL_TO_CART => __('Cart Page', 'woocommerce-gateway-affirm'),
                    self::CANCEL_TO_PAYMENT => __('Payment Page', 'woocommerce-gateway-affirm')
                ),
            ),
        'promo_id' => array(
        'title'       => __('Affirm Promo ID', 'woocommerce-gateway-affirm'),
        'type'        => 'text',
        'description' => sprintf(
            /* translators: 1: html starting code 2: html end code */
                    __('Promo ID is provided by your Affirm technical contact. If present, it will display customized messaging in the rendered marketing components. For more information, please reach out to %1$sAffirm Merchant Help%2$s.', 'woocommerce-gateway-affirm'),
            '<a target="_blank" href="https://docs.affirm.com/Contact_Us">',
            '</a>'
        ),
            'default'     => '',
        ),
        'affirm_color' => array(
        'title'       => __('Affirm Color', 'woocommerce-gateway-affirm'),
        'type'        => 'select',
        'description' => __('Affirm logo/text color on the monthly payment messaging.', 'woocommerce-gateway-affirm'),
        'default'     => 'blue',
        'options'     => array(
                    'blue'  => __('Blue', 'woocommerce-gateway-affirm'),
                    'black' => __('Black', 'woocommerce-gateway-affirm'),
                    'white' => __('White', 'woocommerce-gateway-affirm'),
        ),
        ),
        'show_learnmore' => array(
        'title'       => __('Show Learn More', 'woocommerce-gateway-affirm'),
        'type'        => 'checkbox',
        'description' => __('Show Learn More link in monthly payment messaging.', 'woocommerce-gateway-affirm'),
        'default'     => 'yes',
        ),
            'categoryALA' => array(
                'title'       => __('Category Promo Messaging', 'woocommerce-gateway-affirm'),
                'label'       => __('Enable category promotional messaging', 'woocommerce-gateway-affirm'),
                'type'        => 'checkbox',
                'description' => __('Show promotional messaging at category level pages.', 'woocommerce-gateway-affirm'),
                'default'     => 'yes',
            ),
            'productALA' => array(
                'title'       => __('Product Promo Messaging', 'woocommerce-gateway-affirm'),
                'label'       => __('Enable product promotional messaging', 'woocommerce-gateway-affirm'),
                'type'        => 'checkbox',
                'description' => __('Show promotional messaging at product level pages.', 'woocommerce-gateway-affirm'),
                'default'     => 'yes',
            ),
            'cartALA' => array(
                'title'       => __('Cart Promo Messaging', 'woocommerce-gateway-affirm'),
                'label'       => __('Enable cart promotional messaging', 'woocommerce-gateway-affirm'),
                'type'        => 'checkbox',
                'description' => __('Show promotional messaging on cart.', 'woocommerce-gateway-affirm'),
                'default'     => 'yes',
            ),
            'min' => array(
               'title'           => __('Order Minimum', 'woocommerce-gateway-affirm'),
               'type'           => 'text',
               'description'  => 'Set minimum amount for Affirm to appear at checkout.',
               'default'      => '50',
            ),
            'max' => array(
                'title'       => __('Order Maximum', 'woocommerce-gateway-affirm'),
                'type'           => 'text',
                'description' => 'Set maximum amount for Affirm to appear at checkout.',
                'default'     => '30000'
            ),
            'debug' => array(
                'title'       => __('Debug', 'woocommerce-gateway-affirm'),
                'label'       => __('Enable debugging messages', 'woocommerce-gateway-affirm'),
                'type'        => 'checkbox',
                'description' => __('Sends debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-affirm'),
                'default'     => 'yes',
            ),
            'enhanced_analytics' => array(
                'title'       => __('Enable enhanced analytics', 'woocommerce-gateway-affirm'),
                'type'        => 'checkbox',
                'description' => __('Enable analytics to optimize Affirm implementation and to maximize conversion rates.', 'woocommerce-gateway-affirm.'),
                'default'     => 'yes',
            ),

        );
    }

    /**
     * Don't even allow administration of this extension if the currency is not
     * supported.
     *
     * @since  1.0.0
     * @return boolean
     */
    function isValidForAdministration()
    {
        if ('USD' !== get_woocommerce_currency() ) {
            return false;
        }

        return true;
    }

    /**
     * Admin Warning Message
     *
     * @since  1.0.0
     * @return string
     */
    function admin_options()
    {
        if ($this->isValidForAdministration() ) {
            parent::admin_options();
        } else {
            ?>
            <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'woocommerce-gateway-affirm'); ?></strong>: <?php _e('Affirm does not support your store currency.', 'woocommerce-gateway-affirm'); ?></p></div>
            <?php
        }
    }

    /**
     * Check for required settings, and if SSL is enabled
     *
     * @return string
     */
    public function adminNotices()
    {

        if ('no' === $this->enabled ) {
            return;
        }

        $general_settings_url = admin_url('admin.php?page=wc-settings');
        $checkout_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout');
        $affirm_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_affirm');

        // Check required fields.
        if (empty($this->public_key) || empty($this->private_key) ) {
            /* translators: 1: affirm settings url */
            echo '<div class="error"><p>' . sprintf(__('Affirm: One or more of your keys is missing. Please enter your keys <a href="%s">here</a>', 'woocommerce-gateway-affirm'), $affirm_settings_url) . '</p></div>';
            return;
        }

        // Check for duplicate keys.
        if ($this->public_key == $this->private_key ) {
            echo '<div class="error"><p>' . sprintf(__('Affirm: You have entered the same key in one or more fields. Each key must be unique. Please check and re-enter.', 'woocommerce-gateway-affirm'), $affirm_settings_url) . '</p></div>';
            return;
        }

        // Check Currency.
        if ('USD' !== get_woocommerce_currency() ) {
            echo '<div class="error"><p>' . sprintf(__('Affirm: Affirm only supports USD for currency.', 'woocommerce-gateway-affirm'), $general_settings_url) . '</p></div>';
            return;
        }

        // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS
        // plugin is not detected.
        if (! wc_checkout_is_https() ) {
            /* translators: 1: checkout settings url */
            echo '<div class="error"><p>' . sprintf(__('Affirm: The <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Affirm will only work in test mode.', 'woocommerce-gateway-affirm'), $checkout_settings_url) . '</p></div>';
        }
    }

    /**
     * Don't allow use of this extension if the currency is not supported or if
     * setup is incomplete.
     *
     * @since 1.0.0
     *
     * @return bool Returns true if gateway is valid for use
     */
    function isValidForUse()
    {
        if ($this->isCurrentPageRequiresSsl() && ! is_ssl() ) {
            return false;
        }

        if ('USD' !== get_woocommerce_currency() ) {
            return false;
        }

        if (empty($this->public_key) ) {
            return false;
        }

        if (empty($this->private_key) ) {
            return false;
        }

        return true;
    }

    /**
     * Checks if current page requires SSL.
     *
     * @since 1.0.6
     *
     * @return bool Returns true if current page requires SSL
     */
    public function isCurrentPageRequiresSsl()
    {
        if ($this->testmode ) {
            return false;
        }

        return is_checkout();
    }

    /**
     * Get URLs
     *
     * @param object $order order
     *
     * @return  bool
     * @since   1.0.0
     * @version 1.0.9
     */
    function get_transaction_url( $order )
    {
        $transaction_id = $order->get_transaction_id();

        if (empty($transaction_id) ) {
            return false;
        }

        if ($this->testmode ) {
            $server = 'sandbox.affirm.com';
        } else {
            $server = 'affirm.com';
        }

        return 'https://' . $server . '/dashboard/#/details/' . urlencode($transaction_id);
    }

    /**
     * Affirm only supports US customers
     *
     * @return  bool
     * @since   1.0.0
     * @version 1.0.9
     */
    function is_available()
    {
        if (is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' )) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );
            $total = $order->get_total();
        } else {
            $total = WC()->cart->cart_contents_total;
        }

        $is_available = ( 'yes' === $this->enabled ) ? true : false;
        if (! WC()->customer ) {
            return false;
        }

        $country = version_compare(WC_VERSION, '3.0', '<') ? WC()->customer->get_country() : WC()->customer->get_billing_country();
        $min = $this->get_option('min');
        $max = $this->get_option('max');

        $available_country = ['US','AS','GU','MP','PR','VI'];

        if( !in_array( $country, $available_country ) && $country !== '' ){
            $is_available = false;
        } elseif( $min > $total ){
            $is_available = false;
        } elseif ( $max < $total ) {
            $is_available = false;
        }
        return $is_available;

    }


    /**
     * Affirm is different. We can't redirect to their server after validating the
     * shipping and billing info the user supplies - their Javascript object
     * needs to do the redirection, but we still want to validate the user info,
     * so we'll land here when the customer clicks Place Order and after WooCommerce
     * has validated the customer info and created the order. So, we'll redirect to
     * ourselves with some query args to prompt us to embed the Affirm JavaScript
     * bootstrap and an Affirm formatted version of the cart
     *
     * @param string $order_id order id
     *
     * @return array
     * @since  1.0.0
     */
    public function process_payment( $order_id )
    {
        $order = wc_get_order($order_id);
        $order_key = version_compare(WC_VERSION, '3.0', '<') ? $order->order_key : $order->get_order_key();
        $redirect_url = add_query_arg(
            array(
            'affirm' => '1',
            'order_id' => $order_id,
            'nonce' => wp_create_nonce('affirm-checkout-order-' . $order_id),
            'key' => $order_key,
            ), get_permalink(wc_get_page_id('checkout')) . '/order-pay/' . $order_id .'/'
        );

        return array(
        'result'   => 'success',
        'redirect' => $redirect_url,
        );
    }

    /**
     * Can the order be refunded via Affirm?
     *
     * @param object $order order
     *
     * @return bool
     */
    public function canRefundOrder( $order )
    {
        return ( $order && ( $this->issetOrderAuthOnlyFlag($order) || $order->get_transaction_id() ) );
    }

    /**
     * Process a refund if supported
     *
     * @param int    $order_id      order id
     * @param float  $refund_amount refund amount
     * @param string $reason        reason
     *
     * @return boolean|WP_Error
     */
    public function process_refund( $order_id, $refund_amount = null, $reason = '' )
    {

        $this->log(__FUNCTION__, "Info: Beginning processing refund/void for order $order_id");

        $order = wc_get_order($order_id);

        if (! $order ) {
            $this->log(__FUNCTION__, "Error: Order {$order_id} could not be found.");
            return new WP_Error('error', __('Refund failed: Unable to retrieve order', 'woocommerce-gateway-affirm'));
        }

        $order_total = floatval($order->get_total());
        if (! $refund_amount ) {
            $refund_amount = $order_total;
        }

        if (! $this->canRefundOrder($order) ) {
            $this->log(__FUNCTION__, "Error: Order {$order_id} is not refundable. It was neither authorized nor captured. The customer may have abandoned the order.");
            return new WP_Error('error', __('Refund failed: The order is not refundable. It was neither authorized nor captured. The customer may have abandoned the order.', 'woocommerce-gateway-affirm'));
        }

        include_once 'class-wc-gateway-affirm-charge-api.php';
        $charge_api = new WC_Gateway_Affirm_Charge_API($this, $order_id);

        // Only an auth?  Just void and cancel the whole thing
        if ($this->issetOrderAuthOnlyFlag($order) ) {

            $is_a_full_refund = ( abs($order_total - $refund_amount) < 0.01 ); // Floating point comparison to cents accuracy (Affirm only does USD)
            if (! $is_a_full_refund ) {
                $this->log(__FUNCTION__, "Error: A partial refund of an auth-only order {$order_id} was attempted. You cannot partially refund an order until it has been captured.");
                return new WP_Error('error', __('Refund failed: You cannot partially refund an order until it has been captured.', 'woocommerce-gateway-affirm'));
            }

            // Otherwise, proceed
            $charge_id = $order->get_transaction_id();
            $result = $charge_api->voidCharge($charge_id);

            if (false === $result || is_wp_error($result) ) {
                $this->log(__FUNCTION__, "Error: An error occurred while attempting to void order {$order_id}.");
                return new WP_Error('error', __('Refund failed: The order had been authorized, and not captured, but voiding the order unexpectedly failed.', 'woocommerce-gateway-affirm'));
            }

            $order->add_order_note(
                sprintf(
                    /* translators: 1: reason for void */
                    __('Voided - Reason: %s', 'woocommerce-gateway-affirm'),
                    esc_html($reason)
                )
            );

            $this->clearOrderAuthOnlyFlag($order);
            $this->log(__FUNCTION__, "Info: Successfully voided order {$order_id}");

            return true;
        }

        // Otherwise, process a refund

        $refund_amount_in_cents = intval(100 * $refund_amount);
        $charge_id = $order->get_transaction_id();
        $result = $charge_api->refundCharge($charge_id, $refund_amount_in_cents);

        if (false === $result || is_wp_error($result) ) {
            $this->log(__FUNCTION__, "Error: An error occurred while attempting to refund order {$order_id}.");
            return new WP_Error('error', __('Refund failed: The order had been authorized and captured, but refunding the order unexpectedly failed.', 'woocommerce-gateway-affirm'));
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: refund amount 2: refund id 3: reason */
                __('Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-gateway-affirm'),
                wc_price(0.01 * $result['amount']), // Affirm provides amounts in cents
                esc_html($result['id']),
                esc_html($reason)
            )
        );

        $this->log(__FUNCTION__, "Info: Successfully refunded {$refund_amount} for order {$order_id}");

        return true;
    }


    /**
     * We'll hook here to embed the Affirm JavaScript object bootstrapper into the checkout page
     *
     * @since  1.0.0
     * @return void
     */
    function reviewOrderBeforePayment()
    {

        if (! $this->isCheckoutAutoPostPage() ) {
            return;
        }

        $order = $this->validateOrderFromRequest();
        if (false === $order ) {
            wp_die(__('Checkout using Affirm failed. Please try checking out again later, or try a different payment source.', 'woocommerce-gateway-affirm'));
        }
    }


    /**
     * If we see the query args indicating that the Affirm bootstrap and Affirm-formatted cart
     * is/should be loaded, return true
     *
     * @since  1.0.0
     * @return boolean
     */
    function isCheckoutAutoPostPage()
    {
        if (! is_checkout() ) {
            return false;
        }

        if (! isset($_GET['affirm']) || ! isset($_GET['order_id']) || ! isset($_GET['nonce']) ) {
            return false;
        }

        return true;
    }


    /**
     * Return the appropriate order based on the query args, with nonce protection.
     *
     * @since  1.0.0
     * @return object
     */
    function validateOrderFromRequest()
    {

        if (empty($_GET['order_id']) ) {
            return false;
        }

        $order_id = wc_clean($_GET['order_id']);

        if (! is_numeric($order_id) ) {
            return false;
        }

        $order_id = absint($order_id);

        if (empty($_GET['nonce']) || ! wp_verify_nonce($_GET['nonce'], 'affirm-checkout-order-' . $order_id) ) {
            return false;
        }

        $order = wc_get_order($order_id);

        if (! $order ) {
            return false;
        }

        return $order;
    }

    /**
     * Encode and enqueue the cart contents for use by Affirm's JavaScript object
     *
     * @since   1.0.0
     * @version 1.0.10
     *
     * @return void
     */
    function enqueueScripts()
    {
        if (! $this->isCheckoutAutoPostPage() ) {

            return;
        }

        $order = $this->validateOrderFromRequest();
        if (false === $order ) {
            return;
        }

        // We made it this far, let's fire up affirm and embed the order data in an affirm friendly way
        wp_enqueue_script('woocommerce_affirm', plugins_url('assets/js/affirm-checkout.js', dirname(__FILE__)), array( 'jquery', 'jquery-blockui' ), WC_GATEWAY_AFFIRM_VERSION, true);

        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id : $order->get_id();
        $order_key = version_compare(WC_VERSION, '3.0', '<') ? $order->order_key : $order->get_order_key();

        $confirmation_url = add_query_arg(
            array(
            'action'    => 'complete_checkout',
            'order_id'  => $order_id,
            'order_key' => $order_key,
            ),
            WC()->api_request_url(get_class($this))
        );


        if ($this->cancel_url == self::CANCEL_TO_CART ) {
            $cancel_url = html_entity_decode($order->get_cancel_order_url());
        } else {
            $cancel_url = html_entity_decode($order->get_checkout_payment_url());
        }

        $total_discount = floor(100 * $order->get_total_discount());
        $total_tax      = floor(100 * $order->get_total_tax());
        $total_shipping = version_compare(WC_VERSION, '3.0', '<') ? $order->get_total_shipping() : $order->get_shipping_total();
        $total_shipping = ! empty($order->get_shipping_method()) ? floor(100 * $total_shipping) : 0;
        $total          = floor( strval(100 * $order->get_total()) ) ;

        $affirm_data = array(
        'merchant' => array(
        'user_confirmation_url' => $confirmation_url,
        'user_cancel_url'       => $cancel_url,
        ),
        'items'     => $this->getItemsFormattedForAffirm($order),
        'discounts' => array(
        'discount' => array(
                    'discount_amount' => $total_discount,
        ),
        ),
        'metadata' => array(
        'order_key'        => $order_key,
        'platform_type'    => 'WooCommerce',
        'platform_version' => WOOCOMMERCE_VERSION,
        'platform_affirm'  => WC_GATEWAY_AFFIRM_VERSION,
                'mode' => $this->checkout_mode
        ),
        'tax_amount'      => $total_tax,
        'shipping_amount' => $total_shipping,
        'total'           => $total,
        'order_id'         => $order_id,
        );

        $old_wc = version_compare(WC_VERSION, '3.0', '<');

        $affirm_data += array(
        'currency'                => $old_wc ? $order->get_order_currency() : $order->get_currency(),
        'billing' => array(
        'name' => array(
                    'first'   => $old_wc ? $order->billing_first_name : $order->get_billing_first_name(),
                    'last'    => $old_wc ? $order->billing_last_name : $order->get_billing_last_name(),
        ),
        'address' => array(
        'line1'   => $old_wc ? $order->billing_address_1 : $order->get_billing_address_1(),
        'line2'   => $old_wc ? $order->billing_address_2 : $order->get_billing_address_2(),
        'city'    => $old_wc ? $order->billing_city : $order->get_billing_city(),
        'state'   => $old_wc ? $order->billing_state : $order->get_billing_state(),
        'zipcode' => $old_wc ? $order->billing_postcode : $order->get_billing_postcode(),
        ),
        'email'           => $old_wc ? $order->billing_email : $order->get_billing_email(),
        'phone_number'    => $old_wc ? $order->billing_phone : $order->get_billing_phone(),
        ),
        'shipping' => array(
        'name' => array(
                    'first'   => $old_wc ? $order->shipping_first_name : $order->get_shipping_first_name(),
                    'last'    => $old_wc ? $order->shipping_last_name : $order->get_shipping_last_name(),
        ),
        'address' => array(
        'line1'   => $old_wc ? $order->shipping_address_1 : $order->get_shipping_address_1(),
        'line2'   => $old_wc ? $order->shipping_address_2 : $order->get_shipping_address_2(),
        'city'    => $old_wc ? $order->shipping_city : $order->get_shipping_city(),
        'state'   => $old_wc ? $order->shipping_state : $order->get_shipping_state(),
        'zipcode' => $old_wc ? $order->shipping_postcode : $order->get_shipping_postcode(),
        ),
        ),
        );

        /**
         * If for some reason shipping info is empty (e.g. shipping is disabled),
         * use billing address.
         *
         * @see https://github.com/woocommerce/woocommerce-gateway-affirm/issues/81#event-1109051257
         */
        foreach ( array( 'name', 'address' ) as $field ) {
            $shipping_field = array_filter($affirm_data['shipping'][ $field ]);
            if (empty($shipping_field) ) {
                $affirm_data['shipping'][ $field  ] = $affirm_data['billing'][ $field  ];
            }
        }

        wp_localize_script('woocommerce_affirm', 'affirmData', apply_filters('wc_gateway_affirm_initiate_checkout_data', $affirm_data));
    }

    /**
     * Helper to encode the items in the cart for use by Affirm's JavaScript object
     *
     * @param object $order order
     *
     * @return array
     * @since  1.0.0
     */
    function getItemsFormattedForAffirm( $order )
    {

        $items = array();

        foreach ( (array) $order->get_items(array( 'line_item', 'fee' )) as $item ) {
            $display_name = $item->get_name();
            $sku = '';
            $unit_price = 0;
            $qty = $item->get_quantity();
            $item_image_url = wc_placeholder_img_src();
            $item_url = '';

            if ('fee' === $item['type'] ) {

                $unit_price = $item['line_total'];

            } else {

                $product = $order->get_product_from_item($item);
                $sku = $this->_clean($product->get_sku());
                $unit_price = floor(100.0 * $order->get_item_subtotal($item, false)); // cents please

                $item_image_id = $product->get_image_id();
                $image_attributes = wp_get_attachment_image_src($item_image_id);
                if (is_array($image_attributes) ) {
                    $item_image_url = $image_attributes[0];
                }

                $item_url = $product->get_permalink();

            }

            $items[] = array(
            'display_name'   => $display_name,
            'sku'            => $sku ? $sku : $product->get_id(),
            'unit_price'     => $unit_price,
            'qty'            => $qty,
            'item_image_url' => $item_image_url,
            'item_url'       => $item_url,
            );

        } // End foreach().

        return $items;

    }

    /**
     * Helper to enqueue admin scripts
     *
     * @param object $hook hook
     *
     * @since  1.0.0
     * @return void
     */
    public function adminEnqueueScripts( $hook )
    {

        if ('woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        if ( ! isset( $_GET['section'] ) ) {
            return;
        }

        if ( 'wc_gateway_affirm' == $_GET['section'] || 'affirm' == $_GET['section'] ) {

            wp_register_script('woocommerce_affirm_admin', plugins_url('assets/js/affirm-admin.js', dirname(__FILE__)), array('jquery'), WC_GATEWAY_AFFIRM_VERSION);

            $admin_array = array(
                'sandboxedApiKeysURI' => 'https://sandbox.affirm.com/dashboard/#/apikeys',
                'apiKeysURI' => 'https://affirm.com/dashboard/#/apikeys',
            );

            wp_localize_script('woocommerce_affirm_admin', 'affirmAdminData', $admin_array);
            wp_enqueue_script('woocommerce_affirm_admin');
        }
    }


    /**
     * Helper methods to check order auth flag
     *
     * @param object $order order
     *
     * @return bool|int
     */
    public function issetOrderAuthOnlyFlag( $order )
    {
        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id : $order->get_id();
        return $this->getOrderMeta($order_id, 'authorized_only');
    }

    /**
     * Helper methods to set order auth flag
     *
     * @param object $order order
     *
     * @return bool|int
     */
    public function setOrderAuthOnlyFlag( $order )
    {
        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id : $order->get_id();
        return $this->updateOrderMeta($order_id, 'authorized_only', true);
    }

    /**
     * Helper methods to clear order auth flag
     *
     * @param object $order order
     *
     * @return bool|int
     */
    public function clearOrderAuthOnlyFlag( $order )
    {
        $order_id = version_compare(WC_VERSION, '3.0', '<') ? $order->id : $order->get_id();
        return $this->deleteOrderMeta($order_id, 'authorized_only');
    }

    /**
     * Helper methods to update order meta with scoping for this extension
     *
     * @param string $order_id order id
     * @param string $key      key
     * @param string $value    value
     *
     * @return bool|int
     */
    public function updateOrderMeta( $order_id, $key, $value )
    {
        return update_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}", $value);
    }

    /**
     * Helper methods to get order meta with scoping for this extension
     *
     * @param string $order_id order id
     * @param string $key      key
     *
     * @return bool|int
     */
    public function getOrderMeta( $order_id, $key )
    {
        return get_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}", true);
    }

    /**
     * Helper methods to delete order meta with scoping for this extension
     *
     * @param string $order_id order id
     * @param string $key      key
     *
     * @return bool|int
     */
    public function deleteOrderMeta( $order_id, $key )
    {
        return delete_post_meta($order_id, "_wc_gateway_{$this->id}_{$key}");
    }

    /**
     * Logs action
     *
     * @param string $context context
     * @param string $message message
     *
     * @return void
     */
    public function log( $context, $message )
    {
        if ($this->debug ) {
            if (empty($this->log) ) {
                $this->log = new WC_Logger();
            }

            $this->log->add('woocommerce-gateway-' . $this->id, $context . ' - ' . $message);

            if (defined('WP_DEBUG') && WP_DEBUG ) {
                error_log($context . ' - ' . $message);
            }
        }
    }

    /**
     * Removes all special characters
     *
     * @param $sku
     * @return string
    */
    private function _clean($sku)
    {
        $sku = str_replace(' ', '-', $sku); // Replaces all spaces with hyphens.
        return preg_replace('/[^A-Za-z0-9\-]/', '', $sku); // Removes special chars.
    }

}
