<?php
/**
 * Nightly restore schedule and the watchdog.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register cron handlers.
 */
function dgm_register_schedule_hooks() {
	add_action( 'init', 'dgm_ensure_schedules' );
	add_action( 'dgm_primary_restore', 'dgm_run_primary_restore' );
	add_action( 'dgm_fallback_restore', 'dgm_run_fallback_restore' );
	add_action( 'dgm_watchdog_event', 'dgm_run_watchdog_event' );
	add_action( 'dgm_restore_retry', 'dgm_run_restore_retry' );
	add_action( 'admin_notices', 'dgm_last_reset_admin_notice' );
}

/**
 * Next wall-clock hour in America/Chicago.
 *
 * @param int $hour Hour from 0 through 23.
 * @return int Unix timestamp.
 */
function dgm_next_chicago_hour( $hour ) {
	$hour = max( 0, min( 23, (int) $hour ) );
	try {
		$zone = new DateTimeZone( 'America/Chicago' );
		$now  = new DateTime( 'now', $zone );
		$next = new DateTime( 'today ' . $hour . ':00:00', $zone );
		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	} catch ( Exception $exception ) {
		unset( $exception );
		$target = strtotime( 'today ' . $hour . ':00:00 UTC' );
		if ( ! $target || $target <= time() ) {
			$target = strtotime( 'tomorrow ' . $hour . ':00:00 UTC' );
		}

		return (int) $target;
	}
}

/**
 * Keep the 3:00 AM restore, the hourly fallback, and the 9:00 AM watchdog scheduled.
 */
function dgm_ensure_schedules() {
	if ( ! dgm_is_demo_mode() || ! dgm_nightly_reset_enabled() ) {
		wp_clear_scheduled_hook( 'dgm_primary_restore' );
		wp_clear_scheduled_hook( 'dgm_fallback_restore' );
		wp_clear_scheduled_hook( 'dgm_watchdog_event' );
		return;
	}

	if ( ! wp_next_scheduled( 'dgm_primary_restore' ) ) {
		wp_schedule_single_event( dgm_next_chicago_hour( 3 ), 'dgm_primary_restore' );
	}
	if ( ! wp_next_scheduled( 'dgm_fallback_restore' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dgm_fallback_restore' );
	}
	if ( ! wp_next_scheduled( 'dgm_watchdog_event' ) ) {
		wp_schedule_single_event( dgm_next_chicago_hour( 9 ), 'dgm_watchdog_event' );
	}
}

/**
 * 3:00 AM America/Chicago restore used when the host runs WP-Cron.
 */
function dgm_run_primary_restore() {
	dgm_restore_site(
		array(
			'scheduled' => true,
		)
	);
	wp_schedule_single_event( dgm_next_chicago_hour( 3 ), 'dgm_primary_restore' );
}

/**
 * Restore only when the 3:00 AM run has not succeeded in 26 hours.
 */
function dgm_run_fallback_restore() {
	if ( ! dgm_fallback_should_restore() ) {
		return;
	}

	dgm_restore_site(
		array(
			'scheduled' => true,
			'fallback'  => true,
		)
	);
}

/**
 * Whether the hourly fallback should restore this site.
 *
 * @return bool
 */
function dgm_fallback_should_restore() {
	if ( ! dgm_is_demo_mode() || ! dgm_nightly_reset_enabled() || ! dgm_snapshot_meta( (int) get_current_blog_id() ) ) {
		return false;
	}

	$last = (int) get_option( 'dgm_last_success', 0 );
	if ( $last > 0 ) {
		return ( time() - $last ) > 26 * HOUR_IN_SECONDS;
	}

	$next = wp_next_scheduled( 'dgm_primary_restore' );

	return ! $next || $next <= time();
}

/**
 * Daily watchdog.
 */
function dgm_run_watchdog_event() {
	dgm_run_watchdog();
	wp_schedule_single_event( dgm_next_chicago_hour( 9 ), 'dgm_watchdog_event' );
}

/**
 * Retry a failed restore once.
 *
 * @param int $blog_id Site ID.
 */
function dgm_run_restore_retry( $blog_id = 0 ) {
	$blog_id  = (int) $blog_id;
	$switched = false;
	if ( $blog_id > 0 && is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		dgm_restore_site(
			array(
				'retry'  => true,
				'manual' => true,
			)
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Check snapshot age and whether the nightly restore has ever completed.
 *
 * @return true|WP_Error
 */
function dgm_run_watchdog() {
	$problems = array();
	$blog_id  = (int) get_current_blog_id();
	if ( ! dgm_snapshot_meta( $blog_id ) ) {
		$problems[] = 'no_snapshot';
	}

	$last = (int) get_option( 'dgm_last_success', 0 );
	if ( dgm_snapshot_meta( $blog_id ) && ( $last <= 0 || ( time() - $last ) > 26 * HOUR_IN_SECONDS ) ) {
		$problems[] = 'stale';
	}

	if ( dgm_nightly_reset_enabled() ) {
		$log = get_option( 'dgm_reset_log', array() );
		if ( ! is_array( $log ) || ! $log ) {
			$problems[] = 'never_ran';
		}
	}

	if ( ! $problems ) {
		update_option( 'dgm_watchdog_incident', '', false );
		dgm_progress( 'Watchdog: no problems.' );
		return true;
	}

	$key    = implode( ',', $problems );
	$before = (string) get_option( 'dgm_watchdog_incident', '' );
	$sent   = dgm_send_watchdog_alert( $problems );
	dgm_progress( 'Watchdog problems: ' . $key );
	if ( $before !== $key && ! $sent ) {
		return new WP_Error( 'dgm_watchdog', __( 'The watchdog alert could not be sent.', 'demo-growth-memberships' ) );
	}

	return true;
}

/**
 * Admin notice for the latest reset on this site.
 */
function dgm_last_reset_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$log = get_option( 'dgm_reset_log', array() );
	if ( ! is_array( $log ) || empty( $log[0] ) || ! is_array( $log[0] ) ) {
		return;
	}

	$row  = $log[0];
	$time = isset( $row['time'] ) ? (int) $row['time'] : 0;
	$ok   = isset( $row['result'] ) && 'success' === $row['result'];
	$when = $time > 0 ? wp_date( 'M j, Y g:i a', $time ) : __( 'unknown time', 'demo-growth-memberships' );
	$text = $ok
		? sprintf(
			/* translators: %s: date and time. */
			__( 'Last demo reset succeeded at %s.', 'demo-growth-memberships' ),
			$when
		)
		: sprintf(
			/* translators: 1: date and time, 2: error message. */
			__( 'Last demo reset failed at %1$s. %2$s', 'demo-growth-memberships' ),
			$when,
			isset( $row['error'] ) && is_string( $row['error'] ) ? $row['error'] : ''
		);

	echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . esc_html( $text ) . '</p></div>';
}
