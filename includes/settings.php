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
	add_action( 'admin_post_dgm_save_snapshot', 'dgm_handle_save_snapshot' );
	add_action( 'wp_ajax_dgm_save_snapshot', 'dgm_ajax_save_snapshot' );
	add_action( 'admin_post_dgm_restore_now', 'dgm_handle_restore_now' );
	add_action( 'admin_post_dgm_test_alert', 'dgm_handle_test_alert' );
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
	$nightly_raw  = isset( $_POST['dgm_nightly_reset'] ) ? wp_unslash( $_POST['dgm_nightly_reset'] ) : '';
	$sandbox_raw  = isset( $_POST['dgm_sandbox_mode'] ) ? wp_unslash( $_POST['dgm_sandbox_mode'] ) : '';
	$emails_raw   = isset( $_POST['dgm_alert_emails'] ) ? wp_unslash( $_POST['dgm_alert_emails'] ) : '';
	$enabled      = is_string( $mode_raw ) && '1' === $mode_raw;
	$clear        = is_string( $clear_raw ) && '1' === $clear_raw;
	$nightly      = is_string( $nightly_raw ) && '1' === $nightly_raw;
	$sandbox      = is_string( $sandbox_raw ) && '1' === $sandbox_raw;

	dgm_update_site_settings( (int) get_current_blog_id(), $enabled, $password_raw, $clear );

	if ( $nightly && ! dgm_snapshot_meta( (int) get_current_blog_id() ) ) {
		$nightly = false;
		dgm_flash_notice( 'error', __( 'Save a snapshot before turning on the nightly reset.', 'demo-growth-memberships' ) );
	}
	update_option( 'dgm_nightly_reset', $nightly ? '1' : '0', true );
	update_option( 'dgm_sandbox_mode', $sandbox ? '1' : '0', true );

	$parsed = dgm_parse_email_list( is_string( $emails_raw ) ? $emails_raw : '' );
	$saved  = dgm_write_alert_recipients( (int) get_current_blog_id(), $parsed['valid'] );
	if ( is_wp_error( $saved ) ) {
		dgm_flash_notice( 'error', $saved->get_error_message() );
	} elseif ( $parsed['invalid'] ) {
		dgm_flash_notice( 'error', sprintf(
			/* translators: %s: invalid email addresses. */
			__( 'Some alert addresses were not saved: %s', 'demo-growth-memberships' ),
			implode( ', ', $parsed['invalid'] )
		) );
	}

	dgm_ensure_schedules();
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
function dgm_redirect_after_settings_save( $network, $args = array() ) {
	$url = $network ? network_admin_url( 'settings.php?page=dgm-settings' ) : admin_url( 'options-general.php?page=dgm-settings' );
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	$args['dgm-updated'] = '1';
	wp_safe_redirect( add_query_arg( $args, $url ) );
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

	$demo_on      = dgm_is_demo_mode();
	$has_password = '' !== dgm_demo_password();
	$meta         = dgm_snapshot_meta( (int) get_current_blog_id() );
	$emails       = get_option( 'dgm_alert_emails', array() );
	$email_text   = is_array( $emails ) ? implode( "\n", $emails ) : '';

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Demo Growth Memberships', 'demo-growth-memberships' ) . '</h1>';
	dgm_render_settings_notice();
	dgm_render_flash_notice();
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
	echo '<tr><th scope="row">' . esc_html__( 'Sandbox mode', 'demo-growth-memberships' ) . '</th><td>';
	echo '<label><input type="checkbox" name="dgm_sandbox_mode" value="1" ' . checked( dgm_is_sandbox_mode(), true, false ) . ' /> ';
	echo esc_html__( 'Let the demo client edit content, and block plugins, themes, user changes, and other settings', 'demo-growth-memberships' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Sandbox limits apply only while demo mode is on. They start on. They apply to the demo client, not to Administrator or super admin accounts.', 'demo-growth-memberships' ) . '</p></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Nightly reset', 'demo-growth-memberships' ) . '</th><td>';
	echo '<label><input type="checkbox" name="dgm_nightly_reset" value="1" ' . checked( dgm_nightly_reset_enabled(), true, false ) . disabled( ! $meta, true, false ) . ' /> ';
	echo esc_html__( 'Restore the snapshot every night at 3:00 AM America/Chicago', 'demo-growth-memberships' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'This stays off until a snapshot exists. Restore database now, below, runs that same restore immediately.', 'demo-growth-memberships' ) . '</p></td></tr>';
	echo '<tr><th scope="row"><label for="dgm-alert-emails">' . esc_html__( 'Failure alert emails', 'demo-growth-memberships' ) . '</label></th><td>';
	echo '<textarea class="large-text" rows="5" id="dgm-alert-emails" name="dgm_alert_emails">' . esc_textarea( $email_text ) . '</textarea>';
	echo '<p class="description">' . esc_html__( 'One address per line or separated by commas, up to 10. Invalid addresses are skipped. DGM_ALERT_EMAIL in wp-config.php is always added when an alert is sent.', 'demo-growth-memberships' ) . '</p></td></tr>';
	echo '</tbody></table>';
	submit_button();
	echo '</form>';

	echo '<h2>' . esc_html__( 'Snapshot', 'demo-growth-memberships' ) . '</h2>';
	$build = dgm_snapshot_build_state( (int) get_current_blog_id() );
	if ( $meta ) {
		$when = isset( $meta['created'] ) ? wp_date( 'M j, Y g:i a', (int) $meta['created'] ) : '';
		$size = isset( $meta['bytes'] ) && function_exists( 'size_format' ) ? size_format( (int) $meta['bytes'] ) : '';
		echo '<p id="dgm-snapshot-summary">' . esc_html( sprintf(
			/* translators: 1: date, 2: file size, 3: number of tables. */
			__( 'Snapshot from %1$s, %2$s, %3$d tables.', 'demo-growth-memberships' ),
			$when,
			$size,
			isset( $meta['tables'] ) ? (int) $meta['tables'] : 0
		) ) . '</p>';
	} else {
		echo '<p id="dgm-snapshot-summary">' . esc_html__( 'No snapshot yet.', 'demo-growth-memberships' ) . '</p>';
	}

	echo '<div id="dgm-snapshot-progress" hidden style="max-width:420px;margin:12px 0">';
	echo '<div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="background:#dcdcde;height:18px;border-radius:2px;overflow:hidden">';
	echo '<div id="dgm-snapshot-bar" style="background:#2271b1;height:100%;width:0"></div>';
	echo '</div>';
	echo '<p id="dgm-snapshot-status" style="margin:8px 0 0"></p>';
	echo '</div>';
	echo '<p><button type="button" class="button" id="dgm-snapshot-start">' . esc_html( $build ? __( 'Continue saving snapshot', 'demo-growth-memberships' ) : __( 'Save snapshot', 'demo-growth-memberships' ) ) . '</button></p>';
	echo '<noscript><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'dgm_save_snapshot' );
	echo '<input type="hidden" name="action" value="dgm_save_snapshot" />';
	submit_button( __( 'Save snapshot', 'demo-growth-memberships' ), 'secondary', 'dgm-save-snapshot-button', false );
	echo '</form></noscript>';
	$snapshot_script = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'dgm_save_snapshot' ),
		'resume'  => (bool) $build,
		'starting'=> __( 'Saving snapshot…', 'demo-growth-memberships' ),
		'again'   => __( 'Save snapshot', 'demo-growth-memberships' ),
		'error'   => __( 'The snapshot could not be saved.', 'demo-growth-memberships' ),
	);
	echo '<script>(function(){var config=' . dgm_json_for_script( $snapshot_script ) . ';var button=document.getElementById("dgm-snapshot-start");var panel=document.getElementById("dgm-snapshot-progress");var bar=document.getElementById("dgm-snapshot-bar");var status=document.getElementById("dgm-snapshot-status");var summary=document.getElementById("dgm-snapshot-summary");if(!button||!panel||!bar||!status||!window.fetch||!window.FormData){return;}var running=false;function paint(data){panel.hidden=false;var percent=typeof data.percent==="number"?data.percent:0;bar.style.width=String(percent)+"%";var track=bar.parentNode;if(track&&track.setAttribute){track.setAttribute("aria-valuenow",String(percent));}if(data.message){status.textContent=data.message;}if(data.summary&&summary){summary.textContent=data.summary;}if(!data.pending){bar.style.background="#00a32a";}}function fail(message){running=false;button.disabled=false;paint({percent:0,message:message,pending:true});}function step(){var body=new FormData();body.append("action","dgm_save_snapshot");body.append("nonce",config.nonce);window.fetch(config.ajaxUrl,{method:"POST",credentials:"same-origin",body:body}).then(function(response){return response.json();}).then(function(payload){if(!payload||!payload.success||!payload.data){fail(payload&&payload.data&&payload.data.message?payload.data.message:config.error);return;}paint(payload.data);if(payload.data.pending){window.setTimeout(step,payload.data.wait?2000:0);return;}running=false;button.disabled=false;button.textContent=config.again;var restore=document.getElementById("dgm-restore-now");if(restore){restore.disabled=false;}var hint=document.getElementById("dgm-snapshot-needed");if(hint&&hint.parentNode){hint.parentNode.removeChild(hint);}}).catch(function(){fail(config.error);});}function start(){if(running){return;}running=true;button.disabled=true;bar.style.background="#2271b1";paint({percent:0,message:config.starting,pending:true});step();}button.addEventListener("click",start);if(config.resume){start();}})();</script>';

	echo '<h2>' . esc_html__( 'Restore database now', 'demo-growth-memberships' ) . '</h2>';
	echo '<p>' . esc_html__( 'This runs the nightly database restore immediately. It does not wait for 3:00 AM, and nightly reset can stay off. Demo mode must be on. Changes made since the snapshot are replaced.', 'demo-growth-memberships' ) . '</p>';
	$confirm = __( 'Restore this site from the snapshot now? Changes since the snapshot will be replaced.', 'demo-growth-memberships' );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( dgm_json_for_script( $confirm ) ) . ');">';
	wp_nonce_field( 'dgm_restore_now' );
	echo '<input type="hidden" name="action" value="dgm_restore_now" />';
	submit_button( __( 'Restore database now', 'demo-growth-memberships' ), 'delete', 'submit', false, $meta ? 'id="dgm-restore-now"' : 'disabled id="dgm-restore-now"' );
	if ( ! $meta ) {
		echo '<p class="description" id="dgm-snapshot-needed">' . esc_html__( 'Save a snapshot before restoring.', 'demo-growth-memberships' ) . '</p>';
	}
	echo '</form>';

	echo '<h2>' . esc_html__( 'Test alert', 'demo-growth-memberships' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'dgm_test_alert' );
	echo '<input type="hidden" name="action" value="dgm_test_alert" />';
	echo '<p class="description">' . esc_html__( 'Sends to the saved list. Save the addresses above first.', 'demo-growth-memberships' ) . '</p>';
	submit_button( __( 'Send test alert', 'demo-growth-memberships' ), 'secondary', 'submit', false );
	echo '</form>';

	dgm_render_reset_log();
	echo '</div>';
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

/**
 * Store a one-time admin message for the current user.
 *
 * @param string $type    success|error.
 * @param string $message Message.
 */
function dgm_flash_notice( $type, $message ) {
	$key      = 'dgm_flash_' . get_current_user_id();
	$existing = get_transient( $key );
	if ( is_array( $existing ) && ! empty( $existing['message'] ) && is_string( $existing['message'] ) ) {
		$message = $existing['message'] . ' ' . $message;
		if ( isset( $existing['type'] ) && 'error' === $existing['type'] ) {
			$type = 'error';
		}
	}

	set_transient(
		$key,
		array(
			'type'    => 'error' === $type ? 'error' : 'success',
			'message' => (string) $message,
		),
		MINUTE_IN_SECONDS
	);
}

/**
 * Print and clear the one-time admin message.
 */
function dgm_render_flash_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$key   = 'dgm_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( ! is_array( $flash ) || empty( $flash['message'] ) || ! is_string( $flash['message'] ) ) {
		return;
	}

	delete_transient( $key );
	$class = isset( $flash['type'] ) && 'error' === $flash['type'] ? 'notice-error' : 'notice-success';
	echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $flash['message'] ) . '</p></div>';
}

/**
 * One step of a snapshot save, shaped for the progress bar.
 *
 * @param array|WP_Error $result Snapshot step result.
 * @return array|WP_Error
 */
function dgm_snapshot_step_payload( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot could not be saved.', 'demo-growth-memberships' ) );
	}

	if ( ! empty( $result['pending'] ) ) {
		$done    = isset( $result['done'] ) ? (int) $result['done'] : 0;
		$total   = isset( $result['total'] ) ? (int) $result['total'] : 0;
		$percent = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0;
		if ( $percent > 99 ) {
			$percent = 99;
		}

		return array(
			'pending' => true,
			'done'    => $done,
			'total'   => $total,
			'percent' => $percent,
			'message' => sprintf(
				/* translators: 1: tables finished, 2: total tables. */
				__( 'Saving snapshot: %1$d of %2$d tables.', 'demo-growth-memberships' ),
				$done,
				$total
			),
		);
	}

	$created = isset( $result['created'] ) ? (int) $result['created'] : time();
	$when    = wp_date( 'M j, Y g:i a', $created );
	$bytes   = isset( $result['bytes'] ) ? (int) $result['bytes'] : 0;
	$size    = function_exists( 'size_format' ) ? size_format( $bytes ) : (string) $bytes;
	$tables  = isset( $result['tables'] ) ? (int) $result['tables'] : 0;

	return array(
		'pending' => false,
		'percent' => 100,
		'message' => sprintf(
			/* translators: %s: date and time. */
			__( 'Snapshot saved. %s.', 'demo-growth-memberships' ),
			$when
		),
		'summary' => sprintf(
			/* translators: 1: date, 2: file size, 3: number of tables. */
			__( 'Snapshot from %1$s, %2$s, %3$d tables.', 'demo-growth-memberships' ),
			$when,
			$size,
			$tables
		),
	);
}

/**
 * Whether this site can start a snapshot step right now.
 *
 * @return bool
 */
function dgm_snapshot_claim_step() {
	$key = 'dgm_snapshot_step_' . (int) get_current_blog_id();
	if ( get_transient( $key ) ) {
		return false;
	}

	set_transient( $key, '1', 30 );

	return true;
}

/**
 * Allow the next snapshot step to start.
 */
function dgm_snapshot_release_step() {
	delete_transient( 'dgm_snapshot_step_' . (int) get_current_blog_id() );
}

/**
 * Save the next piece of a snapshot and return progress as JSON.
 */
function dgm_ajax_save_snapshot() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ),
			),
			403
		);
	}

	check_ajax_referer( 'dgm_save_snapshot', 'nonce' );
	if ( ! dgm_snapshot_claim_step() ) {
		$state = dgm_snapshot_build_state( (int) get_current_blog_id() );
		$done  = is_array( $state ) && isset( $state['index'] ) ? (int) $state['index'] : 0;
		$total = is_array( $state ) && isset( $state['tables'] ) && is_array( $state['tables'] ) ? count( $state['tables'] ) : 0;
		wp_send_json_success(
			array(
				'pending' => true,
				'wait'    => true,
				'done'    => $done,
				'total'   => $total,
				'percent' => $total > 0 ? min( 99, (int) floor( ( $done / $total ) * 100 ) ) : 0,
				'message' => __( 'Saving snapshot…', 'demo-growth-memberships' ),
			)
		);
	}

	$payload = dgm_snapshot_step_payload( dgm_create_snapshot( array( 'budget' => 20 ) ) );
	dgm_snapshot_release_step();
	if ( is_wp_error( $payload ) ) {
		wp_send_json_error(
			array(
				'message' => $payload->get_error_message(),
			)
		);
	}

	wp_send_json_success( $payload );
}

/**
 * Save a snapshot from the settings screen.
 */
function dgm_handle_save_snapshot() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'dgm_save_snapshot' );
	$result = dgm_create_snapshot( array( 'budget' => 20 ) );
	if ( is_wp_error( $result ) ) {
		dgm_flash_notice( 'error', $result->get_error_message() );
		dgm_redirect_after_settings_save( false );
	}

	if ( is_array( $result ) && ! empty( $result['pending'] ) ) {
		dgm_flash_notice(
			'success',
			sprintf(
				/* translators: 1: tables finished, 2: total tables. */
				__( 'Saving snapshot: %1$d of %2$d tables.', 'demo-growth-memberships' ),
				isset( $result['done'] ) ? (int) $result['done'] : 0,
				isset( $result['total'] ) ? (int) $result['total'] : 0
			)
		);
		dgm_redirect_after_settings_save( false, array( 'dgm-snapshot-continue' => '1' ) );
	}

	$created = is_array( $result ) && isset( $result['created'] ) ? (int) $result['created'] : time();
	dgm_flash_notice(
		'success',
		sprintf(
			/* translators: %s: date and time. */
			__( 'Snapshot saved. %s.', 'demo-growth-memberships' ),
			wp_date( 'M j, Y g:i a', $created )
		)
	);
	dgm_redirect_after_settings_save( false );
}

/**
 * Restore the current site from the settings screen.
 */
function dgm_handle_restore_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'dgm_restore_now' );
	$result = dgm_restore_site(
		array(
			'manual'   => true,
			'override' => true,
		)
	);
	if ( is_wp_error( $result ) ) {
		dgm_flash_notice( 'error', $result->get_error_message() );
	} else {
		dgm_flash_notice( 'success', __( 'Restore finished.', 'demo-growth-memberships' ) );
	}

	dgm_redirect_after_settings_save( false );
}

/**
 * Send a test alert from the settings screen.
 */
function dgm_handle_test_alert() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'demo-growth-memberships' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'dgm_test_alert' );
	$result = dgm_send_test_alert();
	if ( is_wp_error( $result ) ) {
		dgm_flash_notice( 'error', $result->get_error_message() );
		dgm_redirect_after_settings_save( false );
	}

	$lines = array();
	foreach ( $result['results'] as $row ) {
		if ( empty( $row['email'] ) ) {
			$lines[] = $row['error'];
			continue;
		}
		$lines[] = $row['email'] . ': ' . ( ! empty( $row['ok'] ) ? __( 'sent', 'demo-growth-memberships' ) : __( 'failed', 'demo-growth-memberships' ) );
	}

	dgm_flash_notice( ! empty( $result['ok'] ) ? 'success' : 'error', implode( ' ', $lines ) );
	dgm_redirect_after_settings_save( false );
}

/**
 * Last 10 resets for this site.
 */
function dgm_render_reset_log() {
	$log = get_option( 'dgm_reset_log', array() );
	echo '<h2>' . esc_html__( 'Recent resets', 'demo-growth-memberships' ) . '</h2>';
	if ( ! is_array( $log ) || ! $log ) {
		echo '<p>' . esc_html__( 'No resets yet.', 'demo-growth-memberships' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Time', 'demo-growth-memberships' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Duration', 'demo-growth-memberships' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Result', 'demo-growth-memberships' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Error', 'demo-growth-memberships' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( array_slice( $log, 0, 10 ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$time   = isset( $row['time'] ) ? wp_date( 'M j, Y g:i a', (int) $row['time'] ) : '';
		$ok     = isset( $row['result'] ) && 'success' === $row['result'];
		$result = $ok ? __( 'Success', 'demo-growth-memberships' ) : __( 'Failure', 'demo-growth-memberships' );
		if ( $ok && ! empty( $row['files'] ) ) {
			$result .= ' ' . sprintf(
				/* translators: %d: number of files. */
				__( '(%d files removed)', 'demo-growth-memberships' ),
				(int) $row['files']
			);
		}
		echo '<tr><td>' . esc_html( $time ) . '</td>';
		echo '<td>' . esc_html( isset( $row['duration'] ) ? (string) $row['duration'] . 's' : '' ) . '</td>';
		echo '<td>' . esc_html( $result ) . '</td>';
		echo '<td>' . esc_html( isset( $row['error'] ) && is_string( $row['error'] ) ? $row['error'] : '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}
