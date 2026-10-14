<?php
/**
 * GitHub updates and install-folder safety net.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the updater and the folder rename.
 *
 * The updater stays active when a site turns demo mode off, so Network Admin
 * can still update the plugin.
 */
function dgm_register_update_hooks() {
	dgm_boot_updater();
	add_filter( 'upgrader_source_selection', 'dgm_upgrader_source_selection', 1, 4 );
	add_filter( 'puc_manual_check_link-demo-growth-memberships', 'dgm_hide_duplicate_update_link' );
}

/**
 * Configure Plugin Update Checker against the public GitHub repository.
 *
 * setBranch( 'main' ) is the fallback. For main/master, the library checks the
 * latest GitHub release first, then tags, and only then the branch.
 * enableReleaseAssets() makes that release use the uploaded zip instead of
 * GitHub's generated source archive.
 */
function dgm_boot_updater() {
	$library = DGM_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $library ) ) {
		return;
	}

	require_once $library;

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/scotthill04210/demo-growth-memberships/',
		DGM_PLUGIN_FILE,
		'demo-growth-memberships'
	);

	if ( ! is_object( $checker ) || ! is_callable( array( $checker, 'setBranch' ) ) ) {
		return;
	}

	$checker->setBranch( 'main' );

	if ( property_exists( $checker, 'debugMode' ) ) {
		$checker->debugMode = false;
	}

	if ( ! is_callable( array( $checker, 'getVcsApi' ) ) ) {
		return;
	}

	$api = $checker->getVcsApi();
	if ( is_object( $api ) && is_callable( array( $api, 'enableReleaseAssets' ) ) ) {
		$api->enableReleaseAssets( '/^demo-growth-memberships\.zip$/' );
	}
}

/**
 * Rename this plugin's unpacked update folder to demo-growth-memberships.
 *
 * GitHub source archives use names like scotthill04210-demo-growth-memberships-abc123
 * or demo-growth-memberships-1.0.1. WordPress uses that folder name as the plugin
 * directory. Other plugins are left untouched. Failures return the original path.
 *
 * @param mixed $source        Unpacked package directory.
 * @param mixed $remote_source Directory that contains the unpacked package.
 * @param mixed $upgrader      Upgrader instance.
 * @param mixed $hook_extra    Extra upgrade arguments.
 * @return mixed
 */
function dgm_upgrader_source_selection( $source = null, $remote_source = null, $upgrader = null, $hook_extra = null ) {
	try {
		return dgm_maybe_rename_plugin_source( $source, $remote_source, $upgrader, $hook_extra );
	} catch ( Throwable $throwable ) {
		unset( $throwable );
		return $source;
	}
}

/**
 * Rename logic for dgm_upgrader_source_selection().
 *
 * @param mixed $source        Unpacked package directory.
 * @param mixed $remote_source Directory that contains the unpacked package.
 * @param mixed $upgrader      Upgrader instance.
 * @param mixed $hook_extra    Extra upgrade arguments.
 * @return mixed
 */
function dgm_maybe_rename_plugin_source( $source, $remote_source, $upgrader, $hook_extra = null ) {
	if ( is_wp_error( $source ) || ! is_string( $source ) || '' === $source ) {
		return $source;
	}

	if ( ! is_array( $hook_extra ) ) {
		$hook_extra = array();
	}

	$plugin_file = dgm_upgrader_plugin_file( $hook_extra, $upgrader );
	if ( '' !== $plugin_file && ! dgm_is_our_plugin_file( $plugin_file ) ) {
		return $source;
	}

	if ( isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) && '' !== $hook_extra['theme'] ) {
		return $source;
	}

	if ( isset( $hook_extra['type'] ) && is_string( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) {
		return $source;
	}

	$is_ours = '' !== $plugin_file || dgm_source_contains_this_plugin( $source );
	if ( ! $is_ours ) {
		return $source;
	}

	$from = untrailingslashit( $source );
	if ( '' === $from ) {
		return $source;
	}

	if ( 'demo-growth-memberships' === basename( untrailingslashit( wp_normalize_path( $from ) ) ) ) {
		return $source;
	}

	$parent = '';
	if ( is_string( $remote_source ) && '' !== $remote_source ) {
		$parent = untrailingslashit( $remote_source );
	}
	if ( '' === $parent ) {
		$parent = dirname( $from );
	}

	$to                = untrailingslashit( trailingslashit( $parent ) . 'demo-growth-memberships' );
	$normalized_from   = untrailingslashit( wp_normalize_path( $from ) );
	$normalized_parent = untrailingslashit( wp_normalize_path( $parent ) );
	$normalized_to     = untrailingslashit( wp_normalize_path( $to ) );

	// A flat archive has no inner folder. Renaming the working directory would
	// break WordPress cleanup, so leave it alone.
	if ( $normalized_from === $normalized_parent ) {
		return $source;
	}

	if ( $normalized_from === $normalized_to ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! is_object( $wp_filesystem ) || ! is_callable( array( $wp_filesystem, 'move' ) ) ) {
		return $source;
	}

	$moved = $wp_filesystem->move( $from, $to, true );
	if ( is_wp_error( $moved ) || ! $moved ) {
		return $source;
	}

	return trailingslashit( $to );
}

/**
 * Plugin basename from the upgrade request, when WordPress provides one.
 *
 * @param array $hook_extra Extra upgrade arguments.
 * @param mixed $upgrader   Upgrader instance.
 * @return string
 */
function dgm_upgrader_plugin_file( $hook_extra, $upgrader ) {
	if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
		return $hook_extra['plugin'];
	}

	if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && isset( $upgrader->skin->plugin ) && is_string( $upgrader->skin->plugin ) ) {
		return $upgrader->skin->plugin;
	}

	return '';
}

/**
 * Whether a plugin basename refers to this plugin.
 *
 * @param string $plugin Plugin file relative to the plugins directory.
 * @return bool
 */
function dgm_is_our_plugin_file( $plugin ) {
	if ( ! is_string( $plugin ) || '' === $plugin ) {
		return false;
	}

	$plugin = wp_normalize_path( $plugin );
	$ours   = wp_normalize_path( plugin_basename( DGM_PLUGIN_FILE ) );

	return $plugin === $ours;
}

/**
 * Whether an unpacked directory is this plugin, based on its main file header.
 *
 * @param string $source Unpacked directory.
 * @return bool
 */
function dgm_source_contains_this_plugin( $source ) {
	if ( ! is_string( $source ) || '' === $source || ! function_exists( 'get_file_data' ) ) {
		return false;
	}

	$main = trailingslashit( $source ) . 'demo-growth-memberships.php';
	if ( ! is_readable( $main ) ) {
		return false;
	}

	$headers = get_file_data(
		$main,
		array(
			'Name'       => 'Plugin Name',
			'TextDomain' => 'Text Domain',
		),
		'plugin'
	);

	$name   = isset( $headers['Name'] ) && is_string( $headers['Name'] ) ? $headers['Name'] : '';
	$domain = isset( $headers['TextDomain'] ) && is_string( $headers['TextDomain'] ) ? $headers['TextDomain'] : '';

	return 'Demo Growth Memberships' === $name && 'demo-growth-memberships' === $domain;
}
