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
if (! class_exists('WC_Abstract_Privacy') ) {
    return;
}
/**
 * Affirm Payment Gateway Privacy Class
 *
 * @category Payment_Gateways
 * @class    WC_Gateway_Affirm
 * @package  WooCommerce
 * @author   MTA <mts@affirm.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.affirm.com/
 */
class WC_Affirm_Privacy extends WC_Abstract_Privacy
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(__('Affirm', 'woocommerce-gateway-affirm'));

        $this->add_exporter('woocommerce-gateway-affirm-order-data', __('WooCommerce Affirm Order Data', 'woocommerce-gateway-affirm'), array( $this, 'orderDataExporter' ));

        $this->add_eraser('woocommerce-gateway-affirm-order-data', __('WooCommerce Affirm Data', 'woocommerce-gateway-affirm'), array( $this, 'orderDataEraser' ));
    }

    /**
     * Returns a list of orders that are using one of Affirm's payment methods.
     *
     * @param string $email_address email
     * @param int    $page          page
     *
     * @return array WP_Post
     */
    protected function getOrders( $email_address, $page )
    {
        $user = get_user_by('email', $email_address); // Check if user has an ID in the DB to load stored personal data.

        $order_query    = array(
        'payment_method' => array( 'affirm' ),
        'limit'          => 10,
        'page'           => $page,
        );

        if ($user instanceof WP_User ) {
            $order_query['customer_id'] = (int) $user->ID;
        } else {
            $order_query['billing_email'] = $email_address;
        }

        return wc_get_orders($order_query);
    }

    /**
     * Gets the message of the privacy to display.
     *
     * @return string
     */
    public function get_privacy_message()
    {
        return wpautop(sprintf(__('By using this extension, you may be storing personal data or sharing data with an external service. <a href="%s" target="_blank">Learn more about how this works, including what you may want to include in your privacy policy.</a>', 'woocommerce-gateway-affirm'), 'https://docs.woocommerce.com/document/privacy-payments/#woocommerce-gateway-affirm'));
    }

    /**
     * Handle exporting data for Orders.
     *
     * @param string $email_address E-mail address to export.
     * @param int    $page          Pagination of data.
     *
     * @return array
     */
    public function orderDataExporter( $email_address, $page = 1 )
    {
        $done           = false;
        $data_to_export = array();

        $orders = $this->getOrders($email_address, (int) $page);

        $done = true;

        if (0 < count($orders) ) {
            foreach ( $orders as $order ) {
                $data_to_export[] = array(
                 'group_id'    => 'woocommerce_orders',
                 'group_label' => __('Orders', 'woocommerce-gateway-affirm'),
                 'item_id'     => 'order-' . $order->get_id(),
                 'data'        => array(
                  array(
                'name'  => __('Affirm charge ID', 'woocommerce-gateway-affirm'),
                'value' => get_post_meta($order->get_id(), '_wc_gateway_affirm_charge_id', true),
                  ),
                 ),
                );
            }

            $done = 10 > count($orders);
        }

        return array(
        'data' => $data_to_export,
        'done' => $done,
        );
    }

    /**
     * Finds and erases order data by email address.
     *
     * @param string $email_address The user email address.
     * @param int    $page          Page.
     *
     * @return array An array of personal data in name value pairs
     */
    public function orderDataEraser( $email_address, $page )
    {
        $orders = $this->getOrders($email_address, (int) $page);

        $items_removed  = false;
        $items_retained = false;
        $messages       = array();

        foreach ( (array) $orders as $order ) {
            $order = wc_get_order($order->get_id());

            list( $removed, $retained, $msgs ) = $this->maybeHandleOrder($order);
            $items_removed  |= $removed;
            $items_retained |= $retained;
            $messages        = array_merge($messages, $msgs);
        }

        // Tell core if we have more orders to work on still
        $done = count($orders) < 10;

        return array(
        'items_removed'  => $items_removed,
        'items_retained' => $items_retained,
        'messages'       => $messages,
        'done'           => $done,
        );
    }

    /**
     * Handle eraser of data tied to Orders
     *
     * @param object $order order
     *
     * @return array
     */
    protected function maybeHandleOrder( $order )
    {
        $order_id  = $order->get_id();
        $charge_id = get_post_meta($order_id, '_wc_gateway_affirm_charge_id', true);

        if (empty($charge_id) ) {
            return array( false, false, array() );
        }

        delete_post_meta($order_id, '_wc_gateway_affirm_charge_id');

        return array( true, false, array( __('Affirm personal data erased.', 'woocommerce-gateway-affirm') ) );
    }
}

new WC_Affirm_Privacy();
