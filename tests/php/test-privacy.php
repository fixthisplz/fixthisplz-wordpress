<?php

use FixThisPlz\Activity;
use FixThisPlz\Installer;
use FixThisPlz\Privacy;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Retention;
use FixThisPlz\Screenshot_Store;

/** Stores a tiny real screenshot and returns its private file name. */
function privacy_screenshot(): string {
	$im = imagecreatetruecolor( 20, 20 );
	ob_start();
	imagepng( $im );
	$tmp = wp_tempnam( 'fixthisplz-privacy' );
	file_put_contents( $tmp, ob_get_clean() );
	$name = Screenshot_Store::store( $tmp );
	unlink( $tmp );
	if ( ! is_string( $name ) ) {
		throw new RuntimeException( 'screenshot fixture failed' );
	}
	return $name;
}

fixthisplz_test(
	'privacy: exporter_finds_reports_by_contact_email_and_by_user',
	function () {
		$repo  = new Reports_Repository();
		$uid   = wp_insert_user( array( 'user_login' => 'fixthisplz_priv_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'priv-user@example.test' ) );
		$by_em = $repo->insert( array( 'description' => 'by email', 'page_url' => 'https://example.test/a', 'reporter_email' => 'Priv-User@Example.test', 'ip_hash' => str_repeat( 'b', 64 ), 'context' => array( 'secret' => 'x' ) ) );
		$by_us = $repo->insert( array( 'description' => 'by user', 'page_url' => 'https://example.test/b', 'user_id' => $uid ) );
		$both  = $repo->insert( array( 'description' => 'both', 'page_url' => 'https://example.test/c', 'user_id' => $uid, 'reporter_email' => 'priv-user@example.test' ) );
		$other = $repo->insert( array( 'description' => 'other', 'page_url' => 'https://example.test/d', 'reporter_email' => 'someone@example.test' ) );

		$res = Privacy::export( 'priv-user@example.test', 1 );
		assert_true( $res['done'], 'single page' );
		$ids = array_map( fn( $i ) => $i['item_id'], $res['data'] );
		assert_same( array( 'bug-report-' . $by_em, 'bug-report-' . $by_us, 'bug-report-' . $both ), $ids );
		assert_true( ! in_array( 'bug-report-' . $other, $ids, true ), 'other reporter excluded' );
		assert_same( 'fixthisplz', $res['data'][0]['group_id'] );
		$names = array_column( $res['data'][0]['data'], 'name' );
		assert_same( 7, count( $names ), 'five fields, contact e-mail, screenshot flag' );
		assert_true( in_array( 'Contact e-mail', $names, true ) && in_array( 'Screenshot attached', $names, true ) );
		assert_same( 'Priv-User@Example.test', $res['data'][0]['data'][5]['value'] );
		assert_same( 'No', $res['data'][0]['data'][6]['value'] );
		assert_same( 6, count( $res['data'][1]['data'] ), 'no contact e-mail line when none' );
		$dump = wp_json_encode( $res );
		assert_true( false === strpos( $dump, 'bbbbbbbb' ), 'ip hash must not be exported' );
		assert_true( false === strpos( $dump, '"secret"' ), 'context internals must not be exported' );
		assert_same( 'by email', $res['data'][0]['data'][3]['value'] );

		$reg = Privacy::register_exporter( array() );
		assert_true( is_callable( $reg['fixthisplz']['callback'] ), 'exporter registered' );
		assert_true( is_callable( Privacy::register_eraser( array() )['fixthisplz']['callback'] ), 'eraser registered' );
	}
);

fixthisplz_test(
	'privacy: exporter_paginates_by_fifty',
	function () {
		$repo = new Reports_Repository();
		for ( $i = 0; $i < 51; $i++ ) {
			$repo->insert( array( 'description' => 'p' . $i, 'page_url' => 'https://example.test/p', 'reporter_email' => 'page@example.test' ) );
		}
		$one = Privacy::export( 'page@example.test', 1 );
		$two = Privacy::export( 'page@example.test', 2 );
		assert_same( 50, count( $one['data'] ) );
		assert_same( false, $one['done'] );
		assert_same( 1, count( $two['data'] ) );
		assert_same( true, $two['done'] );
	}
);

fixthisplz_test(
	'privacy: eraser_deletes_reports_and_screenshots',
	function () {
		$repo  = new Reports_Repository();
		$shot  = privacy_screenshot();
		$mine  = $repo->insert( array( 'description' => 'mine', 'page_url' => 'https://example.test/a', 'reporter_email' => 'erase@example.test', 'screenshot' => $shot ) );
		$other = $repo->insert( array( 'description' => 'other', 'page_url' => 'https://example.test/b', 'reporter_email' => 'keep@example.test' ) );

		$res = Privacy::erase( 'erase@example.test', 1 );
		assert_same( true, $res['items_removed'] );
		assert_same( false, $res['items_retained'] );
		assert_same( array(), $res['messages'] );
		assert_same( true, $res['done'] );
		assert_same( null, $repo->find( $mine ), 'report deleted' );
		assert_true( ! file_exists( Installer::private_dir() . '/' . $shot ), 'screenshot deleted' );
		assert_true( null !== $repo->find( $other ), 'other reporter untouched' );

		$again = Privacy::erase( 'erase@example.test', 1 );
		assert_same( false, $again['items_removed'] );
	}
);

fixthisplz_test(
	'privacy: eraser_flags_retained_and_stops_when_deletes_fail',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		for ( $i = 0; $i < 51; $i++ ) {
			$repo->insert( array( 'description' => 'f' . $i, 'page_url' => 'https://example.test/f', 'reporter_email' => 'fail@example.test' ) );
		}
		// Turns every DELETE into invalid SQL so wpdb::delete() returns false.
		$break = static fn( $q ) => 0 === strpos( ltrim( $q ), 'DELETE FROM `' . Installer::table() . '`' ) ? 'DELETE FROM fixthisplz_no_such_table' : $q;
		$wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$res = Privacy::erase( 'fail@example.test', 1 );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( false );
		}
		assert_same( false, $res['items_removed'] );
		assert_same( true, $res['items_retained'] );
		assert_same( 1, count( $res['messages'] ) );
		assert_same( true, $res['done'], 'no progress must end the loop' );
		assert_same( 51, count( $repo->ids_by_email( 'fail@example.test' ) ) );
	}
);

fixthisplz_test(
	'privacy: policy_content_is_registered',
	function () {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' ); // The core helper only accepts calls made in the admin.
		// Core refuses the call outside admin_init; simulate the hook having fired, then restore the counter.
		global $wp_actions;
		$before = $wp_actions['admin_init'] ?? null;
		$wp_actions['admin_init'] = 1;
		try {
			Privacy::add_policy_content();
		} finally {
			if ( null === $before ) {
				unset( $wp_actions['admin_init'] );
			} else {
				$wp_actions['admin_init'] = $before;
			}
		}
		$found = false;
		foreach ( WP_Privacy_Policy_Content::get_suggested_policy_text() as $entry ) {
			if ( 'FixThisPlz' === $entry['plugin_name'] ) {
				$found = true;
				assert_true( false !== strpos( $entry['policy_text'], 'legitimate interest' ), 'legal basis present' );
			}
		}
		assert_true( $found, 'policy text registered' );
	}
);

fixthisplz_test(
	'privacy: retention_run_uses_setting',
	function () {
		with_settings_option(
			function () {
				global $wpdb;
				$repo = new Reports_Repository();
				// The runner's transaction rolls back this DML, but screenshot files are not rolled back:
				// make sure no other bench row can qualify for the purge.
				$wpdb->query( 'UPDATE ' . Installer::table() . " SET status_changed_at = '" . gmdate( 'Y-m-d H:i:s' ) . "'" );
				$old  = gmdate( 'Y-m-d H:i:s', strtotime( '-5 months' ) );
				$id   = $repo->insert( array( 'description' => 'old closed', 'page_url' => 'https://example.test/o' ) );
				$wpdb->update( Installer::table(), array( 'status' => 'fixed', 'status_changed_at' => $old ), array( 'id' => $id ) );

				update_option( 'fixthisplz_settings', array( 'data' => array( 'retention_months' => 0 ) ) );
				assert_same( 0, Retention::run(), 'disabled retention deletes nothing' );
				assert_true( null !== $repo->find( $id ) );

				update_option( 'fixthisplz_settings', array( 'data' => array( 'retention_months' => 3 ) ) );
				assert_true( Retention::run() >= 1, 'closed report older than 3 months purged' );
				assert_same( null, $repo->find( $id ) );
				Retention::register_hooks();
				assert_true( false !== has_action( Installer::CRON_HOOK, array( Retention::class, 'run' ) ), 'retention hooked on the daily cron' );
			}
		);
	}
);

fixthisplz_test(
	'privacy: uninstall_keeps_data_by_default',
	function () {
		global $wpdb;
		with_settings_option(
			function () {
				global $wpdb;
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'fixthisplz/fixthisplz.php' );
				}
				$secret = get_option( 'fixthisplz_secret' );
				include FIXTHISPLZ_DIR . 'uninstall.php';
				assert_same( Installer::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table() ) ), 'table kept' );
				assert_same( Activity::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Activity::table() ) ), 'activity table kept' );
				assert_same( $secret, get_option( 'fixthisplz_secret' ), 'secret kept' );
				assert_true( is_dir( Installer::private_dir() ), 'private dir kept' );

				update_option( 'fixthisplz_settings', array( 'data' => array( 'delete_on_uninstall' => false ) ) );
				include FIXTHISPLZ_DIR . 'uninstall.php';
				assert_same( Installer::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table() ) ), 'table kept when off' );
			}
		);
	}
);

/** Deletes $dir recursively with plain PHP; does nothing unless $dir is a real directory (not a link) at exactly that path. */
function privacy_remove_dir( string $dir ): void {
	if ( is_link( $dir ) || ! is_dir( $dir ) || realpath( $dir ) !== $dir ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $dir );
}

/** Options the uninstall deletes; the uninstall test parks their values (and the caps) in PRIVACY_BACKUP_OPTION. */
const PRIVACY_PLUGIN_OPTIONS = array( 'fixthisplz_settings', 'fixthisplz_secret', 'fixthisplz_db_version' );
const PRIVACY_BACKUP_OPTION  = 'fixthisplz_test_backup';

/** The list's screen option, removed for every user by the uninstall; parked with the options. */
const PRIVACY_PER_PAGE_META = 'fixthisplz_per_page';

function privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/** The plugin's tables, which the uninstall test parks as `{table}_fixthisplz_test_backup`. */
function privacy_plugin_tables(): array {
	global $wpdb;
	return array( $wpdb->prefix . 'fixthisplz_reports', $wpdb->prefix . 'fixthisplz_activity' );
}

/**
 * Puts back what the uninstall test parked (backup tables, then options and caps), with plain SQL / WordPress calls.
 * Each part is restored only when its backup exists, so it also repairs a run that crashed half-way.
 */
function privacy_restore_backup(): void {
	global $wpdb;
	foreach ( privacy_plugin_tables() as $table ) {
		$backup_table = $table . '_fixthisplz_test_backup';
		if ( ! privacy_table_exists( $backup_table ) ) {
			continue;
		}
		// After a crash the site may have recreated the table and received new rows: never drop those.
		if ( privacy_table_exists( $table ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new RuntimeException( $table . ' and ' . $backup_table . ' both hold rows: merge them by hand.' );
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $wpdb->query( "RENAME TABLE {$backup_table} TO {$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new RuntimeException( 'Could not rename ' . $backup_table . ' back: restore it by hand.' );
		}
	}

	$saved = get_option( PRIVACY_BACKUP_OPTION );
	if ( ! is_array( $saved ) ) {
		return;
	}
	foreach ( PRIVACY_PLUGIN_OPTIONS as $opt ) {
		if ( array_key_exists( $opt, $saved['options'] ) ) {
			update_option( $opt, $saved['options'][ $opt ], 'fixthisplz_secret' === $opt ? false : null );
		} else {
			delete_option( $opt );
		}
	}
	delete_metadata( 'user', 0, PRIVACY_PER_PAGE_META, '', true );
	foreach ( $saved['per_page'] ?? array() as $user_id => $value ) {
		update_user_meta( (int) $user_id, PRIVACY_PER_PAGE_META, $value );
	}
	foreach ( wp_roles()->role_objects as $name => $role ) {
		if ( ! empty( $saved['caps'][ $name ] ) ) {
			$role->add_cap( 'fixthisplz_manage' );
		} else {
			$role->remove_cap( 'fixthisplz_manage' );
		}
	}
	delete_option( PRIVACY_BACKUP_OPTION );
}

/**
 * The uninstall destroys real bench data (DROP TABLE is DDL: no rollback), so everything it touches is moved aside
 * before any plugin code runs and put back with plain SQL / WordPress / PHP calls: the tables are renamed, the options
 * and caps are parked in a stored option, the private dir is renamed. A crashed run leaves those backups in place;
 * the next run restores them and stops.
 */
fixthisplz_test(
	'privacy: uninstall_removes_everything_when_enabled',
	function () {
		global $wpdb;
		$tables       = privacy_plugin_tables();
		$dir          = wp_upload_dir( null, false )['basedir'] . '/fixthisplz';
		$missing      = new stdClass();

		// Backups left by a crashed run hold the real data: put them back and stop here.
		$parked_tables = array_filter( $tables, static fn( string $t ): bool => privacy_table_exists( $t . '_fixthisplz_test_backup' ) );
		if ( $parked_tables || false !== get_option( PRIVACY_BACKUP_OPTION ) ) {
			privacy_restore_backup();
			throw new RuntimeException( 'Restored the plugin tables / options from a previous crashed run; run the tests again.' );
		}
		$left = glob( $dir . '.fixthisplz-test-backup-*' );
		if ( $left ) {
			if ( 1 !== count( $left ) || file_exists( $dir ) || ! rename( $left[0], $dir ) ) {
				throw new RuntimeException( 'Leftover private dir backup next to ' . $dir . ': restore it by hand.' );
			}
			throw new RuntimeException( 'Restored the private dir from a previous crashed run; run the tests again.' );
		}

		// Park everything the uninstall destroys.
		$saved = array( 'options' => array(), 'caps' => array(), 'per_page' => array() );
		foreach ( PRIVACY_PLUGIN_OPTIONS as $opt ) {
			$value = get_option( $opt, $missing );
			if ( $missing !== $value ) {
				$saved['options'][ $opt ] = $value;
			}
		}
		foreach ( wp_roles()->role_objects as $name => $role ) {
			$saved['caps'][ $name ] = $role->has_cap( 'fixthisplz_manage' );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", PRIVACY_PER_PAGE_META ) ) as $row ) {
			$saved['per_page'][ (int) $row->user_id ] = maybe_unserialize( $row->meta_value );
		}
		if ( ! add_option( PRIVACY_BACKUP_OPTION, $saved, '', false ) ) {
			throw new RuntimeException( 'Could not store the options backup.' );
		}
		$backup = $dir . '.fixthisplz-test-backup-' . time();
		$parked = false; // True once $dir holds only test files (real dir renamed away, or there was none).

		try {
			try {
				foreach ( $tables as $table ) {
					$backup_table = $table . '_fixthisplz_test_backup';
					if ( false === $wpdb->query( "RENAME TABLE {$table} TO {$backup_table}" ) || false === $wpdb->query( "CREATE TABLE {$table} LIKE {$backup_table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						throw new RuntimeException( 'Could not move ' . $table . ' aside.' );
					}
				}
				if ( file_exists( $dir ) && ! rename( $dir, $backup ) ) {
					throw new RuntimeException( 'Could not move the private dir aside.' );
				}
				$parked = true;

				wp_mkdir_p( $dir );
				file_put_contents( $dir . '/sub-test.txt', 'x' );
				mkdir( $dir . '/nested' );
				file_put_contents( $dir . '/nested/deep.txt', 'x' );
				set_transient( 'fixthisplz_rl_h_test', 1, HOUR_IN_SECONDS );
				$someone = (int) get_users( array( 'number' => 1, 'fields' => 'ID' ) )[0];
				update_user_meta( $someone, PRIVACY_PER_PAGE_META, 37 );
				wp_roles()->get_role( 'administrator' )->add_cap( 'fixthisplz_manage' );

				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'fixthisplz/fixthisplz.php' );
				}
				update_option( 'fixthisplz_settings', array( 'data' => array( 'delete_on_uninstall' => true ) ) );
				include FIXTHISPLZ_DIR . 'uninstall.php';

				foreach ( $tables as $table ) {
					assert_true( ! privacy_table_exists( $table ), $table . ' dropped' );
				}
				assert_same( $missing, get_option( 'fixthisplz_settings', $missing ), 'settings removed' );
				assert_same( $missing, get_option( 'fixthisplz_secret', $missing ), 'secret removed' );
				assert_same( $missing, get_option( 'fixthisplz_db_version', $missing ), 'db version removed' );
				assert_same( false, get_transient( 'fixthisplz_rl_h_test' ), 'rate-limit transient removed' );
				assert_same( '', get_user_meta( $someone, PRIVACY_PER_PAGE_META, true ), 'per-page screen option removed' );
				foreach ( wp_roles()->role_objects as $role ) {
					assert_true( ! $role->has_cap( 'fixthisplz_manage' ), 'capability removed from ' . $role->name );
				}
				assert_true( ! file_exists( $dir ), 'private dir removed' );
				assert_same( false, wp_next_scheduled( 'fixthisplz_daily_purge' ), 'cron cleared' );
			} finally {
				privacy_restore_backup();
			}
		} finally {
			try {
				// Plain PHP only: whatever happened above, the test dir goes and the real one comes back untouched.
				if ( $parked ) {
					privacy_remove_dir( $dir );
					if ( file_exists( $backup ) ) {
						rename( $backup, $dir );
					}
				}
				if ( file_exists( $backup ) ) {
					throw new RuntimeException( 'Private dir backup left at ' . $backup );
				}
			} finally {
				Installer::activate(); // Only re-schedules the purge now: table, options, caps and dir are back.
			}
		}
	}
);

fixthisplz_test(
	'privacy: exporter_query_count_does_not_grow_with_reports',
	function () {
		global $wpdb;
		$repo  = new Reports_Repository();
		$email = 'count-' . wp_rand() . '@example.test';
		$make  = static fn() => $repo->insert( array( 'description' => 'c', 'page_url' => 'https://example.test/', 'reporter_email' => $email ) );
		$make();
		Privacy::export( $email, 1 ); // Warms the caches (user lookup, options).

		$before = $wpdb->num_queries;
		Privacy::export( $email, 1 );
		$one = $wpdb->num_queries - $before;

		for ( $i = 0; $i < 5; $i++ ) {
			$make();
		}
		$before = $wpdb->num_queries;
		$res    = Privacy::export( $email, 1 );
		assert_same( 6, count( $res['data'] ) );
		assert_same( $one, $wpdb->num_queries - $before, 'same number of queries for 1 or 6 reports' );
	}
);
