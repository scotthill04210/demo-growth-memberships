<?php
/**
 * Per-site maintenance during a restore.
 *
 * This does not use WordPress's .maintenance file, which would take down the network.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the maintenance gate and the fatal-error cleanup.
 */
function dgm_register_maintenance_hooks() {
	add_action( 'template_redirect', 'dgm_maintenance_block', 0 );
	add_action( 'admin_init', 'dgm_maintenance_block', 0 );
	add_action( 'init', 'dgm_maintenance_block_ajax', 0 );
	add_filter( 'rest_pre_dispatch', 'dgm_maintenance_block_rest', 0, 3 );
	register_shutdown_function( 'dgm_restore_shutdown_handler' );
}

/**
 * Flag path for the current or given site.
 *
 * @param int $blog_id Site ID. Zero uses the current site.
 * @return string
 */
function dgm_maintenance_flag_path( $blog_id = 0 ) {
	if ( $blog_id <= 0 ) {
		$blog_id = (int) get_current_blog_id();
	}

	return dgm_snapshot_dir( $blog_id ) . '/maintenance.flag';
}

/**
 * Mark this site as resetting.
 *
 * @param int $blog_id Site ID.
 */
function dgm_maintenance_on( $blog_id ) {
	dgm_ensure_snapshot_dir( $blog_id );
	file_put_contents( dgm_maintenance_flag_path( $blog_id ), (string) time(), LOCK_EX );
}

/**
 * Remove the maintenance flag.
 *
 * @param int $blog_id Site ID.
 */
function dgm_maintenance_off( $blog_id ) {
	$path = dgm_maintenance_flag_path( $blog_id );
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

/**
 * Whether visitors should see the resetting page.
 *
 * A flag older than 30 minutes is ignored and removed.
 *
 * @param int $blog_id Site ID. Zero uses the current site.
 * @return bool
 */
function dgm_maintenance_is_active( $blog_id = 0 ) {
	$path = dgm_maintenance_flag_path( $blog_id );
	if ( ! is_file( $path ) ) {
		return false;
	}

	$started = (int) trim( (string) file_get_contents( $path ) );
	if ( $started <= 0 ) {
		$started = (int) filemtime( $path );
	}

	if ( ( time() - $started ) > 30 * MINUTE_IN_SECONDS ) {
		unlink( $path );
		return false;
	}

	return true;
}

/**
 * wp-login.php stays available so a super admin can sign in.
 *
 * @return bool
 */
function dgm_is_login_screen() {
	$scripts = array();
	foreach ( array( 'SCRIPT_NAME', 'PHP_SELF' ) as $key ) {
		if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
			$scripts[] = basename( wp_unslash( $_SERVER[ $key ] ) );
		}
	}

	global $pagenow;
	if ( isset( $pagenow ) && is_string( $pagenow ) ) {
		$scripts[] = $pagenow;
	}

	return in_array( 'wp-login.php', $scripts, true );
}

/**
 * Block front-end and admin requests while this site is resetting.
 */
function dgm_maintenance_block() {
	if ( ! dgm_maintenance_is_active() || dgm_is_login_screen() || dgm_user_is_privileged() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	dgm_maintenance_exit();
}

/**
 * Block admin-ajax.php.
 */
function dgm_maintenance_block_ajax() {
	if ( ! wp_doing_ajax() ) {
		return;
	}

	dgm_maintenance_block();
}

/**
 * Block REST requests with HTTP 503.
 *
 * @param mixed            $result  Response to serve.
 * @param WP_REST_Server   $server  Server instance.
 * @param WP_REST_Request  $request Request.
 * @return mixed
 */
function dgm_maintenance_block_rest( $result = null, $server = null, $request = null ) {
	unset( $server, $request );
	if ( ! dgm_maintenance_is_active() || dgm_user_is_privileged() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return $result;
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		dgm_maintenance_exit();
	}

	$response = new WP_REST_Response(
		array(
			'message' => __( 'Demo is resetting, back in a few minutes', 'demo-growth-memberships' ),
		),
		503
	);
	$response->header( 'Retry-After', '120' );

	return $response;
}

/**
 * Send the plain 503 page and stop.
 */
function dgm_maintenance_exit() {
	if ( ! headers_sent() ) {
		status_header( 503 );
		header( 'Retry-After: 120' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		nocache_headers();
	}

	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Demo is resetting', 'demo-growth-memberships' ) . '</title></head><body><p>' . esc_html__( 'Demo is resetting, back in a few minutes', 'demo-growth-memberships' ) . '</p></body></html>';
	exit;
}

/**
 * Clear a stuck flag after a fatal error and roll back once.
 */
function dgm_restore_shutdown_handler() {
	$state = isset( $GLOBALS['dgm_restore_state'] ) && is_array( $GLOBALS['dgm_restore_state'] ) ? $GLOBALS['dgm_restore_state'] : null;
	if ( ! $state || empty( $state['open'] ) ) {
		return;
	}

	$blog_id = isset( $state['blog_id'] ) ? (int) $state['blog_id'] : 0;
	$error   = error_get_last();
	$fatal   = is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
	$GLOBALS['dgm_restore_state']['open'] = false;

	if ( $fatal && empty( $state['rollback'] ) && empty( $state['shutdown_ran'] ) && $blog_id > 0 && function_exists( 'dgm_restore_body' ) ) {
		$GLOBALS['dgm_restore_state']['shutdown_ran'] = true;
		dgm_log_line( $blog_id, 'Fatal error during restore. Rolling back from the failsafe copy.' );
		dgm_restore_body( $blog_id, true );
	}

	if ( $blog_id > 0 ) {
		dgm_maintenance_off( $blog_id );
		dgm_release_restore_lock( $blog_id );
	}
}
