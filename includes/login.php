<?php
/**
 * Demo login on wp-login.php, and a My Account redirect for signed-out visitors.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register login hooks.
 */
function dgm_register_login_hooks() {
	add_action( 'login_head', 'dgm_login_styles' );
	add_action( 'login_form', 'dgm_login_demo_button' );
	add_action( 'login_footer', 'dgm_login_autofill_script' );
	add_action( 'template_redirect', 'dgm_redirect_logged_out_demo_visitors', 0 );
	add_filter( 'login_redirect', 'dgm_override_home_login_redirect', 100, 3 );
	add_filter( 'woocommerce_login_redirect', 'dgm_override_home_login_redirect', 100, 2 );
	add_action( 'wp_head', 'dgm_print_demo_login_notice_css' );
	add_action( 'wp_body_open', 'dgm_render_demo_login_notice', 1 );
	add_action( 'wp_footer', 'dgm_render_demo_login_notice', 1 );
}

/**
 * Whether the current request is the main login form on wp-login.php.
 *
 * @return bool
 */
function dgm_is_wp_login_request() {
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

	if ( ! in_array( 'wp-login.php', $scripts, true ) ) {
		return false;
	}

	$action = 'login';
	if ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) {
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	}

	return '' === $action || 'login' === $action;
}

/**
 * Make the demo login button visible on the login form.
 */
function dgm_login_styles() {
	if ( ! dgm_is_demo_mode() || ! dgm_is_wp_login_request() ) {
		return;
	}

	echo '<style id="dgm-login-css">#dgm-demo-login{display:block;width:100%;margin:8px 0 0;float:none;clear:both;height:auto;text-align:center;}</style>';
}

/**
 * Submit control that posts the login form with the prefilled demo account.
 */
function dgm_login_demo_button() {
	if ( ! dgm_is_demo_mode() || ! dgm_is_wp_login_request() ) {
		return;
	}

	echo '<p class="dgm-demo-login-wrap"><button type="submit" name="dgm_demo_login" id="dgm-demo-login" class="button button-primary button-large">';
	esc_html_e( 'Demo login', 'demo-growth-memberships' );
	echo '</button></p>';
}

/**
 * Fill the demo account only when Demo login is clicked.
 *
 * Leaving the fields alone lets an administrator sign in with their own account.
 */
function dgm_login_autofill_script() {
	if ( ! dgm_is_demo_mode() || ! dgm_is_wp_login_request() ) {
		return;
	}

	$script = '(function(){var button=document.getElementById("dgm-demo-login");if(!button){return;}button.addEventListener("click",function(){var user=document.getElementById("user_login");if(user){user.value=' . dgm_json_for_script( 'democlient' ) . ';}';

	$password = dgm_demo_password();
	if ( '' !== $password ) {
		$script .= 'var pass=document.getElementById("user_pass");if(pass){pass.value=' . dgm_json_for_script( $password ) . ';}';
	}

	$script .= '});})();';

	echo '<script id="dgm-login-autofill">' . $script . '</script>';
}


/**
 * My Account address for this site.
 *
 * @return string
 */
function dgm_my_account_url() {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'myaccount' );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}

	return home_url( '/my-account/' );
}

/**
 * Whether this request is already the My Account page.
 *
 * @return bool
 */
function dgm_request_is_my_account() {
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return true;
	}

	$request = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$request = wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	$path    = wp_parse_url( $request, PHP_URL_PATH );
	$account = wp_parse_url( dgm_my_account_url(), PHP_URL_PATH );
	$path    = is_string( $path ) ? untrailingslashit( $path ) : '';
	$account = is_string( $account ) ? untrailingslashit( $account ) : '';

	return '' !== $account && $path === $account;
}

/**
 * Whether a URL is this site's home page.
 *
 * @param mixed $url URL to compare.
 * @return bool
 */
function dgm_url_is_home( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$path = wp_parse_url( $url, PHP_URL_PATH );
	$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path = is_string( $path ) ? untrailingslashit( $path ) : '';
	$home = is_string( $home ) ? untrailingslashit( $home ) : '';

	return $path === $home;
}

/**
 * Send signed-out visitors to My Account while demo mode is on.
 */
function dgm_redirect_logged_out_demo_visitors() {
	if ( ! dgm_is_demo_mode() || is_user_logged_in() || is_admin() ) {
		return;
	}

	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return;
	}

	if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
		return;
	}

	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return;
	}

	if ( function_exists( 'dgm_is_login_screen' ) && dgm_is_login_screen() ) {
		return;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		return;
	}

	if ( dgm_request_is_my_account() ) {
		return;
	}

	wp_safe_redirect( dgm_my_account_url() );
	exit;
}

/**
 * Keep a successful login from returning to the home page.
 *
 * Administrators and super admins keep the redirect they asked for.
 * WooCommerce passes the user as the second argument. WordPress passes it as the third.
 *
 * @param string           $redirect_to Redirect chosen so far.
 * @param string|WP_User   $second       Requested redirect, or the user on WooCommerce.
 * @param WP_User|WP_Error $user         Authenticated user, when WordPress provides one.
 * @return string
 */
function dgm_override_home_login_redirect( $redirect_to, $second = null, $user = null ) {
	if ( ! $user instanceof WP_User && $second instanceof WP_User ) {
		$user = $second;
	}

	if ( ! dgm_is_demo_mode() || ! ( $user instanceof WP_User ) || dgm_user_is_privileged( $user ) ) {
		return $redirect_to;
	}

	if ( ! dgm_url_is_home( $redirect_to ) ) {
		return $redirect_to;
	}

	return dgm_my_account_url();
}

/**
 * Whether the signed-out demo login message should show.
 *
 * @return bool
 */
function dgm_should_show_demo_login_notice() {
	if ( ! dgm_is_demo_mode() || is_admin() || is_user_logged_in() || dgm_user_is_privileged() ) {
		return false;
	}

	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return false;
	}

	if ( dgm_is_wp_login_request() || ( function_exists( 'dgm_is_login_screen' ) && dgm_is_login_screen() ) ) {
		return false;
	}

	return dgm_request_is_my_account_screen();
}

/**
 * Whether this request is the My Account screen itself, not wp-login.php.
 *
 * @return bool
 */
function dgm_request_is_my_account_screen() {
	$request = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$request = wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	$path    = wp_parse_url( $request, PHP_URL_PATH );
	$account = wp_parse_url( dgm_my_account_url(), PHP_URL_PATH );
	$path    = is_string( $path ) ? untrailingslashit( $path ) : '';
	$account = is_string( $account ) ? untrailingslashit( $account ) : '';

	return '' !== $account && $path === $account;
}

/**
 * Styles for the signed-out login message.
 */
function dgm_print_demo_login_notice_css() {
	static $done = false;

	if ( $done || ! dgm_should_show_demo_login_notice() ) {
		return;
	}

	$done = true;

	echo '<style id="dgm-demo-login-notice-css">'
		. '.dgm-demo-login-notice{position:fixed;z-index:2147482000;top:0;right:0;bottom:52px;left:0;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;pointer-events:none;}'
		. 'body.admin-bar .dgm-demo-login-notice{top:32px;}'
		. '@media screen and (max-width:782px){body.admin-bar .dgm-demo-login-notice{top:46px;}}'
		. '.dgm-demo-login-notice-card,.dgm-demo-login-notice-open{pointer-events:auto;}'
		. '.dgm-demo-login-notice-card{position:relative;width:min(100%,340px);margin:0;padding:16px 16px 14px;background:#fff;color:#1d2327;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.25);box-sizing:border-box;text-align:left;}'
		. '.dgm-demo-login-notice-card h2{margin:0 36px 12px;font-size:16px;line-height:1.3;text-align:center;}'
		. '.dgm-demo-login-notice-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 8px;}'
		. '.dgm-demo-login-notice-row span{font-size:14px;line-height:1.4;word-break:break-all;}'
		. '.dgm-demo-login-notice-copy,.dgm-demo-login-notice-min,.dgm-demo-login-notice-open{border:0;border-radius:4px;background:#2271b1;color:#fff;font-size:13px;line-height:1.4;cursor:pointer;}'
		. '.dgm-demo-login-notice-copy{padding:6px 10px;white-space:nowrap;}'
		. '.dgm-demo-login-notice-min{position:absolute;top:8px;right:8px;padding:4px 8px;background:#1d2327;}'
		. '.dgm-demo-login-notice-open{display:none;padding:10px 14px;box-shadow:0 8px 24px rgba(0,0,0,.25);}'
		. '.dgm-demo-login-notice.is-minimized .dgm-demo-login-notice-card{display:none;}'
		. '.dgm-demo-login-notice.is-minimized .dgm-demo-login-notice-open{display:inline-block;}'
		. '</style>';
}

/**
 * Tell a signed-out visitor which demo account to use.
 */
function dgm_render_demo_login_notice() {
	static $done = false;

	if ( $done || ! dgm_should_show_demo_login_notice() ) {
		return;
	}

	$done = true;

	dgm_print_demo_login_notice_css();

	echo '<div id="dgm-demo-login-notice" class="dgm-demo-login-notice" role="region" aria-labelledby="dgm-demo-login-notice-title">';
	echo '<div class="dgm-demo-login-notice-card">';
	echo '<button type="button" class="dgm-demo-login-notice-min">' . esc_html__( 'Minimize', 'demo-growth-memberships' ) . '</button>';
	echo '<h2 id="dgm-demo-login-notice-title">' . esc_html__( 'Please Login with:', 'demo-growth-memberships' ) . '</h2>';
	echo '<p class="dgm-demo-login-notice-row"><span>' . esc_html__( 'username: democlient', 'demo-growth-memberships' ) . '</span>';
	echo '<button type="button" class="dgm-demo-login-notice-copy" data-copy="democlient" data-label="' . esc_attr__( 'Copy', 'demo-growth-memberships' ) . '">' . esc_html__( 'Copy', 'demo-growth-memberships' ) . '</button></p>';
	echo '<p class="dgm-demo-login-notice-row"><span>' . esc_html__( 'password: Alliance360', 'demo-growth-memberships' ) . '</span>';
	echo '<button type="button" class="dgm-demo-login-notice-copy" data-copy="Alliance360" data-label="' . esc_attr__( 'Copy', 'demo-growth-memberships' ) . '">' . esc_html__( 'Copy', 'demo-growth-memberships' ) . '</button></p>';
	echo '</div>';
	echo '<button type="button" class="dgm-demo-login-notice-open">' . esc_html__( 'Demo login', 'demo-growth-memberships' ) . '</button>';
	echo '</div>';

	$script = '(function(){'
		. 'var notice=document.getElementById("dgm-demo-login-notice");'
		. 'if(!notice){return;}'
		. 'if(document.body&&notice.parentNode!==document.body){document.body.appendChild(notice);}'
		. 'var key="dgmDemoLoginMinimized";'
		. 'function setMinimized(minimized){if(minimized){notice.classList.add("is-minimized");}else{notice.classList.remove("is-minimized");}try{window.sessionStorage.setItem(key,minimized?"1":"0");}catch(e){}}'
		. 'try{if(window.sessionStorage.getItem(key)==="1"){notice.classList.add("is-minimized");}}catch(e){}'
		. 'var min=notice.querySelector(".dgm-demo-login-notice-min");'
		. 'var open=notice.querySelector(".dgm-demo-login-notice-open");'
		. 'if(min){min.addEventListener("click",function(){setMinimized(true);});}'
		. 'if(open){open.addEventListener("click",function(){setMinimized(false);});}'
		. 'var copied=' . dgm_json_for_script( __( 'Copied', 'demo-growth-memberships' ) ) . ';'
		. 'function markCopied(button){var label=button.getAttribute("data-label")||"Copy";button.textContent=copied;window.setTimeout(function(){button.textContent=label;},1500);}'
		. 'function fallbackCopy(value,button){var field=document.createElement("textarea");field.value=value;document.body.appendChild(field);field.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(field);markCopied(button);}'
		. 'notice.addEventListener("click",function(event){var button=event.target&&event.target.closest?event.target.closest(".dgm-demo-login-notice-copy"):null;if(!button){return;}var value=button.getAttribute("data-copy")||"";if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(value).then(function(){markCopied(button);}).catch(function(){fallbackCopy(value,button);});return;}fallbackCopy(value,button);});'
		. '})();';

	echo '<script id="dgm-demo-login-notice-js">' . $script . '</script>';
}
