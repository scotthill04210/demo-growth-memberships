<?php
/**
 * Demo banner for the front end and wp-admin.
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
	add_filter( 'body_class', 'dgm_banner_body_class' );
	add_filter( 'login_body_class', 'dgm_banner_body_class' );
	add_action( 'wp_head', 'dgm_print_banner_css' );
	add_action( 'login_head', 'dgm_print_banner_css' );
	add_action( 'wp_body_open', 'dgm_render_frontend_banner', 1 );
	add_action( 'wp_footer', 'dgm_render_frontend_banner', 99 );
	add_action( 'login_footer', 'dgm_render_frontend_banner', 99 );
	add_action( 'all_admin_notices', 'dgm_render_admin_banner' );
}

/**
 * Whether the banner should render on this request.
 *
 * @return bool
 */
function dgm_should_show_banner() {
	if ( ! dgm_is_demo_mode() ) {
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
		. '.dgm-demo-banner-front{position:fixed;z-index:2147483000;bottom:0;left:0;right:0;display:flex !important;align-items:center;justify-content:center;margin:0;padding:12px 16px;background:#1d2327;color:#fff;font-size:14px;line-height:1.4;box-sizing:border-box;text-align:center;}'
		. '.dgm-demo-banner-front p{margin:0;text-align:center;}'
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

	dgm_print_banner_css();

	echo '<div id="dgm-demo-banner" class="dgm-demo-banner-front" role="region" aria-label="' . esc_attr__( 'Demo site notice', 'demo-growth-memberships' ) . '">';
	echo '<p>' . esc_html( dgm_banner_message() ) . '</p>';
	echo '</div>';
	echo '<script id="dgm-demo-banner-place">var dgmBanner=document.getElementById("dgm-demo-banner");if(dgmBanner&&document.body&&dgmBanner.parentNode!==document.body){document.body.appendChild(dgmBanner);}</script>';
}

/**
 * Admin banner, including Network Admin.
 */
function dgm_render_admin_banner() {
	if ( ! dgm_should_show_banner() || dgm_user_is_privileged() ) {
		return;
	}

	echo '<div id="dgm-demo-banner" class="notice notice-warning">';
	echo '<p>' . esc_html( dgm_banner_message() ) . '</p>';
	echo '</div>';
}
