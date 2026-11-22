<?php

use Bug_Spotter\Installer;

bugspot_test(
	'installer: activate_creates_table_with_expected_columns',
	function () {
		global $wpdb;
		Installer::activate();
		Installer::activate(); // Idempotent on an existing table.
		$columns = $wpdb->get_col( 'DESCRIBE ' . Installer::table(), 0 );
		$expected = array(
			'id', 'status', 'priority', 'description', 'page_url', 'context', 'user_id', 'reporter_email',
			'reporter_email_hash', 'screenshot', 'ip_hash', 'created_at', 'updated_at', 'seen_at', 'status_changed_at',
		);
		assert_same( $expected, $columns );
		assert_same( '2', get_option( 'bugspot_db_version' ) );
		assert_true( false !== wp_next_scheduled( 'bugspot_daily_purge' ), 'daily purge must be scheduled' );
	}
);

bugspot_test(
	'installer: activate_protects_private_dir',
	function () {
		$dir = Installer::ensure_private_dir();
		assert_same( wp_upload_dir( null, false )['basedir'] . '/bug-spotter', $dir );
		assert_same( $dir, Installer::private_dir() );
		assert_true( is_file( $dir . '/index.php' ), 'index.php missing' );
		assert_true( str_contains( (string) file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ), '.htaccess must deny access' );
	}
);

bugspot_test(
	'installer: private_dir_and_screenshot_path_create_nothing',
	function () {
		$base   = get_temp_dir() . 'bugspot-noside-' . wp_generate_password( 8, false );
		$filter = static function ( array $uploads ) use ( $base ): array {
			$uploads['basedir'] = $base;
			return $uploads;
		};
		add_filter( 'upload_dir', $filter );
		try {
			assert_same( $base . '/bug-spotter', Installer::private_dir() );
			assert_same( $base . '/bug-spotter/' . str_repeat( 'a', 32 ) . '.jpg', Bug_Spotter\Screenshot_Store::path( str_repeat( 'a', 32 ) . '.jpg' ) );
			assert_true( ! file_exists( $base ), 'a getter must not create the directory' );
		} finally {
			remove_filter( 'upload_dir', $filter );
		}
	}
);

bugspot_test(
	'installer: sync_capabilities_grants_and_revokes',
	function () {
		$editor   = get_role( 'editor' );
		$original = $editor->has_cap( 'bugspot_manage' );
		try {
			Installer::sync_capabilities( array( 'administrator', 'editor' ) );
			assert_true( get_role( 'editor' )->has_cap( 'bugspot_manage' ), 'editor should be granted' );
			assert_true( get_role( 'administrator' )->has_cap( 'bugspot_manage' ), 'administrator should be granted' );
			Installer::sync_capabilities( array( 'administrator' ) );
			assert_true( ! get_role( 'editor' )->has_cap( 'bugspot_manage' ), 'editor should be revoked' );
			assert_true( get_role( 'administrator' )->has_cap( 'bugspot_manage' ), 'administrator should keep it' );
		} finally {
			$role = get_role( 'editor' );
			if ( $original ) {
				$role->add_cap( 'bugspot_manage' );
			} else {
				$role->remove_cap( 'bugspot_manage' );
			}
		}
	}
);

bugspot_test(
	'installer: upgrade_from_version_1_widens_priority_and_keeps_the_rows',
	function () {
		global $wpdb;
		// DDL commits the test transaction: the upgrade runs on a copy of the table, under another prefix, dropped afterwards.
		$prefix  = $wpdb->prefix;
		$version = get_option( Installer::DB_VERSION_OPTION );
		$real    = Installer::table();
		$wpdb->prefix = $prefix . 'bstest_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$table   = Installer::table();
		try {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $real ) );
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY priority varchar(10) NOT NULL DEFAULT 'normal'", $table ) );
			$now = gmdate( 'Y-m-d H:i:s' );
			foreach ( array( 'low', 'normal', 'high', 'critical' ) as $priority ) {
				$wpdb->insert( $table, array( 'status' => 'seen', 'priority' => $priority, 'description' => 'upgrade ' . $priority, 'created_at' => $now, 'updated_at' => $now ) );
			}
			$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
			update_option( Installer::DB_VERSION_OPTION, '1' );

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
			assert_same( '2', get_option( Installer::DB_VERSION_OPTION ) );
			assert_same( $before, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ), 'rows and values unchanged' );
			assert_same( 4, count( $before ) );
			// Raw INSERT: wpdb::insert() would check the column widths it cached before the upgrade.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (priority, description, created_at, updated_at) VALUES (%s, %s, %s, %s)', $table, str_repeat( 'p', 20 ), 'long key', $now, $now ) );
			assert_same( str_repeat( 'p', 20 ), $wpdb->get_var( $wpdb->prepare( "SELECT priority FROM %i WHERE description = 'long key'", $table ) ), 'a 20-character key fits' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$wpdb->prefix = $prefix; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			update_option( Installer::DB_VERSION_OPTION, $version );
		}
	}
);
