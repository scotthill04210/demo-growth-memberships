<?php
/**
 * Shared helpers.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether demo behavior is enabled for the current site.
 *
 * @return bool
 */
function dgm_is_demo_mode() {
	$mode = get_option( 'dgm_demo_mode', '0' );

	return true === $mode || 1 === $mode || '1' === $mode;
}

/**
 * Demo password saved for the current site.
 *
 * @return string
 */
function dgm_demo_password() {
	$password = get_option( 'dgm_demo_password', '' );

	if ( is_string( $password ) ) {
		return $password;
	}

	if ( is_int( $password ) || is_float( $password ) ) {
		return (string) $password;
	}

	return '';
}

/**
 * Notice shown where a customer could otherwise pay or place an order.
 *
 * @return string
 */
function dgm_transaction_message() {
	return __( 'This is a demo site. No real transactions are processed.', 'demo-growth-memberships' );
}

/**
 * Banner copy for the front end and admin.
 *
 * @return string
 */
function dgm_banner_message() {
	return __( 'This is a demo site. No emails are sent and no orders are processed.', 'demo-growth-memberships' );
}

/**
 * Load the plugin text domain.
 */
function dgm_load_textdomain() {
	load_plugin_textdomain(
		'demo-growth-memberships',
		false,
		dirname( plugin_basename( DGM_PLUGIN_FILE ) ) . '/languages'
	);
}

/**
 * Encode a value for safe placement inside an inline script.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function dgm_json_for_script( $value ) {
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	if ( defined( 'JSON_UNESCAPED_UNICODE' ) ) {
		$flags = $flags | JSON_UNESCAPED_UNICODE;
	}

	$json = wp_json_encode( $value, $flags );
	if ( ! is_string( $json ) ) {
		return '""';
	}

	return $json;
}
