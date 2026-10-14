<?php
/**
 * Block orders and payments.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register order and payment blocks.
 */
function dgm_register_order_hooks() {
	add_filter( 'woocommerce_available_payment_gateways', 'dgm_clear_payment_gateways', PHP_INT_MAX, 1 );
	add_action( 'woocommerce_after_checkout_validation', 'dgm_block_checkout_validation', PHP_INT_MAX, 2 );
	add_action( 'woocommerce_checkout_create_order', 'dgm_abort_checkout_order', 1, 2 );
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'dgm_abort_store_api_checkout', 1, 2 );
	add_filter( 'woocommerce_rest_pre_insert_shop_order_object', 'dgm_block_rest_order_insert', PHP_INT_MAX, 3 );
	add_filter( 'rest_pre_dispatch', 'dgm_block_order_rest_dispatch', PHP_INT_MAX, 3 );
	add_action( 'wp_loaded', 'dgm_block_order_pay_request', 1 );
	add_action( 'woocommerce_before_cart', 'dgm_print_transaction_notice' );
	add_action( 'woocommerce_before_checkout_form', 'dgm_print_transaction_notice' );
	add_action( 'woocommerce_pay_order_before_submit', 'dgm_print_transaction_notice' );
	add_filter( 'render_block', 'dgm_prepend_checkout_block_notice', 10, 2 );
	add_action( 'all_admin_notices', 'dgm_transaction_admin_notice' );
	add_action( 'gform_loaded', 'dgm_register_gf_payment_blocks', 99 );
	add_action( 'plugins_loaded', 'dgm_register_gf_payment_blocks', 99 );
}

/**
 * No payment gateways are offered at checkout.
 *
 * @param mixed $gateways Gateway list.
 * @return array
 */
function dgm_clear_payment_gateways( $gateways ) {
	if ( ! dgm_is_demo_mode() ) {
		return $gateways;
	}

	return array();
}

/**
 * Stop classic checkout before an order is created.
 *
 * @param mixed $data   Posted checkout data.
 * @param mixed $errors Validation errors.
 */
function dgm_block_checkout_validation( $data = null, $errors = null ) {
	unset( $data );

	if ( ! dgm_is_demo_mode() ) {
		return;
	}

	if ( is_object( $errors ) && is_callable( array( $errors, 'add' ) ) ) {
		$errors->add( 'dgm_demo', dgm_transaction_message() );
	}
}

/**
 * Abort order creation if checkout validation was bypassed.
 *
 * WooCommerce catches Exception inside WC_Checkout::process_checkout().
 *
 * @param mixed $order Order being created.
 * @param mixed $data  Posted checkout data.
 * @throws Exception When demo mode blocks the order.
 */
function dgm_abort_checkout_order( $order = null, $data = null ) {
	unset( $order, $data );

	if ( ! dgm_is_demo_mode() ) {
		return;
	}

	throw new Exception( dgm_transaction_message() );
}

/**
 * Stop the WooCommerce Store API (block checkout) from placing an order.
 *
 * @param mixed $order   Order object.
 * @param mixed $request REST request.
 * @throws Exception When demo mode blocks the order.
 */
function dgm_abort_store_api_checkout( $order = null, $request = null ) {
	unset( $order, $request );

	if ( ! dgm_is_demo_mode() ) {
		return;
	}

	$message = dgm_transaction_message();
	if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'dgm_demo_no_orders', $message, 403 );
	}

	throw new Exception( $message );
}

/**
 * Reject order creation through the WooCommerce REST controller.
 *
 * @param mixed $order    Order object.
 * @param mixed $request  REST request.
 * @param bool  $creating Whether this insert creates an order.
 * @return mixed
 */
function dgm_block_rest_order_insert( $order = null, $request = null, $creating = false ) {
	unset( $request );

	if ( ! dgm_is_demo_mode() ) {
		return $order;
	}

	if ( $creating ) {
		return new WP_Error(
			'dgm_demo_no_orders',
			dgm_transaction_message(),
			array( 'status' => 403 )
		);
	}

	return $order;
}

/**
 * Whether this REST request would create or place an order.
 *
 * @param mixed $request REST request.
 * @return bool
 */
function dgm_rest_request_creates_order( $request ) {
	if ( ! $request instanceof WP_REST_Request ) {
		return false;
	}

	$method = strtoupper( (string) $request->get_method() );
	if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		return false;
	}

	$route = $request->get_route();
	if ( ! is_string( $route ) || '' === $route ) {
		return false;
	}

	if ( 'POST' === $method && preg_match( '#^/wc/v\d+/orders/?$#', $route ) ) {
		return true;
	}

	if ( preg_match( '#^/wc/v\d+/orders/batch/?$#', $route ) ) {
		return true;
	}

	if ( preg_match( '#^/wc/store(?:/v\d+)?/checkout(?:/|$)#', $route ) ) {
		return true;
	}

	return false;
}

/**
 * Block order creation and block checkout in the REST API.
 *
 * @param mixed $result  Short-circuit result.
 * @param mixed $server  REST server.
 * @param mixed $request REST request.
 * @return mixed
 */
function dgm_block_order_rest_dispatch( $result = null, $server = null, $request = null ) {
	unset( $server );

	if ( ! dgm_is_demo_mode() || ! dgm_rest_request_creates_order( $request ) ) {
		return $result;
	}

	return new WP_Error(
		'dgm_demo_no_orders',
		dgm_transaction_message(),
		array( 'status' => 403 )
	);
}

/**
 * Stop the pay-for-order form before a gateway can charge.
 *
 * WooCommerce handles this POST on wp_loaded when woocommerce_pay is set.
 */
function dgm_block_order_pay_request() {
	if ( ! dgm_is_demo_mode() || ! isset( $_POST['woocommerce_pay'] ) ) {
		return;
	}

	unset( $_POST['woocommerce_pay'] );

	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( dgm_transaction_message(), 'error' );
	}
}

/**
 * Front-end transaction notice markup. Printed at most once per request.
 *
 * @return string
 */
function dgm_transaction_notice_markup() {
	static $shown = false;

	if ( $shown ) {
		return '';
	}

	$shown = true;

	return '<div class="woocommerce-info dgm-transaction-notice" role="status">' . esc_html( dgm_transaction_message() ) . '</div>';
}

/**
 * Print the transaction notice on classic cart, checkout, and pay screens.
 */
function dgm_print_transaction_notice() {
	if ( ! dgm_is_demo_mode() ) {
		return;
	}

	$markup = dgm_transaction_notice_markup();
	if ( '' === $markup ) {
		return;
	}

	echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in dgm_transaction_notice_markup().
}

/**
 * Show the transaction notice above Cart and Checkout blocks.
 *
 * @param mixed $content Block HTML.
 * @param mixed $block   Block data.
 * @return mixed
 */
function dgm_prepend_checkout_block_notice( $content = '', $block = array() ) {
	if ( ! dgm_is_demo_mode() ) {
		return $content;
	}

	if ( ! is_array( $block ) || empty( $block['blockName'] ) || ! is_string( $block['blockName'] ) ) {
		return $content;
	}

	if ( 'woocommerce/checkout' !== $block['blockName'] && 'woocommerce/cart' !== $block['blockName'] ) {
		return $content;
	}

	$notice = dgm_transaction_notice_markup();
	if ( '' === $notice ) {
		return $content;
	}

	if ( ! is_string( $content ) ) {
		$content = '';
	}

	return $notice . $content;
}

/**
 * Whether the current admin screen is about orders or taking payment.
 *
 * @return bool
 */
function dgm_is_transaction_admin_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();
	if ( ! is_object( $screen ) ) {
		return false;
	}

	$post_type = isset( $screen->post_type ) && is_string( $screen->post_type ) ? $screen->post_type : '';
	$screen_id = isset( $screen->id ) && is_string( $screen->id ) ? $screen->id : '';

	if ( 'shop_order' === $post_type || false !== strpos( $screen_id, 'wc-orders' ) ) {
		return true;
	}

	if ( 'woocommerce_page_wc-settings' === $screen_id ) {
		$tab = '';
		if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		return 'checkout' === $tab;
	}

	if ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) {
		$page    = sanitize_key( wp_unslash( $_GET['page'] ) );
		$needles = array( 'paypal', 'stripe', 'square', 'authorize', 'mollie', 'payment' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $page, $needle ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Admin notice on order and payment screens.
 */
function dgm_transaction_admin_notice() {
	if ( ! dgm_is_demo_mode() || ! dgm_is_transaction_admin_screen() ) {
		return;
	}

	echo '<div class="notice notice-info"><p>' . esc_html( dgm_transaction_message() ) . '</p></div>';
}

/**
 * Disable Gravity Forms payment feeds without removing them.
 *
 * Feeds are left in place so turning demo mode off restores processing.
 */
function dgm_register_gf_payment_blocks() {
	static $done = false;

	if ( $done || ! dgm_is_demo_mode() ) {
		return;
	}

	if ( ! class_exists( 'GFAddOn' ) || ! is_callable( array( 'GFAddOn', 'get_registered_addons' ) ) ) {
		return;
	}

	$done   = true;
	$addons = GFAddOn::get_registered_addons();
	if ( ! is_array( $addons ) ) {
		return;
	}

	foreach ( $addons as $class_name ) {
		if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
			continue;
		}

		if ( ! is_subclass_of( $class_name, 'GFPaymentAddOn' ) ) {
			continue;
		}

		if ( ! is_callable( array( $class_name, 'get_instance' ) ) ) {
			continue;
		}

		$addon = $class_name::get_instance();
		if ( ! is_object( $addon ) || ! is_callable( array( $addon, 'get_slug' ) ) ) {
			continue;
		}

		$slug = $addon->get_slug();
		if ( ! is_string( $slug ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			continue;
		}

		add_filter( 'gform_' . $slug . '_pre_process_feeds', 'dgm_clear_payment_feeds', PHP_INT_MAX, 1 );
	}
}

/**
 * Replace a payment feed list with an empty list.
 *
 * @param mixed $feeds Feed list.
 * @return array
 */
function dgm_clear_payment_feeds( $feeds ) {
	unset( $feeds );
	return array();
}
