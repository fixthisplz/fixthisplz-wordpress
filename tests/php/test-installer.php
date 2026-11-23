<?php

use FixThisPlz\Installer;

fixthisplz_test(
	'installer: activate_creates_table_with_expected_columns',
	function () {
		global $wpdb;
		Installer::activate();
		Installer::activate(); // Idempotent on an existing table.
		$columns = $wpdb->get_col( 'DESCRIBE ' . Installer::table(), 0 );
		$expected = array(
			'id', 'status', 'priority', 'description', 'page_url', 'context', 'user_id', 'reporter_email',
			'reporter_email_hash', 'screenshot', 'ip_hash', 'created_at', 'updated_at', 'seen_at', 'status_changed_at', 'assigned_to',
		);
		assert_same( $expected, $columns );
		assert_same(
			array( 'id', 'report_id', 'user_id', 'type', 'field', 'old_value', 'new_value', 'content', 'created_at' ),
			$wpdb->get_col( 'DESCRIBE ' . FixThisPlz\Activity::table(), 0 )
		);
		assert_same( '3', get_option( 'fixthisplz_db_version' ) );
		assert_true( false !== wp_next_scheduled( 'fixthisplz_daily_purge' ), 'daily purge must be scheduled' );
	}
);

fixthisplz_test(
	'installer: activate_protects_private_dir',
	function () {
		$dir = Installer::ensure_private_dir();
		assert_same( wp_upload_dir( null, false )['basedir'] . '/fixthisplz', $dir );
		assert_same( $dir, Installer::private_dir() );
		assert_true( is_file( $dir . '/index.php' ), 'index.php missing' );
		assert_true( str_contains( (string) file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ), '.htaccess must deny access' );
	}
);

fixthisplz_test(
	'installer: private_dir_and_screenshot_path_create_nothing',
	function () {
		$base   = get_temp_dir() . 'fixthisplz-noside-' . wp_generate_password( 8, false );
		$filter = static function ( array $uploads ) use ( $base ): array {
			$uploads['basedir'] = $base;
			return $uploads;
		};
		add_filter( 'upload_dir', $filter );
		try {
			assert_same( $base . '/fixthisplz', Installer::private_dir() );
			assert_same( $base . '/fixthisplz/' . str_repeat( 'a', 32 ) . '.jpg', FixThisPlz\Screenshot_Store::path( str_repeat( 'a', 32 ) . '.jpg' ) );
			assert_true( ! file_exists( $base ), 'a getter must not create the directory' );
		} finally {
			remove_filter( 'upload_dir', $filter );
		}
	}
);

fixthisplz_test(
	'installer: sync_capabilities_grants_and_revokes',
	function () {
		$editor   = get_role( 'editor' );
		$original = $editor->has_cap( 'fixthisplz_manage' );
		try {
			Installer::sync_capabilities( array( 'administrator', 'editor' ) );
			assert_true( get_role( 'editor' )->has_cap( 'fixthisplz_manage' ), 'editor should be granted' );
			assert_true( get_role( 'administrator' )->has_cap( 'fixthisplz_manage' ), 'administrator should be granted' );
			Installer::sync_capabilities( array( 'administrator' ) );
			assert_true( ! get_role( 'editor' )->has_cap( 'fixthisplz_manage' ), 'editor should be revoked' );
			assert_true( get_role( 'administrator' )->has_cap( 'fixthisplz_manage' ), 'administrator should keep it' );
		} finally {
			$role = get_role( 'editor' );
			if ( $original ) {
				$role->add_cap( 'fixthisplz_manage' );
			} else {
				$role->remove_cap( 'fixthisplz_manage' );
			}
		}
	}
);

/**
 * Runs $fn( $table, $activity ) on a copy of the reports table under another prefix (DDL commits the test
 * transaction, so the real tables are never touched), shaped by $downgrade SQL ALTER clauses, with no activity
 * table and the stored schema version $version. Everything is dropped afterwards.
 */
function installer_on_old_schema( array $downgrade, string $version, callable $fn ): void {
	global $wpdb;
	$prefix  = $wpdb->prefix;
	$stored  = get_option( Installer::DB_VERSION_OPTION );
	$real    = Installer::table();
	$wpdb->prefix = $prefix . 'bstest_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$table    = Installer::table();
	$activity = FixThisPlz\Activity::table();
	try {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $table, $activity ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $real ) );
		foreach ( $downgrade as $clause ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $table ) . $clause );
		}
		update_option( Installer::DB_VERSION_OPTION, $version );
		$fn( $table, $activity );
	} finally {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $table, $activity ) );
		$wpdb->prefix = $prefix; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		update_option( Installer::DB_VERSION_OPTION, $stored );
	}
}

fixthisplz_test(
	'installer: upgrade_from_version_1_widens_priority_and_keeps_the_rows',
	function () {
		global $wpdb;
		$version1 = array( 'DROP INDEX assigned_to', 'DROP COLUMN assigned_to', "MODIFY priority varchar(10) NOT NULL DEFAULT 'normal'" );
		installer_on_old_schema(
			$version1,
			'1',
			function ( string $table, string $activity ) {
				global $wpdb;
				$now = gmdate( 'Y-m-d H:i:s' );
				foreach ( array( 'low', 'normal', 'high', 'critical' ) as $priority ) {
					$wpdb->insert( $table, array( 'status' => 'seen', 'priority' => $priority, 'description' => 'upgrade ' . $priority, 'created_at' => $now, 'updated_at' => $now ) );
				}
				$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );

				// An upgrade that does not apply keeps the old version, so the next load tries again.
				$skip = static fn() => array();
				add_filter( 'dbdelta_queries', $skip );
				try {
					Installer::maybe_upgrade();
				} finally {
					remove_filter( 'dbdelta_queries', $skip );
				}
				assert_same( '1', get_option( Installer::DB_VERSION_OPTION ), 'not applied: version kept' );
				assert_same( 'varchar(10)', $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'priority' ), ARRAY_A )['Type'] );

				Installer::maybe_upgrade();

				$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'priority' ), ARRAY_A );
				assert_same( 'varchar(20)', $column['Type'] );
				assert_same( 'normal', $column['Default'] );
				assert_same( '3', get_option( Installer::DB_VERSION_OPTION ) );
				$after = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
				assert_same( array_fill( 0, 4, null ), array_column( $after, 'assigned_to' ), 'nobody assigned' );
				assert_same( $before, array_map( static fn( array $row ): array => array_diff_key( $row, array( 'assigned_to' => true ) ), $after ), 'rows and values unchanged' );
				assert_same( 4, count( $before ) );
				// Raw INSERT: wpdb::insert() would check the column widths it cached before the upgrade.
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (priority, description, created_at, updated_at) VALUES (%s, %s, %s, %s)', $table, str_repeat( 'p', 20 ), 'long key', $now, $now ) );
				assert_same( str_repeat( 'p', 20 ), $wpdb->get_var( $wpdb->prepare( "SELECT priority FROM %i WHERE description = 'long key'", $table ) ), 'a 20-character key fits' );
				assert_same( $activity, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity ) ), 'activity table created' );
			}
		);
	}
);

fixthisplz_test(
	'installer: upgrade_from_version_2_adds_assignment_and_activity_and_keeps_the_rows',
	function () {
		installer_on_old_schema(
			array( 'DROP INDEX assigned_to', 'DROP COLUMN assigned_to' ),
			'2',
			function ( string $table, string $activity ) {
				global $wpdb;
				$now = gmdate( 'Y-m-d H:i:s' );
				foreach ( array( 'new', 'seen', 'fixed' ) as $status ) {
					$wpdb->insert( $table, array( 'status' => $status, 'priority' => 'high', 'description' => 'v2 ' . $status, 'user_id' => 7, 'created_at' => $now, 'updated_at' => $now ) );
				}
				$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );

				Installer::maybe_upgrade();

				assert_same( '3', get_option( Installer::DB_VERSION_OPTION ) );
				$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'assigned_to' ), ARRAY_A );
				assert_same( array( 'bigint unsigned', 'YES', 'MUL' ), array( $column['Type'], $column['Null'], $column['Key'] ), 'nullable, indexed' );
				$after = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
				assert_same( $before, array_map( static fn( array $row ): array => array_diff_key( $row, array( 'assigned_to' => true ) ), $after ), 'rows and values unchanged' );
				assert_same( 3, count( $after ) );
				assert_same( $activity, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity ) ), 'activity table created' );
				assert_same( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $activity ) ) );

				Installer::maybe_upgrade(); // Same version: nothing to do, nothing breaks.
				assert_same( $after, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ) );
			}
		);
	}
);
