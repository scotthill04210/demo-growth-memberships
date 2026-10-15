<?php
/**
 * Demo login autofill on wp-login.php.
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
