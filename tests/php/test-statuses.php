<?php

use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;
use Bug_Spotter\Settings_Sanitizer;
use Bug_Spotter\Statuses;

/** Saves a "statuses" setting through the real sanitizer, as a settings form would. */
function statuses_save( array $statuses ): void {
	update_option( Settings::OPTION, Settings_Sanitizer::sanitize( array( 'statuses' => $statuses ) ) );
}

bugspot_test(
	'statuses: defaults_keep_the_former_keys_order_labels_and_closed_set',
	function () {
		with_settings_option(
			function () {
				assert_same(
					array( 'new' => 'New', 'seen' => 'Seen', 'confirmed' => 'Confirmed', 'in_progress' => 'In progress', 'fixed' => 'Fixed', 'wont_fix' => 'Won\'t fix', 'duplicate' => 'Duplicate' ),
					Statuses::all()
				);
				assert_same( array( 'fixed', 'wont_fix', 'duplicate' ), Statuses::closed() );
				assert_same( Settings::defaults()['statuses'], Statuses::sanitize( Settings::defaults()['statuses'] ), 'defaults are valid as they are' );
				assert_same( '#2271b1', Statuses::color( 'new' ) );
			}
		);
	}
);

bugspot_test(
	'statuses: sanitize_drops_invalid_entries_and_keeps_new_and_seen_first_and_open',
	function () {
		$out = Statuses::sanitize(
			array(
				array( 'key' => 'fixed', 'label' => ' <b>Done</b> ', 'color' => 'red', 'closed' => '1' ),
				array( 'key' => 'Bad-Key', 'label' => 'x' ),
				array( 'key' => str_repeat( 'a', 21 ) ),
				'not an entry',
				array( 'key' => 'fixed', 'label' => 'duplicate key' ),
				array( 'key' => 'seen', 'label' => str_repeat( 'é', 50 ), 'color' => '#ABCDEF', 'closed' => true ),
				array( 'key' => 'to_test', 'color' => '#123456', 'closed' => 'false' ),
			)
		);
		assert_same( array( 'new', 'fixed', 'seen', 'to_test' ), array_column( $out, 'key' ), 'missing "new" is put back first' );
		assert_same( array( 'key' => 'new', 'label' => '', 'color' => '#2271b1', 'closed' => false ), $out[0] );
		assert_same( array( 'key' => 'fixed', 'label' => 'Done', 'color' => '#008a20', 'closed' => true ), $out[1], 'invalid color takes the default' );
		assert_same( array( 'key' => 'seen', 'label' => str_repeat( 'é', 40 ), 'color' => '#ABCDEF', 'closed' => false ), $out[2], 'seen is never closed' );
		assert_same( array( 'key' => 'to_test', 'label' => '', 'color' => '#123456', 'closed' => false ), $out[3] );

		$only = Statuses::sanitize( array( array( 'key' => 'custom', 'color' => 'nope' ) ) );
		assert_same( array( 'new', 'seen', 'custom' ), array_column( $only, 'key' ) );
		assert_same( Statuses::FALLBACK_COLOR, $only[2]['color'] );
		assert_same( Settings::defaults()['statuses'], Statuses::sanitize( 'garbage' ), 'a non-list gives the defaults' );
	}
);

bugspot_test(
	'statuses: sanitize_caps_the_list_at_20_and_always_keeps_new_and_seen',
	function () {
		$many = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$many[] = array( 'key' => 's' . $i );
		}
		$many[] = array( 'key' => 'seen' );
		$out    = Statuses::sanitize( $many );
		assert_same( Statuses::MAX, count( $out ) );
		assert_same( array( 'new', 's1' ), array_slice( array_column( $out, 'key' ), 0, 2 ) );
		assert_same( 's18', $out[18]['key'] );
		assert_same( 'seen', $out[19]['key'], 'seen still kept after the cap' );
	}
);

bugspot_test(
	'statuses: reading_follows_the_saved_setting',
	function () {
		with_settings_option(
			function () {
				statuses_save(
					array(
						array( 'key' => 'new', 'label' => 'Inbox' ),
						array( 'key' => 'seen' ),
						array( 'key' => 'to_test', 'label' => 'To test', 'color' => '#aa00cc', 'closed' => true ),
					)
				);
				assert_same( array( 'new' => 'Inbox', 'seen' => 'Seen', 'to_test' => 'To test' ), Statuses::all() );
				assert_same( array( 'to_test' ), Statuses::closed() );
				assert_same( '#aa00cc', Statuses::color( 'to_test' ) );
				assert_true( ! Statuses::is_valid( 'fixed' ), 'a removed status is no longer valid' );
				assert_same( 'fixed', Statuses::label( 'fixed' ), 'an unknown stored key shows as itself' );
				assert_same( Statuses::FALLBACK_COLOR, Statuses::color( 'fixed' ) );

				statuses_save( array( array( 'key' => 'new' ), array( 'key' => 'seen' ) ) );
				assert_same( array(), Statuses::closed() );
				assert_same( 0, Bug_Spotter\Retention::purge_closed_older_than( 1 ), 'no closed status: nothing purged' );
			}
		);
	}
);

bugspot_test(
	'statuses: purge_only_removes_the_statuses_set_as_closed',
	function () {
		with_settings_option(
			function () {
				// A key no real report uses, so the bench's screenshot files are never touched.
				statuses_save( array( array( 'key' => 'zz_test_closed', 'closed' => true ), array( 'key' => 'fixed' ) ) );
				$repo   = new Reports_Repository();
				$old    = gmdate( 'Y-m-d H:i:s', strtotime( '-13 months' ) );
				$closed = repo_make( $repo, 'STPRG', array(), array( 'status' => 'zz_test_closed', 'status_changed_at' => $old ) );
				$fixed  = repo_make( $repo, 'STPRG', array(), array( 'status' => 'fixed', 'status_changed_at' => $old ) );
				assert_same( 1, Bug_Spotter\Retention::purge_closed_older_than( 12 ) );
				assert_same( null, $repo->find( $closed ) );
				assert_true( null !== $repo->find( $fixed ), '"fixed" is no longer closed here' );
			}
		);
	}
);

bugspot_test(
	'statuses: a_stored_status_unknown_to_the_settings_is_counted_kept_and_changeable',
	function () {
		with_settings_option(
			function () {
				$repo = new Reports_Repository();
				$id   = repo_make( $repo, 'STUNK', array(), array( 'status' => 'zz_gone' ) );
				assert_same( 1, $repo->count_by_status( array( 'search' => 'STUNK' ) )['zz_gone'] );
				assert_true( null !== $repo->update( $id, array( 'status' => 'zz_gone', 'priority' => 'high' ) ), 'unchanged unknown status is kept' );
				assert_same( 'high', $repo->find( $id )['priority'] );
				assert_same( null, $repo->update( $id, array( 'status' => 'zz_other' ) ), 'an unknown status is never newly set' );
				assert_true( null !== $repo->update( $id, array( 'status' => 'confirmed' ) ) );
				assert_same( 'confirmed', $repo->find( $id )['status'] );
			}
		);
	}
);

bugspot_test(
	'statuses: the_pill_is_tinted_from_the_validated_color_and_escapes_the_label',
	function () {
		with_settings_option(
			function () {
				statuses_save( array( array( 'key' => 'new', 'label' => 'A & B', 'color' => '#aa00cc' ), array( 'key' => 'seen' ) ) );
				assert_same( '<span class="bugspot-pill bugspot-status" style="--bugspot-color:#aa00cc">A &amp; B</span>', Bug_Spotter\Report_Format::pill( Statuses::class, 'new' ) );
				assert_same( '<span class="bugspot-pill bugspot-status" style="--bugspot-color:' . Statuses::FALLBACK_COLOR . '">zz_gone</span>', Bug_Spotter\Report_Format::pill( Statuses::class, 'zz_gone' ) );
			}
		);
	}
);
