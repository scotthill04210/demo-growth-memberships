<?php
/**
 * Per-site demo settings and plugin row links.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Plugins screen links and the settings pages.
 */
function dgm_register_settings_hooks() {
	$basename = plugin_basename( DGM_PLUGIN_FILE );
	add_filter( 'plugin_action_links_' . $basename, 'dgm_plugin_action_links' );
	add_filter( 'network_admin_plugin_action_links_' . $basename, 'dgm_plugin_action_links' );
	add_action( 'admin_init', 'dgm_handle_settings_save' );
	add_action( 'network_admin_menu', 'dgm_register_network_settings_page' );
	add_action( 'admin_menu', 'dgm_register_site_settings_page' );
}

/**
 * Settings screen URL for the screen that is currently being viewed.
 *
 * @return string
 */
function dgm_settings_url() {
	if ( is_network_admin() ) {
		return network_admin_url( 'settings.php?page=dgm-settings' );
	}

	return admin_url( 'options-general.php?page=dgm-settings' );
}

/**
 * Plugins-screen URL that asks Plugin Update Checker to check this plugin.
 *
 * @return string
 */
function dgm_check_for_updates_url() {
	$url = add_query_arg(
		array(
			'puc_check_for_updates' => '1',
			'puc_slug'              => 'demo-growth-memberships',
		),
		self_admin_url( 'plugins.php' )
	);

	return wp_nonce_url( $url, 'puc_check_for_updates' );
}

/**
 * Settings and Check for updates links under the plugin name on plugins.php.
 *
 * @param mixed $links Existing action links.
 * @return array
 */
function dgm_plugin_action_links( $links ) {
	if ( ! is_array( $links ) ) {
		$links = array();
	}

	$custom        = array();
	$can_settings  = is_network_admin() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );

	if ( $can_settings ) {
		$custom['dgm_settings'] = '<a href="' . esc_url( dgm_settings_url() ) . '">' . esc_html__( 'Settings', 'demo-growth-memberships' ) . '</a>';
	}

	if ( current_user_can( 'update_plugins' ) ) {
		$custom['dgm_check_updates'] = '<a href="' . esc_url( dgm_check_for_updates_url() ) . '">' . esc_html__( 'Check for updates', 'demo-growth-memberships' ) . '</a>';
	}

	if ( ! $custom ) {
		return $links;
	}

	return array_merge( $custom, $links );
}

/**
 * Hide the extra Check for updates link Plugin Update Checker adds beside the description.
 *
 * @return string
 */
function dgm_hide_duplicate_update_link() {
	return '';
}

/**
 * Network Admin screen for every site's demo settings.
 */
function dgm_register_network_settings_page() {
	add_submenu_page(
		'settings.php',
		__( 'Demo Growth Memberships', 'demo-growth-memberships' ),
		__( 'Demo Growth Memberships', 'demo-growth-memberships' ),
		'manage_network_options',
		'dgm-settings',
		'dgm_render_network_settings_page'
	);
}

/**
 * Settings screen for the current site.
 */
function dgm_register_site_settings_page() {
	add_options_page(
		__( 'Demo Growth Memberships', 'demo-growth-memberships' ),
		__( 'Demo Growth Memberships', 'demo-growth-memberships' ),
		'manage_options',
		'dgm-settings',
		'dgm_render_site_settings_page'
	);
}

/**
 * Save a posted settings form.
 */
function dgm_handle_settings_save() {
	if ( ! isset( $_POST['dgm_settings_action'] ) || ! is_string( $_POST['dgm_settings_action'] ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['dgm_settings_action'] ) );

	if ( 'site' === $action ) {
		dgm_save_current_site_settings();
		return;
	}

	if ( 'network' === $action ) {
		dgm_save_network_settings();
	}
}

/**
 * Save demo mode and password for the site whose admin is open.
 */
function dgm_save_current_site_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'dgm_save_site_settings' );

	$mode_raw     = isset( $_POST['dgm_demo_mode'] ) ? wp_unslash( $_POST['dgm_demo_mode'] ) : '';
	$password_raw = isset( $_POST['dgm_demo_password'] ) ? wp_unslash( $_POST['dgm_demo_password'] ) : '';
	$clear_raw    = isset( $_POST['dgm_clear_password'] ) ? wp_unslash( $_POST['dgm_clear_password'] ) : '';
	$enabled      = is_string( $mode_raw ) && '1' === $mode_raw;
	$clear        = is_string( $clear_raw ) && '1' === $clear_raw;

	dgm_update_site_settings( (int) get_current_blog_id(), $enabled, $password_raw, $clear );
	dgm_redirect_after_settings_save( false );
}

/**
 * Save the network form, one row per site.
 */
function dgm_save_network_settings() {
	if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	check_admin_referer( 'dgm_save_network_settings' );

	$site_ids = array();
	if ( isset( $_POST['dgm_site_ids'] ) && is_array( $_POST['dgm_site_ids'] ) ) {
		foreach ( $_POST['dgm_site_ids'] as $site_id ) {
			$site_ids[] = absint( $site_id );
		}
	}

	$modes     = isset( $_POST['dgm_demo_mode'] ) && is_array( $_POST['dgm_demo_mode'] ) ? wp_unslash( $_POST['dgm_demo_mode'] ) : array();
	$passwords = isset( $_POST['dgm_demo_password'] ) && is_array( $_POST['dgm_demo_password'] ) ? wp_unslash( $_POST['dgm_demo_password'] ) : array();
	$clears    = isset( $_POST['dgm_clear_password'] ) && is_array( $_POST['dgm_clear_password'] ) ? wp_unslash( $_POST['dgm_clear_password'] ) : array();

	foreach ( array_slice( array_unique( $site_ids ), 0, 200 ) as $blog_id ) {
		if ( $blog_id <= 0 ) {
			continue;
		}

		$enabled  = isset( $modes[ $blog_id ] ) && is_string( $modes[ $blog_id ] ) && '1' === $modes[ $blog_id ];
		$password = isset( $passwords[ $blog_id ] ) && is_string( $passwords[ $blog_id ] ) ? $passwords[ $blog_id ] : '';
		$clear    = isset( $clears[ $blog_id ] ) && is_string( $clears[ $blog_id ] ) && '1' === $clears[ $blog_id ];

		dgm_update_site_settings( $blog_id, $enabled, $password, $clear );
	}

	dgm_redirect_after_settings_save( true );
}

/**
 * Write one site's demo settings.
 *
 * A blank password keeps the saved value. Checking remove clears it.
 *
 * @param int    $blog_id  Site ID.
 * @param bool   $enabled  Whether demo mode is on.
 * @param mixed  $password Submitted password.
 * @param bool   $clear    Whether to delete the saved password.
 */
function dgm_update_site_settings( $blog_id, $enabled, $password, $clear ) {
	$blog_id = (int) $blog_id;
	if ( $blog_id <= 0 ) {
		return;
	}

	if ( is_multisite() ) {
		if ( ! function_exists( 'get_site' ) || ! get_site( $blog_id ) ) {
			return;
		}
	} elseif ( (int) get_current_blog_id() !== $blog_id ) {
		return;
	}

	$switched = false;
	if ( is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		update_option( 'dgm_demo_mode', $enabled ? '1' : '0', true );
		dgm_store_demo_password( $password, $clear );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Store or clear the current site's demo password.
 *
 * @param mixed $password Submitted password.
 * @param bool  $clear    Whether to delete the saved password.
 */
function dgm_store_demo_password( $password, $clear ) {
	if ( $clear ) {
		update_option( 'dgm_demo_password', '', false );
		return;
	}

	if ( ! is_string( $password ) || '' === $password ) {
		return;
	}

	$password = wp_check_invalid_utf8( $password, true );
	if ( ! is_string( $password ) ) {
		return;
	}

	$password = str_replace( "\0", '', $password );
	if ( function_exists( 'mb_substr' ) ) {
		$password = mb_substr( $password, 0, 4096 );
	} elseif ( strlen( $password ) > 4096 ) {
		$password = substr( $password, 0, 4096 );
	}

	update_option( 'dgm_demo_password', $password, false );
}

/**
 * Return to the settings screen after a save.
 *
 * @param bool $network Whether the save came from Network Admin.
 */
function dgm_redirect_after_settings_save( $network ) {
	$url = $network ? network_admin_url( 'settings.php?page=dgm-settings' ) : admin_url( 'options-general.php?page=dgm-settings' );
	wp_safe_redirect( add_query_arg( 'dgm-updated', '1', $url ) );
	exit;
}

/**
 * Success notice after a redirect from a save.
 */
function dgm_render_settings_notice() {
	if ( ! isset( $_GET['dgm-updated'] ) || ! is_string( $_GET['dgm-updated'] ) || '1' !== $_GET['dgm-updated'] ) {
		return;
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'demo-growth-memberships' ) . '</p></div>';
}

/**
 * Current site settings form.
 */
function dgm_render_site_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ) );
	}

	$demo_on       = dgm_is_demo_mode();
	$has_password  = '' !== dgm_demo_password();

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Demo Growth Memberships', 'demo-growth-memberships' ) . '</h1>';
	dgm_render_settings_notice();
	echo '<p>' . esc_html__( 'These settings apply only to this site. Demo mode prefills the democlient login, blocks outgoing email, blocks orders and payments, shows the demo banner, and keeps democlient on the client role.', 'demo-growth-memberships' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'options-general.php?page=dgm-settings' ) ) . '">';
	wp_nonce_field( 'dgm_save_site_settings' );
	echo '<input type="hidden" name="dgm_settings_action" value="site" />';
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'Demo mode', 'demo-growth-memberships' ) . '</th><td>';
	echo '<label><input type="checkbox" name="dgm_demo_mode" value="1" ' . checked( $demo_on, true, false ) . ' /> ';
	echo esc_html__( 'Enable demo mode on this site', 'demo-growth-memberships' ) . '</label></td></tr>';
	echo '<tr><th scope="row"><label for="dgm-demo-password">' . esc_html__( 'Demo password', 'demo-growth-memberships' ) . '</label></th><td>';
	echo '<input type="password" class="regular-text" id="dgm-demo-password" name="dgm_demo_password" value="" autocomplete="new-password" />';
	echo '<p class="description">';
	if ( $has_password ) {
		echo esc_html__( 'A password is saved for this site. Leave the field blank to keep it.', 'demo-growth-memberships' );
	} else {
		echo esc_html__( 'No password is saved for this site.', 'demo-growth-memberships' );
	}
	echo '</p>';
	echo '<label><input type="checkbox" name="dgm_clear_password" value="1" /> ' . esc_html__( 'Remove the saved password', 'demo-growth-memberships' ) . '</label>';
	echo '</td></tr>';
	echo '</tbody></table>';
	submit_button();
	echo '</form></div>';
}

/**
 * Network table of per-site demo settings.
 */
function dgm_render_network_settings_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ) );
	}

	$rows = dgm_network_settings_rows();

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Demo Growth Memberships', 'demo-growth-memberships' ) . '</h1>';
	dgm_render_settings_notice();
	echo '<p>' . esc_html__( 'Demo mode and the demo password are saved separately for each site.', 'demo-growth-memberships' ) . '</p>';

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No sites were found.', 'demo-growth-memberships' ) . '</p></div>';
		return;
	}

	echo '<form method="post" action="' . esc_url( network_admin_url( 'settings.php?page=dgm-settings' ) ) . '">';
	wp_nonce_field( 'dgm_save_network_settings' );
	echo '<input type="hidden" name="dgm_settings_action" value="network" />';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Site', 'demo-growth-memberships' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Demo mode', 'demo-growth-memberships' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Demo password', 'demo-growth-memberships' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$blog_id = (int) $row['id'];
		echo '<tr>';
		echo '<td><input type="hidden" name="dgm_site_ids[]" value="' . esc_attr( (string) $blog_id ) . '" />';
		echo '<strong>' . esc_html( $row['name'] ) . '</strong><br />';
		echo '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['url'] ) . '</a><br />';
		echo '<a href="' . esc_url( $row['edit_url'] ) . '">' . esc_html__( 'Edit on this site', 'demo-growth-memberships' ) . '</a></td>';
		echo '<td><label><input type="checkbox" name="dgm_demo_mode[' . esc_attr( (string) $blog_id ) . ']" value="1" ' . checked( ! empty( $row['demo'] ), true, false ) . ' /> ';
		echo esc_html__( 'Enabled', 'demo-growth-memberships' ) . '</label></td>';
		echo '<td>';
		echo '<input type="password" class="regular-text" name="dgm_demo_password[' . esc_attr( (string) $blog_id ) . ']" value="" autocomplete="new-password" />';
		echo '<p class="description">';
		echo ! empty( $row['has_password'] ) ? esc_html__( 'A password is saved. Leave blank to keep it.', 'demo-growth-memberships' ) : esc_html__( 'No password is saved.', 'demo-growth-memberships' );
		echo '</p>';
		echo '<label><input type="checkbox" name="dgm_clear_password[' . esc_attr( (string) $blog_id ) . ']" value="1" /> ' . esc_html__( 'Remove the saved password', 'demo-growth-memberships' ) . '</label>';
		echo '</td></tr>';
	}

	echo '</tbody></table>';
	if ( ! empty( $rows[0]['truncated'] ) ) {
		echo '<p class="description">' . esc_html__( 'Only the first 200 sites are listed here. Open Settings on any other site to change it.', 'demo-growth-memberships' ) . '</p>';
	}
	submit_button();
	echo '</form></div>';
}

/**
 * Sites to show on the network settings screen.
 *
 * @return array<int, array<string, mixed>>
 */
function dgm_network_settings_rows() {
	if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
		return array();
	}

	$sites = get_sites(
		array(
			'number'  => 201,
			'orderby' => 'id',
			'order'   => 'ASC',
		)
	);

	if ( ! is_array( $sites ) ) {
		return array();
	}

	$truncated = count( $sites ) > 200;
	if ( $truncated ) {
		$sites = array_slice( $sites, 0, 200 );
	}

	$rows = array();
	foreach ( $sites as $site ) {
		if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
			continue;
		}

		$blog_id  = (int) $site->blog_id;
		$mode     = get_blog_option( $blog_id, 'dgm_demo_mode', '0' );
		$password = get_blog_option( $blog_id, 'dgm_demo_password', '' );
		$name     = get_blog_option( $blog_id, 'blogname', '' );
		if ( ! is_string( $name ) || '' === $name ) {
			/* translators: %d: site ID. */
			$name = sprintf( __( 'Site %d', 'demo-growth-memberships' ), $blog_id );
		}

		$rows[] = array(
			'id'           => $blog_id,
			'name'         => $name,
			'url'          => (string) get_home_url( $blog_id ),
			'edit_url'     => (string) get_admin_url( $blog_id, 'options-general.php?page=dgm-settings' ),
			'demo'         => true === $mode || 1 === $mode || '1' === $mode,
			'has_password' => is_string( $password ) && '' !== $password,
			'truncated'    => $truncated,
		);
	}

	return $rows;
}
