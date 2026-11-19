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
		assert_same( '1', get_option( 'bugspot_db_version' ) );
		assert_true( false !== wp_next_scheduled( 'bugspot_daily_purge' ), 'daily purge must be scheduled' );
	}
);

bugspot_test(
	'installer: activate_protects_private_dir',
	function () {
		$dir = Installer::private_dir();
		assert_same( wp_upload_dir( null, false )['basedir'] . '/bug-spotter', $dir );
		assert_true( is_file( $dir . '/index.php' ), 'index.php missing' );
		assert_true( str_contains( (string) file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ), '.htaccess must deny access' );
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
