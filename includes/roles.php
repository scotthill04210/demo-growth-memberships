<?php
/**
 * Keep the democlient user on the client role.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register role locks.
 */
function dgm_register_role_hooks() {
	add_action( 'plugins_loaded', 'dgm_enforce_demo_client_role', 1 );
	add_action( 'init', 'dgm_enforce_demo_client_role', 1 );
	add_action( 'init', 'dgm_enforce_demo_client_role', 99 );
	add_action( 'user_register', 'dgm_enforce_demo_client_role' );
	add_action( 'profile_update', 'dgm_enforce_demo_client_role' );
	add_action( 'set_user_role', 'dgm_enforce_demo_client_role' );
	add_action( 'add_user_role', 'dgm_enforce_demo_client_role' );
	add_action( 'remove_user_role', 'dgm_enforce_demo_client_role' );
	add_action( 'granted_super_admin', 'dgm_enforce_demo_client_role' );
	add_action( 'added_user_meta', 'dgm_watch_demo_client_cap_meta', 10, 4 );
	add_action( 'updated_user_meta', 'dgm_watch_demo_client_cap_meta', 10, 4 );
	add_filter( 'user_has_cap', 'dgm_limit_demo_client_caps', PHP_INT_MAX, 4 );
	add_filter( 'wp_pre_insert_user_data', 'dgm_lock_demo_client_login', 10, 3 );
	add_filter( 'pre_update_site_option_site_admins', 'dgm_strip_demo_super_admin' );
}

/**
 * Capabilities that would let Demo Client leave the client role or administer the site.
 *
 * @return string[]
 */
function dgm_escalation_capabilities() {
	return array(
		'manage_options',
		'activate_plugins',
		'edit_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_themes',
		'install_themes',
		'switch_themes',
		'update_themes',
		'delete_themes',
		'edit_theme_options',
		'customize',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'list_users',
		'add_users',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'unfiltered_html',
		'unfiltered_upload',
		'edit_files',
		'update_core',
		'install_languages',
		'update_languages',
		'delete_languages',
		'export',
		'import',
		'manage_woocommerce',
		'level_10',
		'level_9',
		'level_8',
		'level_7',
		'level_6',
		'level_5',
		'level_4',
		'level_3',
		'level_2',
		'level_1',
	);
}

/**
 * The demo account, when it exists.
 *
 * @return WP_User|null
 */
function dgm_get_demo_client_user() {
	$user = get_user_by( 'login', 'democlient' );
	if ( ! $user instanceof WP_User ) {
		return null;
	}

	return $user;
}

/**
 * Force democlient onto the client role for the current site and drop super admin.
 *
 * Does not add the user to sites they do not already belong to.
 *
 * @param int $user_id User ID from a user hook, or 0 to look up democlient.
 */
function dgm_enforce_demo_client_role( $user_id = 0 ) {
	static $running = false;

	if ( $running || ! dgm_is_demo_mode() ) {
		return;
	}

	$user_id = (int) $user_id;
	if ( $user_id > 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || 'democlient' !== $user->user_login ) {
			return;
		}
	} else {
		$user = dgm_get_demo_client_user();
		if ( ! $user ) {
			return;
		}
	}

	$running = true;

	if ( is_multisite() && function_exists( 'is_super_admin' ) && function_exists( 'revoke_super_admin' ) && is_super_admin( $user->ID ) ) {
		revoke_super_admin( $user->ID );
	}

	$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	if ( is_multisite() && function_exists( 'is_user_member_of_blog' ) && $blog_id > 0 && ! is_user_member_of_blog( $user->ID, $blog_id ) ) {
		$running = false;
		return;
	}

	$roles = array();
	foreach ( (array) $user->roles as $role ) {
		if ( is_string( $role ) && '' !== $role ) {
			$roles[] = $role;
		}
	}
	$roles = array_values( array_unique( $roles ) );

	$only_client = 1 === count( $roles ) && 'client' === $roles[0];
	if ( ! $only_client ) {
		$user->set_role( 'client' );
	} elseif ( is_callable( array( $user, 'get_role_caps' ) ) ) {
		$user->get_role_caps();
	}

	$running = false;
}

/**
 * If capability meta for democlient changes, put the client role back.
 *
 * @param int    $meta_id    Meta row ID.
 * @param int    $user_id    User ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function dgm_watch_demo_client_cap_meta( $meta_id = 0, $user_id = 0, $meta_key = '', $meta_value = null ) {
	unset( $meta_id, $meta_value );

	if ( ! is_string( $meta_key ) || ! preg_match( '/capabilities$/', $meta_key ) ) {
		return;
	}

	dgm_enforce_demo_client_role( (int) $user_id );
}

/**
 * Deny administrative capabilities for democlient even if the client role gains them.
 *
 * @param mixed   $allcaps All capabilities for the user.
 * @param mixed   $caps    Required primitive capabilities.
 * @param mixed   $args    Capability check arguments.
 * @param WP_User $user    User being checked.
 * @return mixed
 */
function dgm_limit_demo_client_caps( $allcaps = array(), $caps = array(), $args = array(), $user = null ) {
	unset( $caps, $args );

	if ( ! is_array( $allcaps ) || ! ( $user instanceof WP_User ) || 'democlient' !== $user->user_login ) {
		return $allcaps;
	}

	foreach ( dgm_escalation_capabilities() as $cap ) {
		$allcaps[ $cap ] = false;
	}

	return $allcaps;
}

/**
 * Keep the account username stable so the role lock still finds it.
 *
 * @param mixed $data    User row data.
 * @param bool  $update  Whether this is an update.
 * @param int   $user_id Existing user ID.
 * @return mixed
 */
function dgm_lock_demo_client_login( $data = array(), $update = false, $user_id = 0 ) {
	if ( ! is_array( $data ) || ! $update || ! $user_id ) {
		return $data;
	}

	$existing = get_userdata( (int) $user_id );
	if ( ! $existing instanceof WP_User || 'democlient' !== $existing->user_login ) {
		return $data;
	}

	$data['user_login'] = 'democlient';

	return $data;
}

/**
 * Never store democlient in the network super admin list.
 *
 * @param mixed $value Proposed site_admins value.
 * @return mixed
 */
function dgm_strip_demo_super_admin( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$clean = array();
	foreach ( $value as $login ) {
		if ( is_string( $login ) && 'democlient' === $login ) {
			continue;
		}
		$clean[] = $login;
	}

	return $clean;
}
