<?php

use Bug_Spotter\Admin\Bugs_Page;
use Bug_Spotter\Admin\Csv_Export;
use Bug_Spotter\Admin\Kanban;
use Bug_Spotter\Admin\Report_Query_Args;
use Bug_Spotter\Priorities;
use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;

// list_editor_rows(), list_editor_save(), status_editor_test(), repo_make(), bugspot_capture(), also when run alone.
require_once __DIR__ . '/test-list-editor.php';
require_once __DIR__ . '/test-admin-list.php';
require_once __DIR__ . '/test-admin-detail.php';

function priority_rows(): array {
	return list_editor_rows( 'priorities', 'priority' );
}

function priority_save( array $rows, array $moves = array() ): void {
	list_editor_save( 'priority', $rows, $moves );
}

bugspot_test(
	'priorities: defaults_keep_the_four_former_priorities_lowest_first',
	function () {
		with_settings_option(
			function () {
				assert_same( array( 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical' ), Priorities::all() );
				assert_same( Settings::defaults()['priorities'], Priorities::sanitize( Settings::defaults()['priorities'] ), 'defaults are valid as they are' );
				assert_same( array( '#787c82', '#2271b1', '#b26200', '#d63638' ), array_map( array( Priorities::class, 'color' ), array_keys( Priorities::all() ) ), 'the former Kanban colors' );
				assert_same( array( 'key' => 'critical', 'label' => '', 'color' => '#d63638' ), Priorities::sanitize( Settings::defaults()['priorities'] )[3], 'no "closed" flag' );
			}
		);
	}
);

bugspot_test(
	'priorities: sanitize_keeps_normal_first_when_missing_and_caps_at_10',
	function () {
		$out = Priorities::sanitize( array( array( 'key' => 'urgent', 'label' => '<i>Now</i>', 'color' => 'red' ), array( 'key' => 'Bad Key' ) ) );
		assert_same( array( 'normal', 'urgent' ), array_column( $out, 'key' ) );
		assert_same( array( 'key' => 'urgent', 'label' => 'Now', 'color' => Priorities::FALLBACK_COLOR ), $out[1] );

		$many = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$many[] = array( 'key' => 'p' . $i );
		}
		$many[] = array( 'key' => 'normal' );
		$out    = Priorities::sanitize( $many );
		assert_same( Priorities::MAX, count( $out ) );
		assert_same( 10, Priorities::MAX );
		assert_same( 'normal', $out[9]['key'], 'normal still kept after the cap' );
	}
);

status_editor_test(
	'priorities: the_tab_round_trips_and_new_priorities_get_keys_from_their_name',
	function () {
		$html = settings_page_tab_html( 'priorities' );
		assert_true( ! str_contains( $html, '>Closed<' ), 'no Closed column' );
		assert_same( 3 + 1, substr_count( $html, 'bugspot-list-delete' ), 'normal has no Delete button (+1: the new-row template)' );
		assert_true( str_contains( $html, 'data-moves="bugspot_settings[priority_moves]"' ) && str_contains( $html, 'data-fallback="normal"' ) );

		$statuses = Settings::get()['statuses'];
		$rows     = priority_rows();
		assert_same( array( 'low', 'normal', 'high', 'critical' ), array_values( array_filter( array_column( $rows, 'key' ) ) ) );
		$rows[]   = array( 'key' => '', 'label' => 'Bloquant !', 'color' => '#000000' );
		$rows[]   = array( 'key' => '', 'label' => 'High' );
		$rows[0]['label'] = 'Minor';
		priority_save( $rows );

		assert_same( array( 'low', 'normal', 'high', 'critical', 'bloquant_', 'high_2' ), array_keys( Priorities::all() ) );
		assert_same( 'Minor', Priorities::label( 'low' ) );
		assert_same( '#000000', Priorities::color( 'bloquant_' ) );
		assert_same( $statuses, Settings::get()['statuses'], 'the statuses are not touched' );

		$before = Settings::get();
		priority_save( priority_rows() );
		assert_same( $before, Settings::get(), 're-posting the rendered tab changes nothing' );
	}
);

status_editor_test(
	'priorities: deleting_a_used_priority_moves_its_reports_and_normal_cannot_be_deleted',
	function () {
		$repo     = new Reports_Repository();
		$high     = repo_make( $repo, 'PRDEL', array(), array( 'priority' => 'high' ) );
		$critical = repo_make( $repo, 'PRDEL', array(), array( 'priority' => 'critical' ) );
		$low      = repo_make( $repo, 'PRDEL', array(), array( 'priority' => 'low', 'status' => 'fixed' ) );
		$status   = $repo->find( $low )['status_changed_at'];

		// Forged post: normal left out, "high" and "low" deleted, a valid, a deleted and an unknown target.
		$rows = array_values( array_filter( priority_rows(), static fn( $row ) => in_array( $row['key'], array( 'critical' ), true ) ) );
		priority_save( $rows, array( 'high' => 'critical', 'low' => 'low', 'normal' => 'critical' ) );

		assert_same( array( 'normal', 'critical' ), array_keys( Priorities::all() ), 'normal is put back first' );
		assert_same( 'critical', $repo->find( $high )['priority'] );
		assert_same( 'normal', $repo->find( $low )['priority'], 'invalid target: normal' );
		assert_same( 'critical', $repo->find( $critical )['priority'] );
		assert_same( 'fixed', $repo->find( $low )['status'] );
		assert_same( $status, $repo->find( $low )['status_changed_at'], 'a priority move is not a status change' );
		assert_same( array( 'normal' => 1, 'critical' => 2 ), $repo->count_by( Priorities::class, array( 'search' => 'PRDEL' ) ) );
	}
);

status_editor_test(
	'priorities: a_new_priority_beyond_the_cap_is_refused',
	function () {
		$priorities = array( array( 'key' => 'normal' ) );
		for ( $i = 1; $i < Priorities::MAX; $i++ ) {
			$priorities[] = array( 'key' => 'p' . $i );
		}
		update_option( Settings::OPTION, array( 'priorities' => $priorities ) );
		$keys   = array_keys( Priorities::all() );
		$rows   = array_values( array_filter( priority_rows(), static fn( $row ) => '' !== $row['key'] ) );
		$rows[] = array( 'key' => '', 'label' => 'One too many' );
		priority_save( $rows );
		assert_same( $keys, array_keys( Priorities::all() ) );
	}
);

bugspot_test(
	'priorities: sorting_by_priority_follows_the_settings_order',
	function () {
		with_settings_option(
			function () {
				$repo = new Reports_Repository();
				$lo   = repo_make( $repo, 'PRORD', array(), array( 'priority' => 'low' ) );
				$no   = repo_make( $repo, 'PRORD' );
				$cr   = repo_make( $repo, 'PRORD', array(), array( 'priority' => 'critical' ) );
				$ids  = static fn( string $order ) => array_column( $repo->query( array( 'search' => 'PRORD', 'orderby' => 'priority', 'order' => $order ) )['items'], 'id' );
				assert_same( array( $cr, $no, $lo ), $ids( 'DESC' ) );

				// "critical" moved first (lowest), "low" last (highest).
				update_option( Settings::OPTION, array( 'priorities' => array( array( 'key' => 'critical' ), array( 'key' => 'normal' ), array( 'key' => 'low' ) ) ) );
				assert_same( array( $lo, $no, $cr ), $ids( 'DESC' ) );
				assert_same( array( $cr, $no, $lo ), $ids( 'ASC' ) );

				// A key with a quote cannot reach the SQL unescaped: only [a-z0-9_] keys are ever stored.
				update_option( Settings::OPTION, array( 'priorities' => array( array( 'key' => "x') OR 1=1 --" ), array( 'key' => 'normal' ) ) ) );
				assert_same( array( 'normal' ), array_keys( Priorities::all() ) );
				assert_same( 3, count( $ids( 'ASC' ) ) );
			}
		);
	}
);

bugspot_test(
	'priorities: list_kanban_csv_bulk_detail_and_filters_follow_the_settings',
	function () {
		with_settings_option(
			function () {
				update_option(
					Settings::OPTION,
					array(
						'priorities' => array(
							array( 'key' => 'normal' ),
							array( 'key' => 'urgent', 'label' => 'Urgent & co', 'color' => '#aa00cc' ),
						),
					)
				);
				$repo = new Reports_Repository();
				$id   = bugspot_admin_insert( 'prio-everywhere' );
				assert_true( ! $repo->update( $id, array( 'priority' => 'high' ) ), 'a deleted priority cannot be set' );
				assert_same( array( 'updated', 1 ), Bugs_Page::apply_bulk( 'priority_urgent', array( $id ) ) );
				assert_same( 'urgent', $repo->find( $id )['priority'] );

				$pill = '<span class="bugspot-pill bugspot-priority" style="--bugspot-color:#aa00cc">Urgent &amp; co</span>';
				assert_same( $pill, Report_Format::pill( Priorities::class, 'urgent' ) );
				$filters = array( 'status' => '', 'priority' => 'urgent', 'from' => '', 'to' => '', 'search' => 'prio-everywhere' );
				assert_same( $filters, Report_Query_Args::read_filters( array( 'priority' => 'urgent', 's' => 'prio-everywhere' ) ) );
				assert_same( '', Report_Query_Args::read_filters( array( 'priority' => 'high' ) )['priority'] );
				assert_true( str_contains( bugspot_capture( fn() => Kanban::render( $filters ) ), $pill ), 'Kanban card pill' );

				$detail = bugspot_capture( fn() => Bug_Spotter\Admin\Detail_Page::render( $id ) );
				assert_true( str_contains( $detail, '<option value="urgent" selected=\'selected\'>Urgent &amp; co</option>' ), 'detail select' );
				assert_true( ! str_contains( $detail, 'value="high"' ), 'deleted priority not offered' );

				$table   = new Bug_Spotter\Admin\List_Table();
				$actions = ( new ReflectionMethod( $table, 'get_bulk_actions' ) )->invoke( $table );
				assert_same( 'Set priority: Urgent & co', $actions['priority_urgent'] );
				assert_true( ! isset( $actions['priority_high'] ) );
				assert_same( $pill, ( new ReflectionMethod( $table, 'column_priority' ) )->invoke( $table, array( 'priority' => 'urgent' ) ) );

				$stream = fopen( 'php://memory', 'w+' );
				Csv_Export::write( $stream, $filters, 'priority', 'DESC' );
				rewind( $stream );
				$csv = (string) stream_get_contents( $stream );
				fclose( $stream );
				assert_true( str_contains( $csv, ';"Urgent & co";' ), 'CSV priority name' );

				// A priority unknown to the settings (stored by hand) is kept when another field changes.
				$repo->update( $id, array( 'priority' => 'urgent' ) );
				$GLOBALS['wpdb']->update( Bug_Spotter\Installer::table(), array( 'priority' => 'zz_gone' ), array( 'id' => $id ) );
				assert_true( $repo->update( $id, array( 'status' => 'fixed', 'priority' => 'zz_gone' ) ) );
				assert_same( 'zz_gone', Priorities::label( 'zz_gone' ) );
				assert_same( Priorities::FALLBACK_COLOR, Priorities::color( 'zz_gone' ) );
			}
		);
	}
);
