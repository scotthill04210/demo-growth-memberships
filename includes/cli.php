<?php
/**
 * WP-CLI commands.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register wp dgm snapshot, wp dgm restore, and wp dgm watchdog.
 */
function dgm_register_cli() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
		return;
	}

	WP_CLI::add_command( 'dgm', 'DGM_CLI_Command' );
}

/**
 * Snapshot and restore commands for one site.
 */
class DGM_CLI_Command {
	/**
	 * Save a snapshot of this site.
	 *
	 *     wp dgm snapshot --url=<site>
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function snapshot( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$result = dgm_create_snapshot();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Snapshot saved (' . (int) $result['tables'] . ' tables).' );
	}

	/**
	 * Restore this site from its snapshot.
	 *
	 *     wp dgm restore --url=<site> [--dry-run] [--scheduled]
	 *
	 * --scheduled is for cron. It does nothing when nightly reset or demo mode is off.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function restore( $args = array(), $assoc_args = array() ) {
		unset( $args );
		$assoc_args = is_array( $assoc_args ) ? $assoc_args : array();
		$scheduled  = isset( $assoc_args['scheduled'] );
		$result     = dgm_restore_site(
			array(
				'dry_run'   => isset( $assoc_args['dry-run'] ),
				'scheduled' => $scheduled,
				'manual'    => ! $scheduled,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( isset( $assoc_args['dry-run'] ) ? 'Dry run finished.' : 'Finished.' );
	}

	/**
	 * Email the alert recipients if the nightly restore is stale.
	 *
	 *     wp dgm watchdog --url=<site>
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function watchdog( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$result = dgm_run_watchdog();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Watchdog finished.' );
	}
}
