<?php
/**
 * Demo login on wp-login.php and a home-page pop-in for signed-out visitors.
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
	add_action( 'login_init', 'dgm_prepare_demo_popin_login' );
	add_action( 'wp_login_failed', 'dgm_redirect_failed_demo_popin_login', 10, 2 );
	add_filter( 'login_redirect', 'dgm_redirect_demo_popin_login', 100, 3 );
	add_action( 'wp', 'dgm_mark_demo_popin_home' );
	add_action( 'wp_head', 'dgm_print_demo_popin_css' );
	add_action( 'wp_body_open', 'dgm_render_demo_popin', 1 );
	add_action( 'wp_footer', 'dgm_render_demo_popin', 1 );
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
 * Whether this POST came from the home-page demo login.
 *
 * @return bool
 */
function dgm_is_demo_popin_login_request() {
	if ( ! dgm_is_demo_mode() ) {
		return false;
	}

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_string( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		return false;
	}

	return isset( $_POST['dgm_demo_popin'] ) && is_string( $_POST['dgm_demo_popin'] ) && '1' === wp_unslash( $_POST['dgm_demo_popin'] );
}

/**
 * Remember that this request is the site home while the main query is still intact.
 */
function dgm_mark_demo_popin_home() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return;
	}

	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return;
	}

	if ( dgm_request_is_site_home() ) {
		$GLOBALS['dgm_demo_popin_home'] = true;
	}
}

/**
 * Whether this request is the current site's home page.
 *
 * @return bool
 */
function dgm_request_is_site_home() {
	if ( ! empty( $GLOBALS['dgm_demo_popin_home'] ) ) {
		return true;
	}

	if ( function_exists( 'is_front_page' ) && is_front_page() ) {
		return true;
	}

	$request = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
		$request = wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	$path = wp_parse_url( $request, PHP_URL_PATH );
	$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path = is_string( $path ) ? untrailingslashit( $path ) : '';
	$home = is_string( $home ) ? untrailingslashit( $home ) : '';

	return $path === $home;
}

/**
 * Home page for signed-out visitors while demo mode is on.
 *
 * Logged-in accounts, including Administrator and super admin, do not see it.
 *
 * @return bool
 */
function dgm_should_show_demo_popin() {
	if ( ! dgm_is_demo_mode() || is_admin() || is_user_logged_in() || dgm_user_is_privileged() ) {
		return false;
	}

	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return false;
	}

	return dgm_request_is_site_home();
}

/**
 * Keep the home-page demo form on the democlient account and send it back home.
 *
 * Posting to wp-login.php lets the security plugin validate its reCAPTCHA.
 */
function dgm_prepare_demo_popin_login() {
	if ( ! dgm_is_demo_popin_login_request() ) {
		return;
	}

	$nonce = '';
	if ( isset( $_POST['dgm_demo_popin_nonce'] ) && is_string( $_POST['dgm_demo_popin_nonce'] ) ) {
		$nonce = wp_unslash( $_POST['dgm_demo_popin_nonce'] );
	}

	if ( ! wp_verify_nonce( $nonce, 'dgm_demo_popin_login' ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$_POST['log']            = 'democlient';
	$_REQUEST['log']         = 'democlient';
	$home                    = home_url( '/' );
	$_POST['redirect_to']    = $home;
	$_REQUEST['redirect_to'] = $home;
}

/**
 * After a failed home-page demo login, return to the home page with the pop-in open.
 *
 * @param string   $username Submitted username.
 * @param WP_Error $error    Login error, when WordPress provides one.
 */
function dgm_redirect_failed_demo_popin_login( $username, $error = null ) {
	unset( $username );

	if ( ! dgm_is_demo_popin_login_request() ) {
		return;
	}

	$code = 'failed';
	if ( $error instanceof WP_Error ) {
		$codes = $error->get_error_codes();
		if ( in_array( 'captcha_error', $codes, true ) || in_array( 'invalid_captcha', $codes, true ) ) {
			$code = 'captcha';
		}
	}

	wp_safe_redirect( add_query_arg( 'dgm_login', $code, home_url( '/' ) ) );
	exit;
}

/**
 * Send a successful home-page demo login back to the home page.
 *
 * @param string           $redirect_to           Redirect chosen so far.
 * @param string           $requested_redirect_to Redirect requested by the form.
 * @param WP_User|WP_Error $user                  Authenticated user, or an error.
 * @return string
 */
function dgm_redirect_demo_popin_login( $redirect_to, $requested_redirect_to, $user ) {
	unset( $requested_redirect_to );

	if ( ! dgm_is_demo_popin_login_request() ) {
		return $redirect_to;
	}

	if ( $user instanceof WP_User && 'democlient' === $user->user_login ) {
		return home_url( '/' );
	}

	return $redirect_to;
}

/**
 * Message for a demo login that returned to the home page.
 *
 * @return string
 */
function dgm_demo_popin_error_message() {
	$code = '';
	if ( isset( $_GET['dgm_login'] ) && is_string( $_GET['dgm_login'] ) ) {
		$code = sanitize_key( wp_unslash( $_GET['dgm_login'] ) );
	}

	if ( 'captcha' === $code ) {
		return __( 'Confirm you are not a robot, then try the demo login again.', 'demo-growth-memberships' );
	}

	if ( 'failed' === $code ) {
		return __( 'The demo login could not be completed.', 'demo-growth-memberships' );
	}

	return '';
}

/**
 * Styles for the home-page demo login.
 */
function dgm_print_demo_popin_css() {
	static $done = false;

	if ( $done || ! dgm_should_show_demo_popin() ) {
		return;
	}

	$done = true;

	echo '<style id="dgm-demo-popin-css">'
		. '.dgm-demo-popin{position:fixed;z-index:2147483000;top:0;right:0;bottom:0;left:0;display:flex !important;align-items:center;justify-content:center;padding:24px 16px;background:rgba(0,0,0,.45);box-sizing:border-box;}'
		. '.dgm-demo-popin[hidden],.dgm-demo-popin.is-closed{display:none !important;}'
		. '.dgm-demo-popin-card{width:min(100%,420px);margin:0;padding:24px 20px 20px;background:#fff;color:#1d2327;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.25);box-sizing:border-box;text-align:center;animation:dgm-popin .25s ease-out;}'
		. '@keyframes dgm-popin{from{transform:translateY(16px);opacity:0;}to{transform:none;opacity:1;}}'
		. '.dgm-demo-popin-card h2{margin:0 0 8px;font-size:22px;line-height:1.3;}'
		. '.dgm-demo-popin-card p{margin:0 0 12px;}'
		. '.dgm-demo-popin-card label{display:block;font-size:13px;font-weight:600;text-align:left;}'
		. '.dgm-demo-popin-card input[type="text"],.dgm-demo-popin-card input[type="password"]{display:block;width:100%;margin-top:4px;padding:8px 10px;box-sizing:border-box;}'
		. '.dgm-demo-popin-card .captcha_wrap,.dgm-demo-popin-card .wpdef_recaptcha{display:flex;justify-content:center;margin:12px 0;}'
		. '.dgm-demo-popin-error{color:#b32d2e;font-size:14px;}'
		. '.dgm-demo-popin-submit,.dgm-demo-popin-open{display:inline-block;margin:0;padding:10px 16px;border:0;border-radius:4px;background:#2271b1;color:#fff;font-size:14px;line-height:1.4;cursor:pointer;}'
		. '.dgm-demo-popin-close{position:absolute;top:8px;right:8px;border:0;background:transparent;color:#1d2327;font-size:22px;line-height:1;cursor:pointer;}'
		. '.dgm-demo-popin-card{position:relative;}'
		. '.dgm-demo-popin-open{position:fixed;z-index:100001;right:16px;bottom:68px;}'
		. '.dgm-demo-popin-open[hidden]{display:none;}'
		. '</style>';
}

/**
 * Demo client login on the home page.
 *
 * The form posts to wp-login.php so the security plugin can render and check reCAPTCHA.
 */
function dgm_render_demo_popin() {
	static $done = false;

	if ( $done || ! dgm_should_show_demo_popin() ) {
		return;
	}

	$done = true;

	dgm_print_demo_popin_css();

	$error    = dgm_demo_popin_error_message();
	$password = dgm_demo_password();

	echo '<div id="dgm-demo-popin" class="dgm-demo-popin" role="dialog" aria-modal="true" aria-labelledby="dgm-demo-popin-title">';
	echo '<div class="dgm-demo-popin-card">';
	echo '<button type="button" class="dgm-demo-popin-close" aria-label="' . esc_attr__( 'Close demo login', 'demo-growth-memberships' ) . '">&times;</button>';
	echo '<h2 id="dgm-demo-popin-title">' . esc_html__( 'Demo login', 'demo-growth-memberships' ) . '</h2>';
	echo '<p>' . esc_html__( 'Sign in with the demo account.', 'demo-growth-memberships' ) . '</p>';

	if ( '' !== $error ) {
		echo '<p class="dgm-demo-popin-error" role="alert">' . esc_html( $error ) . '</p>';
	}

	echo '<form id="dgm-demo-popin-form" method="post" action="' . esc_url( wp_login_url() ) . '">';
	wp_nonce_field( 'dgm_demo_popin_login', 'dgm_demo_popin_nonce' );
	echo '<input type="hidden" name="dgm_demo_popin" value="1" />';
	echo '<input type="hidden" name="redirect_to" value="' . esc_url( home_url( '/' ) ) . '" />';
	echo '<p><label for="dgm-popin-user">' . esc_html__( 'Username', 'demo-growth-memberships' );
	echo '<input type="text" name="log" id="dgm-popin-user" value="democlient" autocomplete="username" /></label></p>';
	echo '<p><label for="dgm-popin-pass">' . esc_html__( 'Password', 'demo-growth-memberships' );
	echo '<input type="password" name="pwd" id="dgm-popin-pass" value="' . esc_attr( $password ) . '" autocomplete="current-password" /></label></p>';

	do_action( 'login_form' );

	echo '<p><button type="submit" class="dgm-demo-popin-submit">' . esc_html__( 'Log in', 'demo-growth-memberships' ) . '</button></p>';
	echo '</form></div></div>';

	echo '<button type="button" id="dgm-demo-popin-open" class="dgm-demo-popin-open" hidden>' . esc_html__( 'Demo login', 'demo-growth-memberships' ) . '</button>';

	$script = '(function(){'
		. 'var popin=document.getElementById("dgm-demo-popin");'
		. 'var opener=document.getElementById("dgm-demo-popin-open");'
		. 'var pass=document.getElementById("dgm-popin-pass");'
		. 'var user=document.getElementById("dgm-popin-user");'
		. 'if(user){user.value=' . dgm_json_for_script( 'democlient' ) . ';}'
		. 'if(pass){pass.value=' . dgm_json_for_script( $password ) . ';}'
		. 'if(!popin){return;}'
		. 'function setOpen(open){if(open){popin.classList.remove("is-closed");popin.removeAttribute("hidden");if(opener){opener.setAttribute("hidden","hidden");}}else{popin.classList.add("is-closed");popin.setAttribute("hidden","hidden");if(opener){opener.removeAttribute("hidden");}}}'
		. 'popin.addEventListener("click",function(event){var close=event.target&&event.target.closest?event.target.closest(".dgm-demo-popin-close"):null;if(event.target===popin||close){setOpen(false);}});'
		. 'if(opener){opener.addEventListener("click",function(){setOpen(true);});}'
		. 'if(window.history&&window.history.replaceState&&window.location.search.indexOf("dgm_login=")!==-1){var url=new URL(window.location.href);url.searchParams.delete("dgm_login");window.history.replaceState({},"",url.pathname+url.search+url.hash);}'
		. '})();';

	echo '<script id="dgm-demo-popin-js">' . $script . '</script>';
}
