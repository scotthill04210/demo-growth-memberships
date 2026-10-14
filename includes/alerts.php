<?php
/**
 * Failure and recovery alerts.
 *
 * Recipients are read from the snapshot directory at send time, not from the database.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a textarea of alert addresses.
 *
 * @param string $raw Raw textarea.
 * @return array{valid: string[], invalid: string[]}
 */
function dgm_parse_email_list( $raw ) {
	$raw   = str_replace( array( "\r\n", "\r", "\n", "\t", ';' ), ',', (string) $raw );
	$valid = array();
	$invalid = array();

	foreach ( explode( ',', $raw ) as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}

		$clean = sanitize_email( $part );
		if ( is_string( $clean ) && is_email( $clean ) ) {
			$valid[] = strtolower( $clean );
		} else {
			$invalid[] = $part;
		}
	}

	$valid = array_values( array_unique( $valid ) );
	if ( count( $valid ) > 10 ) {
		$invalid = array_merge( $invalid, array_slice( $valid, 10 ) );
		$valid   = array_slice( $valid, 0, 10 );
	}

	return array(
		'valid'   => $valid,
		'invalid' => $invalid,
	);
}

/**
 * Path to the recipient file for a site.
 *
 * @param int $blog_id Site ID.
 * @return string
 */
function dgm_alert_recipient_path( $blog_id ) {
	return dgm_snapshot_dir( $blog_id ) . '/alert-recipients.json';
}

/**
 * Save the sanitized recipient list beside the snapshot.
 *
 * @param int      $blog_id Site ID.
 * @param string[] $emails  Valid addresses.
 * @return true|WP_Error
 */
function dgm_write_alert_recipients( $blog_id, $emails ) {
	$ready = dgm_ensure_snapshot_dir( $blog_id );
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$clean = array();
	foreach ( $emails as $email ) {
		if ( is_string( $email ) && is_email( $email ) ) {
			$clean[] = strtolower( $email );
		}
	}
	$clean = array_slice( array_values( array_unique( $clean ) ), 0, 10 );

	if ( ! dgm_write_json_file( dgm_alert_recipient_path( $blog_id ), array( 'emails' => $clean ) ) ) {
		return new WP_Error( 'dgm_alert_file', __( 'Could not save the alert recipient file.', 'demo-growth-memberships' ) );
	}

	update_option( 'dgm_alert_emails', $clean, false );

	return true;
}

/**
 * Addresses saved in the protected recipient file.
 *
 * @param int $blog_id Site ID.
 * @return string[]
 */
function dgm_file_alert_recipients( $blog_id ) {
	$data = dgm_read_json_file( dgm_alert_recipient_path( $blog_id ) );
	if ( ! is_array( $data ) || ! isset( $data['emails'] ) || ! is_array( $data['emails'] ) ) {
		return array();
	}

	$emails = array();
	foreach ( $data['emails'] as $email ) {
		if ( is_string( $email ) && is_email( $email ) ) {
			$emails[] = strtolower( $email );
		}
	}

	return array_values( array_unique( $emails ) );
}

/**
 * Addresses from the optional wp-config constant.
 *
 * @return string[]
 */
function dgm_constant_alert_recipients() {
	if ( ! defined( 'DGM_ALERT_EMAIL' ) || ! is_string( DGM_ALERT_EMAIL ) ) {
		return array();
	}

	return dgm_parse_email_list( DGM_ALERT_EMAIL )['valid'];
}

/**
 * Final recipient list. The database is used only when the file and constant are empty.
 *
 * @param int $blog_id Site ID.
 * @return string[]
 */
function dgm_alert_recipients( $blog_id ) {
	$emails = array_merge( dgm_file_alert_recipients( $blog_id ), dgm_constant_alert_recipients() );
	$emails = array_values( array_unique( $emails ) );

	if ( $emails ) {
		return array_slice( $emails, 0, 20 );
	}

	$admin = sanitize_email( (string) get_option( 'admin_email' ) );
	if ( is_email( $admin ) ) {
		return array( strtolower( $admin ) );
	}

	return array();
}

/**
 * Send one message with every recipient in Bcc.
 *
 * @param string   $subject Subject.
 * @param string   $body    Plain text body.
 * @param string[] $emails  Validated recipients.
 * @return array{ok: bool, results: array<int, array{email: string, ok: bool, error: string}>}
 */
function dgm_send_bcc_mail( $subject, $body, $emails ) {
	$emails  = array_values( array_unique( array_map( 'strtolower', $emails ) ) );
	$results = array();
	if ( ! $emails ) {
		return array(
			'ok'      => false,
			'results' => array(
				array(
					'email' => '',
					'ok'    => false,
					'error' => __( 'No alert recipients are configured.', 'demo-growth-memberships' ),
				),
			),
		);
	}

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	foreach ( $emails as $email ) {
		$headers[] = 'Bcc: ' . $email;
	}

	DGM_Mail_Gate::allow( $emails );
	$sent  = false;
	$error = '';
	try {
		$sent = wp_mail( $emails[0], $subject, $body, $headers );
		if ( ! $sent ) {
			global $phpmailer;
			if ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) {
				$error = (string) $phpmailer->ErrorInfo;
			} else {
				$error = __( 'The alert email was not accepted.', 'demo-growth-memberships' );
			}
		}
	} finally {
		DGM_Mail_Gate::clear();
	}

	foreach ( $emails as $email ) {
		$results[] = array(
			'email' => $email,
			'ok'    => (bool) $sent,
			'error' => $sent ? '' : $error,
		);
	}

	return array(
		'ok'      => (bool) $sent,
		'results' => $results,
	);
}

/**
 * Email a restore failure. One message per incident.
 *
 * @param int    $blog_id  Site ID.
 * @param string $error    Error message.
 * @param bool   $rolled   Whether the failsafe restore worked.
 * @param int    $snapshot Snapshot timestamp.
 * @return bool Whether an email was sent.
 */
function dgm_send_failure_alert( $blog_id, $error, $rolled, $snapshot ) {
	if ( 'alerted' === get_option( 'dgm_alert_state', '' ) ) {
		dgm_log_line( $blog_id, 'Failure alert already sent for this incident.' );
		return false;
	}

	$emails = dgm_alert_recipients( $blog_id );
	$body   = dgm_alert_body( $blog_id, $error, $rolled, $snapshot );
	$result = dgm_send_bcc_mail( sprintf( '[%s] Demo restore failed', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ), $body, $emails );
	dgm_log_alert_result( $blog_id, 'failure alert', $result );
	if ( $result['ok'] ) {
		update_option( 'dgm_alert_state', 'alerted', false );
	}

	return (bool) $result['ok'];
}

/**
 * Email once when the next restore succeeds after a failure alert.
 *
 * @param int $blog_id  Site ID.
 * @param int $snapshot Snapshot timestamp.
 */
function dgm_send_recovery_alert( $blog_id, $snapshot ) {
	if ( 'alerted' !== get_option( 'dgm_alert_state', '' ) ) {
		update_option( 'dgm_alert_state', 'ok', false );
		return;
	}

	$when   = $snapshot > 0 ? gmdate( 'c', $snapshot ) : __( 'unknown', 'demo-growth-memberships' );
	$body   = "The demo restore succeeded.\r\n\r\n";
	$body  .= 'Site: ' . wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) . "\r\n";
	$body  .= 'URL: ' . home_url( '/' ) . "\r\n";
	$body  .= 'Time: ' . gmdate( 'c' ) . "\r\n";
	$body  .= 'Snapshot: ' . $when . "\r\n";
	$result = dgm_send_bcc_mail( sprintf( '[%s] Demo restore recovered', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ), $body, dgm_alert_recipients( $blog_id ) );
	dgm_log_alert_result( $blog_id, 'recovery alert', $result );
	update_option( 'dgm_alert_state', 'ok', false );
}

/**
 * Build the failure message. The log path is included for the site admins who receive the mail.
 *
 * @param int    $blog_id  Site ID.
 * @param string $error    Error message.
 * @param bool   $rolled   Rollback result.
 * @param int    $snapshot Snapshot timestamp.
 * @return string
 */
function dgm_alert_body( $blog_id, $error, $rolled, $snapshot ) {
	$when = $snapshot > 0 ? gmdate( 'c', $snapshot ) : 'unknown';
	$body = "A demo restore failed.\r\n\r\n";
	$body .= 'Site: ' . wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) . "\r\n";
	$body .= 'URL: ' . home_url( '/' ) . "\r\n";
	$body .= 'Time: ' . gmdate( 'c' ) . "\r\n";
	$body .= 'Error: ' . str_replace( array( "\r", "\n" ), ' ', $error ) . "\r\n";
	$body .= 'Rollback: ' . ( $rolled ? 'succeeded' : 'failed' ) . "\r\n";
	$body .= 'Snapshot: ' . $when . "\r\n";
	$body .= 'Log: ' . dgm_snapshot_dir( $blog_id ) . "/last-run.log\r\n";

	return $body;
}

/**
 * Write send results without repeating the message body.
 *
 * @param int   $blog_id Site ID.
 * @param string $label What was sent.
 * @param array $result Send result.
 */
function dgm_log_alert_result( $blog_id, $label, $result ) {
	$parts = array();
	foreach ( $result['results'] as $row ) {
		$parts[] = ( $row['ok'] ? 'ok' : 'failed' ) . ' ' . $row['email'];
	}
	dgm_log_line( $blog_id, $label . ': ' . implode( ', ', $parts ) );
}

/**
 * Send the test alert through the same path as a failure alert.
 *
 * @return array{ok: bool, results: array<int, array{email: string, ok: bool, error: string}>}|WP_Error
 */
function dgm_send_test_alert() {
	$blog_id = (int) get_current_blog_id();
	$emails  = dgm_alert_recipients( $blog_id );
	if ( ! $emails ) {
		return new WP_Error( 'dgm_alert_none', __( 'No valid alert recipients are available.', 'demo-growth-memberships' ) );
	}

	$body = "This is a test of the demo restore alert.\r\n\r\n";
	$body .= 'Site: ' . wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) . "\r\n";
	$body .= 'URL: ' . home_url( '/' ) . "\r\n";
	$body .= 'Time: ' . gmdate( 'c' ) . "\r\n";

	$result = dgm_send_bcc_mail( sprintf( '[%s] Demo alert test', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ), $body, $emails );
	dgm_log_alert_result( $blog_id, 'test alert', $result );

	return $result;
}

/**
 * Watchdog email. One message until the incident changes.
 *
 * @param string[] $problems Problem codes.
 * @return bool
 */
function dgm_send_watchdog_alert( $problems ) {
	$blog_id = (int) get_current_blog_id();
	$key     = implode( ',', $problems );
	if ( '' === $key || get_option( 'dgm_watchdog_incident', '' ) === $key ) {
		return false;
	}

	$labels = array(
		'no_snapshot' => 'No snapshot exists.',
		'stale'       => 'The last successful restore is older than 26 hours.',
		'never_ran'   => 'Nightly reset is on, but no restore has been recorded.',
	);
	$lines  = array();
	foreach ( $problems as $problem ) {
		$lines[] = isset( $labels[ $problem ] ) ? $labels[ $problem ] : $problem;
	}

	$body  = "The demo watchdog found a problem.\r\n\r\n";
	$body .= 'Site: ' . wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) . "\r\n";
	$body .= 'URL: ' . home_url( '/' ) . "\r\n";
	$body .= 'Time: ' . gmdate( 'c' ) . "\r\n";
	$body .= implode( "\r\n", $lines ) . "\r\n";
	$body .= 'Log: ' . dgm_snapshot_dir( $blog_id ) . "/last-run.log\r\n";

	$result = dgm_send_bcc_mail( sprintf( '[%s] Demo watchdog', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ), $body, dgm_alert_recipients( $blog_id ) );
	dgm_log_alert_result( $blog_id, 'watchdog', $result );
	if ( $result['ok'] ) {
		update_option( 'dgm_watchdog_incident', $key, false );
	}

	return (bool) $result['ok'];
}
