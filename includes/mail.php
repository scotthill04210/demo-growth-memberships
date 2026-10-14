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
 * Holds the one alert send that is allowed through the demo mail block.
 */
class DGM_Mail_Gate {
	/**
	 * Validated recipients for the send in progress.
	 *
	 * @var string[]
	 */
	private static $allowed = array();

	/**
	 * Allow one wp_mail() call to these addresses.
	 *
	 * @param string[] $emails Lowercase addresses.
	 */
	public static function allow( $emails ) {
		self::$allowed = array();
		foreach ( $emails as $email ) {
			if ( is_string( $email ) && '' !== $email ) {
				self::$allowed[] = strtolower( $email );
			}
		}
	}

	/**
	 * Close the bypass.
	 */
	public static function clear() {
		self::$allowed = array();
	}

	/**
	 * Whether an alert send is in progress.
	 *
	 * @return bool
	 */
	public static function is_open() {
		return (bool) self::$allowed;
	}

	/**
	 * Whether a wp_mail() payload is the allowed alert.
	 *
	 * @param mixed $atts Mail arguments from pre_wp_mail.
	 * @return bool
	 */
	public static function allows( $atts ) {
		if ( ! self::$allowed || ! is_array( $atts ) ) {
			return false;
		}

		$found = dgm_mail_addresses_from_atts( $atts );
		if ( ! $found ) {
			return false;
		}

		$allowed = array_fill_keys( self::$allowed, true );
		foreach ( $found as $email ) {
			if ( ! isset( $allowed[ $email ] ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * Register the mail block.
 */
function dgm_register_mail_hooks() {
	add_filter( 'pre_wp_mail', 'dgm_block_outgoing_mail', PHP_INT_MAX, 2 );
	add_action( 'phpmailer_init', 'dgm_configure_alert_smtp' );
	add_action( 'wp_footer', 'dgm_render_blocked_mail_notice' );
	add_action( 'admin_notices', 'dgm_render_blocked_mail_notice' );
	add_action( 'login_footer', 'dgm_render_blocked_mail_notice' );
}

/**
 * Short-circuit wp_mail(). A non-null value stops delivery.
 *
 * Replaces the mu-plugin alliance360-block-mail.php. Failure alerts pass
 * only while DGM_Mail_Gate is open and every recipient was validated.
 *
 * @param mixed $short_circuit Previous short-circuit value.
 * @param mixed $atts          wp_mail() arguments.
 * @return mixed False when this site is in demo mode.
 */
function dgm_block_outgoing_mail( $short_circuit = null, $atts = null ) {
	if ( ! dgm_is_demo_mode() ) {
		return $short_circuit;
	}

	if ( DGM_Mail_Gate::allows( $atts ) ) {
		return null;
	}

	dgm_remember_blocked_mail();

	return false;
}

/**
 * Read To, Cc, and Bcc addresses from a wp_mail() payload.
 *
 * @param array $atts Mail arguments.
 * @return string[]
 */
function dgm_mail_addresses_from_atts( $atts ) {
	$emails = array();
	$to     = isset( $atts['to'] ) ? $atts['to'] : '';
	foreach ( (array) $to as $item ) {
		if ( is_string( $item ) ) {
			$emails = array_merge( $emails, dgm_split_email_header( $item ) );
		}
	}

	$headers = isset( $atts['headers'] ) ? $atts['headers'] : array();
	if ( is_string( $headers ) ) {
		$headers = preg_split( "/\r\n|\n|\r/", $headers );
	}

	foreach ( (array) $headers as $header ) {
		if ( ! is_string( $header ) || ! preg_match( '/^(bcc|cc|to)\s*:/i', $header ) ) {
			continue;
		}
		$value  = trim( (string) preg_replace( '/^(bcc|cc|to)\s*:/i', '', $header ) );
		$emails = array_merge( $emails, dgm_split_email_header( $value ) );
	}

	return array_values( array_unique( $emails ) );
}

/**
 * Split a header value into lowercase addresses.
 *
 * @param string $value Header value.
 * @return string[]
 */
function dgm_split_email_header( $value ) {
	$emails = array();
	foreach ( preg_split( '/\s*,\s*/', $value ) as $part ) {
		if ( preg_match( '/<([^>]+)>/', $part, $matches ) ) {
			$part = $matches[1];
		}
		$part = strtolower( trim( $part ) );
		if ( '' !== $part ) {
			$emails[] = $part;
		}
	}

	return $emails;
}

/**
 * Remember that a visitor email was blocked, including across a redirect.
 */
function dgm_remember_blocked_mail() {
	$GLOBALS['dgm_show_mail_notice'] = true;

	if ( headers_sent() ) {
		return;
	}

	$path = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
	$host = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	setcookie( 'dgm_mail_would_send', '1', time() + 120, $path, $host, is_ssl(), true );
}

/**
 * Tell the visitor that a live site would have sent mail.
 */
function dgm_render_blocked_mail_notice() {
	$show = ! empty( $GLOBALS['dgm_show_mail_notice'] );
	if ( ! $show && isset( $_COOKIE['dgm_mail_would_send'] ) && '1' === $_COOKIE['dgm_mail_would_send'] ) {
		$show = true;
	}

	if ( ! $show || ! dgm_is_demo_mode() ) {
		return;
	}

	unset( $GLOBALS['dgm_show_mail_notice'] );
	if ( ! headers_sent() ) {
		$path = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$host = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie( 'dgm_mail_would_send', '', time() - 3600, $path, $host, is_ssl(), true );
	}

	$message = __( 'An email would have been sent in a live site.', 'demo-growth-memberships' );
	if ( is_admin() ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
		return;
	}

	echo '<p class="dgm-mail-notice" role="status">' . esc_html( $message ) . '</p>';
}

/**
 * Use optional SMTP constants for an alert send.
 *
 * @param PHPMailer\PHPMailer\PHPMailer|object $phpmailer Mailer instance.
 */
function dgm_configure_alert_smtp( $phpmailer ) {
	if ( ! is_object( $phpmailer ) || ! DGM_Mail_Gate::is_open() || ! defined( 'DGM_SMTP_HOST' ) || ! is_string( DGM_SMTP_HOST ) || '' === DGM_SMTP_HOST ) {
		return;
	}

	if ( ! method_exists( $phpmailer, 'isSMTP' ) ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host = DGM_SMTP_HOST;
	$phpmailer->Port = defined( 'DGM_SMTP_PORT' ) ? (int) DGM_SMTP_PORT : 587;
	$user            = defined( 'DGM_SMTP_USER' ) && is_string( DGM_SMTP_USER ) ? DGM_SMTP_USER : '';
	if ( '' !== $user ) {
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $user;
		$phpmailer->Password = defined( 'DGM_SMTP_PASS' ) && is_string( DGM_SMTP_PASS ) ? DGM_SMTP_PASS : '';
	}
}
