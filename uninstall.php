<?php
/**
 * Remove plugin options. The snapshot folder is left in place.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$blog_ids = array( (int) get_current_blog_id() );
if ( is_multisite() && ! empty( $wpdb->blogs ) ) {
	$found = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	if ( is_array( $found ) && $found ) {
		$blog_ids = $found;
	}
}

foreach ( $blog_ids as $blog_id ) {
	$blog_id  = (int) $blog_id;
	$switched = false;
	if ( is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$like = $wpdb->esc_like( 'dgm_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	wp_clear_scheduled_hook( 'dgm_primary_restore' );
	wp_clear_scheduled_hook( 'dgm_fallback_restore' );
	wp_clear_scheduled_hook( 'dgm_watchdog_event' );
	wp_clear_scheduled_hook( 'dgm_restore_retry' );

	if ( $switched ) {
		restore_current_blog();
	}
}
