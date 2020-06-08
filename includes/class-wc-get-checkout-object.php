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
class Affirm_Checkout_Object
{
    /**
     * Instance of WC_Gateway_Affirm.
     *
     * @var WC_Gateway_Affirm
     */
    private $gateway = false;

    /**
     * Get checkout object
     * 
     * @param boolean $order order
     * 
     * @return object
     *
     * @var WC_Gateway_Affirm
     */
    public function getCheckoutObject( $order = false)
    {   
        if ($order) {
            return $this->formatCheckoutObjectForPlaceOrder($order);       
        } 
        $checkoutObject = $this->formatCheckoutObject();
        return $this->response($checkoutObject);
    }

    /**
     * Format cart for Affirm checkout opject
     * 
     * @return object
     *
     * @var WC_Gateway_Affirm
     */
    public function formatCheckoutObject()
    {
        $confirmation_url = add_query_arg(
            array(
            'action'    => 'complete_checkout',
            ),
            WC()->api_request_url('WC_Gateway_Affirm')
        );

        if ($this->getGateway()->cancel_url == WC_Gateway_Affirm::CANCEL_TO_CART ) {
            $cancel_url = wc_get_cart_url();
        } else if ($this->getGateway()->cancel_url ==WC_Gateway_Affirm::CANCEL_TO_PAYMENT 
        ) {
            $cancel_url = get_checkout_payment_url();
        } else {
            $cancel_url = wc_get_checkout_url();
        }
        $cart = WC()->cart;
        $total_discount = floor(100 * $cart->get_discount_total());
        $total_tax      = floor(100 * $cart->get_total_tax());
        $total_shipping = floor(100 * $cart->get_shipping_total());
        $total          = floor(100 * $cart->get_total('false'));
        
        $affirm_data = array(
            'merchant' => array(
                'user_confirmation_url' => $confirmation_url,
                'user_cancel_url'       => $cancel_url,
            ),
            'items'     => $this->getItemsFormattedForAffirm($cart->get_cart()),
            'discounts' => array(
                'discount' => array(
                    'discount_amount' => $total_discount,
                ),
            ),
            'metadata' => array(
                'order_key'        => $cart->get_cart_hash(),
                'platform_type'    => 'WooCommerce',
                'platform_version' => WOOCOMMERCE_VERSION,
                'platform_affirm'  => WC_GATEWAY_AFFIRM_VERSION,
                'mode' => $this->getGateway()->checkout_mode
            ),
            'tax_amount'      => $total_tax,
            'shipping_amount' => $total_shipping,
            'total'           => $total,
        );

        $customer = WC()->customer;
        $affirm_data += array(
            'currency' => get_woocommerce_currency(),
            'billing' => array(
                'name' => array(
                    'first'   => $customer->get_billing_first_name(),
                    'last'    => $customer->get_billing_last_name(),
                ),
                'address' => array(
                    'line1'   => $customer->get_billing_address_1(),
                    'line2'   => $customer->get_billing_address_2(),
                    'city'    => $customer->get_billing_city(),
                    'state'   => $customer->get_billing_state(),
                    'zipcode' => $customer->get_billing_postcode()
                ),
                'email'           => $customer->get_billing_email(),
                'phone_number'    => $customer->get_billing_phone()
            ),
            'shipping' => array(
                'name' => array(
                    'first'   => $customer->get_shipping_first_name(),
                    'last'    => $customer->get_shipping_last_name(),
                ),
                'address' => array(
                    'line1'   => $customer->get_shipping_address_1(),
                    'line2'   => $customer->get_shipping_address_2(),
                    'city'    => $customer->get_shipping_city(),
                    'state'   => $customer->get_shipping_state(),
                    'zipcode' => $customer->get_shipping_postcode(),
                ),
            ),
        );

        return $affirm_data;
    }

    /**
     * Helper to encode the items in the cart for use by Affirm's JavaScript object
     *
     * @param array $items items
     *
     * @return array
     * @since  1.0.0
     */
    function getItemsFormattedForAffirm( $items )
    {
        $itemArray = array();
        foreach ( $items as $item ) {   
            $product_id = $item['data']->get_id();
            $getProductDetail = wc_get_product($product_id);
            $display_name = $item['data']->get_title();
            $sku = $item['data']->get_sku();
            $unit_price = $item['data']->get_price();
            $qty = $item['quantity'];
            $item_image_url = wp_get_attachment_image_url(
                $getProductDetail->get_image_id(), 
                'woocommerce_thumbnail'
            );
            $item_url = $getProductDetail->get_permalink();

            $itemArray[] = array(
                'display_name'   => $display_name,
                'sku'            => $sku ? $sku : $product->get_id(),
                'unit_price'     => $unit_price,
                'qty'            => $qty,
                'item_image_url' => $item_image_url,
                'item_url'       => $item_url,
            );

        } 
        
        return $itemArray;

    }
    
    /**
     * HTTP Response
     *
     * @param string $message string
     *
     * @return WP_REST_Response
     */
    public function response($message)
    {

        $response = new WP_REST_Response($message);
        $response->set_status(200);
        $response->header(
            'Access-Control-Allow-Methods',
            'POST, GET, OPTIONS, DELETE, PUT'
        );
        $response->header(
            'Access-Control-Allow-Credentials',
            true
        );
        $response->header(
            'Access-Control-Allow-Headers',
            'content-type'
        );
        $response->header(
            'Content-Type',
            'text/html'
        );

        return $response;
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

}