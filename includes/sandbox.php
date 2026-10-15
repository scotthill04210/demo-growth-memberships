<?php
/**
 * Limits for the demo sandbox.
 *
 * Visitors can add and edit content. Plugin, theme, user, and settings changes stay blocked.
 * Administrator and super admin accounts are not limited.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register sandbox limits.
 */
function dgm_register_sandbox_hooks() {
	add_filter( 'user_has_cap', 'dgm_sandbox_limit_caps', PHP_INT_MAX, 4 );
	add_filter( 'map_meta_cap', 'dgm_sandbox_map_meta_cap', 10, 4 );
	add_filter( 'editable_roles', 'dgm_sandbox_editable_roles' );
	add_action( 'admin_init', 'dgm_sandbox_block_screens' );
	add_action( 'set_user_role', 'dgm_sandbox_block_admin_role', 10, 3 );
	add_filter( 'wp_pre_insert_user_data', 'dgm_sandbox_protect_demo_login', 20, 4 );
	add_filter( 'allow_password_reset', 'dgm_sandbox_block_demo_password_reset', 10, 2 );
	add_filter( 'wp_handle_upload_prefilter', 'dgm_sandbox_filter_upload' );
	add_filter( 'upload_size_limit', 'dgm_sandbox_upload_size_limit' );
	add_filter( 'upload_mimes', 'dgm_sandbox_upload_mimes' );
	add_filter( 'wp_insert_post_data', 'dgm_sandbox_clean_post', 10, 2 );
	add_filter( 'preprocess_comment', 'dgm_sandbox_clean_comment' );
	add_filter( 'gform_save_field_value', 'dgm_sandbox_clean_form_value', 10, 5 );
	add_action( 'gform_pre_submission', 'dgm_sandbox_count_form' );
}

/**
 * Whether this user is exempt from sandbox limits.
 *
 * @param int          $user_id User ID. Zero means the current user.
 * @param WP_User|null $user    User already loaded, so capability checks do not look the user up again.
 * @return bool
 */
function dgm_sandbox_user_is_exempt( $user_id = 0, $user = null ) {
	if ( ! dgm_sandbox_is_active() ) {
		return true;
	}

	if ( $user instanceof WP_User ) {
		return dgm_user_is_privileged( $user );
	}

	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	return dgm_user_is_privileged( $user_id );
}

/**
 * Whether the current user is the demo client role.
 *
 * @return bool
 */
function dgm_current_user_is_demo_role() {
	if ( ! is_user_logged_in() || dgm_user_is_privileged() ) {
		return false;
	}

	$user = wp_get_current_user();

	return in_array( 'client', (array) $user->roles, true );
}

/**
 * Remove plugin, theme, and file capabilities from demo visitors.
 *
 * @param mixed    $allcaps Current caps.
 * @param mixed    $caps    Required caps.
 * @param mixed    $args    Cap check arguments.
 * @param WP_User  $user    User being checked.
 * @return array
 */
function dgm_sandbox_limit_caps( $allcaps = array(), $caps = array(), $args = array(), $user = null ) {
	unset( $caps, $args );
	if ( ! is_array( $allcaps ) || ! dgm_sandbox_is_active() ) {
		return is_array( $allcaps ) ? $allcaps : array();
	}

	if ( dgm_sandbox_user_is_exempt( 0, $user ) ) {
		return $allcaps;
	}

	foreach ( array( 'install_plugins', 'activate_plugins', 'edit_plugins', 'delete_plugins', 'update_plugins', 'install_themes', 'switch_themes', 'edit_themes', 'delete_themes', 'update_themes', 'edit_files', 'update_core', 'unfiltered_upload', 'unfiltered_html', 'promote_users' ) as $cap ) {
		$allcaps[ $cap ] = false;
	}

	return $allcaps;
}

/**
 * Block user changes that would leave the sandbox.
 *
 * @param string[] $caps    Caps.
 * @param string   $cap     Capability.
 * @param int      $user_id Actor.
 * @param array    $args    Extra arguments.
 * @return string[]
 */
function dgm_sandbox_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( ! is_array( $caps ) || dgm_sandbox_user_is_exempt( (int) $user_id ) ) {
		return is_array( $caps ) ? $caps : array();
	}

	if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}

	$target = get_userdata( (int) $args[0] );
	if ( ! $target instanceof WP_User ) {
		return $caps;
	}

	if ( 'democlient' === $target->user_login ) {
		return array( 'do_not_allow' );
	}

	$roles = array_values( array_filter( (array) $target->roles ) );
	$only_client = ! $roles || array( 'client' ) === $roles;
	if ( ! $only_client ) {
		return array( 'do_not_allow' );
	}

	return $caps;
}

/**
 * Hide the administrator role from demo visitors.
 *
 * @param mixed $roles Editable roles.
 * @return array
 */
function dgm_sandbox_editable_roles( $roles ) {
	if ( ! is_array( $roles ) || dgm_sandbox_user_is_exempt() ) {
		return is_array( $roles ) ? $roles : array();
	}

	unset( $roles['administrator'] );

	return $roles;
}

/**
 * Keep demo visitors out of settings, plugin, and theme screens.
 */
function dgm_sandbox_block_screens() {
	global $pagenow;

	if ( 'index.php' === $pagenow || dgm_sandbox_user_is_exempt() || ! is_admin() ) {
		return;
	}
	$page    = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$blocked = array( 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options.php', 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'themes.php', 'theme-install.php', 'theme-editor.php', 'update.php', 'update-core.php', 'customize.php', 'users.php', 'user-new.php', 'user-edit.php' );

	if ( 'options-general.php' === $pagenow && 'dgm-settings' === $page ) {
		return;
	}

	if ( 'options-general.php' === $pagenow || in_array( $pagenow, $blocked, true ) || ( '' !== $page && false !== strpos( $page, 'settings' ) && 'dgm-settings' !== $page ) ) {
		wp_die( esc_html__( 'This demo keeps settings, plugins, and themes unchanged.', 'demo-growth-memberships' ), '', array( 'response' => 403 ) );
	}
}

/**
 * Put an administrator role back if a non-super-admin assigned it.
 *
 * @param int      $user_id   User ID.
 * @param string   $role      New role.
 * @param string[] $old_roles Previous roles.
 */
function dgm_sandbox_block_admin_role( $user_id = 0, $role = '', $old_roles = array() ) {
	if ( dgm_sandbox_user_is_exempt() || 'administrator' !== $role ) {
		return;
	}

	static $running = false;
	if ( $running ) {
		return;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user instanceof WP_User || 'democlient' === $user->user_login ) {
		return;
	}

	$running  = true;
	$previous = is_array( $old_roles ) && isset( $old_roles[0] ) && is_string( $old_roles[0] ) && 'administrator' !== $old_roles[0] ? $old_roles[0] : 'subscriber';
	$user->set_role( $previous );
	$running = false;
}

/**
 * Keep the democlient password, email, and login unchanged.
 *
 * @param mixed $data     User data.
 * @param bool  $update   Whether this is an update.
 * @param int   $user_id  User ID.
 * @param array $userdata Raw user data.
 * @return mixed
 */
function dgm_sandbox_protect_demo_login( $data = array(), $update = false, $user_id = 0, $userdata = array() ) {
	unset( $update, $userdata );
	if ( ! is_array( $data ) || dgm_sandbox_user_is_exempt() ) {
		return $data;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user instanceof WP_User || 'democlient' !== $user->user_login ) {
		return $data;
	}

	$data['user_login'] = $user->user_login;
	$data['user_email'] = $user->user_email;
	$data['user_pass']  = $user->user_pass;

	return $data;
}

/**
 * Refuse password resets for the demo login.
 *
 * @param mixed $allow   Whether reset is allowed.
 * @param int   $user_id User ID.
 * @return mixed
 */
function dgm_sandbox_block_demo_password_reset( $allow = true, $user_id = 0 ) {
	if ( ! dgm_sandbox_is_active() || dgm_user_is_privileged() ) {
		return $allow;
	}

	$user = get_userdata( (int) $user_id );
	if ( $user instanceof WP_User && 'democlient' === $user->user_login ) {
		return false;
	}

	return $allow;
}

/**
 * Limit demo uploads to 2 MB images and PDFs.
 *
 * @param array $file Upload file data.
 * @return array
 */
function dgm_sandbox_filter_upload( $file ) {
	if ( ! is_array( $file ) || dgm_sandbox_user_is_exempt() ) {
		return $file;
	}

	$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
	if ( $size > 2 * MB_IN_BYTES ) {
		$file['error'] = __( 'Demo uploads are limited to 2 MB.', 'demo-growth-memberships' );
		return $file;
	}

	$name    = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
	$checked = wp_check_filetype( $name );
	$ext     = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? strtolower( $checked['ext'] ) : '';
	if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ), true ) ) {
		$file['error'] = __( 'Demo uploads must be an image or a PDF.', 'demo-growth-memberships' );
	}

	return $file;
}

/**
 * Cap the upload size at 2 MB for the sandbox.
 *
 * @param int $size Current limit.
 * @return int
 */
function dgm_sandbox_upload_size_limit( $size, $u_bytes = 0, $p_bytes = 0 ) {
	unset( $u_bytes, $p_bytes );
	if ( dgm_sandbox_user_is_exempt() ) {
		return $size;
	}

	$cap = 2 * MB_IN_BYTES;

	return (int) $size > 0 ? min( (int) $size, $cap ) : $cap;
}

/**
 * Allow image and PDF uploads.
 *
 * @param mixed $mimes Mime map.
 * @return array
 */
function dgm_sandbox_upload_mimes( $mimes ) {
	if ( ! is_array( $mimes ) || dgm_sandbox_user_is_exempt() ) {
		return is_array( $mimes ) ? $mimes : array();
	}

	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'gif'          => 'image/gif',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
	);
}

/**
 * Strip HTML and links from content saved by the demo role, and rate-limit new records.
 *
 * @param array $data    Post data.
 * @param array $postarr Raw post array.
 * @return array
 */
function dgm_sandbox_clean_post( $data = array(), $postarr = array(), $unsanitized = array(), $update = false ) {
	unset( $unsanitized, $update );
	if ( ! is_array( $data ) || ! dgm_sandbox_is_active() ) {
		return is_array( $data ) ? $data : array();
	}

	$post_id = is_array( $postarr ) && isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	$type    = isset( $data['post_type'] ) ? $data['post_type'] : '';
	$new     = $post_id <= 0 && 'revision' !== $type && 'auto-draft' !== ( isset( $data['post_status'] ) ? $data['post_status'] : '' );
	if ( $new && empty( $postarr['dgm_rate_checked'] ) ) {
		dgm_sandbox_hit_rate_limit();
	}

	if ( ! dgm_current_user_is_demo_role() ) {
		return $data;
	}

	foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
		if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
			$data[ $field ] = dgm_strip_demo_text( $data[ $field ] );
		}
	}

	return $data;
}

/**
 * Strip HTML and links from a demo comment and count it.
 *
 * @param mixed $comment Comment data.
 * @return mixed
 */
function dgm_sandbox_clean_comment( $comment = array() ) {
	if ( ! is_array( $comment ) || ! dgm_sandbox_is_active() ) {
		return $comment;
	}

	dgm_sandbox_hit_rate_limit();
	if ( dgm_current_user_is_demo_role() && isset( $comment['comment_content'] ) && is_string( $comment['comment_content'] ) ) {
		$comment['comment_content'] = dgm_strip_demo_text( $comment['comment_content'] );
	}

	return $comment;
}

/**
 * Strip HTML and links from Gravity Forms values saved by the demo role.
 *
 * @param mixed $value Field value.
 * @return mixed
 */
function dgm_sandbox_clean_form_value( $value = '', $lead = null, $field = null, $form = null, $input_id = '' ) {
	unset( $lead, $field, $form, $input_id );
	if ( ! dgm_sandbox_is_active() || ! dgm_current_user_is_demo_role() || ! is_string( $value ) ) {
		return $value;
	}

	return dgm_strip_demo_text( $value );
}

/**
 * Count a form submission against the hourly limit.
 */
function dgm_sandbox_count_form( $form = null ) {
	unset( $form );
	if ( dgm_sandbox_is_active() ) {
		dgm_sandbox_hit_rate_limit();
	}
}

/**
 * Remove tags and URLs from demo text.
 *
 * @param string $text Raw text.
 * @return string
 */
function dgm_strip_demo_text( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = preg_replace( '#https?://\S+#i', '', $text );
	$text = preg_replace( '#www\.\S+#i', '', $text );

	return trim( (string) $text );
}

/**
 * Stop the request after 50 new records an hour from one IP.
 */
function dgm_sandbox_hit_rate_limit() {
	if ( dgm_sandbox_user_is_exempt() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	if ( dgm_rate_limit_exceeded() ) {
		wp_die( esc_html__( 'Demo limit reached: 50 new records per hour.', 'demo-growth-memberships' ), '', array( 'response' => 429 ) );
	}
}

/**
 * Client IP from the connection, not a forwarded header.
 *
 * @return string
 */
function dgm_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '0.0.0.0';
	}

	return $ip;
}

/**
 * Increment and test the hourly creation counter.
 *
 * @return bool True when the request is over the limit.
 */
function dgm_rate_limit_exceeded() {
	$ready = dgm_ensure_snapshot_dir( (int) get_current_blog_id() );
	if ( is_wp_error( $ready ) ) {
		return false;
	}

	$path = dgm_snapshot_dir( (int) get_current_blog_id() ) . '/rate.json';
	$fh   = fopen( $path, 'c+' );
	if ( false === $fh ) {
		return false;
	}

	$over = false;
	if ( flock( $fh, LOCK_EX ) ) {
		$raw     = stream_get_contents( $fh );
		$data    = json_decode( (string) $raw, true );
		$buckets = is_array( $data ) && isset( $data['buckets'] ) && is_array( $data['buckets'] ) ? $data['buckets'] : array();
		$now     = time();
		$key     = hash( 'sha256', dgm_client_ip() );
		foreach ( $buckets as $bucket_key => $bucket ) {
			if ( ! is_array( $bucket ) || ! isset( $bucket['start'] ) || ( $now - (int) $bucket['start'] ) >= HOUR_IN_SECONDS ) {
				unset( $buckets[ $bucket_key ] );
			}
		}

		if ( ! isset( $buckets[ $key ] ) || ! is_array( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'start' => $now,
				'count' => 0,
			);
		}

		$buckets[ $key ]['count'] = (int) $buckets[ $key ]['count'] + 1;
		$over                     = $buckets[ $key ]['count'] > 50;
		$json                     = wp_json_encode( array( 'buckets' => $buckets ) );
		if ( is_string( $json ) ) {
			ftruncate( $fh, 0 );
			rewind( $fh );
			fwrite( $fh, $json );
		}
		flock( $fh, LOCK_UN );
	}
	fclose( $fh );

	return $over;
}
