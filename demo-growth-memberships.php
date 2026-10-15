<?php
/**
 * Plugin Name: Demo Growth Memberships
 * Plugin URI: https://github.com/scotthill04210/demo-growth-memberships
 * Description: Demo mode for a multisite membership site. Prefills a demo login, blocks outgoing email and orders, and can restore a per-site snapshot every night.
 * Version: 1.1.10
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: true
 * Text Domain: demo-growth-memberships
 * Update URI: https://github.com/scotthill04210/demo-growth-memberships
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGM_PLUGIN_FILE', __FILE__ );
define( 'DGM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$dgm_plugin_header = get_file_data(
	DGM_PLUGIN_FILE,
	array(
		'Version' => 'Version',
	),
	'plugin'
);

define(
	'DGM_VERSION',
	( isset( $dgm_plugin_header['Version'] ) && is_string( $dgm_plugin_header['Version'] ) && '' !== $dgm_plugin_header['Version'] )
		? $dgm_plugin_header['Version']
		: '0.0.0'
);

unset( $dgm_plugin_header );

require_once DGM_PLUGIN_DIR . 'includes/helpers.php';
require_once DGM_PLUGIN_DIR . 'includes/login.php';
require_once DGM_PLUGIN_DIR . 'includes/mail.php';
require_once DGM_PLUGIN_DIR . 'includes/orders.php';
require_once DGM_PLUGIN_DIR . 'includes/banner.php';
require_once DGM_PLUGIN_DIR . 'includes/roles.php';
require_once DGM_PLUGIN_DIR . 'includes/updates.php';
require_once DGM_PLUGIN_DIR . 'includes/snapshot-store.php';
require_once DGM_PLUGIN_DIR . 'includes/alerts.php';
require_once DGM_PLUGIN_DIR . 'includes/maintenance.php';
require_once DGM_PLUGIN_DIR . 'includes/sandbox.php';
require_once DGM_PLUGIN_DIR . 'includes/snapshot.php';
require_once DGM_PLUGIN_DIR . 'includes/schedule.php';
require_once DGM_PLUGIN_DIR . 'includes/cli.php';
require_once DGM_PLUGIN_DIR . 'includes/settings.php';

/**
 * Load translations, the GitHub updater, and demo behavior.
 */
function dgm_boot() {
	add_action( 'init', 'dgm_load_textdomain' );
	dgm_register_update_hooks();
	dgm_register_settings_hooks();
	dgm_register_login_hooks();
	dgm_register_mail_hooks();
	dgm_register_order_hooks();
	dgm_register_banner_hooks();
	dgm_register_role_hooks();
	dgm_register_maintenance_hooks();
	dgm_register_sandbox_hooks();
	dgm_register_schedule_hooks();
	dgm_register_cli();
}

dgm_boot();
