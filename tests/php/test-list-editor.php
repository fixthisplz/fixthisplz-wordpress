<?php

use FixThisPlz\List_Editor;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Settings;
use FixThisPlz\Statuses;

// settings_page_test(), settings_page_form_post(), settings_page_tab_html() and repo_make(), also when run alone.
require_once __DIR__ . '/test-settings-page.php';
require_once __DIR__ . '/test-repository.php';

/** The rows a Tracking list view ("statuses" or "priorities") posts as rendered (the <template> row has no name, so it is dropped on save). @return array<string,array> */
function list_editor_rows( string $tab, string $field ): array {
	$post = settings_page_form_post( settings_page_tab_html( 'tracking', $tab ) );
	assert_same( array( $field . '_rows' ), array_keys( $post[ Settings::OPTION ] ), 'the tab posts only its rows' );
	return $post[ Settings::OPTION ][ $field . '_rows' ];
}

/** Saves like options.php: the sanitize callback, then the post-save moves through the real hooks. */
function list_editor_save( string $field, array $rows, array $moves = array() ): void {
	update_option( Settings::OPTION, array( $field . '_rows' => $rows, $field . '_moves' => $moves ) );
}

function status_editor_rows(): array {
	return list_editor_rows( 'statuses', 'status' );
}

function status_editor_save( array $rows, array $moves = array() ): void {
	list_editor_save( 'status', $rows, $moves );
}

/** settings_page_test() with the List_Editor hooks registered (wp-admin only otherwise), removed afterwards. */
function status_editor_test( string $name, callable $fn ): void {
	settings_page_test(
		$name,
		static function () use ( $fn ) {
			List_Editor::register_hooks();
			try {
				$fn();
			} finally {
				remove_action( 'update_option_' . Settings::OPTION, array( List_Editor::class, 'apply_moves' ) );
				remove_action( 'add_option_' . Settings::OPTION, array( List_Editor::class, 'apply_moves' ) );
			}
		}
	);
}

status_editor_test(
	'status-editor: saving_keeps_order_names_colors_and_closed',
	function () {
		$rows = status_editor_rows();
		assert_same( array( 'new', 'seen', 'confirmed', 'in_progress', 'fixed', 'wont_fix', 'duplicate' ), array_values( array_filter( array_column( $rows, 'key' ) ) ) );
		assert_true( ! isset( $rows[0]['closed'] ) && ! isset( $rows[1]['closed'] ), 'new and seen have no Closed box' );

		// Drag "fixed" first after the required ones, rename "new", recolor and close "confirmed".
		$rows[0]['label']  = 'Inbox';
		$rows[2]['color']  = '#123abc';
		$rows[2]['closed'] = '1';
		$rows[4]['closed'] = '0';
		$fixed             = $rows[4];
		unset( $rows[4] );
		$rows = array_merge( array_slice( $rows, 0, 2 ), array( $fixed ), array_slice( $rows, 2 ) );
		status_editor_save( $rows );

		assert_same( array( 'new', 'seen', 'fixed', 'confirmed', 'in_progress', 'wont_fix', 'duplicate' ), array_keys( Statuses::all() ) );
		assert_same( 'Inbox', Statuses::label( 'new' ) );
		assert_same( '#123abc', Statuses::color( 'confirmed' ) );
		assert_same( array( 'confirmed', 'wont_fix', 'duplicate' ), Statuses::closed() );

		$before = Settings::get();
		status_editor_save( status_editor_rows() );
		assert_same( $before, Settings::get(), 're-posting the rendered tab changes nothing' );
	}
);

status_editor_test(
	'status-editor: a_new_status_gets_a_unique_key_from_its_name',
	function () {
		$rows   = status_editor_rows();
		$rows[] = array( 'key' => '', 'label' => 'À tester', 'color' => '#aa00cc', 'closed' => '0' );
		$rows[] = array( 'key' => '', 'label' => 'à  Tester', 'color' => '#aa00cc', 'closed' => '1' );
		$rows[] = array( 'key' => '', 'label' => 'Fixed', 'color' => '#aa00cc' );
		$rows[] = array( 'key' => '', 'label' => 'A very long status name here' );
		$rows[] = array( 'key' => '', 'label' => 'A very long status name again' );
		$rows[] = array( 'key' => '', 'label' => '' );
		status_editor_save( $rows );

		$keys = array_keys( Statuses::all() );
		assert_same( array( 'a_tester', 'a_tester_2', 'fixed_2', 'a_very_long_status_n', 'a_very_long_status_2' ), array_slice( $keys, 7 ) );
		assert_same( 'À tester', Statuses::label( 'a_tester' ) );
		assert_same( array( 'fixed', 'wont_fix', 'duplicate', 'a_tester_2' ), Statuses::closed() );
		assert_same( 'status_2', List_Editor::new_key( Statuses::class, '测试', array( 'status' => true ) ), 'a name without a usable letter' );

		// Saved again: keys never change, even when the name does.
		$rows             = status_editor_rows();
		$rows[7]['label'] = 'Recette';
		status_editor_save( $rows );
		assert_same( 'Recette', Statuses::label( 'a_tester' ) );
	}
);

status_editor_test(
	'status-editor: deleting_a_used_status_moves_its_reports_to_the_chosen_one',
	function () {
		assert_same( 'absent', get_option( Settings::OPTION, 'absent' ), 'starts without the option: first save goes through add_option' );
		$repo      = new Reports_Repository();
		$confirmed = repo_make( $repo, 'STEDMV', array(), array( 'status' => 'confirmed' ) );
		$dup       = repo_make( $repo, 'STEDMV', array(), array( 'status' => 'duplicate' ) );
		$fixed     = repo_make( $repo, 'STEDMV', array(), array( 'status' => 'fixed' ) );

		$rows = array_values(
			array_filter(
				status_editor_rows(),
				static fn( $row ) => ! in_array( $row['key'], array( 'confirmed', 'duplicate', 'fixed' ), true )
			)
		);
		status_editor_save(
			$rows,
			array(
				'confirmed' => 'in_progress',
				'duplicate' => 'wont_fix',
				'fixed'     => 'fixed', // A deleted status is not a valid target.
			)
		);

		assert_same( array( 'new', 'seen', 'in_progress', 'wont_fix' ), array_keys( Statuses::all() ) );
		assert_same( 'in_progress', $repo->find( $confirmed )['status'] );
		assert_same( 'wont_fix', $repo->find( $dup )['status'] );
		assert_same( 'new', $repo->find( $fixed )['status'], 'invalid target: new' );
		assert_same( 0, $repo->count_by_status( array() )['confirmed'] ?? 0 );

		// Saved again, now through update_option: a used status deleted with the default target.
		$wont = repo_make( $repo, 'STEDMV', array(), array( 'status' => 'wont_fix' ) );
		status_editor_save( array_slice( status_editor_rows(), 0, 3 ), array( 'wont_fix' => 'new' ) );
		assert_same( array( 'new', 'seen', 'in_progress' ), array_keys( Statuses::all() ) );
		assert_same( 'new', $repo->find( $wont )['status'] );
		assert_same( 'in_progress', $repo->find( $confirmed )['status'], 'a kept status is not touched' );
	}
);

status_editor_test(
	'status-editor: new_and_seen_cannot_be_deleted_nor_keys_forged',
	function () {
		update_option( Settings::OPTION, array( 'statuses' => array( array( 'key' => 'new', 'label' => 'Inbox', 'color' => '#111111' ), array( 'key' => 'seen' ), array( 'key' => 'fixed' ) ) ) );
		$repo  = new Reports_Repository();
		$fixed = repo_make( $repo, 'STEDFG', array(), array( 'status' => 'fixed' ) );

		// Forged post: required rows removed, "fixed" renamed to an arbitrary key, a forged move target.
		status_editor_save(
			array( array( 'key' => 'hacked', 'label' => 'Done', 'closed' => '1' ) ),
			array( 'fixed' => 'hacked', 'new' => 'fixed' )
		);

		assert_same( array( 'new', 'seen', 'done' ), array_keys( Statuses::all() ), 'unknown key = new status, key from its name' );
		assert_same( 'Inbox', Statuses::label( 'new' ), 'new is put back as saved' );
		assert_same( '#111111', Statuses::color( 'new' ) );
		assert_same( 'new', $repo->find( $fixed )['status'], 'the forged target is not a remaining status' );
	}
);

status_editor_test(
	'status-editor: a_new_status_beyond_the_cap_is_refused_and_no_saved_one_is_dropped',
	function () {
		$statuses = array( array( 'key' => 'new' ), array( 'key' => 'seen' ) );
		for ( $i = 1; $i <= Statuses::MAX - 2; $i++ ) {
			$statuses[] = array( 'key' => 'st' . $i );
		}
		update_option( Settings::OPTION, array( 'statuses' => $statuses ) );
		$keys = array_keys( Statuses::all() );
		assert_same( Statuses::MAX, count( $keys ) );
		$repo = new Reports_Repository();
		$last = repo_make( $repo, 'STEDCAP', array(), array( 'status' => 'st18' ) );

		// The 21st row is dragged above the last saved one.
		$rows = array_values( array_filter( status_editor_rows(), static fn( $row ) => '' !== $row['key'] ) );
		array_splice( $rows, Statuses::MAX - 1, 0, array( array( 'key' => '', 'label' => 'One too many' ) ) );
		status_editor_save( $rows );

		assert_same( $keys, array_keys( Statuses::all() ), 'all 20 saved statuses kept, the new one refused' );
		assert_same( 'st18', $repo->find( $last )['status'], 'no report moved' );

		// Once one is deleted, there is room for a new one.
		unset( $rows[1 + 1] );
		status_editor_save( array_values( $rows ) );
		assert_same( 'one_too_many', array_keys( Statuses::all() )[ Statuses::MAX - 2 ] );
		assert_true( ! Statuses::is_valid( 'st1' ) );
	}
);
