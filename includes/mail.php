<?php
/**
 * Block outgoing mail.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the mail block.
 */
function dgm_register_mail_hooks() {
	add_filter( 'pre_wp_mail', 'dgm_block_outgoing_mail', PHP_INT_MAX, 1 );
}

/**
 * Short-circuit wp_mail(). A non-null value stops delivery.
 *
 * Replaces the mu-plugin alliance360-block-mail.php.
 *
 * @param mixed $short_circuit Previous short-circuit value.
 * @return mixed False when this site is in demo mode.
 */
function dgm_block_outgoing_mail( $short_circuit = null ) {
	if ( ! dgm_is_demo_mode() ) {
		return $short_circuit;
	}

	return false;
}
