<?php

use Bug_Spotter\Installer;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

/** Inserts a report with a unique marker in its description; $overrides may carry raw column values. */
function repo_make( Reports_Repository $repo, string $marker, array $row = array(), array $overrides = array() ): int {
	global $wpdb;
	$id = $repo->insert( array_merge( array( 'description' => 'Bug ' . $marker, 'page_url' => 'https://example.test/' . $marker ), $row ) );
	if ( $overrides ) {
		$wpdb->update( Installer::table(), $overrides, array( 'id' => $id ) );
	}
	return $id;
}

bugspot_test(
	'repository: insert_sets_defaults_and_encrypts_email',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		$id   = $repo->insert(
			array(
				'description'    => 'It breaks',
				'page_url'       => 'https://example.test/a',
				'context'        => array( 'viewport' => '800x600' ),
				'user_id'        => 7,
				'reporter_email' => ' Jane@Example.com ',
				'ip_hash'        => str_repeat( 'a', 64 ),
			)
		);
		assert_true( $id > 0, 'insert returns an id' );
		$raw = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table() . ' WHERE id = %d', $id ), ARRAY_A );
		assert_true( false === strpos( (string) $raw['reporter_email'], 'Jane' ), 'email is not stored in clear' );
		assert_same( 64, strlen( (string) $raw['reporter_email_hash'] ) );
		assert_same( 'new', $raw['status'] );
		assert_same( 'normal', $raw['priority'] );
		assert_same( $raw['created_at'], $raw['updated_at'] );
		$found = $repo->find( $id );
		assert_same( 'Jane@Example.com', $found['reporter_email'] );
		assert_same( array( 'viewport' => '800x600' ), $found['context'] );
		assert_same( 7, $found['user_id'] );
		assert_same( 'new', $found['status'] );
		assert_same( null, $repo->find( 999999999 ) );
	}
);

bugspot_test(
	'repository: insert_without_email_leaves_both_columns_null',
	function () {
		$repo  = new Reports_Repository();
		$id    = $repo->insert( array( 'description' => 'x', 'page_url' => 'https://example.test/', 'reporter_email' => '' ) );
		$found = $repo->find( $id );
		assert_same( null, $found['reporter_email'] );
		assert_same( null, $found['reporter_email_hash'] );
	}
);

bugspot_test(
	'repository: update_sets_status_changed_at_and_seen_at',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		$id   = repo_make( $repo, 'upd' );
		$wpdb->update( Installer::table(), array( 'updated_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		assert_same( null, $repo->find( $id )['status_changed_at'] );
		assert_true( null !== $repo->update( $id, array( 'status' => 'confirmed', 'priority' => 'high' ) ) );
		$r = $repo->find( $id );
		assert_same( 'confirmed', $r['status'] );
		assert_same( 'high', $r['priority'] );
		assert_true( null !== $r['status_changed_at'], 'status_changed_at set' );
		assert_true( null !== $r['seen_at'], 'seen_at set when leaving new' );
		assert_true( '2000-01-01 00:00:00' !== $r['updated_at'], 'updated_at refreshed' );
		// A later priority-only change leaves status_changed_at untouched.
		$wpdb->update( Installer::table(), array( 'status_changed_at' => '2001-01-01 00:00:00' ), array( 'id' => $id ) );
		$repo->update( $id, array( 'priority' => 'low' ) );
		assert_same( '2001-01-01 00:00:00', $repo->find( $id )['status_changed_at'] );
	}
);

bugspot_test(
	'repository: mark_seen_only_from_new',
	function () {
		$repo = new Reports_Repository();
		$id   = repo_make( $repo, 'seen' );
		assert_true( $repo->mark_seen( $id ) );
		$r = $repo->find( $id );
		assert_same( 'seen', $r['status'] );
		assert_true( null !== $r['seen_at'] );
		assert_true( ! $repo->mark_seen( $id ), 'second call is a no-op' );
		$repo->update( $id, array( 'status' => 'fixed' ) );
		assert_true( ! $repo->mark_seen( $id ) );
		assert_same( 'fixed', $repo->find( $id )['status'] );
	}
);

bugspot_test(
	'repository: update_rejects_unknown_status',
	function () {
		$repo = new Reports_Repository();
		$id   = repo_make( $repo, 'bad' );
		assert_same( null, $repo->update( $id, array( 'status' => 'bogus' ) ) );
		assert_same( null, $repo->update( $id, array( 'priority' => 'urgent' ) ) );
		assert_same( null, $repo->update( $id, array( 'description' => 'hack' ) ) );
		$r = $repo->find( $id );
		assert_same( 'new', $r['status'] );
		assert_same( 'normal', $r['priority'] );
		assert_same( 'Bug bad', $r['description'] );
	}
);

bugspot_test(
	'repository: delete_removes_row',
	function () {
		$repo = new Reports_Repository();
		$id   = repo_make( $repo, 'del' );
		assert_true( $repo->delete( $id ) );
		assert_same( null, $repo->find( $id ) );
		assert_true( ! $repo->delete( $id ) );
	}
);

bugspot_test(
	'repository: query_filters_status_dates_search',
	function () {
		$repo = new Reports_Repository();
		$tz   = wp_timezone();
		$a    = repo_make( $repo, 'QF1', array(), array( 'created_at' => '2026-03-10 12:00:00' ) );
		$b    = repo_make( $repo, 'QF2', array(), array( 'created_at' => '2026-03-20 12:00:00', 'status' => 'fixed' ) );
		$c    = repo_make( $repo, 'QF3', array( 'description' => 'other QF3 100%_x' ), array( 'created_at' => '2026-03-25 12:00:00' ) );

		$ids = function ( array $args ) use ( $repo ) {
			$res = $repo->query( $args );
			$out = array_map( fn( $i ) => $i['id'], $res['items'] );
			sort( $out );
			return array( $out, $res['total'] );
		};

		assert_same( array( array( $a, $b ), 2 ), $ids( array( 'search' => 'Bug QF' ) ) );
		assert_same( array( array( $b ), 1 ), $ids( array( 'search' => 'QF', 'status' => 'fixed' ) ) );
		assert_same( array( array( $c ), 1 ), $ids( array( 'search' => 'example.test/QF3' ) ) );
		assert_same( array( array( $c ), 1 ), $ids( array( 'search' => '100%_x' ) ), 'LIKE wildcards are escaped' );
		assert_same( array( array(), 0 ), $ids( array( 'search' => 'QF%' ) ), 'percent is literal' );
		assert_same( array( array( $b, $c ), 2 ), $ids( array( 'search' => 'QF', 'from' => '2026-03-15' ) ) );
		assert_same( array( array( $a, $b ), 2 ), $ids( array( 'search' => 'QF', 'to' => '2026-03-20' ) ), 'to is inclusive of the whole day' );
		assert_same( array( array( $b ), 1 ), $ids( array( 'search' => 'QF', 'from' => '2026-03-20', 'to' => '2026-03-20' ) ) );
		assert_same( array( array( $a, $b, $c ), 3 ), $ids( array( 'search' => 'QF', 'from' => 'garbage', 'to' => '2026-13-45' ) ), 'invalid dates are ignored' );

		// Site-timezone boundary: a row at the UTC bound of local midnight belongs to the local day.
		$local_midnight = new DateTimeImmutable( '2026-03-21 00:00:00', $tz );
		$utc            = $local_midnight->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$d              = repo_make( $repo, 'QF4', array(), array( 'created_at' => $utc ) );
		assert_same( array( array( $d ), 1 ), $ids( array( 'search' => 'QF4', 'from' => '2026-03-21', 'to' => '2026-03-21' ) ) );

		// Pagination and total.
		$res = $repo->query( array( 'search' => 'QF', 'per_page' => 2, 'page' => 2 ) );
		assert_same( 4, $res['total'] );
		assert_same( 2, count( $res['items'] ) );
		assert_same( array(), $repo->query( array( 'search' => 'QF', 'per_page' => 2, 'page' => 3 ) )['items'] );
	}
);

bugspot_test(
	'repository: query_orders_oldest_first_when_asc',
	function () {
		$repo = new Reports_Repository();
		$old  = repo_make( $repo, 'ORD', array(), array( 'created_at' => '2026-01-01 10:00:00' ) );
		$new  = repo_make( $repo, 'ORD', array(), array( 'created_at' => '2026-02-01 10:00:00' ) );
		$asc  = array_map( fn( $i ) => $i['id'], $repo->query( array( 'search' => 'ORD', 'order' => 'ASC' ) )['items'] );
		$desc = array_map( fn( $i ) => $i['id'], $repo->query( array( 'search' => 'ORD' ) )['items'] );
		assert_same( array( $old, $new ), $asc );
		assert_same( array( $new, $old ), $desc );
		// Hostile values fall back to safe defaults instead of reaching SQL.
		$res = $repo->query( array( 'search' => 'ORD', 'orderby' => 'id; DROP TABLE x', 'order' => 'ASC; --' ) );
		assert_same( 2, $res['total'] );
	}
);

bugspot_test(
	'repository: query_orders_by_priority',
	function () {
		$repo = new Reports_Repository();
		$lo   = repo_make( $repo, 'PRI' );
		$cr   = repo_make( $repo, 'PRI' );
		$no   = repo_make( $repo, 'PRI' );
		$hi   = repo_make( $repo, 'PRI' );
		$repo->update( $lo, array( 'priority' => 'low' ) );
		$repo->update( $cr, array( 'priority' => 'critical' ) );
		$repo->update( $hi, array( 'priority' => 'high' ) );
		$desc = array_map( fn( $i ) => $i['id'], $repo->query( array( 'search' => 'PRI', 'orderby' => 'priority' ) )['items'] );
		$asc  = array_map( fn( $i ) => $i['id'], $repo->query( array( 'search' => 'PRI', 'orderby' => 'priority', 'order' => 'ASC' ) )['items'] );
		assert_same( array( $cr, $hi, $no, $lo ), $desc );
		assert_same( array( $lo, $no, $hi, $cr ), $asc );
		$by_priority = $repo->query( array( 'search' => 'PRI', 'priority' => 'high' ) );
		assert_same( 1, $by_priority['total'] );
	}
);

bugspot_test(
	'repository: count_by_status_has_all_keys',
	function () {
		$repo = new Reports_Repository();
		repo_make( $repo, 'CNT' );
		repo_make( $repo, 'CNT', array(), array( 'status' => 'fixed' ) );
		repo_make( $repo, 'CNT', array(), array( 'status' => 'fixed' ) );
		$counts = $repo->count_by_status( array( 'search' => 'CNT' ) );
		assert_same( array_keys( Statuses::all() ), array_keys( $counts ) );
		assert_same( 1, $counts['new'] );
		assert_same( 2, $counts['fixed'] );
		assert_same( 0, $counts['seen'] );
		$filtered = $repo->count_by_status( array( 'search' => 'CNT', 'status' => 'fixed' ) );
		assert_same( 1, $filtered['new'], 'status filter is ignored' );
	}
);

bugspot_test(
	'repository: ids_by_email_is_case_insensitive',
	function () {
		$repo = new Reports_Repository();
		$a    = $repo->insert( array( 'description' => 'a', 'page_url' => 'x', 'reporter_email' => 'Mixed.Case@Example.com', 'user_id' => 424242 ) );
		$b    = $repo->insert( array( 'description' => 'b', 'page_url' => 'x', 'reporter_email' => 'other@example.com' ) );
		assert_same( array( $a ), $repo->ids_by_email( '  mixed.case@example.COM ' ) );
		assert_same( array( $b ), $repo->ids_by_email( 'other@example.com' ) );
		assert_same( array(), $repo->ids_by_email( 'nobody@example.com' ) );
		assert_same( array( $a ), $repo->ids_by_user( 424242 ) );
	}
);

bugspot_test(
	'repository: purge_only_closed_and_old',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		$old  = gmdate( 'Y-m-d H:i:s', strtotime( '-13 months' ) );
		$new  = gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) );
		$a    = repo_make( $repo, 'PRG', array(), array( 'status' => 'fixed', 'status_changed_at' => $old, 'created_at' => $new ) );
		$b    = repo_make( $repo, 'PRG', array(), array( 'status' => 'in_progress', 'status_changed_at' => $old ) );
		$c    = repo_make( $repo, 'PRG', array(), array( 'status' => 'fixed', 'status_changed_at' => $new, 'created_at' => $old ) );
		assert_same( 0, Bug_Spotter\Retention::purge_closed_older_than( 0 ), '0 months does nothing' );
		assert_true( null !== $repo->find( $a ) );
		$deleted = Bug_Spotter\Retention::purge_closed_older_than( 12 );
		assert_true( $deleted >= 1, 'at least the old closed row is purged' );
		assert_same( null, $repo->find( $a ) );
		assert_true( null !== $repo->find( $b ), 'in_progress kept' );
		assert_true( null !== $repo->find( $c ), 'recently closed kept (status_changed_at, not created_at)' );
	}
);

bugspot_test(
	'repository: query_returns_light_rows_and_skips_email_on_request',
	function () {
		$repo = new Reports_Repository();
		$id   = repo_make( $repo, 'LIGHT1', array( 'context' => array( 'big' => 'x' ), 'reporter_email' => 'light@example.test', 'ip_hash' => str_repeat( 'c', 64 ) ) );

		$item = $repo->query( array( 'search' => 'LIGHT1' ) )['items'][0];
		assert_same( array( 'id', 'status', 'priority', 'description', 'page_url', 'user_id', 'screenshot', 'created_at', 'assigned_to', 'reporter_email' ), array_keys( $item ) );
		assert_same( $id, $item['id'] );
		assert_same( 'light@example.test', $item['reporter_email'] );

		$card = $repo->query( array( 'search' => 'LIGHT1', 'with_email' => false ) )['items'][0];
		assert_true( ! array_key_exists( 'reporter_email', $card ) && ! array_key_exists( 'context', $card ), 'cards carry neither e-mail nor context' );

		$full = $repo->find( $id );
		assert_same( array( 'big' => 'x' ), $full['context'], 'find() stays complete' );
		assert_same( str_repeat( 'c', 64 ), $full['ip_hash'] );
	}
);

bugspot_test(
	'repository: find_many_reads_given_ids_in_one_query',
	function () {
		global $wpdb;
		$repo = new Reports_Repository();
		$a    = repo_make( $repo, 'FM1', array( 'reporter_email' => 'fm@example.test' ) );
		$b    = repo_make( $repo, 'FM2' );

		$before = $wpdb->num_queries;
		$rows   = $repo->find_many( array( $b, 999999999, $a ) );
		assert_same( 1, $wpdb->num_queries - $before, 'one query' );
		assert_same( array( $a, $b ), array_column( $rows, 'id' ), 'by id, missing ids skipped' );
		assert_same( 'fm@example.test', $rows[0]['reporter_email'] );
		assert_same( null, $rows[1]['reporter_email'] );

		$before = $wpdb->num_queries;
		assert_same( array(), $repo->find_many( array() ) );
		assert_same( 0, $wpdb->num_queries - $before, 'no query for no id' );
	}
);
