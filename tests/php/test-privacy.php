<?php

use Bug_Spotter\Installer;
use Bug_Spotter\Privacy;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Retention;
use Bug_Spotter\Screenshot_Store;

/** Stores a tiny real screenshot and returns its private file name. */
function privacy_screenshot(): string {
	$im = imagecreatetruecolor( 20, 20 );
	ob_start();
	imagepng( $im );
	$tmp = wp_tempnam( 'bugspot-privacy' );
	file_put_contents( $tmp, ob_get_clean() );
	$name = Screenshot_Store::store( $tmp );
	unlink( $tmp );
	if ( ! is_string( $name ) ) {
		throw new RuntimeException( 'screenshot fixture failed' );
	}
	return $name;
}

bugspot_test(
	'privacy: exporter_finds_reports_by_contact_email_and_by_user',
	function () {
		$repo  = new Reports_Repository();
		$uid   = wp_insert_user( array( 'user_login' => 'bugspot_priv_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'priv-user@example.test' ) );
		$by_em = $repo->insert( array( 'description' => 'by email', 'page_url' => 'https://example.test/a', 'reporter_email' => 'Priv-User@Example.test', 'ip_hash' => str_repeat( 'b', 64 ), 'context' => array( 'secret' => 'x' ) ) );
		$by_us = $repo->insert( array( 'description' => 'by user', 'page_url' => 'https://example.test/b', 'user_id' => $uid ) );
		$both  = $repo->insert( array( 'description' => 'both', 'page_url' => 'https://example.test/c', 'user_id' => $uid, 'reporter_email' => 'priv-user@example.test' ) );
		$other = $repo->insert( array( 'description' => 'other', 'page_url' => 'https://example.test/d', 'reporter_email' => 'someone@example.test' ) );

		$res = Privacy::export( 'priv-user@example.test', 1 );
		assert_true( $res['done'], 'single page' );
		$ids = array_map( fn( $i ) => $i['item_id'], $res['data'] );
		assert_same( array( 'bug-report-' . $by_em, 'bug-report-' . $by_us, 'bug-report-' . $both ), $ids );
		assert_true( ! in_array( 'bug-report-' . $other, $ids, true ), 'other reporter excluded' );
		assert_same( 'bug-spotter', $res['data'][0]['group_id'] );
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
		assert_true( is_callable( $reg['bug-spotter']['callback'] ), 'exporter registered' );
		assert_true( is_callable( Privacy::register_eraser( array() )['bug-spotter']['callback'] ), 'eraser registered' );
	}
);

bugspot_test(
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

bugspot_test(
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

bugspot_test(
	'privacy: eraser_flags_retained_and_stops_when_deletes_fail',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		for ( $i = 0; $i < 51; $i++ ) {
			$repo->insert( array( 'description' => 'f' . $i, 'page_url' => 'https://example.test/f', 'reporter_email' => 'fail@example.test' ) );
		}
		// Turns every DELETE into invalid SQL so wpdb::delete() returns false.
		$break = static fn( $q ) => 0 === strpos( ltrim( $q ), 'DELETE FROM `' . Installer::table() . '`' ) ? 'DELETE FROM bugspot_no_such_table' : $q;
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

bugspot_test(
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
			if ( 'Bug Spotter' === $entry['plugin_name'] ) {
				$found = true;
				assert_true( false !== strpos( $entry['policy_text'], 'legitimate interest' ), 'legal basis present' );
			}
		}
		assert_true( $found, 'policy text registered' );
	}
);

bugspot_test(
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

				update_option( 'bugspot_settings', array( 'data' => array( 'retention_months' => 0 ) ) );
				assert_same( 0, Retention::run(), 'disabled retention deletes nothing' );
				assert_true( null !== $repo->find( $id ) );

				update_option( 'bugspot_settings', array( 'data' => array( 'retention_months' => 3 ) ) );
				assert_true( Retention::run() >= 1, 'closed report older than 3 months purged' );
				assert_same( null, $repo->find( $id ) );
				Retention::register_hooks();
				assert_true( false !== has_action( Installer::CRON_HOOK, array( Retention::class, 'run' ) ), 'retention hooked on the daily cron' );
			}
		);
	}
);

bugspot_test(
	'privacy: uninstall_keeps_data_by_default',
	function () {
		global $wpdb;
		with_settings_option(
			function () {
				global $wpdb;
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'bug-spotter/bug-spotter.php' );
				}
				$secret = get_option( 'bugspot_secret' );
				include BUGSPOT_DIR . 'uninstall.php';
				assert_same( Installer::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table() ) ), 'table kept' );
				assert_same( $secret, get_option( 'bugspot_secret' ), 'secret kept' );
				assert_true( is_dir( Installer::private_dir() ), 'private dir kept' );

				update_option( 'bugspot_settings', array( 'data' => array( 'delete_on_uninstall' => false ) ) );
				include BUGSPOT_DIR . 'uninstall.php';
				assert_same( Installer::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table() ) ), 'table kept when off' );
			}
		);
	}
);

/**
 * DROP TABLE is DDL (implicit commit, cannot be rolled back), so this test snapshots every piece of
 * state it destroys (rows with their ids, settings, secret, db version, role caps) and restores it in `finally`.
 */
bugspot_test(
	'privacy: uninstall_removes_everything_when_enabled',
	function () {
		global $wpdb;
		$table   = Installer::table();
		$missing = new stdClass();
		$saved   = array();
		$caps    = array();
		$files   = array();
		$rows    = array();
		$dir     = Installer::private_dir();
		$meta    = null;

		try {
			// Snapshot everything the uninstall destroys: rows (original ids), options, caps, private dir files and ownership.
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array( 'bugspot_settings', 'bugspot_secret', 'bugspot_db_version' ) as $opt ) {
				$saved[ $opt ] = get_option( $opt, $missing );
			}
			foreach ( wp_roles()->role_objects as $name => $role ) {
				$caps[ $name ] = $role->has_cap( 'bugspot_manage' );
			}
			foreach ( new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS ) as $f ) {
				if ( $f->isFile() && ! $f->isLink() ) {
					$files[ $f->getFilename() ] = array(
						'bytes' => (string) file_get_contents( $f->getPathname() ),
						'owner' => fileowner( $f->getPathname() ),
						'group' => filegroup( $f->getPathname() ),
						'perms' => fileperms( $f->getPathname() ) & 07777,
					);
				}
			}
			$meta = array( 'owner' => fileowner( $dir ), 'group' => filegroup( $dir ), 'perms' => fileperms( $dir ) & 07777 );

			file_put_contents( $dir . '/sub-test.txt', 'x' );
			mkdir( $dir . '/nested' );
			file_put_contents( $dir . '/nested/deep.txt', 'x' );
			set_transient( 'bugspot_rl_h_test', 1, HOUR_IN_SECONDS );
			wp_roles()->get_role( 'administrator' )->add_cap( 'bugspot_manage' );

			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'bug-spotter/bug-spotter.php' );
			}
			update_option( 'bugspot_settings', array( 'data' => array( 'delete_on_uninstall' => true ) ) );
			include BUGSPOT_DIR . 'uninstall.php';

			assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'table dropped' );
			assert_same( $missing, get_option( 'bugspot_settings', $missing ), 'settings removed' );
			assert_same( $missing, get_option( 'bugspot_secret', $missing ), 'secret removed' );
			assert_same( $missing, get_option( 'bugspot_db_version', $missing ), 'db version removed' );
			assert_same( false, get_transient( 'bugspot_rl_h_test' ), 'rate-limit transient removed' );
			foreach ( wp_roles()->role_objects as $role ) {
				assert_true( ! $role->has_cap( 'bugspot_manage' ), 'capability removed from ' . $role->name );
			}
			assert_true( ! file_exists( $dir ), 'private dir removed' );
			assert_same( false, wp_next_scheduled( 'bugspot_daily_purge' ), 'cron cleared' );
		} finally {
			Installer::activate();
			foreach ( $saved as $opt => $value ) {
				if ( $missing === $value ) {
					delete_option( $opt );
				} else {
					update_option( $opt, $value, 'bugspot_secret' === $opt ? false : null );
				}
			}
			foreach ( $rows as $row ) {
				$wpdb->insert( $table, $row );
			}
			foreach ( wp_roles()->role_objects as $name => $role ) {
				if ( ! empty( $caps[ $name ] ) ) {
					$role->add_cap( 'bugspot_manage' );
				} else {
					$role->remove_cap( 'bugspot_manage' );
				}
			}
			// Put the private dir back exactly as found (WP-CLI runs as root: recreated files would be root-owned).
			$dir = Installer::private_dir();
			foreach ( $files as $name => $f ) {
				file_put_contents( $dir . '/' . $name, $f['bytes'] );
				chown( $dir . '/' . $name, $f['owner'] );
				chgrp( $dir . '/' . $name, $f['group'] );
				chmod( $dir . '/' . $name, $f['perms'] );
			}
			if ( null !== $meta ) {
				chown( $dir, $meta['owner'] );
				chgrp( $dir, $meta['group'] );
				chmod( $dir, $meta['perms'] );
			}
		}
	}
);
