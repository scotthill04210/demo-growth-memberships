<?php
/**
 * Keep signed-out demo visitors out unless they arrived from Growth Memberships.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the entry gate.
 */
function dgm_register_gate_hooks() {
	add_action( 'init', 'dgm_enforce_entry_gate', 1 );
}

/**
 * Whether a hostname is growthmemberships.com or one of its subdomains.
 *
 * @param string $host Hostname.
 * @return bool
 */
function dgm_host_is_growth_memberships( $host ) {
	$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
	if ( '' === $host ) {
		return false;
	}

	if ( 'growthmemberships.com' === $host ) {
		return true;
	}

	$suffix = '.growthmemberships.com';
	$length = strlen( $suffix );

	return strlen( $host ) > $length && substr( $host, - $length ) === $suffix;
}

/**
 * Hostname from the browser Referer header.
 *
 * The raw header is used on purpose. WordPress rejects off-site referrers.
 *
 * @return string
 */
function dgm_request_referrer_host() {
	if ( empty( $_SERVER['HTTP_REFERER'] ) || ! is_string( $_SERVER['HTTP_REFERER'] ) ) {
		return '';
	}

	$host = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );

	return is_string( $host ) ? $host : '';
}

/**
 * Cookie path for this site, so a subdirectory site does not unlock the network.
 *
 * @return string
 */
function dgm_entry_cookie_path() {
	$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		return '/';
	}

	return $path;
}

/**
 * Remember that this browser arrived from Growth Memberships.
 */
function dgm_mark_valid_entry() {
	$_COOKIE['dgm_entry'] = '1';

	if ( headers_sent() ) {
		return;
	}

	setcookie(
		'dgm_entry',
		'1',
		array(
			'expires'  => 0,
			'path'     => dgm_entry_cookie_path(),
			'secure'   => function_exists( 'is_ssl' ) ? is_ssl() : false,
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * Whether this browser already passed the entry check.
 *
 * @return bool
 */
function dgm_has_valid_entry() {
	return isset( $_COOKIE['dgm_entry'] ) && is_string( $_COOKIE['dgm_entry'] ) && '1' === $_COOKIE['dgm_entry'];
}

/**
 * Stop the request with a plain unauthorized page.
 */
function dgm_exit_unauthorized() {
	if ( ! headers_sent() ) {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	$title = esc_html__( 'Unauthorized', 'demo-growth-memberships' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title></head>';
	echo '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:sans-serif;background:#f0f0f1;color:#1d2327;">';
	echo '<p style="font-size:24px;margin:0;">' . $title . '</p>';
	echo '</body></html>';
	exit;
}

/**
 * Show an unauthorized screen to signed-out visitors who did not come from Growth Memberships.
 */
function dgm_enforce_entry_gate() {
	if ( ! dgm_is_demo_mode() || is_user_logged_in() ) {
		return;
	}

	if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	if ( dgm_host_is_growth_memberships( dgm_request_referrer_host() ) ) {
		dgm_mark_valid_entry();
		return;
	}

	if ( dgm_has_valid_entry() ) {
		return;
	}

	dgm_exit_unauthorized();
}
