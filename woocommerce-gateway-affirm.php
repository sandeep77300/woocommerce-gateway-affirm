<?php
/**
 * Plugin Name: WooCommerce Affirm Gateway
 * Plugin URI: https://woocommerce.com/products/woocommerce-gateway-affirm/
 * Description: Receive payments using the Affirm payments provider.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Version: 1.1.8
 * WC tested up to: 3.7
 * WC requires at least: 2.6
 * Woo: 1474706:b271ae89b8b86c34020f58af2f4cbc81
 *
 * Copyright (c) 2019 WooCommerce
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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Required functions and classes
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}


/**
 * Constants
 */
define( 'WC_GATEWAY_AFFIRM_VERSION', '1.1.8' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), 'b271ae89b8b86c34020f58af2f4cbc81', '1474706' );

class WC_Affirm_Loader {

	/**
	 * The reference the *Singleton* instance of this class.
	 *
	 * @var WC_Affirm_Loader
	 */
	private static $instance;

	/**
	 * Whether or not we've already embedded the affirm script loader.
	 *
	 * @deprecated Since 1.1.0
	 *
	 * @var bool
	 */
	private $loader_has_been_embedded = false;

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
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	private function __clone() {
	}

	/**
	 * Private unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	private function __wakeup() {
	}

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * *Singleton* via the `new` operator from outside of this class.
	 */
	protected function __construct() {

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_action( 'plugins_loaded', array( $this, 'init_gateway' ), 0 );

		// Order actions.
		add_filter( 'woocommerce_order_actions', array( $this, 'possibly_add_capture_to_order_actions' ) );
		add_action( 'woocommerce_order_action_wc_affirm_capture_charge', array( $this, 'possibly_capture_charge' ) );
		add_action( 'woocommerce_order_status_pending_to_cancelled', array( $this, 'possibly_void_charge' ) );
		add_action( 'woocommerce_order_status_processing_to_cancelled', array( $this, 'possibly_refund_captured_charge' ) );
		add_action( 'woocommerce_order_status_completed_to_cancelled', array( $this, 'possibly_refund_captured_charge' ) );

		// Bulk capture.
		add_action( 'admin_footer-edit.php', array( $this, 'possibly_add_capture_charge_bulk_order_action' ) );
		add_action( 'load-edit.php', array( $this, 'possibly_capture_charge_bulk_order_action' ) );
		add_action( 'admin_notices', array( $this, 'custom_bulk_admin_notices' ) );

		// As low as.
		add_action( 'wp_head', array( $this, 'affirm_js_runtime_script' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'possibly_enqueue_scripts' ) );
		add_action( 'woocommerce_after_shop_loop_item',array( $this, 'woocommerce_after_shop_loop_item' ) );
		// Uses priority 15 to get the as-low-as to appear after the product price.
		add_action( 'woocommerce_single_product_summary', array( $this, 'woocommerce_single_product_summary' ), 15 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'woocommerce_cart_totals_after_order_total' ) );
        add_action( 'woocommerce_thankyou', array($this, 'wc_affirm_checkout_analytics') );

        // Checkout Button -  Changes Place Order Button to Continue with Affirm
        add_action( 'woocommerce_before_checkout_form', array($this, 'woocommerce_order_button_text' ) );
    }


	/**
	 * Initialize the gateway.
	 *
	 * @since 1.0.0
	 */
	function init_gateway() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once( dirname( __FILE__ ) . '/includes/class-wc-affirm-privacy.php' );
		require_once( plugin_basename( 'includes/class-wc-gateway-affirm.php' ) );
		load_plugin_textdomain( 'woocommerce-gateway-affirm', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
	}


	/**
	 * Adds plugin action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array Plugin action links.
	 */
	public function plugin_action_links( $links ) {

		$settings_url = add_query_arg(
			array(
				'page' => 'wc-settings',
				'tab' => 'checkout',
				'section' => 'wc_gateway_affirm',
			),
			admin_url( 'admin.php' )
		);

		$plugin_links = array(
			'<a href="' . $settings_url . '">' . __( 'Settings', 'woocommerce-gateway-affirm' ) . '</a>',
			'<a href="http://docs.woothemes.com/document/woocommerce-gateway-affirm/">' . __( 'Docs', 'woocommerce-gateway-affirm' ) . '</a>',
			'<a href="http://support.woothemes.com/">' . __( 'Support', 'woocommerce-gateway-affirm' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	/**
	 * Return an instance of the gateway for those loader functions that need it
	 * so we don't keep creating it over and over again.
	 *
	 * @since 1.0.0
	 */
	public function get_gateway() {
		if ( ! $this->gateway ) {
			$this->gateway = new WC_Gateway_Affirm();
		}
		return $this->gateway;
	}

	/**
	 * Helper method to check the payment method and authentication.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @since 1.0.7
	 * @return bool Returns true if the payment method is affirm and the auth flag is set. False otherwise.
	 */
	private function check_payment_method_and_auth_flag( $order ) {
		$payment_method = version_compare( WC_VERSION, '3.0', '<' ) ? $order->payment_method : $order->get_payment_method();

		return 'affirm' === $payment_method && $this->get_gateway()->isset_order_auth_only_flag( $order );
	}

	/**
	 * Possibly add the means to capture an order with Affirm to the order actions
	 * This was added here and not in WC_Gateway_Affirm because that class' construct
	 * is not called until after this filter is fired.
	 *
	 * @since 1.0.0
	 *
	 * @param array $actions Order actions.
	 *
	 * @return array Order actions.
	 */
	public function possibly_add_capture_to_order_actions( $actions ) {

		if ( ! isset( $_REQUEST['post'] ) ) {
			return $actions;
		}

		$order = wc_get_order( $_REQUEST['post'] );

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return $actions;
		}

		$actions['wc_affirm_capture_charge'] = __( 'Capture Charge', 'woocommerce-gateway-affirm' );

		return $actions;
	}


	/**
	 * Possibly capture the charge.  Used by woocommerce_order_action_wc_affirm_capture_charge hook / possibly_add_capture_to_order_actions
	 *
	 * @since 1.0.0
	 */
	public function possibly_capture_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		return $this->get_gateway()->capture_charge( $order );
	}

	/**
	 * Possibly void the charge.
	 *
	 * @since 1.0.1
	 *
	 * @param int|WC_Order $order Order ID or Order object
	 * @return bool Returns true when succeed
	 */
	public function possibly_void_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		return $this->get_gateway()->void_charge( $order );
	}

	/**
	 * Possibly refund captured charge of an order when it's cancelled.
	 *
	 * Hooked into order transition action from processing or completed to
	 * cancelled.
	 *
	 * @since 1.0.1
	 *
	 * @param int|WC_Order $order Order ID or Order object
	 * @return bool Returns true when succeed
	 */
	public function possibly_refund_captured_charge( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->check_payment_method_and_auth_flag( $order ) ) {
			return false;
		}

		$order_id = version_compare( WC_VERSION, '3.0', '<' ) ? $order->id : $order->get_id();

		return $this->get_gateway()->process_refund( $order_id, null, __( 'Order is cancelled', 'woocommerce-gateway-affirm' ) );
	}

	/**
	 * Possibly add capture charge bulk order action
	 * Surprisingly, WP core doesn't really make this easy.
	 * See http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
	 * and https://www.skyverge.com/blog/add-custom-bulk-action/
	 *
	 * @since 1.0.0
	 */
	public function possibly_add_capture_charge_bulk_order_action() {

		global $post_type, $post_status;

		if ( 'shop_order' === $post_type && 'trash' !== $post_status ) {
			?>
				<script type="text/javascript">
					jQuery( document ).ready( function ( $ ) {
						if ( 0 == $( 'select[name^=action] option[value=wc_capture_charge_affirm]' ).size() ) {
							$( 'select[name^=action]' ).append(
								$( '<option>' ).val( 'wc_capture_charge_affirm' ).text( '<?php esc_attr_e( 'Capture Charge (Affirm)', 'woocommerce-gateway-affirm' ); ?>' )
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
	 * @since 1.0.0
	 */
	public function possibly_capture_charge_bulk_order_action() {

		global $typenow;

		if ( 'shop_order' == $typenow ) {

			// Get the action (I'm not entirely happy with using this internal WP function, but this is the only way presently)
			// See https://core.trac.wordpress.org/ticket/16031
			$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
			$action        = $wp_list_table->current_action();

			// Bail if not processing a capture
			if ( 'wc_capture_charge_affirm' !== $action ) {
				return;
			}

			// Security check
			check_admin_referer( 'bulk-posts' );

			// Make sure order IDs are submitted
			if ( isset( $_REQUEST['post'] ) ) {
				$order_ids = array_map( 'absint', $_REQUEST['post'] );
			}

			$sendback = remove_query_arg( array( 'captured', 'untrashed', 'deleted', 'ids' ), wp_get_referer() );
			if ( ! $sendback ) {
				$sendback = admin_url( "edit.php?post_type=$post_type" );
			}

			$capture_count = 0;

			if ( ! empty( $order_ids ) ) {
				// Give ourselves an unlimited timeout if possible
				set_time_limit( 0 );

				foreach ( $order_ids as $order_id ) {

					$order = wc_get_order( $order_id );
					$capture_successful = $this->possibly_capture_charge( $order );

					if ( $capture_successful ) {
						$capture_count++;
					}
				}
			}

			$sendback = add_query_arg( array( 'captured' => $capture_count ), $sendback );
			$sendback = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit', 'post_view' ), $sendback );
			wp_redirect( $sendback );
			exit();

		} // End if().

	}


	/**
	 * Tell the user how much the capture bulk order action did
	 *
	 * @since 1.0.0
	 */
	function custom_bulk_admin_notices() {
		global $post_type, $pagenow;

		if ( 'edit.php' === $pagenow && 'shop_order' === $post_type && isset( $_REQUEST['captured'] ) ) {

			$capture_count = (int) $_REQUEST['captured'];

			if ( 0 >= $capture_count ) {
				$message = __( 'Affirm: No charges were able to be captured.', 'woocommerce-gateway-affirm' );
			} else {
				$message = sprintf(
					/* translators: 1: number of charge(s) captured */
					_n( 'Affirm: %s charge was captured.', 'Affirm: %s charges were captured.', $_REQUEST['captured'], 'woocommerce-gateway-affirm' ),
					number_format_i18n( $_REQUEST['captured'] )
				);
			}

			?>
				<div class='updated'>
					<p>
						<?php echo esc_html( $message ); ?>
					</p>
				</div>
			<?php
		}
	}


	/**
	 * Add the gateway to WooCommerce
	 * @since 1.0.0
	 */
	public function add_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Affirm';
		return $methods;
	}


	/**
	 * Loads front side script when viewing product and cart pages.
	 *
	 * @since 1.0.0
	 * @version 1.1.0
	 */
	function possibly_enqueue_scripts() {
		if ( ! is_product() && ! is_cart() ) {
			return;
		}

		if ( ! $this->get_gateway()->is_valid_for_use() ) {
			return;
		}

		if ( ! $this->get_gateway()->enabled ) {
			return;
		}

		// See https://docs.affirm.com/Partners/Email_Service_Providers/Monthly_Payment_Messaging_API#Collect_the_loan_details
		// for maximum and minimum amounts.
		$options = array(
			'minimum' => 5000,    // $50 in cents.
			'maximum' => 3000000, // $30000 in cents.
		);

		$options = apply_filters( 'wc_gateway_affirm_as_low_as_data', $options );

		wp_register_script( 'affirm_as_low_as', plugins_url( 'assets/js/affirm-as-low-as.js', __FILE__ ), array( 'jquery' ) );
		wp_localize_script( 'affirm_as_low_as', 'affirmOptions', $options );
		wp_enqueue_script( 'affirm_as_low_as' );
	}

	/**
	 * Add Affirm's monthly payment messaging to single product page.
	 *
	 * @since 1.0.0
	 * @version 1.1.0
	 */
	public function woocommerce_single_product_summary() {
        if( $this->get_gateway()->productALA ) {
            global $product;

            // Only do this for simple, variable, and composite products. This
            // gateway does not (yet) support subscriptions.
            $supported_types = apply_filters( 'wc_gateway_affirm_supported_product_types', array( 'simple', 'variable', 'grouped', 'composite' ) );

            if ( !$product->is_type( $supported_types ) ) {
                return;
            }
            $price = $product->get_price() ? $product->get_price() : 0;

            // For intial messaging in grouped product, use the most low-priced one.
            if ( $product->is_type( 'grouped' ) ) {
                $price = $this->get_grouped_product_price( $product );
            }


            $this->render_affirm_monthly_payment_messaging( floatval( $price * 100 ), 'product' );
        }
	}

	/**
	 * Get grouped product price by returning the most low-priced child.
	 *
	 * @since 1.1.0
	 * @version 1.1.0
	 *
	 * @param WC_Product $product Product instance.
	 *
	 * @return float Price.
	 */
	protected function get_grouped_product_price( $product ) {
		$children = array_filter( array_map( 'wc_get_product', $product->get_children() ), array( $this, 'filter_visible_group_child' ) );
		uasort( $children, array( $this, 'order_grouped_product_by_price' ) );

		return reset( $children )->get_price();
	}

	/**
	 * Filter visible child in grouped product.
	 *
	 * @since 1.1.0
	 * @version 1.1.0
	 *
	 * @param WC_Product $product Child product of grouped product.
	 *
	 * @return bool True if it's visible group child.
	 */
	public function filter_visible_group_child( $product ) {
		return $product && is_a( $product, 'WC_Product' ) && ( 'publish' === $product->get_status() || current_user_can( 'edit_product', $product->get_id() ) );
	}

	/**
	 * Sort callback to sort grouped product child based on price, from low to
	 * high.
	 *
	 * @since 1.1.0
	 * @version 1.1.0
	 *
	 * @param WC_Product object $a Product A.
	 * @param WC_Product object $b Product B.
	 *
	 * @return int
	 */
	public function order_grouped_product_by_price( $a, $b ) {
		if ( $a->get_price() === $b->get_price() ) {
			return 0;
		}
		return ( $a->get_price() < $b->get_price() ) ? -1 : 1;
	}

	/**
	 * Add Affirm's monthly payment messaging below the cart total.
	 */
	public function woocommerce_cart_totals_after_order_total() {
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return;
		}

		?>
		<tr>
			<th></th>
			<td>
			<?php if( $this->get_gateway()->cartALA ) { $this->render_affirm_monthly_payment_messaging( floatval( WC()->cart->total ) * 100 , 'cart' );} ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render Affirm monthly payment messaging.
	 *
	 * @since 1.1.0
	 *
	 * @param float $amount Total amount to be passed to Affirm.
	 */
	protected function render_affirm_monthly_payment_messaging( $amount, $affirmPageType ) {
		$attrs = array(
			'amount'         => $amount,
			'promo-id'       => $this->get_gateway()->promo_id,
			'affirm-color'   => $this->get_gateway()->affirm_color,
			'learnmore-show' => $this->get_gateway()->show_learnmore ? 'true' : 'false',
            'page-type'      => $affirmPageType
		);

		$data_attrs = '';
		foreach ( $attrs as $attr => $val ) {
			if ( ! $val ) {
				continue;
			}
			$data_attrs .= sprintf( ' data-%s="%s"', $attr, esc_attr( $val ) );
		}

		$affirm_message = '<p id="learn-more" class="affirm-as-low-as"' . $data_attrs . '></p>';

		if(($amount > $this->get_gateway()->min*100) && ($amount < $this->get_gateway()->max*100) && ('cart' === $attrs['page-type'])) {
		    echo $affirm_message;
        } elseif ('product' == $attrs['page-type'] || 'category' == $attrs['page-type']) {
		    echo $affirm_message;
        }
	}

	/**
	 * Render script tag for Affirm JS runtime in the head.
	 *
	 * @since 1.1.0
	 */
	public function affirm_js_runtime_script() {
		if ( ! $this->get_gateway()->is_valid_for_use() ) {
			return;
		}

		if ( ! $this->get_gateway()->enabled ) {
			return;
		}

		$public_key = $this->get_gateway()->public_key;
		$testmode   = $this->get_gateway()->testmode;

		if ( $testmode ) {
			$script_url = 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js';
		} else {
			$script_url = 'https://cdn1.affirm.com/js/v2/affirm.js';
		}

		?>
		<script>
			if ( 'undefined' === typeof _affirm_config ) {
				var _affirm_config = {
					public_api_key: "<?php echo esc_js( $public_key ); ?>",
					script: "<?php echo esc_js( $script_url ); ?>"
				};
				(function(l,g,m,e,a,f,b){var d,c=l[m]||{},h=document.createElement(f),n=document.getElementsByTagName(f)[0],k=function(a,b,c){return function(){a[b]._.push([c,arguments])}};c[e]=k(c,e,"set");d=c[e];c[a]={};c[a]._=[];d._=[];c[a][b]=k(c,a,b);a=0;for(b="set add save post open empty reset on off trigger ready setProduct".split(" ");a<b.length;a++)d[b[a]]=k(c,e,b[a]);a=0;for(b=["get","token","url","items"];a<b.length;a++)d[b[a]]=function(){};h.async=!0;h.src=g[f];n.parentNode.insertBefore(h,n);delete g[f];d(g);l[m]=c})(window,_affirm_config,"affirm","checkout","ui","script","ready");
			}
		</script>
		<?php

	}

	/**
	 * Embed Affirm's JavaScript loader.
	 *
	 * @since 1.0.0
	 *
	 * @deprecated Since 1.1.0
	 */
	public function embed_script_loader() {
		_deprecated_function( __METHOD__, '1.1.0', '' );

		if ( $this->loader_has_been_embedded ) {
			return;
		}

		$this->affirm_js_runtime_script();

		$this->loader_has_been_embedded = true;
	}

    /**
     * Add Tracking Code to the Thank You Page
     */
	public function wc_affirm_checkout_analytics( $order_id ) {
        if ( ! $this->get_gateway()->enhanced_analytics ) {
            return;
        }
        $order = new WC_Order($order_id);
        $total = floor(100 * $order->get_total());
        $order_id = trim(str_replace('#', '', $order->get_order_number()));
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

    public function woocommerce_after_shop_loop_item() {

        if ( $this->get_gateway()->categoryALA ) {
            global $product;

            // Only do this for simple, variable, and composite products. This
            // gateway does not (yet) support subscriptions.
            $supported_types = apply_filters( 'wc_gateway_affirm_supported_product_types', array( 'simple', 'variable', 'grouped', 'composite' ) );

            if ( !$product->is_type( $supported_types ) ) {
                return;
            }
            $price = $product->get_price() ? $product->get_price() : 0;

            // For intial messaging in grouped product, use the most low-priced one.
            if ( $product->is_type( 'grouped' ) ) {
                $price = $this->get_grouped_product_price( $product );
            }

            $this->render_affirm_monthly_payment_messaging( floatval( $price * 100 ), 'category' );
        }
    }

    public function woocommerce_order_button_text(){
        wp_register_script( 'affirm_checkout_button', plugins_url( 'assets/js/affirm-checkout-button.js', __FILE__ ), array( 'jquery' ) );
        wp_enqueue_script( 'affirm_checkout_button' );
	}
}

$GLOBALS['wc_affirm_loader'] = WC_Affirm_Loader::get_instance();

