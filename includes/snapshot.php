<?php
/**
 * Per-site snapshots and restores.
 *
 * Site tables are loaded into shadow tables and swapped with one RENAME TABLE.
 * Network users are limited to people who have a role on this site.
 *
 * @package Demo_Growth_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save a snapshot of the current site.
 *
 * @param array $args Optional. failsafe => true writes the rollback copy.
 * @return array|WP_Error Manifest.
 */
function dgm_create_snapshot( $args = array() ) {
	global $wpdb;

	$args     = is_array( $args ) ? $args : array();
	$failsafe = ! empty( $args['failsafe'] );
	$blog_id  = (int) get_current_blog_id();

	if ( ! dgm_is_demo_mode() ) {
		return new WP_Error( 'dgm_demo_mode', __( 'Demo mode is off for this site.', 'demo-growth-memberships' ) );
	}

	$ready = dgm_ensure_snapshot_dir( $blog_id );
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	dgm_raise_restore_limits();
	$kind    = $failsafe ? 'failsafe' : 'current';
	$token   = bin2hex( random_bytes( 16 ) );
	$base    = ( $failsafe ? 'failsafe-' : 'snap-' ) . $token . '.dgm';
	$path    = dgm_snapshot_dir( $blog_id ) . '/' . $base;
	$handle  = fopen( $path, 'wb' );
	if ( false === $handle ) {
		return new WP_Error( 'dgm_snapshot', __( 'Could not write the snapshot file.', 'demo-growth-memberships' ) );
	}

	$prefix  = $wpdb->get_blog_prefix();
	$created = time();
	fwrite( $handle, "DGM1\n" );
	fwrite( $handle, 'blog ' . $blog_id . "\n" );
	fwrite( $handle, 'prefix ' . $prefix . "\n" );
	fwrite( $handle, 'created ' . $created . "\n" );

	$tables = 0;
	dgm_progress( $failsafe ? 'Saving a failsafe copy.' : 'Saving a snapshot.' );
	foreach ( dgm_tables_to_dump() as $table => $scope ) {
		dgm_progress( 'Dumping ' . $table . '.' );
		$dumped = dgm_dump_one_table( $handle, $table, $scope );
		if ( is_wp_error( $dumped ) ) {
			fclose( $handle );
			unlink( $path );
			return $dumped;
		}
		++$tables;
	}

	fwrite( $handle, "done\n" );
	fclose( $handle );

	$hash = dgm_write_checksum( $path );
	if ( is_wp_error( $hash ) ) {
		unlink( $path );
		return $hash;
	}

	$verified = dgm_verify_file_checksum( $path, $path . '.sha256', $hash );
	if ( is_wp_error( $verified ) ) {
		unlink( $path );
		return $verified;
	}

	$meta = array(
		'file'     => $base,
		'sha256'   => $hash,
		'created'  => $created,
		'bytes'    => (int) filesize( $path ),
		'tables'   => $tables,
		'blog_id'  => $blog_id,
		'prefix'   => $prefix,
	);
	$meta_path = dgm_snapshot_dir( $blog_id ) . '/' . ( $failsafe ? 'failsafe.json' : 'current.json' );
	if ( ! dgm_write_json_file( $meta_path, $meta ) ) {
		unlink( $path );
		return new WP_Error( 'dgm_snapshot', __( 'Could not save the snapshot manifest.', 'demo-growth-memberships' ) );
	}

	dgm_cleanup_orphan_snapshots( $blog_id );
	dgm_progress( ( $failsafe ? 'Failsafe copy saved. ' : 'Snapshot saved. ' ) . $tables . ' tables.' );

	return $meta;
}

/**
 * Restore this site from the current snapshot, or report what would happen.
 *
 * @param array $args manual, scheduled, retry, dry_run.
 * @return true|WP_Error
 */
function dgm_restore_site( $args = array() ) {
	$args      = is_array( $args ) ? $args : array();
	$blog_id   = (int) get_current_blog_id();
	$dry       = ! empty( $args['dry_run'] );
	$retry     = ! empty( $args['retry'] );
	$scheduled = ! empty( $args['scheduled'] );
	$manual    = ! empty( $args['manual'] );

	if ( $scheduled && ! $retry && ! $manual ) {
		if ( ! dgm_nightly_reset_enabled() ) {
			dgm_progress( 'Nightly reset is off.' );
			return true;
		}
		if ( ! dgm_is_demo_mode() ) {
			dgm_progress( 'Demo mode is off.' );
			return true;
		}
	}

	if ( ! dgm_is_demo_mode() ) {
		return new WP_Error( 'dgm_demo_mode', __( 'Demo mode is off for this site.', 'demo-growth-memberships' ) );
	}

	$meta = dgm_snapshot_meta( $blog_id, 'current' );
	if ( ! $meta ) {
		return new WP_Error( 'dgm_snapshot', __( 'No snapshot exists for this site.', 'demo-growth-memberships' ) );
	}

	$path  = dgm_snapshot_file_from_meta( $blog_id, $meta );
	$guard = dgm_guard_snapshot( $blog_id, $meta, $path );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	if ( $dry ) {
		dgm_progress( 'Dry run finished. ' . count( $guard ) . ' tables checked. No data was changed.' );
		return true;
	}

	$hold_lock = empty( $GLOBALS['dgm_holds_restore_lock'] );
	if ( $hold_lock ) {
		$locked = dgm_acquire_restore_lock( $blog_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
	}

	dgm_raise_restore_limits();
	dgm_maintenance_on( $blog_id );
	$GLOBALS['dgm_restore_state'] = array(
		'blog_id'  => $blog_id,
		'open'     => true,
		'rollback' => false,
	);

	$started   = microtime( true );
	$preserved = dgm_capture_plugin_options();
	dgm_progress( 'Restoring this site from the snapshot.' );
	$result = dgm_restore_body( $blog_id, $meta, $path, $preserved, false );

	if ( is_wp_error( $result ) ) {
		$rolled = dgm_rollback_from_failsafe( $blog_id, $preserved );
		$GLOBALS['dgm_restore_state']['open'] = false;
		dgm_record_reset( false, microtime( true ) - $started, $result->get_error_message(), 0 );
		dgm_maintenance_off( $blog_id );
		if ( $hold_lock ) {
			dgm_release_restore_lock( $blog_id );
		}
		dgm_after_restore_failure( $blog_id, $result->get_error_message(), $rolled, $retry, isset( $meta['created'] ) ? (int) $meta['created'] : 0 );
		return $result;
	}

	$GLOBALS['dgm_restore_state']['open'] = false;
	dgm_record_reset( true, microtime( true ) - $started, '', (int) $result );
	dgm_send_recovery_alert( $blog_id, isset( $meta['created'] ) ? (int) $meta['created'] : 0 );
	update_option( 'dgm_watchdog_incident', '', false );
	dgm_maintenance_off( $blog_id );
	if ( $hold_lock ) {
		dgm_release_restore_lock( $blog_id );
	}
	dgm_progress( 'Restore finished.' );

	return true;
}

/**
 * Load one snapshot into this site.
 *
 * @param int    $blog_id   Site ID.
 * @param array  $meta      Manifest.
 * @param string $path      Snapshot file.
 * @param array  $preserved Plugin options captured before changes.
 * @param bool   $rollback  Whether this is the failsafe rollback.
 * @return int|WP_Error Files removed, or an error.
 */
function dgm_restore_body( $blog_id, $meta, $path, $preserved, $rollback ) {
	global $wpdb;

	dgm_drop_ephemeral_tables();
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
	try {
		if ( ! $rollback ) {
			$failsafe = dgm_create_snapshot( array( 'failsafe' => true ) );
			if ( is_wp_error( $failsafe ) ) {
				dgm_reapply_plugin_options( $preserved );
				return $failsafe;
			}
		}

		$map = dgm_load_site_shadows( $blog_id, $path );
		if ( is_wp_error( $map ) ) {
			dgm_drop_ephemeral_tables();
			dgm_reapply_plugin_options( $preserved );
			return $map;
		}

		$swapped = dgm_swap_shadow_tables( $map );
		if ( is_wp_error( $swapped ) ) {
			dgm_drop_ephemeral_tables();
			dgm_reapply_plugin_options( $preserved );
			return $swapped;
		}

		$users = dgm_apply_network_users( $path );
		if ( is_wp_error( $users ) ) {
			dgm_reapply_plugin_options( $preserved );
			return $users;
		}

		dgm_drop_tables_not_in_snapshot( array_keys( $map ) );
		dgm_drop_ephemeral_tables();
		dgm_reapply_plugin_options( $preserved );
		dgm_finish_caches();

		if ( $rollback ) {
			return 0;
		}

		$removed = dgm_cleanup_uploads( isset( $meta['created'] ) ? (int) $meta['created'] : 0 );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		dgm_progress( 'Removed ' . (int) $removed . ' upload files newer than the snapshot.' );

		return (int) $removed;
	} finally {
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	}
}

/**
 * Restore the failsafe copy after a failed restore.
 *
 * @param int   $blog_id   Site ID.
 * @param array $preserved Plugin options from before the restore.
 * @return bool
 */
function dgm_rollback_from_failsafe( $blog_id, $preserved ) {
	$meta = dgm_snapshot_meta( $blog_id, 'failsafe' );
	if ( ! $meta ) {
		dgm_progress( 'Rollback failed: no failsafe copy exists.' );
		return false;
	}

	$path = dgm_snapshot_file_from_meta( $blog_id, $meta );
	$guard = dgm_guard_snapshot( $blog_id, $meta, $path );
	if ( is_wp_error( $guard ) ) {
		dgm_progress( 'Rollback failed: ' . $guard->get_error_message() );
		return false;
	}

	dgm_progress( 'Rolling back from the failsafe copy.' );
	$GLOBALS['dgm_restore_state']['rollback'] = true;
	$result = dgm_restore_body( $blog_id, $meta, $path, $preserved, true );
	$GLOBALS['dgm_restore_state']['rollback'] = false;
	if ( is_wp_error( $result ) ) {
		dgm_progress( 'Rollback failed: ' . $result->get_error_message() );
		return false;
	}

	dgm_progress( 'Rollback finished.' );
	return true;
}

/**
 * Checksum, table names, and free disk before anything is changed.
 *
 * @param int    $blog_id Site ID.
 * @param array  $meta    Manifest.
 * @param string $path    Snapshot file.
 * @return string[]|WP_Error Table names.
 */
function dgm_guard_snapshot( $blog_id, $meta, $path ) {
	$hash = isset( $meta['sha256'] ) ? $meta['sha256'] : '';
	$check = dgm_verify_file_checksum( $path, $path . '.sha256', is_string( $hash ) ? $hash : '' );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$scan = dgm_scan_snapshot( $blog_id, $path, $meta );
	if ( is_wp_error( $scan ) ) {
		return $scan;
	}

	$ready = dgm_ensure_snapshot_dir( $blog_id );
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$bytes = isset( $meta['bytes'] ) ? (int) $meta['bytes'] : 0;
	$need  = max( 64 * MB_IN_BYTES, $bytes * 3 );
	$free  = dgm_free_disk_bytes( dgm_snapshot_dir( $blog_id ) );
	if ( is_wp_error( $free ) ) {
		return $free;
	}
	if ( $free < $need ) {
		return new WP_Error( 'dgm_disk', __( 'Not enough free disk space to restore this site.', 'demo-growth-memberships' ) );
	}

	return $scan;
}

/**
 * Retry once, then alert.
 *
 * @param int    $blog_id  Site ID.
 * @param string $error    Error message.
 * @param bool   $rolled   Rollback result.
 * @param bool   $retry    Whether this attempt was already the retry.
 * @param int    $created  Snapshot timestamp.
 */
function dgm_after_restore_failure( $blog_id, $error, $rolled, $retry, $created ) {
	if ( $retry ) {
		dgm_send_failure_alert( $blog_id, $error, $rolled, $created );
		return;
	}

	$direct_cli = defined( 'WP_CLI' ) && WP_CLI && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON );
	if ( $direct_cli ) {
		dgm_progress( 'Waiting 5 minutes before one retry.' );
		sleep( 300 );
		dgm_restore_site(
			array(
				'retry'  => true,
				'manual' => true,
			)
		);
		return;
	}

	wp_schedule_single_event( time() + 300, 'dgm_restore_retry', array( (int) $blog_id ) );
	dgm_progress( 'Retry scheduled in 5 minutes.' );
}

/**
 * Tables included in a snapshot.
 *
 * @return array<string, string> Table name => site|network.
 */
function dgm_tables_to_dump() {
	global $wpdb;

	$prefix = $wpdb->get_blog_prefix();
	$like   = $wpdb->esc_like( $prefix ) . '%';
	$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	$tables = array();
	foreach ( (array) $found as $table ) {
		if ( ! is_string( $table ) ) {
			continue;
		}
		if ( $table === $wpdb->users || $table === $wpdb->usermeta ) {
			continue;
		}
		if ( dgm_table_is_allowed( $table, $prefix, $wpdb->users, $wpdb->usermeta ) ) {
			$tables[ $table ] = 'site';
		}
	}

	$tables[ $wpdb->users ]    = 'network';
	$tables[ $wpdb->usermeta ] = 'network';

	return $tables;
}

/**
 * Dump one table into an open snapshot.
 *
 * @param resource $handle File handle.
 * @param string   $table  Table name.
 * @param string   $scope  site|network.
 * @return true|WP_Error
 */
function dgm_dump_one_table( $handle, $table, $scope ) {
	if ( 'site' === $scope && ! dgm_table_needs_row_filter( $table ) && dgm_mysqldump_available() ) {
		$dumped = dgm_dump_table_mysqldump( $handle, $table );
		if ( ! is_wp_error( $dumped ) ) {
			return true;
		}
		dgm_progress( 'mysqldump was not used for ' . $table . '. Using the PHP dump.' );
	}

	if ( dgm_table_needs_row_filter( $table ) && 'network' === $scope ) {
		return dgm_dump_network_table( $handle, $table );
	}

	return dgm_dump_table_php( $handle, $table );
}

/**
 * Whether rows must be filtered instead of copying the whole table.
 *
 * @param string $table Table name.
 * @return bool
 */
function dgm_table_needs_row_filter( $table ) {
	global $wpdb;

	return $table === $wpdb->options || $table === $wpdb->users || $table === $wpdb->usermeta;
}

/**
 * Dump a site table in chunks.
 *
 * @param resource $handle File handle.
 * @param string   $table  Table name.
 * @return true|WP_Error
 */
function dgm_dump_table_php( $handle, $table ) {
	global $wpdb;

	$create = dgm_show_create_table( $table );
	if ( is_wp_error( $create ) ) {
		return $create;
	}

	$where = '';
	if ( $table === $wpdb->options ) {
		$where = $wpdb->prepare(
			' WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( 'dgm_' ) . '%'
		);
	}

	$quoted = dgm_quote_identifier( $table );
	$count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted}{$where}" );
	$error  = dgm_db_error();
	if ( is_wp_error( $error ) ) {
		return $error;
	}

	fwrite( $handle, 'table ' . $table . " php\n" );
	fwrite( $handle, 'create ' . base64_encode( $create ) . "\n" );
	fwrite( $handle, 'count ' . $count . "\n" );

	$written = 0;
	$pk      = dgm_table_primary_key( $table );
	$pk_sql  = dgm_quote_identifier( $pk );
	$last    = 0;
	$offset  = 0;
	do {
		if ( '' !== $pk_sql ) {
			$clause = '' === $where ? 'WHERE' : 'AND';
			$last   = (int) $last;
			$rows   = $wpdb->get_results( "SELECT * FROM {$quoted}{$where} {$clause} {$pk_sql} > {$last} ORDER BY {$pk_sql} ASC LIMIT 200", ARRAY_A );
		} else {
			$offset = (int) $offset;
			$rows   = $wpdb->get_results( "SELECT * FROM {$quoted}{$where} LIMIT 200 OFFSET {$offset}", ARRAY_A );
			$offset += 200;
		}

		$error = dgm_db_error();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		if ( ! is_array( $rows ) || ! $rows ) {
			break;
		}

		foreach ( $rows as $row ) {
			$encoded = dgm_encode_row( $row );
			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'dgm_snapshot', __( 'Could not encode a snapshot row.', 'demo-growth-memberships' ) );
			}
			fwrite( $handle, 'row ' . $encoded . "\n" );
			++$written;
			if ( '' !== $pk && isset( $row[ $pk ] ) ) {
				$next = (int) $row[ $pk ];
				if ( $next <= $last ) {
					return new WP_Error( 'dgm_snapshot', __( 'Could not page through a snapshot table.', 'demo-growth-memberships' ) );
				}
				$last = $next;
			}
		}
	} while ( count( $rows ) === 200 );

	if ( $written !== $count ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot row count did not match the table.', 'demo-growth-memberships' ) );
	}

	fwrite( $handle, "endtable\n" );

	return true;
}

/**
 * Dump the users or usermeta rows for people who have a role on this site.
 *
 * @param resource $handle File handle.
 * @param string   $table  users or usermeta.
 * @return true|WP_Error
 */
function dgm_dump_network_table( $handle, $table ) {
	global $wpdb;

	$create = dgm_show_create_table( $table );
	if ( is_wp_error( $create ) ) {
		return $create;
	}

	$ids   = dgm_site_user_ids();
	$count = $table === $wpdb->users ? dgm_count_user_rows( $ids ) : dgm_site_usermeta_count( $ids );
	fwrite( $handle, 'table ' . $table . " php\n" );
	fwrite( $handle, 'create ' . base64_encode( $create ) . "\n" );
	fwrite( $handle, 'count ' . (int) $count . "\n" );

	$written = 0;
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		if ( ! $chunk ) {
			continue;
		}
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		if ( $table === $wpdb->users ) {
			$sql = dgm_prepare_values( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$placeholders})", $chunk );
		} else {
			$args   = $chunk;
			$args[] = 'session_tokens';
			$sql    = dgm_prepare_values( "SELECT * FROM {$wpdb->usermeta} WHERE user_id IN ({$placeholders}) AND meta_key <> %s", $args );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$error = dgm_db_error();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		foreach ( (array) $rows as $row ) {
			$encoded = dgm_encode_row( $row );
			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'dgm_snapshot', __( 'Could not encode a snapshot row.', 'demo-growth-memberships' ) );
			}
			fwrite( $handle, 'row ' . $encoded . "\n" );
			++$written;
		}
	}

	if ( $written !== (int) $count ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot row count did not match the table.', 'demo-growth-memberships' ) );
	}

	fwrite( $handle, "endtable\n" );

	return true;
}

/**
 * User IDs that have a role on the current site.
 *
 * @return int[]
 */
function dgm_site_user_ids() {
	global $wpdb;

	$key = $wpdb->get_blog_prefix() . 'capabilities';
	$ids = array();
	$last = 0;
	do {
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", $key, $last, 500 ) );
		if ( ! is_array( $rows ) || ! $rows ) {
			break;
		}
		foreach ( $rows as $id ) {
			$ids[] = (int) $id;
			$last  = (int) $id;
		}
	} while ( count( $rows ) === 500 );

	return $ids;
}

/**
 * Usermeta rows for a set of users, excluding sessions.
 *
 * @param int[] $ids User IDs.
 * @return int
 */
function dgm_site_usermeta_count( $ids ) {
	global $wpdb;

	$total = 0;
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		if ( ! $chunk ) {
			continue;
		}
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$args         = $chunk;
		$args[]       = 'session_tokens';
		$total       += (int) $wpdb->get_var( dgm_prepare_values( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id IN ({$placeholders}) AND meta_key <> %s", $args ) );
	}

	return $total;
}

/**
 * Dump a full site table with mysqldump, one INSERT per row.
 *
 * @param resource $handle File handle.
 * @param string   $table  Table name.
 * @return true|WP_Error
 */
function dgm_dump_table_mysqldump( $handle, $table ) {
	global $wpdb;

	$quoted = dgm_quote_identifier( $table );
	$count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted}" );
	$error  = dgm_db_error();
	if ( is_wp_error( $error ) ) {
		return $error;
	}

	$tmp = dgm_snapshot_dir( (int) get_current_blog_id() ) . '/tmp-' . bin2hex( random_bytes( 8 ) ) . '.sql';
	$ran = dgm_run_mysqldump( $table, $tmp );
	if ( is_wp_error( $ran ) ) {
		if ( is_file( $tmp ) ) {
			unlink( $tmp );
		}
		return $ran;
	}

	$bytes = filesize( $tmp );
	if ( false === $bytes ) {
		unlink( $tmp );
		return new WP_Error( 'dgm_snapshot', __( 'mysqldump did not produce a file.', 'demo-growth-memberships' ) );
	}

	fwrite( $handle, 'table ' . $table . " mysql\n" );
	fwrite( $handle, 'count ' . $count . "\n" );
	fwrite( $handle, 'bytes ' . (int) $bytes . "\n" );
	$input = fopen( $tmp, 'rb' );
	if ( false === $input ) {
		unlink( $tmp );
		return new WP_Error( 'dgm_snapshot', __( 'Could not read the mysqldump output.', 'demo-growth-memberships' ) );
	}
	stream_copy_to_stream( $input, $handle );
	fclose( $input );
	unlink( $tmp );
	fwrite( $handle, "\nendtable\n" );

	return true;
}

/**
 * Whether mysqldump can be executed.
 *
 * @return bool
 */
function dgm_mysqldump_available() {
	static $available = null;
	if ( null !== $available ) {
		return $available;
	}

	$available = false;
	if ( ! function_exists( 'proc_open' ) ) {
		return false;
	}
	$disabled = ini_get( 'disable_functions' );
	if ( is_string( $disabled ) && false !== stripos( $disabled, 'proc_open' ) ) {
		return false;
	}
	if ( defined( 'DB_PASSWORD' ) && is_string( DB_PASSWORD ) && false !== strpbrk( DB_PASSWORD, "\r\n" ) ) {
		return false;
	}

	$process = @proc_open( array( 'mysqldump', '--version' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		return false;
	}
	if ( isset( $pipes[1] ) ) {
		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
	}
	if ( isset( $pipes[2] ) ) {
		fclose( $pipes[2] );
	}
	$available = 0 === proc_close( $process );

	return $available;
}

/**
 * Run mysqldump for one table into a file.
 *
 * @param string $table Table name.
 * @param string $dest  Output path.
 * @return true|WP_Error
 */
function dgm_run_mysqldump( $table, $dest ) {
	if ( ! dgm_is_valid_identifier( $table ) || ! defined( 'DB_NAME' ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'mysqldump could not be started.', 'demo-growth-memberships' ) );
	}

	$defaults = dgm_snapshot_dir( (int) get_current_blog_id() ) . '/mysql-' . bin2hex( random_bytes( 8 ) ) . '.cnf';
	$host     = dgm_db_host_parts();
	$body     = "[client]\nuser=" . dgm_cnf_value( DB_USER ) . "\npassword=" . dgm_cnf_value( DB_PASSWORD ) . "\nhost=" . dgm_cnf_value( $host['host'] ) . "\n";
	if ( $host['port'] ) {
		$body .= 'port=' . (int) $host['port'] . "\n";
	}
	if ( $host['socket'] ) {
		$body .= 'socket=' . dgm_cnf_value( $host['socket'] ) . "\n";
	}
	if ( false === file_put_contents( $defaults, $body, LOCK_EX ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'mysqldump could not be started.', 'demo-growth-memberships' ) );
	}
	chmod( $defaults, 0600 );

	$command = array(
		'mysqldump',
		'--defaults-extra-file=' . $defaults,
		'--single-transaction',
		'--skip-lock-tables',
		'--no-tablespaces',
		'--skip-add-drop-table',
		'--skip-comments',
		'--skip-add-locks',
		'--compact',
		'--hex-blob',
		'--skip-extended-insert',
		'--default-character-set=utf8mb4',
		'--result-file=' . $dest,
		DB_NAME,
		$table,
	);
	$process = @proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		unlink( $defaults );
		return new WP_Error( 'dgm_snapshot', __( 'mysqldump could not be started.', 'demo-growth-memberships' ) );
	}
	if ( isset( $pipes[1] ) ) {
		fclose( $pipes[1] );
	}
	$stderr = isset( $pipes[2] ) ? (string) stream_get_contents( $pipes[2] ) : '';
	if ( isset( $pipes[2] ) ) {
		fclose( $pipes[2] );
	}
	$code = proc_close( $process );
	unlink( $defaults );
	if ( 0 !== $code ) {
		unset( $stderr );
		return new WP_Error( 'dgm_snapshot', __( 'mysqldump failed.', 'demo-growth-memberships' ) );
	}

	return true;
}

/**
 * Quote a MySQL option-file value.
 *
 * @param string $value Raw value.
 * @return string
 */
function dgm_cnf_value( $value ) {
	return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
}

/**
 * Split DB_HOST the way WordPress does.
 *
 * @return array{host: string, port: int, socket: string}
 */
function dgm_db_host_parts() {
	$host   = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
	$port   = 0;
	$socket = '';
	$extra  = strstr( $host, ':' );
	if ( false !== $extra ) {
		$host  = substr( $host, 0, strpos( $host, ':' ) );
		$extra = substr( $extra, 1 );
		if ( 0 !== strpos( $extra, '/' ) ) {
			$port  = (int) $extra;
			$maybe = strstr( $extra, ':' );
			if ( false !== $maybe ) {
				$socket = substr( $maybe, 1 );
			}
		} else {
			$socket = $extra;
		}
	}

	return array(
		'host'   => $host,
		'port'   => $port,
		'socket' => $socket,
	);
}

/**
 * SHOW CREATE TABLE for a validated table.
 *
 * @param string $table Table name.
 * @return string|WP_Error
 */
function dgm_show_create_table( $table ) {
	global $wpdb;

	$quoted = dgm_quote_identifier( $table );
	if ( '' === $quoted ) {
		return new WP_Error( 'dgm_table', __( 'A snapshot table name is not valid.', 'demo-growth-memberships' ) );
	}

	$row = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N );
	$error = dgm_db_error();
	if ( is_wp_error( $error ) ) {
		return $error;
	}
	if ( ! is_array( $row ) || empty( $row[1] ) || ! is_string( $row[1] ) ) {
		return new WP_Error( 'dgm_table', __( 'Could not read a table definition.', 'demo-growth-memberships' ) );
	}

	return $row[1];
}

/**
 * Single-column primary key, when the table has one.
 *
 * @param string $table Table name.
 * @return string
 */
function dgm_table_primary_key( $table ) {
	global $wpdb;

	$quoted = dgm_quote_identifier( $table );
	$rows   = $wpdb->get_results( "SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'", ARRAY_A );
	if ( ! is_array( $rows ) || 1 !== count( $rows ) || empty( $rows[0]['Column_name'] ) ) {
		return '';
	}

	$column = $rows[0]['Column_name'];
	if ( ! dgm_is_valid_identifier( $column ) ) {
		return '';
	}

	$column_row = $wpdb->get_row( 'SHOW COLUMNS FROM ' . $quoted . ' LIKE \'' . esc_sql( $column ) . '\'', ARRAY_A );
	$type       = is_array( $column_row ) && isset( $column_row['Type'] ) ? strtolower( (string) $column_row['Type'] ) : '';
	if ( ! preg_match( '/^(tiny|small|medium|big)?int\\b/', $type ) ) {
		return '';
	}

	return $column;
}

/**
 * Read the snapshot and reject any table outside this site.
 *
 * @param int    $blog_id Site ID.
 * @param string $path    Snapshot path.
 * @param array  $meta    Manifest.
 * @return string[]|WP_Error
 */
function dgm_scan_snapshot( $blog_id, $path, $meta ) {
	$names = array();
	$walk  = dgm_walk_snapshot(
		$blog_id,
		$path,
		$meta,
		function ( $table ) use ( &$names ) {
			$names[] = $table;
			return true;
		},
		function () {
			return true;
		},
		function () {
			return true;
		}
	);
	if ( is_wp_error( $walk ) ) {
		return $walk;
	}

	global $wpdb;
	$required = array( $wpdb->users, $wpdb->usermeta );
	foreach ( $required as $table ) {
		if ( ! in_array( $table, $names, true ) ) {
			return new WP_Error( 'dgm_snapshot', __( 'The snapshot is missing the network user rows for this site.', 'demo-growth-memberships' ) );
		}
	}

	return $names;
}

/**
 * Create shadow tables and load site data into them.
 *
 * @param int    $blog_id Site ID.
 * @param string $path    Snapshot path.
 * @return array<string, array{fresh: string, old: string}>|WP_Error
 */
function dgm_load_site_shadows( $blog_id, $path ) {
	global $wpdb;

	$map  = array();
	$meta = array( 'created' => 0 );
	$walk = dgm_walk_snapshot(
		$blog_id,
		$path,
		$meta,
		function ( $table, $mode, $create, $count ) use ( &$map, $wpdb ) {
			if ( $table === $wpdb->users || $table === $wpdb->usermeta ) {
				return true;
			}
			$names = dgm_shadow_names( $table );
			$map[ $table ] = $names;
			dgm_remember_shadow( $names );
			if ( 'mysql' === $mode ) {
				return true;
			}
			$sql = dgm_rewrite_table_sql( $create, $table, $names['fresh'] );
			if ( is_wp_error( $sql ) ) {
				return $sql;
			}
			$wpdb->query( 'DROP TABLE IF EXISTS ' . dgm_quote_identifier( $names['fresh'] ) );
			$wpdb->query( $sql );
			$error = dgm_db_error();
			return $error ? $error : true;
		},
		function ( $table, $row ) use ( &$map, $wpdb ) {
			if ( $table === $wpdb->users || $table === $wpdb->usermeta ) {
				return true;
			}
			if ( ! isset( $map[ $table ] ) ) {
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot table order is not valid.', 'demo-growth-memberships' ) );
			}
			$inserted = dgm_insert_row( $map[ $table ]['fresh'], $row );
			return $inserted ? $inserted : true;
		},
		function ( $table, $count, $sql_path ) use ( &$map ) {
			if ( ! isset( $map[ $table ] ) ) {
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot table order is not valid.', 'demo-growth-memberships' ) );
			}
			$loaded = dgm_import_mysqldump_file( $sql_path, $table, $map[ $table ]['fresh'] );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			return dgm_assert_row_count( $map[ $table ]['fresh'], $count );
		}
	);

	if ( is_wp_error( $walk ) ) {
		return $walk;
	}

	foreach ( $map as $table => $names ) {
		$expected = dgm_shadow_expected_count( $table );
		if ( null !== $expected ) {
			$check = dgm_assert_row_count( $names['fresh'], $expected );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
	}

	return $map;
}

/**
 * Swap every shadow table into place with one RENAME TABLE.
 *
 * @param array<string, array{fresh: string, old: string}> $map Tables.
 * @return true|WP_Error
 */
function dgm_swap_shadow_tables( $map ) {
	global $wpdb;

	if ( ! $map ) {
		return true;
	}

	$parts = array();
	foreach ( $map as $live => $names ) {
		$live_sql  = dgm_quote_identifier( $live );
		$fresh_sql = dgm_quote_identifier( $names['fresh'] );
		$old_sql   = dgm_quote_identifier( $names['old'] );
		if ( '' === $live_sql || '' === $fresh_sql || '' === $old_sql ) {
			return new WP_Error( 'dgm_table', __( 'A snapshot table name is not valid.', 'demo-growth-memberships' ) );
		}
		if ( dgm_table_exists( $live ) ) {
			$parts[] = "{$live_sql} TO {$old_sql}";
		}
		$parts[] = "{$fresh_sql} TO {$live_sql}";
	}

	$wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) );
	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Replace network user rows for this site and drop users created after the snapshot.
 *
 * @param string $path Snapshot path.
 * @return true|WP_Error
 */
function dgm_apply_network_users( $path ) {
	global $wpdb;

	$blog_id  = (int) get_current_blog_id();
	$ids_name = $wpdb->get_blog_prefix() . 'dgm_snap_ids';
	if ( ! dgm_is_valid_identifier( $ids_name ) ) {
		return new WP_Error( 'dgm_table', __( 'Could not prepare the user restore.', 'demo-growth-memberships' ) );
	}
	$quoted = dgm_quote_identifier( $ids_name );
	$wpdb->query( "DROP TABLE IF EXISTS {$quoted}" );
	$wpdb->query( "CREATE TABLE {$quoted} (user_id bigint(20) unsigned NOT NULL, PRIMARY KEY (user_id))" );
	$error = dgm_db_error();
	if ( is_wp_error( $error ) ) {
		return $error;
	}

	$meta = array( 'created' => 0 );
	$walk = dgm_walk_snapshot(
		$blog_id,
		$path,
		$meta,
		function ( $table ) use ( $wpdb ) {
			return ( $table === $wpdb->users || $table === $wpdb->usermeta ) ? true : true;
		},
		function ( $table, $row ) use ( $wpdb, $quoted ) {
			if ( $table === $wpdb->users ) {
				$user_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
				$login   = isset( $row['user_login'] ) && is_string( $row['user_login'] ) ? $row['user_login'] : '';
				if ( dgm_restore_preserves_user( $user_id, $login ) ) {
					if ( $user_id > 0 ) {
						$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$quoted} (user_id) VALUES (%d)", $user_id ) );
					}
					return true;
				}
				$saved = dgm_upsert_row( $wpdb->users, $row, 'ID' );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				if ( $user_id > 0 ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$quoted} (user_id) VALUES (%d)", $user_id ) );
					$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id, 'meta_key' => 'session_tokens' ) );
				}
				return true;
			}
			if ( $table === $wpdb->usermeta ) {
				if ( isset( $row['meta_key'] ) && 'session_tokens' === $row['meta_key'] ) {
					return true;
				}
				$owner_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				if ( dgm_restore_preserves_user( $owner_id ) ) {
					return true;
				}
				if ( isset( $row['umeta_id'] ) ) {
					$current_owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE umeta_id = %d", (int) $row['umeta_id'] ) );
					if ( $current_owner > 0 && dgm_restore_preserves_user( $current_owner ) ) {
						return true;
					}
				}
				return dgm_upsert_row( $wpdb->usermeta, $row, 'umeta_id' );
			}
			return true;
		},
		function () {
			return true;
		}
	);
	if ( is_wp_error( $walk ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$quoted}" );
		return $walk;
	}

	$removed = dgm_remove_users_missing_from_snapshot( $ids_name );
	$wpdb->query( "DROP TABLE IF EXISTS {$quoted}" );

	return $removed;
}

/**
 * Remove this site's role from users who were not in the snapshot.
 *
 * @param string $ids_table Snapshot id table.
 * @return true|WP_Error
 */
function dgm_remove_users_missing_from_snapshot( $ids_table ) {
	global $wpdb;

	$quoted = dgm_quote_identifier( $ids_table );
	$prefix = $wpdb->get_blog_prefix();
	$cap    = $prefix . 'capabilities';
	$blog   = (int) get_current_blog_id();
	$last   = 0;

	do {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d AND user_id NOT IN (SELECT user_id FROM {$quoted}) ORDER BY user_id ASC LIMIT %d", $cap, $last, 100 ) );
		if ( ! is_array( $ids ) || ! $ids ) {
			break;
		}
		foreach ( $ids as $user_id ) {
			$user_id = (int) $user_id;
			$last    = $user_id;
			if ( dgm_restore_preserves_user( $user_id ) ) {
				continue;
			}
			$like    = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, $like ) );
			if ( dgm_user_has_another_site_role( $user_id, $blog ) ) {
				continue;
			}
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ) );
			$wpdb->delete( $wpdb->users, array( 'ID' => $user_id ) );
		}
	} while ( count( $ids ) === 100 );

	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Whether a user has a role on a site other than this one.
 *
 * @param int $user_id User ID.
 * @param int $blog_id Current site ID.
 * @return bool
 */
function dgm_user_has_another_site_role( $user_id, $blog_id ) {
	global $wpdb;

	if ( ! is_multisite() ) {
		return false;
	}

	$sites = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( (array) $sites as $site_id ) {
		$site_id = (int) $site_id;
		if ( $site_id === (int) $blog_id ) {
			continue;
		}
		$key   = $wpdb->get_blog_prefix( $site_id ) . 'capabilities';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1", $user_id, $key ) );
		if ( $found ) {
			return true;
		}
	}

	return false;
}

/**
 * Delete attachment rows and unreferenced uploads newer than the snapshot.
 *
 * @param int $created Snapshot timestamp.
 * @return int|WP_Error
 */
function dgm_cleanup_uploads( $created ) {
	global $wpdb;

	$created = (int) $created;
	if ( $created <= 0 ) {
		return new WP_Error( 'dgm_uploads', __( 'The snapshot has no creation time.', 'demo-growth-memberships' ) );
	}

	$removed = 0;
	$cutoff  = gmdate( 'Y-m-d H:i:s', $created );
	$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt > %s", 'attachment', $cutoff ) );
	foreach ( (array) $ids as $id ) {
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ) );
		++$removed;
	}

	$uploads = wp_get_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return new WP_Error( 'dgm_uploads', __( 'Could not read this site\'s uploads directory.', 'demo-growth-memberships' ) );
	}

	$base = realpath( $uploads['basedir'] );
	if ( false === $base || ! is_dir( $base ) ) {
		return $removed;
	}

	$referenced = dgm_referenced_upload_files();
	$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->isLink() ) {
			continue;
		}
		$real = realpath( $file->getPathname() );
		if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) . '/' ) ) {
			continue;
		}
		if ( $file->getMTime() <= $created ) {
			continue;
		}
		$relative = ltrim( substr( wp_normalize_path( $real ), strlen( wp_normalize_path( $base ) ) ), '/' );
		if ( isset( $referenced[ $relative ] ) ) {
			continue;
		}
		if ( @unlink( $real ) ) {
			++$removed;
		}
	}

	return $removed;
}

/**
 * Relative upload paths still used by attachments.
 *
 * @return array<string, bool>
 */
function dgm_referenced_upload_files() {
	global $wpdb;

	$files = array();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attached_file' ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		if ( isset( $row['meta_value'] ) && is_string( $row['meta_value'] ) && '' !== $row['meta_value'] ) {
			$files[ ltrim( wp_normalize_path( $row['meta_value'] ), '/' ) ] = true;
		}
	}

	$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attachment_metadata' ), ARRAY_A );
	foreach ( (array) $meta_rows as $row ) {
		if ( empty( $row['meta_value'] ) || ! is_string( $row['meta_value'] ) ) {
			continue;
		}
		$meta = maybe_unserialize( $row['meta_value'] );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			continue;
		}
		$files[ ltrim( wp_normalize_path( $meta['file'] ), '/' ) ] = true;
		$dir = trailingslashit( dirname( $meta['file'] ) );
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$files[ ltrim( wp_normalize_path( $dir . $size['file'] ), '/' ) ] = true;
				}
			}
		}
	}

	return $files;
}

/**
 * Walk a snapshot file without loading a table into memory.
 *
 * @param int      $blog_id Site ID.
 * @param string   $path    Snapshot path.
 * @param array    $meta    Manifest.
 * @param callable $on_table function( $table, $mode, $create, $count ).
 * @param callable $on_row   function( $table, $row ).
 * @param callable $on_sql   function( $table, $count, $sql_path ).
 * @return true|WP_Error
 */
function dgm_walk_snapshot( $blog_id, $path, $meta, $on_table, $on_row, $on_sql ) {
	global $wpdb;

	$handle = fopen( $path, 'rb' );
	if ( false === $handle ) {
		return new WP_Error( 'dgm_snapshot', __( 'Could not read the snapshot.', 'demo-growth-memberships' ) );
	}

	$header = array();
	$line   = '';
	while ( true ) {
		$line = dgm_read_snapshot_line( $handle );
		if ( is_wp_error( $line ) || null === $line ) {
			fclose( $handle );
			return is_wp_error( $line ) ? $line : new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
		}
		if ( 'DGM1' === $line ) {
			continue;
		}
		if ( 'done' === $line || 0 === strpos( $line, 'table ' ) ) {
			break;
		}
		$parts = explode( ' ', $line, 2 );
		$header[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : '';
	}

	if ( ! isset( $header['blog'] ) || (int) $header['blog'] !== (int) $blog_id || ! isset( $header['prefix'] ) || $header['prefix'] !== $wpdb->get_blog_prefix() ) {
		fclose( $handle );
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot belongs to a different site.', 'demo-growth-memberships' ) );
	}
	if ( ! empty( $meta['created'] ) && isset( $header['created'] ) && (int) $header['created'] !== (int) $meta['created'] ) {
		fclose( $handle );
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot manifest does not match the file.', 'demo-growth-memberships' ) );
	}

	$counts = array();
	while ( 'done' !== $line ) {
		$bits = explode( ' ', $line );
		if ( count( $bits ) < 3 || 'table' !== $bits[0] || ! dgm_table_is_allowed( $bits[1], $wpdb->get_blog_prefix(), $wpdb->users, $wpdb->usermeta ) ) {
			fclose( $handle );
			return new WP_Error( 'dgm_snapshot', __( 'The snapshot contains a table that is outside this site.', 'demo-growth-memberships' ) );
		}
		$table = $bits[1];
		$mode  = $bits[2];
		if ( 'mysql' === $mode ) {
			$count_line = dgm_read_snapshot_line( $handle );
			$bytes_line = dgm_read_snapshot_line( $handle );
			if ( ! is_string( $count_line ) || ! is_string( $bytes_line ) || 0 !== strpos( $count_line, 'count ' ) || 0 !== strpos( $bytes_line, 'bytes ' ) ) {
				fclose( $handle );
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
			}
			$count  = (int) substr( $count_line, 6 );
			$bytes  = (int) substr( $bytes_line, 6 );
			$result = call_user_func( $on_table, $table, 'mysql', '', $count );
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
				return $result;
			}
			$tmp = dgm_snapshot_dir( $blog_id ) . '/tmp-' . bin2hex( random_bytes( 8 ) ) . '.sql';
			$out = fopen( $tmp, 'wb' );
			$left = $bytes;
			while ( $left > 0 ) {
				$chunk = fread( $handle, min( 8192, $left ) );
				if ( ! is_string( $chunk ) || '' === $chunk ) {
					if ( is_resource( $out ) ) {
						fclose( $out );
					}
					if ( is_file( $tmp ) ) {
						unlink( $tmp );
					}
					fclose( $handle );
					return new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
				}
				fwrite( $out, $chunk );
				$left -= strlen( $chunk );
			}
			fclose( $out );
			fread( $handle, 1 );
			$end = dgm_read_snapshot_line( $handle );
			$result = ( 'endtable' === $end ) ? call_user_func( $on_sql, $table, $count, $tmp ) : new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
				return $result;
			}
		} else {
			$create_line = dgm_read_snapshot_line( $handle );
			$count_line  = dgm_read_snapshot_line( $handle );
			if ( ! is_string( $create_line ) || ! is_string( $count_line ) || 0 !== strpos( $create_line, 'create ' ) || 0 !== strpos( $count_line, 'count ' ) ) {
				fclose( $handle );
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
			}
			$create = base64_decode( substr( $create_line, 7 ), true );
			$count  = (int) substr( $count_line, 6 );
			if ( ! is_string( $create ) ) {
				fclose( $handle );
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot table definition is not valid.', 'demo-growth-memberships' ) );
			}
			$counts[ $table ] = $count;
			$result = call_user_func( $on_table, $table, 'php', $create, $count );
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
				return $result;
			}
			$seen = 0;
			while ( true ) {
				$row_line = dgm_read_snapshot_line( $handle );
				if ( is_wp_error( $row_line ) || null === $row_line ) {
					fclose( $handle );
					return is_wp_error( $row_line ) ? $row_line : new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
				}
				if ( 'endtable' === $row_line ) {
					break;
				}
				if ( 0 !== strpos( $row_line, 'row ' ) ) {
					fclose( $handle );
					return new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
				}
				++$seen;
				$decoded = dgm_decode_row( substr( $row_line, 4 ) );
				if ( ! is_array( $decoded ) ) {
					fclose( $handle );
					return new WP_Error( 'dgm_snapshot', __( 'A snapshot row could not be read.', 'demo-growth-memberships' ) );
				}
				$result = call_user_func( $on_row, $table, $decoded );
				if ( is_wp_error( $result ) ) {
					fclose( $handle );
					return $result;
				}
			}
			if ( $seen !== $count ) {
				fclose( $handle );
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot row count did not match.', 'demo-growth-memberships' ) );
			}
		}

		$line = dgm_read_snapshot_line( $handle );
		if ( is_wp_error( $line ) || null === $line ) {
			fclose( $handle );
			return is_wp_error( $line ) ? $line : new WP_Error( 'dgm_snapshot', __( 'The snapshot is incomplete.', 'demo-growth-memberships' ) );
		}
	}

	fclose( $handle );
	$GLOBALS['dgm_shadow_counts'] = $counts;

	return true;
}

/**
 * Read one snapshot line, refusing a single huge line.
 *
 * @param resource $handle File handle.
 * @return string|null|WP_Error
 */
function dgm_read_snapshot_line( $handle ) {
	$line = fgets( $handle, 48 * 1024 * 1024 );
	if ( false === $line ) {
		return null;
	}
	if ( strlen( $line ) >= ( 48 * 1024 * 1024 ) - 1 && "\n" !== substr( $line, -1 ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot contains a row that is too large.', 'demo-growth-memberships' ) );
	}

	return rtrim( $line, "\r\n" );
}

/**
 * Shadow and rollback names for a live table.
 *
 * @param string $table Live table.
 * @return array{fresh: string, old: string}
 */
function dgm_shadow_names( $table ) {
	$fresh = $table . '__dgmnew';
	$old   = $table . '__dgmold';
	if ( strlen( $fresh ) > 64 || strlen( $old ) > 64 ) {
		$fresh = 'dgm' . substr( md5( $table . 'new' ), 0, 16 );
		$old   = 'dgm' . substr( md5( $table . 'old' ), 0, 16 );
	}

	return array(
		'fresh' => $fresh,
		'old'   => $old,
	);
}

/**
 * Remember short shadow names so a later run can drop them.
 *
 * @param array{fresh: string, old: string} $names Names.
 */
function dgm_remember_shadow( $names ) {
	$path = dgm_snapshot_dir( (int) get_current_blog_id() ) . '/shadows.json';
	$data = dgm_read_json_file( $path );
	$list = is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ) ? $data['tables'] : array();
	$list[] = $names['fresh'];
	$list[] = $names['old'];
	dgm_write_json_file( $path, array( 'tables' => array_values( array_unique( $list ) ) ) );
}

/**
 * Drop shadow, rollback, and helper tables.
 */
function dgm_drop_ephemeral_tables() {
	global $wpdb;

	$prefix = $wpdb->get_blog_prefix();
	$like   = $wpdb->esc_like( $prefix ) . '%';
	$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	foreach ( (array) $found as $table ) {
		if ( is_string( $table ) && dgm_is_skipped_site_table( $table, $prefix ) ) {
			$quoted = dgm_quote_identifier( $table );
			if ( '' !== $quoted ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$quoted}" );
			}
		}
	}

	$path = dgm_snapshot_dir( (int) get_current_blog_id() ) . '/shadows.json';
	$data = dgm_read_json_file( $path );
	if ( is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ) ) {
		foreach ( $data['tables'] as $table ) {
			if ( dgm_is_valid_identifier( $table ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . dgm_quote_identifier( $table ) );
			}
		}
	}
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

/**
 * Drop site tables created after the snapshot.
 *
 * @param string[] $keep Live table names that were restored.
 */
function dgm_drop_tables_not_in_snapshot( $keep ) {
	global $wpdb;

	$keep   = array_fill_keys( $keep, true );
	$prefix = $wpdb->get_blog_prefix();
	$like   = $wpdb->esc_like( $prefix ) . '%';
	$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	foreach ( (array) $found as $table ) {
		if ( ! is_string( $table ) || isset( $keep[ $table ] ) || $table === $wpdb->users || $table === $wpdb->usermeta ) {
			continue;
		}
		if ( ! dgm_table_is_allowed( $table, $prefix, $wpdb->users, $wpdb->usermeta ) ) {
			continue;
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . dgm_quote_identifier( $table ) );
	}
}

/**
 * Point a CREATE TABLE statement at the shadow table.
 *
 * @param string $sql   CREATE statement.
 * @param string $from  Original table.
 * @param string $to    Shadow table.
 * @return string|WP_Error
 */
function dgm_rewrite_table_sql( $sql, $from, $to ) {
	if ( ! dgm_is_valid_identifier( $from ) || ! dgm_is_valid_identifier( $to ) ) {
		return new WP_Error( 'dgm_table', __( 'A snapshot table name is not valid.', 'demo-growth-memberships' ) );
	}

	$rewritten = preg_replace( '/^CREATE TABLE `' . preg_quote( $from, '/' ) . '`/', 'CREATE TABLE `' . $to . '`', $sql, 1, $count );
	if ( ! is_string( $rewritten ) || 1 !== (int) $count ) {
		return new WP_Error( 'dgm_table', __( 'The snapshot table definition is not valid.', 'demo-growth-memberships' ) );
	}

	return $rewritten;
}

/**
 * Insert one decoded row.
 *
 * @param string $table Table name.
 * @param array  $row   Row.
 * @return true|WP_Error
 */
function dgm_insert_row( $table, $row ) {
	global $wpdb;

	if ( '' === dgm_quote_identifier( $table ) ) {
		return new WP_Error( 'dgm_table', __( 'A snapshot table name is not valid.', 'demo-growth-memberships' ) );
	}
	foreach ( array_keys( $row ) as $column ) {
		if ( ! dgm_is_valid_identifier( $column ) ) {
			return new WP_Error( 'dgm_table', __( 'A snapshot column name is not valid.', 'demo-growth-memberships' ) );
		}
	}

	$wpdb->insert( $table, $row );
	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Insert or update a row by its primary key.
 *
 * @param string $table   Table name.
 * @param array  $row     Row.
 * @param string $primary Primary column.
 * @return true|WP_Error
 */
function dgm_upsert_row( $table, $row, $primary ) {
	global $wpdb;

	if ( ! isset( $row[ $primary ] ) || '' === dgm_quote_identifier( $table ) || ! dgm_is_valid_identifier( $primary ) ) {
		return new WP_Error( 'dgm_table', __( 'A snapshot row is missing its primary key.', 'demo-growth-memberships' ) );
	}
	foreach ( array_keys( $row ) as $column ) {
		if ( ! dgm_is_valid_identifier( $column ) ) {
			return new WP_Error( 'dgm_table', __( 'A snapshot column name is not valid.', 'demo-growth-memberships' ) );
		}
	}

	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . dgm_quote_identifier( $primary ) . ' FROM ' . dgm_quote_identifier( $table ) . ' WHERE ' . dgm_quote_identifier( $primary ) . ' = %s LIMIT 1', (string) $row[ $primary ] ) );
	if ( null !== $exists ) {
		$data = $row;
		unset( $data[ $primary ] );
		$wpdb->update( $table, $data, array( $primary => $row[ $primary ] ) );
	} else {
		$wpdb->insert( $table, $row );
	}
	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Import a mysqldump script into a shadow table.
 *
 * @param string $path   SQL file.
 * @param string $from   Original table.
 * @param string $shadow Shadow table.
 * @return true|WP_Error
 */
function dgm_import_mysqldump_file( $path, $from, $shadow ) {
	global $wpdb;

	$handle = fopen( $path, 'rb' );
	if ( false === $handle ) {
		return new WP_Error( 'dgm_snapshot', __( 'Could not read the snapshot.', 'demo-growth-memberships' ) );
	}

	$sql      = '';
	$in_quote = false;
	$quote    = '';
	$escape   = false;
	while ( ! feof( $handle ) ) {
		$chunk = fread( $handle, 8192 );
		if ( ! is_string( $chunk ) || '' === $chunk ) {
			break;
		}
		$length = strlen( $chunk );
		for ( $i = 0; $i < $length; $i++ ) {
			$char  = $chunk[ $i ];
			$sql  .= $char;
			if ( strlen( $sql ) > 32 * 1024 * 1024 ) {
				fclose( $handle );
				return new WP_Error( 'dgm_snapshot', __( 'The snapshot contains a row that is too large.', 'demo-growth-memberships' ) );
			}
			if ( $in_quote ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( $char === $quote ) {
					$in_quote = false;
				}
				continue;
			}
			if ( '\'' === $char || '"' === $char || '`' === $char ) {
				$in_quote = true;
				$quote    = $char;
				continue;
			}
			if ( ';' !== $char ) {
				continue;
			}
			$ran = dgm_run_dump_statement( trim( $sql ), $from, $shadow );
			$sql = '';
			if ( is_wp_error( $ran ) ) {
				fclose( $handle );
				return $ran;
			}
		}
	}
	fclose( $handle );
	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Run one mysqldump statement after renaming its table.
 *
 * @param string $sql    Statement.
 * @param string $from   Original table.
 * @param string $shadow Shadow table.
 * @return true|WP_Error
 */
function dgm_run_dump_statement( $sql, $from, $shadow ) {
	global $wpdb;

	if ( '' === $sql || ';' === $sql ) {
		return true;
	}
	$upper = strtoupper( ltrim( $sql ) );
	if ( 0 === strpos( $upper, 'SET ' ) || 0 === strpos( $upper, 'LOCK ' ) || 0 === strpos( $upper, 'UNLOCK ' ) || 0 === strpos( $upper, '/*' ) ) {
		return true;
	}
	if ( 0 !== strpos( $upper, 'CREATE TABLE' ) && 0 !== strpos( $upper, 'INSERT INTO' ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot contains an unexpected SQL statement.', 'demo-growth-memberships' ) );
	}
	if ( false === strpos( $sql, '`' . $from . '`' ) ) {
		return new WP_Error( 'dgm_snapshot', __( 'The snapshot contains a table that is outside this site.', 'demo-growth-memberships' ) );
	}

	$rewritten = preg_replace( '/`' . preg_quote( $from, '/' ) . '`/', '`' . $shadow . '`', $sql, 1 );
	if ( ! is_string( $rewritten ) || ! dgm_is_valid_identifier( $shadow ) ) {
		return new WP_Error( 'dgm_table', __( 'A snapshot table name is not valid.', 'demo-growth-memberships' ) );
	}
	if ( 0 === strpos( $upper, 'CREATE TABLE' ) ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . dgm_quote_identifier( $shadow ) );
	}
	$wpdb->query( $rewritten );
	$error = dgm_db_error();

	return $error ? $error : true;
}

/**
 * Compare a shadow table with the snapshot count.
 *
 * @param string $table Table name.
 * @param int    $count Expected rows.
 * @return true|WP_Error
 */
function dgm_assert_row_count( $table, $count ) {
	global $wpdb;

	$quoted = dgm_quote_identifier( $table );
	$got    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted}" );
	$error  = dgm_db_error();
	if ( is_wp_error( $error ) ) {
		return $error;
	}
	if ( $got !== (int) $count ) {
		return new WP_Error( 'dgm_snapshot', __( 'A restored table did not match the snapshot row count.', 'demo-growth-memberships' ) );
	}

	return true;
}

/**
 * Expected PHP row count recorded while walking the snapshot.
 *
 * @param string $table Table name.
 * @return int|null
 */
function dgm_shadow_expected_count( $table ) {
	$counts = isset( $GLOBALS['dgm_shadow_counts'] ) && is_array( $GLOBALS['dgm_shadow_counts'] ) ? $GLOBALS['dgm_shadow_counts'] : array();

	return isset( $counts[ $table ] ) ? (int) $counts[ $table ] : null;
}

/**
 * Whether a table exists.
 *
 * @param string $table Table name.
 * @return bool
 */
function dgm_table_exists( $table ) {
	global $wpdb;

	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	return $found === $table;
}

/**
 * Copy every dgm_ option out of the database.
 *
 * @return array<int, array<string, string>>
 */
function dgm_capture_plugin_options() {
	global $wpdb;

	$like = $wpdb->esc_like( 'dgm_' ) . '%';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Write the captured plugin options back after a swap.
 *
 * @param array $rows Captured rows.
 */
function dgm_reapply_plugin_options( $rows ) {
	global $wpdb;

	if ( ! is_array( $rows ) ) {
		return;
	}

	$like = $wpdb->esc_like( 'dgm_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['option_name'] ) || ! is_string( $row['option_name'] ) || 0 !== strpos( $row['option_name'], 'dgm_' ) ) {
			continue;
		}
		$autoload = ! isset( $row['autoload'] ) || ( 'no' !== $row['autoload'] && 'off' !== $row['autoload'] );
		update_option( $row['option_name'], maybe_unserialize( $row['option_value'] ), $autoload );
	}
}

/**
 * Flush caches, transients, and rewrite rules for this site.
 */
function dgm_finish_caches() {
	global $wpdb;

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%'
		)
	);
	flush_rewrite_rules( false );
}

/**
 * Append the reset log and the success time.
 *
 * @param bool   $ok       Success.
 * @param float  $seconds  Duration.
 * @param string $error    Error message.
 * @param int    $files    Files removed.
 */
function dgm_record_reset( $ok, $seconds, $error, $files ) {
	$log = get_option( 'dgm_reset_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	array_unshift(
		$log,
		array(
			'time'     => time(),
			'duration' => round( (float) $seconds, 2 ),
			'result'   => $ok ? 'success' : 'failure',
			'error'    => $ok ? '' : substr( (string) $error, 0, 500 ),
			'files'    => (int) $files,
		)
	);
	update_option( 'dgm_reset_log', array_slice( $log, 0, 10 ), false );
	if ( $ok ) {
		update_option( 'dgm_last_success', time(), false );
	}

	dgm_log_line( (int) get_current_blog_id(), ( $ok ? 'Restore succeeded' : 'Restore failed' ) . ' in ' . round( (float) $seconds, 2 ) . 's. ' . substr( (string) $error, 0, 500 ) );
}

/**
 * Delete snapshot files that are no longer current or the failsafe copy.
 *
 * @param int $blog_id Site ID.
 */
function dgm_cleanup_orphan_snapshots( $blog_id ) {
	$keep = array();
	foreach ( array( 'current', 'failsafe' ) as $kind ) {
		$meta = dgm_snapshot_meta( $blog_id, $kind );
		if ( $meta ) {
			$keep[] = $meta['file'];
		}
	}

	$matches = glob( dgm_snapshot_dir( $blog_id ) . '/*.dgm' );
	foreach ( (array) $matches as $file ) {
		$base = basename( $file );
		if ( in_array( $base, $keep, true ) ) {
			continue;
		}
		unlink( $file );
		if ( is_file( $file . '.sha256' ) ) {
			unlink( $file . '.sha256' );
		}
	}
}

/**
 * prepare() that accepts a list of values on WordPress 5.8.
 *
 * @param string $sql    SQL with placeholders.
 * @param array  $values Values.
 * @return string
 */
function dgm_prepare_values( $sql, $values ) {
	global $wpdb;

	$arguments = array_merge( array( $sql ), array_values( $values ) );

	return (string) call_user_func_array( array( $wpdb, 'prepare' ), $arguments );
}

/**
 * How many of these user IDs still have a users row.
 *
 * @param int[] $ids User IDs.
 * @return int
 */
function dgm_count_user_rows( $ids ) {
	global $wpdb;

	$total = 0;
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		if ( ! $chunk ) {
			continue;
		}
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$total       += (int) $wpdb->get_var( dgm_prepare_values( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID IN ({$placeholders})", $chunk ) );
	}

	return $total;
}

/**
 * Last database error, if one is set.
 *
 * @return WP_Error|null
 */
function dgm_db_error() {
	global $wpdb;

	if ( ! is_string( $wpdb->last_error ) || '' === $wpdb->last_error ) {
		return null;
	}

	$message          = substr( $wpdb->last_error, 0, 500 );
	$wpdb->last_error = '';

	return new WP_Error( 'dgm_db', $message );
}

/**
 * Give a restore enough time and memory to finish.
 */
function dgm_raise_restore_limits() {
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}
	ignore_user_abort( true );
}
