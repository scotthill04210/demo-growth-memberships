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
 * Whether the nightly restore is enabled for the current site.
 *
 * @return bool
 */
function dgm_nightly_reset_enabled() {
	$value = get_option( 'dgm_nightly_reset', '0' );

	return true === $value || 1 === $value || '1' === $value;
}

/**
 * Whether sandbox limits are enabled for the current site.
 *
 * Sandbox mode defaults to on. Limits also require demo mode, so other
 * sites in the network keep their normal capabilities.
 *
 * @return bool
 */
function dgm_is_sandbox_mode() {
	$value = get_option( 'dgm_sandbox_mode', '1' );

	return false !== $value && 0 !== $value && '0' !== $value && '' !== $value;
}

/**
 * Whether sandbox limits should apply on this request.
 *
 * @return bool
 */
function dgm_sandbox_is_active() {
	return dgm_is_demo_mode() && dgm_is_sandbox_mode();
}

/**
 * Whether this account is an administrator or a network super admin.
 *
 * The democlient login is never treated as privileged. This does not call
 * is_super_admin(), which checks capabilities and would recurse through user_has_cap.
 *
 * @param mixed $user User object, user ID, or null for the current user.
 * @return bool
 */
function dgm_user_is_privileged( $user = null ) {
	if ( is_numeric( $user ) ) {
		$user_id = (int) $user;
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
	}
	if ( ! $user instanceof WP_User ) {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	}
	if ( ! $user instanceof WP_User || ! $user->exists() || 'democlient' === $user->user_login ) {
		return false;
	}

	if ( in_array( 'administrator', (array) $user->roles, true ) ) {
		return true;
	}

	if ( is_multisite() && function_exists( 'get_super_admins' ) ) {
		$supers = get_super_admins();
		return is_array( $supers ) && in_array( $user->user_login, $supers, true );
	}

	return false;
}

/**
 * Whether a restore must leave this account untouched.
 *
 * Administrators and super admins keep their current password, role, and profile.
 *
 * @param int    $user_id User ID.
 * @param string $login   Login from a snapshot row, when available.
 * @return bool
 */
function dgm_restore_preserves_user( $user_id, $login = '' ) {
	if ( (int) $user_id > 0 && dgm_user_is_privileged( (int) $user_id ) ) {
		return true;
	}

	if ( ! is_string( $login ) || '' === $login || 'democlient' === $login ) {
		return false;
	}

	if ( is_multisite() && function_exists( 'get_super_admins' ) ) {
		$supers = get_super_admins();
		if ( is_array( $supers ) && in_array( $login, $supers, true ) ) {
			return true;
		}
	}

	$by_login = function_exists( 'get_user_by' ) ? get_user_by( 'login', $login ) : false;

	return $by_login instanceof WP_User && dgm_user_is_privileged( $by_login );
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
