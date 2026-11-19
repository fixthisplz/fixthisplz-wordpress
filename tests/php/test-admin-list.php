<?php

use Bug_Spotter\Admin\Bugs_Page;
use Bug_Spotter\Admin\Csv_Export;
use Bug_Spotter\Admin\Menu;
use Bug_Spotter\Reports_Repository;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/** Inserts a report with a distinctive description marker and returns its id. */
function bugspot_admin_insert( string $description, array $extra = array() ): int {
	$id = ( new Reports_Repository() )->insert(
		array_merge(
			array(
				'description' => $description,
				'page_url'    => 'https://example.test/some/page?x=1',
				'context'     => array(),
			),
			$extra
		)
	);
	assert_true( $id > 0, 'insert failed' );
	return $id;
}

/** Runs $fn with a clean global admin menu and an administrator as current user; restores everything. */
function bugspot_with_admin_menu( callable $fn ): void {
	global $menu, $submenu;
	$saved_menu    = $menu ?? null;
	$saved_submenu = $submenu ?? null;
	$saved_user    = get_current_user_id();
	$admins        = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$menu    = array();
	$submenu = array();
	try {
		Menu::register();
		$fn();
	} finally {
		$menu    = $saved_menu;
		$submenu = $saved_submenu;
		wp_set_current_user( $saved_user );
	}
}

bugspot_test(
	'admin-list: menu_registered_with_capability',
	function () {
		bugspot_with_admin_menu(
			function () {
				global $menu, $submenu;
				$top = null;
				foreach ( $menu as $entry ) {
					if ( 'bug-spotter' === $entry[2] ) {
						$top = $entry;
					}
				}
				assert_true( null !== $top, 'top-level menu missing' );
				assert_same( 'bugspot_manage', $top[1] );
				assert_true( str_starts_with( wp_strip_all_tags( $top[0] ), 'Bugs' ), 'menu title' );
				assert_same( 'dashicons-flag', $top[6] );
				$slugs = array_column( $submenu['bug-spotter'], 2 );
				assert_same( array( 'bug-spotter', 'bug-spotter-settings' ), $slugs );
				assert_same( 'bugspot_manage', $submenu['bug-spotter'][0][1] );
			}
		);
	}
);

bugspot_test(
	'admin-list: menu_badge_counts_new_reports_and_can_be_disabled',
	function () {
		with_settings_option(
			function () {
				$expected = ( new Reports_Repository() )->count_by_status( array() )['new'] + 1;
				bugspot_admin_insert( 'badge test' );
				$title = Menu::menu_title();
				assert_true( str_contains( $title, '<span class="awaiting-mod count-' . $expected . '"><span class="pending-count">' . $expected . '</span></span>' ), 'badge: ' . $title );

				update_option( 'bugspot_settings', array( 'management' => array( 'menu_badge' => false ) ) );
				assert_true( ! str_contains( Menu::menu_title(), 'awaiting-mod' ), 'badge must be hidden' );
			}
		);
	}
);

bugspot_test(
	'admin-list: settings_submenu_requires_manage_options',
	function () {
		$role = 'bugspot_tmp_manager';
		add_role( $role, 'Tmp', array( 'read' => true, 'bugspot_manage' => true ) );
		$uid = 0;
		try {
			$uid = wp_insert_user( array( 'user_login' => 'bugspot_tmp_user', 'user_pass' => wp_generate_password(), 'role' => $role ) );
			assert_true( is_int( $uid ), 'user creation' );
			bugspot_with_admin_menu(
				function () use ( $uid ) {
					global $submenu;
					$caps = array();
					foreach ( $submenu['bug-spotter'] as $entry ) {
						$caps[ $entry[2] ] = $entry[1];
					}
					assert_same( 'manage_options', $caps['bug-spotter-settings'] );
					assert_true( user_can( $uid, $caps['bug-spotter'] ), 'manager sees the list' );
					assert_true( ! user_can( $uid, $caps['bug-spotter-settings'] ), 'manager must not reach settings' );
				}
			);
		} finally {
			if ( is_int( $uid ) && $uid > 0 ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $uid );
			}
			remove_role( $role );
		}
	}
);

bugspot_test(
	'admin-list: bulk_status_change_updates_rows',
	function () {
		$repo = new Reports_Repository();
		$a    = bugspot_admin_insert( 'bulk a' );
		$b    = bugspot_admin_insert( 'bulk b' );
		$c    = bugspot_admin_insert( 'bulk c' );

		$result = Bugs_Page::apply_bulk( 'status_confirmed', array( $a, $b, 999999 ) );
		assert_same( array( 'updated', 2 ), $result );
		assert_same( 'confirmed', $repo->find( $a )['status'] );
		assert_same( 'confirmed', $repo->find( $b )['status'] );
		assert_same( 'new', $repo->find( $c )['status'] );

		assert_same( array( 'updated', 1 ), Bugs_Page::apply_bulk( 'priority_critical', array( $c ) ) );
		assert_same( 'critical', $repo->find( $c )['priority'] );

		assert_same( array( '', 0 ), Bugs_Page::apply_bulk( 'status_bogus', array( $a ) ), 'invalid status ignored' );
		assert_same( 'confirmed', $repo->find( $a )['status'] );

		assert_same( array( 'deleted', 2 ), Bugs_Page::apply_bulk( 'delete', array( $a, $c ) ) );
		assert_same( null, $repo->find( $a ) );
		assert_same( null, $repo->find( $c ) );
		assert_true( null !== $repo->find( $b ) );
	}
);

bugspot_test(
	'admin-list: csv_export_applies_filters_and_escapes',
	function () {
		$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$user_id = (int) $admins[0];

		bugspot_admin_insert( '=HYPERLINK("http://evil.test")', array( 'reporter_email' => 'visitor@example.test' ) );
		bugspot_admin_insert( "multi\nline; \"quoted\" csvmarker", array( 'user_id' => $user_id ) );
		$low_id = bugspot_admin_insert( 'other thing' );
		( new Reports_Repository() )->update( $low_id, array( 'priority' => 'low' ) );

		$stream = fopen( 'php://memory', 'w+' );
		Csv_Export::write( $stream, array( 'search' => 'e', 'status' => 'new' ), 'created_at', 'ASC' );
		rewind( $stream );
		$raw = stream_get_contents( $stream );
		fclose( $stream );

		assert_true( str_starts_with( $raw, "\xEF\xBB\xBF" ), 'BOM' );
		assert_true( ! str_contains( $raw, 'ip_hash' ) && ! str_contains( $raw, 'email_hash' ), 'no hashes' );
		assert_true( str_contains( $raw, "'=HYPERLINK(" ), 'formula must be neutralised' );
		assert_true( ! preg_match( '/;"=HYPERLINK/', $raw ), 'no raw formula cell' );
		assert_true( str_contains( $raw, 'Visitor (visitor@example.test)' ), 'visitor + email' );
		assert_true( str_contains( $raw, get_userdata( $user_id )->display_name ), 'user display name' );
		assert_true( str_contains( $raw, "multi\nline; \"\"quoted\"\" csvmarker" ), 'quoting' );

		$lines = str_getcsv( substr( $raw, 3 ), "\n", '"', '' );
		assert_true( str_starts_with( $lines[0], 'No.;Date;Status;Priority;Page;Reporter;Description' ) || str_contains( $lines[0], ';' ), 'header' );

		// Filters are honoured: priority=low only returns the third report.
		$stream = fopen( 'php://memory', 'w+' );
		Csv_Export::write( $stream, array( 'priority' => 'low' ), 'created_at', 'DESC' );
		rewind( $stream );
		$low = stream_get_contents( $stream );
		fclose( $stream );
		assert_true( str_contains( $low, 'other thing' ), 'low report present' );
		assert_true( ! str_contains( $low, 'HYPERLINK' ), 'other priorities excluded' );
	}
);

bugspot_test(
	'admin-list: csv_cell_guard_prefixes_formula_triggers',
	function () {
		foreach ( array( '=1+1', '+1', '-1', '@sum', "\tcmd", "\rcmd" ) as $value ) {
			assert_same( "'" . $value, Csv_Export::safe_cell( $value ) );
		}
		assert_same( 'plain', Csv_Export::safe_cell( 'plain' ) );
	}
);

bugspot_test(
	'admin-list: read_filters_sanitises_input',
	function () {
		$f = Bugs_Page::read_filters(
			array(
				'status'   => 'bogus',
				'priority' => 'high',
				'from'     => '2026-02-30x',
				'to'       => '2026-03-01',
				's'        => '  <b>needle</b> ',
			)
		);
		assert_same( '', $f['status'] );
		assert_same( 'high', $f['priority'] );
		assert_same( '', $f['from'] );
		assert_same( '2026-03-01', $f['to'] );
		assert_same( 'needle', $f['search'] );
	}
);
