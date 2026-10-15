<?php
/**
 * Dismissible demo banner for the front end and wp-admin.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register banner hooks.
 */
function dgm_register_banner_hooks() {
	add_action( 'init', 'dgm_handle_banner_dismiss' );
	add_action( 'wp_ajax_dgm_dismiss_banner', 'dgm_ajax_dismiss_banner' );
	add_filter( 'body_class', 'dgm_banner_body_class' );
	add_filter( 'login_body_class', 'dgm_banner_body_class' );
	add_action( 'wp_head', 'dgm_print_banner_css' );
	add_action( 'login_head', 'dgm_print_banner_css' );
	add_action( 'wp_body_open', 'dgm_render_frontend_banner' );
	add_action( 'wp_footer', 'dgm_render_frontend_banner' );
	add_action( 'login_footer', 'dgm_render_frontend_banner', 5 );
	add_action( 'all_admin_notices', 'dgm_render_admin_banner' );
	add_action( 'wp_footer', 'dgm_print_banner_script', 20 );
	add_action( 'admin_footer', 'dgm_print_banner_script', 20 );
	add_action( 'login_footer', 'dgm_print_banner_script', 20 );
}

/**
 * Cookie lifetime for a dismissed banner.
 *
 * @return int
 */
function dgm_banner_dismiss_ttl() {
	if ( defined( 'DAY_IN_SECONDS' ) ) {
		return 30 * (int) DAY_IN_SECONDS;
	}

	return 2592000;
}

/**
 * Whether the current visitor has dismissed the banner.
 *
 * @return bool
 */
function dgm_banner_is_dismissed() {
	if ( is_user_logged_in() ) {
		$meta = get_user_meta( get_current_user_id(), 'dgm_banner_dismissed', true );
		if ( '1' === (string) $meta ) {
			return true;
		}
	}

	return isset( $_COOKIE['dgm_banner_dismissed'] ) && is_string( $_COOKIE['dgm_banner_dismissed'] ) && '1' === $_COOKIE['dgm_banner_dismissed'];
}

/**
 * Whether the banner should render on this request.
 *
 * @return bool
 */
function dgm_should_show_banner() {
	if ( ! dgm_is_demo_mode() || dgm_banner_is_dismissed() ) {
		return false;
	}

	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return false;
	}

	if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
		return false;
	}

	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return false;
	}

	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return false;
	}

	return true;
}

/**
 * Remember a dismissal for logged-in users and for this browser.
 */
function dgm_remember_banner_dismissal() {
	if ( is_user_logged_in() ) {
		update_user_meta( get_current_user_id(), 'dgm_banner_dismissed', '1' );
	}

	if ( headers_sent() ) {
		return;
	}

	setcookie(
		'dgm_banner_dismissed',
		'1',
		array(
			'expires'  => time() + dgm_banner_dismiss_ttl(),
			'path'     => '/',
			'secure'   => function_exists( 'is_ssl' ) ? is_ssl() : false,
			'httponly' => false,
			'samesite' => 'Lax',
		)
	);

	$_COOKIE['dgm_banner_dismissed'] = '1';
}

/**
 * Persist dismissal from the banner link, then return to the same screen.
 */
function dgm_handle_banner_dismiss() {
	if ( ! dgm_is_demo_mode() ) {
		return;
	}

	if ( ! isset( $_GET['dgm_dismiss_banner'] ) || ! is_string( $_GET['dgm_dismiss_banner'] ) || '1' !== $_GET['dgm_dismiss_banner'] ) {
		return;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		$nonce = '';
		if ( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ) {
			$nonce = wp_unslash( $_GET['_wpnonce'] );
		}
		if ( ! wp_verify_nonce( $nonce, 'dgm_dismiss_banner' ) ) {
			return;
		}
	}

	if ( headers_sent() ) {
		return;
	}

	dgm_remember_banner_dismissal();

	wp_safe_redirect( remove_query_arg( array( 'dgm_dismiss_banner', '_wpnonce' ) ) );
	exit;
}

/**
 * AJAX dismissal for logged-in users. Guests use the cookie set in the browser.
 */
function dgm_ajax_dismiss_banner() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error(
			array(
				'message' => __( 'You must be logged in to dismiss this notice.', 'demo-growth-memberships' ),
			),
			403
		);
	}

	check_ajax_referer( 'dgm_dismiss_banner', 'nonce' );
	update_user_meta( get_current_user_id(), 'dgm_banner_dismissed', '1' );
	wp_send_json_success();
}

/**
 * URL that dismisses the banner without JavaScript.
 *
 * @return string
 */
function dgm_banner_dismiss_url() {
	$url = add_query_arg( 'dgm_dismiss_banner', '1' );
	if ( is_user_logged_in() ) {
		$url = wp_nonce_url( $url, 'dgm_dismiss_banner' );
	}

	return $url;
}

/**
 * Add bottom spacing while the front-end banner is visible.
 *
 * @param mixed $classes Body classes.
 * @return array
 */
function dgm_banner_body_class( $classes ) {
	if ( ! is_array( $classes ) ) {
		$classes = array();
	}

	if ( dgm_should_show_banner() && ! is_admin() ) {
		$classes[] = 'dgm-has-banner';
	}

	return $classes;
}

/**
 * Front-end and login banner styles.
 */
function dgm_print_banner_css() {
	static $done = false;

	if ( $done || is_admin() || ! dgm_should_show_banner() ) {
		return;
	}

	$done = true;

	echo '<style id="dgm-demo-banner-css">'
		. '.dgm-demo-banner-front{position:fixed;z-index:100000;bottom:0;left:0;right:0;display:flex;align-items:center;justify-content:center;margin:0;padding:12px 96px;background:#1d2327;color:#fff;font-size:14px;line-height:1.4;box-sizing:border-box;text-align:center;}'
		. '.dgm-demo-banner-front p{margin:0;text-align:center;}'
		. '.dgm-demo-banner-front a{position:absolute;right:16px;top:50%;transform:translateY(-50%);color:#fff;white-space:nowrap;}'
		. 'body.dgm-has-banner{padding-bottom:52px;}'
		. '</style>';
}

/**
 * Front-end and login banner.
 */
function dgm_render_frontend_banner() {
	static $done = false;

	if ( $done || is_admin() || ! dgm_should_show_banner() ) {
		return;
	}

	$done = true;

	echo '<div id="dgm-demo-banner" class="dgm-demo-banner-front" role="region" aria-label="' . esc_attr__( 'Demo site notice', 'demo-growth-memberships' ) . '">';
	echo '<p>' . esc_html( dgm_banner_message() ) . '</p>';
	echo '<a class="dgm-dismiss" href="' . esc_url( dgm_banner_dismiss_url() ) . '">' . esc_html__( 'Dismiss', 'demo-growth-memberships' ) . '</a>';
	echo '</div>';
}

/**
 * Admin banner, including Network Admin.
 */
function dgm_render_admin_banner() {
	if ( ! dgm_should_show_banner() || dgm_user_is_privileged() ) {
		return;
	}

	echo '<div id="dgm-demo-banner" class="notice notice-warning is-dismissible">';
	echo '<p>' . esc_html( dgm_banner_message() ) . ' ';
	echo '<a class="dgm-dismiss" href="' . esc_url( dgm_banner_dismiss_url() ) . '">' . esc_html__( 'Dismiss', 'demo-growth-memberships' ) . '</a>';
	echo '</p></div>';
}

/**
 * Remember dismissal when the visitor closes the banner.
 */
function dgm_print_banner_script() {
	static $done = false;

	if ( $done || ! dgm_should_show_banner() ) {
		return;
	}

	$done = true;

	$config = array(
		'maxAge' => dgm_banner_dismiss_ttl(),
	);

	if ( is_user_logged_in() ) {
		$config['ajaxUrl'] = admin_url( 'admin-ajax.php' );
		$config['action']  = 'dgm_dismiss_banner';
		$config['nonce']   = wp_create_nonce( 'dgm_dismiss_banner' );
	}

	$script = '(function(){'
		. 'var config=' . dgm_json_for_script( $config ) . ';'
		. 'function closestDismiss(node,banner){while(node&&node!==banner){if(node.classList&&(node.classList.contains("dgm-dismiss")||node.classList.contains("notice-dismiss"))){return node;}node=node.parentNode;}return null;}'
		. 'document.addEventListener("click",function(event){'
		. 'var banner=document.getElementById("dgm-demo-banner");'
		. 'if(!banner||!event.target||!banner.contains(event.target)){return;}'
		. 'var control=closestDismiss(event.target,banner);'
		. 'if(!control){return;}'
		. 'if(control.classList.contains("dgm-dismiss")){event.preventDefault();}'
		. 'var secure=window.location.protocol==="https:"?"; Secure":"";'
		. 'document.cookie="dgm_banner_dismissed=1; Path=/; Max-Age="+String(config.maxAge)+"; SameSite=Lax"+secure;'
		. 'if(banner.parentNode){banner.parentNode.removeChild(banner);}'
		. 'if(document.body&&document.body.classList){document.body.classList.remove("dgm-has-banner");}'
		. 'if(config.ajaxUrl&&window.fetch&&window.FormData){var data=new FormData();data.append("action",config.action);data.append("nonce",config.nonce);window.fetch(config.ajaxUrl,{method:"POST",credentials:"same-origin",body:data});}'
		. '});'
		. '})();';

	echo '<script id="dgm-banner-js">' . $script . '</script>';
}
