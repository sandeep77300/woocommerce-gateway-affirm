<?php
/**
 * * WC_Gateway_Affirm_Charge_API
 *
 * WC_Gateway_Affirm_Charge_API connects to the 
 * affirm API to do all charge actions 
 * ie capture, return void, auth
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
if (! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

/**
 * * WC_Gateway_Affirm_Charge_API
 *
 * WC_Gateway_Affirm_Charge_API connects to the 
 * affirm API to do all charge actions ie capture, 
 * return void, auth
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
class WC_Gateway_Affirm_Charge_API
{

    const STATUS_AUTHORIZED = 'authorized';

    /**
     * Pointer to gateway making the request
     *
     * @var WC_Gateway_Affirm
     */
    protected $gateway;


    /**
     * Order ID for all interactions with Affirm's Charge API
     *
     * @var integer
     */
    protected $order_id;


    /**
     * Constructor
     *
     * @param array  $gateway  gateway
     * @param string $order_id order id
     */
    public function __construct( $gateway, $order_id )
    {
        $this->gateway = $gateway;
        $this->order_id = $order_id;
    }


    /**
     * Exchange the checkout token provided to us by Affirm in the postback
     * for a charge id
     *
     * @param string $checkout_token checkout token
     *
     * @return array|WP_Error Returns array containing charge ID. Otherwise
     *                        WP_Error is returned
     * @since  1.0.0
     */
    public function requestChargeIdForToken( $checkout_token )
    {

        $response = $this->_postAuthenticatedJsonRequest(
            'api/v2/charges',
            array( 
                'checkout_token' => $checkout_token , 
                'order_id' => $this->order_id 
            )
        );

        if (is_wp_error($response) ) {
            return $response;
        }

        // Check HTTP status.
        $http_status = intval(wp_remote_retrieve_response_code($response));
        if (in_array($http_status, array( 400, 401 )) ) {
            return new WP_Error(
                'authorization_failed', 
                __(
                    'There was an issue authorizing your Affirm loan. '.
                    'Please check out again or use a different payment method.', 
                    'woocommerce-gateway-affirm'
                )
            );
        }

        if (! array_key_exists('body', $response) ) {
            return new WP_Error(
                'unexpected_response', 
                __(
                    'Unexpected response from Affirm. '.
                    'Missing response body.', 
                    'woocommerce-gateway-affirm'
                )
            );
        }

        $body = json_decode($response['body']);
        if (! property_exists($body, 'id') ) {
            return new WP_Error(
                'unexpected_response', 
                __(
                    'Unexpected response from Affirm. '.
                    'Missing id in response body.', 
                    'woocommerce-gateway-affirm'
                )
            );
        }

        // Validate this charge corresponds to the order.
        $validates = false;

        if (property_exists($body, 'details') ) {
            $details = $body->details;

            if (property_exists($details, 'metadata') ) {
                $metadata = $details->metadata;

                if (property_exists($metadata, 'order_key') ) {
                    $order     = wc_get_order($this->order_id);
                    $orderAmount = intval(floor(strval(100 * $order->get_total())));
                    $authorizedAmount = $details->total;
                    $order_key = version_compare(WC_VERSION, '3.0', '<') ? 
                        $order->order_key : 
                        $order->get_order_key();
                    $cart_hash = $order->get_cart_hash();
                    $validates = ( 
                        $metadata->order_key === $order_key || 
                        $metadata->order_key === $cart_hash  
                    );
                    $amount_validation = ($orderAmount === $authorizedAmount );
                }
            }
        }

        $result = array(
        'charge_id' => $body->id,
        'validates' => $validates,
        'amount_validation' => $amount_validation
        );

        return $result;
    }

    /**
     * Read the charge information for a specific charge.
     *
     * @param string $charge_id Charge ID
     *
     * @since  1.0.1
     * @return bool|array Returns false if failed, 
     * otherwise array of charge information
     */
    public function readCharge( $charge_id )
    {
        if (empty($charge_id) ) {
            return false;
        }

        $response = $this->_getAuthenticatedJsonRequest(
            "api/v2/charges/{$charge_id}"
        );
        if (is_wp_error($response) ) {
            return false;
        }

        $body   = wp_remote_retrieve_body($response);
        $charge = json_decode($body);

        if (empty($charge->id) ) {
            return false;
        }

        if ($charge_id !== $charge->id ) {
            return false;
        }

        return $charge;
    }

    /**
     * Capture the charge
     *
     * @param string $charge_id charge id
     *
     * @return bool
     * @since  1.0.0
     */
    public function captureCharge( $charge_id )
    {

        $response = $this->_postAuthenticatedJsonRequest(
            "api/v2/charges/{$charge_id}/capture",
            array(
            'order_id' => $this->order_id,
            )
        );

        if (is_wp_error($response) ) {
            return false;
        }

        if (! array_key_exists('response', $response) ) {
            return false;
        }

        $response_response = $response['response'];
        if (! array_key_exists('code', $response_response) ) {
            return false;
        }

        if (200 != $response_response['code'] ) {
            return false;
        }

        if (! array_key_exists('body', $response) ) {
            return false;
        }

        $body = json_decode($response['body']);

        $fee_amount = 0;

        if (property_exists($body, 'fee') ) {
            $fee_amount = intval($body->fee);
        }

        return array(
            'fee_amount' => $fee_amount, // in cents
            'charge_id' => $charge_id,
        );
    }


    /**
     * Void the charge
     *
     * @param string $charge_id charge id
     *
     * @return bool
     * @since  1.0.0
     */
    public function voidCharge( $charge_id )
    {
        $charge = $this->readCharge($charge_id);
        if (! $charge ) {
            return false;
        }

        // Make sure charge is in authorized state.
        if (self::STATUS_AUTHORIZED !== $charge->status ) {
            return false;
        }

        $response = $this->_postAuthenticatedJsonRequest(
            "api/v2/charges/{$charge_id}/void"
        );

        if (is_wp_error($response) ) {
            return false;
        }

        return true;
    }


    /**
     * Refund the charge
     * Amount in cents (e.g. $50.00 = 5000)
     *
     * @param string $charge_id charge_id
     * @param int    $amount    amount
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function refundCharge( $charge_id, $amount )
    {

        $response = $this->_postAuthenticatedJsonRequest(
            "api/v2/charges/{$charge_id}/refund",
            array(
            'amount' => $amount,
            )
        );

        if (is_wp_error($response) ) {
            return false;
        }

        if (! array_key_exists('response', $response) ) {
            return false;
        }

        $response_response = $response['response'];
        if (! array_key_exists('code', $response_response) ) {
            return false;
        }

        if (200 != $response_response['code'] ) {
            return false;
        }

        if (! array_key_exists('body', $response) ) {
            return false;
        }

        $body = json_decode($response['body']);

        $refund_amount = 0;
        $transaction_id = '';
        $fee_refunded = 0;

        if (property_exists($body, 'amount') ) {
            $refund_amount = intval($body->amount);
        }

        if (property_exists($body, 'id') ) {
            $id = $body->id;
        }

        if (property_exists($body, 'fee_refunded') ) {
            $fee_refunded = intval($body->fee_refunded);
        }

        return array(
        'amount' => $refund_amount, // in cents
        'id' => $id,
        'fee_refunded' => $fee_refunded
        );
    }


    /**
     * Helper to POST json data to Affirm using Basic Authentication
     *
     * @param string $route The API endpoint we are POSTing to e.g. 'api/v2/charges'
     * @param array  $body  The data (if any) to jsonify and POST to the endpoint
     *
     * @since  1.0.0
     * @return string
     */
    private function _postAuthenticatedJsonRequest( $route, $body = false )
    {

        if ($this->gateway->testmode ) {
            $server = 'https://sandbox.affirm.com/';
        } else {
            $server = 'https://api.affirm.com/';
        }

        $url = $server . $route;

        $options = array(
        'method' => 'POST',
        'headers' => array(
        'Authorization' => 'Basic ' . base64_encode(
            $this->gateway->public_key . 
            ':' . 
            $this->gateway->private_key
        ),
        'Content-Type' => 'application/json',
        ),
        );

        if (! empty($body) ) {
            $options['body'] = wp_json_encode($body);
        }

        return wp_safe_remote_post($url, $options);
    }

    /**
     * Helper to GET json data from Affirm using Basic Authentication.
     *
     * @param string $route The API endpoint we are POSTing to e.g. 'api/v2/charges'
     *
     * @since  1.0.1
     * @return string
     */
    private function _getAuthenticatedJsonRequest( $route )
    {
        if ($this->gateway->testmode ) {
            $server = 'https://sandbox.affirm.com/';
        } else {
            $server = 'https://api.affirm.com/';
        }

        $url = $server . $route;

        $options = array(
        'headers' => array(
        'Authorization' => 'Basic ' . base64_encode(
            $this->gateway->public_key . 
            ':' . 
            $this->gateway->private_key
        ),
        'Content-Type' => 'application/json',
        ),
        );

        return wp_safe_remote_get($url, $options);
    }
}
