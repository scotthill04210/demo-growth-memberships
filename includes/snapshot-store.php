<?php
/**
 * Snapshot files, checksums, and the restore lock.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root directory for every site's snapshots. Never inside uploads.
 *
 * @return string
 */
function dgm_snapshot_root() {
	return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'dgm-snapshots' );
}

/**
 * Directory for one site's snapshot files.
 *
 * @param int $blog_id Site ID.
 * @return string
 */
function dgm_snapshot_dir( $blog_id ) {
	return dgm_snapshot_root() . '/' . (int) $blog_id;
}

/**
 * Create the snapshot directory and its public-access blocks.
 *
 * @param int $blog_id Site ID.
 * @return true|WP_Error
 */
function dgm_ensure_snapshot_dir( $blog_id ) {
	$dir = dgm_snapshot_dir( $blog_id );
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'dgm_snapshot_dir', __( 'Could not create the snapshot directory.', 'demo-growth-memberships' ) );
	}

	dgm_protect_directory( dgm_snapshot_root() );
	dgm_protect_directory( $dir );

	return true;
}

/**
 * Deny web access to a directory.
 *
 * @param string $dir Absolute directory path.
 */
function dgm_protect_directory( $dir ) {
	$htaccess = $dir . '/.htaccess';
	$rules    = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
	if ( ! is_file( $htaccess ) || false === strpos( (string) file_get_contents( $htaccess ), 'Require all denied' ) ) {
		file_put_contents( $htaccess, $rules, LOCK_EX );
	}

	$index = $dir . '/index.php';
	if ( ! is_file( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
	}
}

/**
 * Whether a string is safe to use as a MySQL identifier.
 *
 * @param mixed $name Identifier.
 * @return bool
 */
function dgm_is_valid_identifier( $name ) {
	return is_string( $name ) && '' !== $name && strlen( $name ) <= 64 && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
}

/**
 * Quote a validated MySQL identifier.
 *
 * @param string $name Identifier.
 * @return string
 */
function dgm_quote_identifier( $name ) {
	if ( ! dgm_is_valid_identifier( $name ) ) {
		return '';
	}

	return '`' . $name . '`';
}

/**
 * Whether a table may appear in this site's snapshot.
 *
 * Site tables must start with this site's prefix. wp_3_ does not match wp_30_.
 * The network users and usermeta tables are the only exceptions.
 *
 * @param string $table    Table name.
 * @param string $prefix   Current site prefix.
 * @param string $users    Network users table.
 * @param string $usermeta Network usermeta table.
 * @return bool
 */
function dgm_table_is_allowed( $table, $prefix, $users, $usermeta ) {
	if ( ! dgm_is_valid_identifier( $table ) || ! dgm_is_valid_identifier( $prefix ) ) {
		return false;
	}

	if ( $table === $users || $table === $usermeta ) {
		return true;
	}

	if ( strlen( $table ) <= strlen( $prefix ) || 0 !== strpos( $table, $prefix ) ) {
		return false;
	}

	return ! dgm_is_skipped_site_table( $table, $prefix );
}

/**
 * Tables this plugin creates while restoring, plus session tables.
 *
 * @param string $table  Table name.
 * @param string $prefix Site prefix.
 * @return bool
 */
function dgm_is_skipped_site_table( $table, $prefix ) {
	if ( preg_match( '/__(dgmnew|dgmold)$/', $table ) ) {
		return true;
	}

	if ( false !== strpos( $table, 'dgm_snap_' ) ) {
		return true;
	}

	$rest = substr( $table, strlen( $prefix ) );

	return (bool) preg_match( '/session/i', $rest );
}

/**
 * Encode one database row so it can sit on a single line.
 *
 * @param array $row Row.
 * @return string|false
 */
function dgm_encode_row( $row ) {
	$payload = array();
	foreach ( $row as $key => $value ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		$payload[ $key ] = null === $value ? null : base64_encode( (string) $value );
	}

	$json = wp_json_encode( $payload );
	if ( ! is_string( $json ) ) {
		return false;
	}

	return base64_encode( $json );
}

/**
 * Decode a row written by dgm_encode_row().
 *
 * @param string $payload Base64 payload.
 * @return array|false
 */
function dgm_decode_row( $payload ) {
	$json = base64_decode( $payload, true );
	if ( ! is_string( $json ) ) {
		return false;
	}

	$row = json_decode( $json, true );
	if ( ! is_array( $row ) ) {
		return false;
	}

	$decoded = array();
	foreach ( $row as $key => $value ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		if ( null === $value ) {
			$decoded[ $key ] = null;
			continue;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$plain = base64_decode( $value, true );
		if ( ! is_string( $plain ) ) {
			return false;
		}
		$decoded[ $key ] = $plain;
	}

	return $decoded;
}

/**
 * Replace a file, including on Windows where rename will not overwrite.
 *
 * @param string $from Source path.
 * @param string $to   Destination path.
 * @return bool
 */
function dgm_replace_file( $from, $to ) {
	if ( ! is_file( $from ) ) {
		return false;
	}

	if ( ! file_exists( $to ) ) {
		return rename( $from, $to );
	}

	$backup = $to . '.replacing';
	if ( is_file( $backup ) ) {
		unlink( $backup );
	}
	if ( ! rename( $to, $backup ) ) {
		return false;
	}
	if ( ! rename( $from, $to ) ) {
		rename( $backup, $to );
		return false;
	}
	unlink( $backup );

	return true;
}

/**
 * Write a SHA-256 checksum beside a snapshot file.
 *
 * @param string $path Snapshot path.
 * @return string|WP_Error Hex digest.
 */
function dgm_write_checksum( $path ) {
	$hash = hash_file( 'sha256', $path );
	if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
		return new WP_Error( 'dgm_checksum', __( 'Could not checksum the snapshot.', 'demo-growth-memberships' ) );
	}

	$written = file_put_contents( $path . '.sha256', $hash . "\n", LOCK_EX );
	if ( false === $written ) {
		return new WP_Error( 'dgm_checksum', __( 'Could not write the snapshot checksum.', 'demo-growth-memberships' ) );
	}

	return $hash;
}

/**
 * Verify a snapshot file against its checksum file.
 *
 * @param string $path          Snapshot path.
 * @param string $checksum_path Checksum path.
 * @param string $expected      Digest stored in the manifest.
 * @return true|WP_Error
 */
function dgm_verify_file_checksum( $path, $checksum_path, $expected ) {
	if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
		return new WP_Error( 'dgm_checksum', __( 'The snapshot checksum is missing.', 'demo-growth-memberships' ) );
	}
	if ( ! is_file( $path ) || ! is_file( $checksum_path ) ) {
		return new WP_Error( 'dgm_checksum', __( 'The snapshot file or its checksum is missing.', 'demo-growth-memberships' ) );
	}

	$stored = trim( (string) file_get_contents( $checksum_path ) );
	$actual = hash_file( 'sha256', $path );
	if ( ! is_string( $actual ) || ! hash_equals( $expected, $stored ) || ! hash_equals( $expected, $actual ) ) {
		return new WP_Error( 'dgm_checksum', __( 'The snapshot checksum does not match.', 'demo-growth-memberships' ) );
	}

	return true;
}

/**
 * Whether a manifest basename is one this plugin created.
 *
 * @param mixed  $name Snapshot basename.
 * @param string $kind current|failsafe.
 * @return bool
 */
function dgm_snapshot_basename_ok( $name, $kind ) {
	$prefix = 'failsafe' === $kind ? 'failsafe' : 'snap';

	return is_string( $name ) && 1 === preg_match( '/^' . $prefix . '-[a-f0-9]{32}\\.dgm$/', $name );
}

/**
 * Read a JSON object from disk.
 *
 * @param string $path File path.
 * @return array|null
 */
function dgm_read_json_file( $path ) {
	if ( ! is_file( $path ) ) {
		return null;
	}

	$data = json_decode( (string) file_get_contents( $path ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Atomically write a JSON file.
 *
 * @param string $path File path.
 * @param array  $data JSON data.
 * @return bool
 */
function dgm_write_json_file( $path, $data ) {
	$json = wp_json_encode( $data );
	if ( ! is_string( $json ) ) {
		return false;
	}

	$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) {
		return false;
	}

	$moved = dgm_replace_file( $tmp, $path );
	if ( ! $moved && is_file( $tmp ) ) {
		unlink( $tmp );
	}

	return $moved;
}

/**
 * Manifest for the current snapshot or the failsafe copy.
 *
 * @param int    $blog_id Site ID.
 * @param string $kind    current|failsafe.
 * @return array|null
 */
function dgm_snapshot_meta( $blog_id, $kind = 'current' ) {
	$name = 'failsafe' === $kind ? 'failsafe.json' : 'current.json';
	$meta = dgm_read_json_file( dgm_snapshot_dir( $blog_id ) . '/' . $name );
	if ( ! is_array( $meta ) || ! dgm_snapshot_basename_ok( isset( $meta['file'] ) ? $meta['file'] : '', $kind ) ) {
		return null;
	}

	return $meta;
}

/**
 * Absolute path to a manifest's snapshot file.
 *
 * @param int   $blog_id Site ID.
 * @param array $meta    Manifest.
 * @return string
 */
function dgm_snapshot_file_from_meta( $blog_id, $meta ) {
	return dgm_snapshot_dir( $blog_id ) . '/' . $meta['file'];
}

/**
 * Append one line to last-run.log and keep the file under 100 KB.
 *
 * @param int    $blog_id Site ID.
 * @param string $message Log line.
 */
function dgm_log_line( $blog_id, $message ) {
	$ready = dgm_ensure_snapshot_dir( $blog_id );
	if ( is_wp_error( $ready ) ) {
		return;
	}

	$file = dgm_snapshot_dir( $blog_id ) . '/last-run.log';
	$line = '[' . gmdate( 'c' ) . '] ' . str_replace( array( "\r", "\n" ), ' ', $message ) . "\n";
	file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	clearstatcache( true, $file );
	$size = filesize( $file );
	if ( false === $size || $size <= 100 * 1024 ) {
		return;
	}

	$contents = (string) file_get_contents( $file );
	file_put_contents( $file, dgm_rotate_log_contents( $contents, 80 * 1024 ), LOCK_EX );
}

/**
 * Keep the tail of a log.
 *
 * @param string $contents Log text.
 * @param int    $keep     Bytes to keep.
 * @return string
 */
function dgm_rotate_log_contents( $contents, $keep ) {
	$keep = (int) $keep;
	if ( strlen( $contents ) <= $keep ) {
		return $contents;
	}

	$tail = substr( $contents, -1 * $keep );
	$break = strpos( $tail, "\n" );
	if ( false !== $break ) {
		$tail = substr( $tail, $break + 1 );
	}

	return $tail;
}

/**
 * Print progress for WP-CLI and always write last-run.log.
 *
 * @param string $message Progress line.
 */
function dgm_progress( $message ) {
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $message );
	}

	dgm_log_line( (int) get_current_blog_id(), $message );
}

/**
 * Take the MySQL lock and the lock file.
 *
 * @param int $blog_id Site ID.
 * @return true|WP_Error
 */
function dgm_acquire_restore_lock( $blog_id ) {
	global $wpdb;

	$name = 'dgm_r_' . (int) $blog_id;
	$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d) AS locked', $name, 0 ) );
	if ( ! is_object( $row ) || 1 !== (int) $row->locked ) {
		return new WP_Error( 'dgm_locked', __( 'Another restore is already running for this site.', 'demo-growth-memberships' ) );
	}

	$ready = dgm_ensure_snapshot_dir( $blog_id );
	if ( is_wp_error( $ready ) ) {
		dgm_release_mysql_lock( $blog_id );
		return $ready;
	}

	$lockfile = dgm_snapshot_dir( $blog_id ) . '/restore.lock';
	if ( is_file( $lockfile ) && ( time() - (int) filemtime( $lockfile ) ) < 30 * MINUTE_IN_SECONDS ) {
		dgm_release_mysql_lock( $blog_id );
		return new WP_Error( 'dgm_locked', __( 'Another restore is already running for this site.', 'demo-growth-memberships' ) );
	}

	file_put_contents( $lockfile, (string) getmypid(), LOCK_EX );
	$GLOBALS['dgm_holds_restore_lock'] = (int) $blog_id;

	return true;
}

/**
 * Release the MySQL named lock.
 *
 * @param int $blog_id Site ID.
 */
function dgm_release_mysql_lock( $blog_id ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'dgm_r_' . (int) $blog_id ) );
}

/**
 * Release both restore locks.
 *
 * @param int $blog_id Site ID.
 */
function dgm_release_restore_lock( $blog_id ) {
	dgm_release_mysql_lock( $blog_id );
	$lockfile = dgm_snapshot_dir( $blog_id ) . '/restore.lock';
	if ( is_file( $lockfile ) ) {
		unlink( $lockfile );
	}
	unset( $GLOBALS['dgm_holds_restore_lock'] );
}

/**
 * Free bytes on the snapshot volume.
 *
 * @param string $dir Directory on the volume to check.
 * @return float|WP_Error
 */
function dgm_free_disk_bytes( $dir ) {
	$free = @disk_free_space( $dir );
	if ( false === $free ) {
		return new WP_Error( 'dgm_disk', __( 'Could not check free disk space.', 'demo-growth-memberships' ) );
	}

	return (float) $free;
}

/**
 * Delete snapshot payloads listed in a manifest, keeping any basename in $keep.
 *
 * @param int      $blog_id Site ID.
 * @param array    $meta    Manifest.
 * @param string[] $keep    Basenames to keep.
 */
function dgm_delete_snapshot_payload( $blog_id, $meta, $keep ) {
	if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
		return;
	}

	$base = basename( $meta['file'] );
	if ( in_array( $base, $keep, true ) ) {
		return;
	}

	$dir = dgm_snapshot_dir( $blog_id );
	foreach ( array( $dir . '/' . $base, $dir . '/' . $base . '.sha256' ) as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}
