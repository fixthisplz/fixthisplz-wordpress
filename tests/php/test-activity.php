<?php

use FixThisPlz\Activity;
use FixThisPlz\Admin\Activity_Box;
use FixThisPlz\Admin\Bugs_Page;
use FixThisPlz\Admin\Detail_Page;
use FixThisPlz\Admin\Kanban;
use FixThisPlz\Admin\List_Table;
use FixThisPlz\Priorities;
use FixThisPlz\Privacy;
use FixThisPlz\Report_Format;
use FixThisPlz\Report_Changes;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Statuses;
use FixThisPlz\Team;

require_once __DIR__ . '/test-admin-detail.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/** A user of $role, given fixthisplz_manage unless $manager is false. */
function activity_user( string $role = 'editor', bool $manager = true, string $name = '' ): int {
	$login = 'fixthisplz_act_' . strtolower( wp_generate_password( 8, false ) );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => $role, 'locale' => 'en_US', 'display_name' => '' !== $name ? $name : $login ) );
	assert_true( is_int( $id ), 'user fixture failed' );
	if ( $manager ) {
		( new WP_User( $id ) )->add_cap( 'fixthisplz_manage' );
	}
	return $id;
}

/**
 * Calls an admin-post handler with $post as the request, as user $user_id; its wp_die() / redirect end it with an
 * exception instead of exit. @return string "die:…", "redirect:…" or "returned".
 */
function activity_call( callable $handler, array $post, int $user_id ): string {
	$saved = array( $_POST, $_REQUEST, get_current_user_id() );
	wp_set_current_user( $user_id );
	$_POST    = $post;
	$_REQUEST = $post;
	$die      = static fn() => static function ( $message ): void {
		throw new RuntimeException( 'die:' . ( is_string( $message ) ? $message : '' ) );
	};
	$redirect = static function ( $location ): void {
		throw new RuntimeException( 'redirect:' . $location );
	};
	add_filter( 'wp_die_handler', $die, 999 );
	add_filter( 'wp_redirect', $redirect, 999 );
	try {
		$handler();
		return 'returned';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $die, 999 );
		remove_filter( 'wp_redirect', $redirect, 999 );
		list( $_POST, $_REQUEST ) = $saved;
		wp_set_current_user( $saved[2] );
	}
}

/** Runs $fn with every e-mail captured instead of sent. @return array[] wp_mail() arguments. */
function activity_mails( callable $fn ): array {
	$sent = array();
	$mail = static function ( $short, $atts ) use ( &$sent ) {
		$sent[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $mail, 10, 2 );
	try {
		$fn();
	} finally {
		remove_filter( 'pre_wp_mail', $mail, 10 );
	}
	return $sent;
}

/** @return array[] [field, old, new, user_id] of the report's changes, newest first. */
function activity_changes( int $report_id ): array {
	$out = array();
	foreach ( Activity::for_report( $report_id ) as $entry ) {
		if ( Activity::CHANGE === $entry['type'] ) {
			$out[] = array( $entry['field'], $entry['old_value'], $entry['new_value'], $entry['user_id'] );
		}
	}
	return $out;
}

fixthisplz_test(
	'activity: only_bug_managers_are_assignable',
	function () {
		$manager = activity_user( 'editor', true, 'Ada Lovelace' );
		$other   = activity_user( 'subscriber', false );
		assert_same( 'Ada Lovelace', Team::members()[ $manager ] ?? null );
		assert_true( ! isset( Team::members()[ $other ] ), 'no capability, not listed' );
		assert_true( Team::is_member( $manager ) && ! Team::is_member( $other ) && ! Team::is_member( 0 ) );
		assert_same( 'AL', Team::initials( 'Ada Lovelace' ) );
		assert_same( 'É', Team::initials( 'élodie' ) );
		assert_same( 'Deleted user', Team::name( 999999999 ) );

		$repo = new Reports_Repository();
		$id   = fixthisplz_admin_insert( 'assignable' );
		assert_true( ! $repo->update( $id, array( 'assigned_to' => $other ) ), 'a non-manager cannot be assigned' );
		assert_true( ! $repo->update( $id, array( 'assigned_to' => 999999999 ) ), 'nor a missing user' );
		assert_same( null, $repo->find( $id )['assigned_to'] );
		assert_same( array(), activity_changes( $id ) );
	}
);

fixthisplz_test(
	'activity: every_path_records_one_history_entry_and_mails_the_new_assignee',
	function () {
		$me   = activity_user( 'editor', true, 'Me Myself' );
		$bob  = activity_user( 'editor', true, 'Bob Builder' );
		$repo = new Reports_Repository();
		$id   = fixthisplz_admin_insert( 'assign-paths' );

		// Detail page: status and priority posted unchanged, only the assignment changes.
		$post = array( 'report' => $id, 'status' => 'new', 'priority' => 'normal', 'assigned_to' => $bob );
		wp_set_current_user( $me );
		$post['_wpnonce'] = wp_create_nonce( Detail_Page::UPDATE_ACTION . '_' . $id );
		$mails            = activity_mails( fn() => assert_true( str_starts_with( activity_call( array( Detail_Page::class, 'handle_update' ), $post, $me ), 'redirect:' ) ) );
		assert_same( array( array( 'assigned_to', null, (string) $bob, $me ) ), activity_changes( $id ) );
		assert_same( $bob, $repo->find( $id )['assigned_to'] );
		assert_same( 1, count( $mails ), 'one e-mail to the new assignee' );
		assert_same( array( get_userdata( $bob )->user_email ), $mails[0]['to'] );
		assert_true( str_ends_with( $mails[0]['subject'], '] Assigned to you: ' . Report_Format::title( $id ) ), 'subject: ' . $mails[0]['subject'] );
		assert_true( str_contains( $mails[0]['message'], 'page=fixthisplz&#038;report=' . $id . '"' ), 'link to the detail page' );
		assert_true( ! str_contains( $mails[0]['message'], '<script' ), 'escaped' );

		// Bulk action, assigning oneself: recorded, no e-mail.
		wp_set_current_user( $me );
		$mails = activity_mails( fn() => assert_same( array( 'updated', 1 ), Bugs_Page::apply_bulk( 'assign_' . $me, array( $id ) ) ) );
		assert_same( array(), $mails, 'no e-mail when assigning oneself' );
		assert_same( array( 'assigned_to', (string) $bob, (string) $me, $me ), activity_changes( $id )[0] );
		assert_same( 2, count( activity_changes( $id ) ) );

		// Kanban drag (REST PATCH of the status).
		rest_get_server();
		$req = new WP_REST_Request( 'PATCH', '/fixthisplz/v1/reports/' . $id );
		$req->set_body_params( array( 'status' => 'confirmed' ) );
		assert_same( 200, rest_do_request( $req )->get_status() );
		assert_same( array( 'status', 'new', 'confirmed', $me ), activity_changes( $id )[0] );

		// Bulk "Unassign", then a deleted status whose reports are moved: a "System" change.
		Bugs_Page::apply_bulk( 'unassign', array( $id ) );
		assert_same( array( 'assigned_to', (string) $me, null, $me ), activity_changes( $id )[0] );
		$repo->reassign( Statuses::class, 'confirmed', 'seen' );
		assert_same( array( 'status', 'confirmed', 'seen', 0 ), activity_changes( $id )[0] );
		assert_same( 5, count( activity_changes( $id ) ), 'one entry per change' );

		// An update that changes nothing writes and records nothing, and says so.
		global $wpdb;
		$wpdb->update( FixThisPlz\Installer::table(), array( 'updated_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		assert_same( array(), $repo->update( $id, array( 'status' => 'seen', 'assigned_to' => 0 ) ) );
		assert_same( '2000-01-01 00:00:00', $repo->find( $id )['updated_at'], 'updated_at untouched' );
		assert_same( 5, count( activity_changes( $id ) ) );
		assert_same( array( '', 0 ), Bugs_Page::apply_bulk( 'unassign', array( $id ) ), 'not counted as updated' );
		wp_set_current_user( 0 );
	}
);

fixthisplz_test(
	'activity: note_post_checks_nonce_and_capability',
	function () {
		$author = activity_user();
		$guest  = activity_user( 'subscriber', false );
		$id     = fixthisplz_admin_insert( 'note-post' );
		$call   = static fn( array $post, int $user ) => activity_call( array( Activity_Box::class, 'handle_add' ), $post + array( 'report' => $id, 'note' => 'Checked on staging' ), $user );

		assert_true( str_starts_with( $call( array( '_wpnonce' => 'bad' ), $author ), 'die:' ), 'bad nonce' );
		wp_set_current_user( $guest );
		assert_true( str_starts_with( $call( array( '_wpnonce' => wp_create_nonce( Activity_Box::ADD_ACTION . '_' . $id ) ), $guest ), 'die:' ), 'no capability' );
		assert_same( array(), Activity::for_report( $id ) );

		wp_set_current_user( $author );
		$nonce = wp_create_nonce( Activity_Box::ADD_ACTION . '_' . $id );
		assert_true( str_contains( $call( array( '_wpnonce' => $nonce ), $author ), 'fixthisplz_notice=note_added' ) );
		assert_true( str_contains( $call( array( '_wpnonce' => $nonce, 'note' => " \n " ), $author ), 'fixthisplz_notice=note_failed' ), 'empty note refused' );
		$notes = Activity::for_report( $id );
		assert_same( 1, count( $notes ) );
		assert_same( array( Activity::NOTE, $author, 'Checked on staging' ), array( $notes[0]['type'], $notes[0]['user_id'], $notes[0]['content'] ) );
		wp_set_current_user( 0 );
	}
);

fixthisplz_test(
	'activity: notes_are_deleted_by_their_author_or_an_administrator_only',
	function () {
		$author = activity_user();
		$other  = activity_user();
		$admin  = activity_user( 'administrator' );
		$id     = fixthisplz_admin_insert( 'note-delete' );
		$first  = Activity::add_note( $id, $author, 'first' );
		$second = Activity::add_note( $id, $author, 'second' );
		$delete = static function ( int $note, int $user ): string {
			wp_set_current_user( $user );
			$post = array( 'note' => $note, '_wpnonce' => wp_create_nonce( Activity_Box::DELETE_ACTION . '_' . $note ) );
			return activity_call( array( Activity_Box::class, 'handle_delete' ), $post, $user );
		};

		assert_true( ! Activity::can_delete( Activity::find( $first ), $other ) );
		assert_true( str_starts_with( $delete( $first, $other ), 'die:' ), 'another manager is refused' );
		assert_true( null !== Activity::find( $first ) );
		assert_true( str_contains( $delete( $first, $author ), 'fixthisplz_notice=note_deleted' ), 'the author may' );
		assert_same( null, Activity::find( $first ) );
		assert_true( str_contains( $delete( $second, $admin ), 'report=' . $id ), 'an administrator may' );
		assert_same( null, Activity::find( $second ) );

		( new Reports_Repository() )->update( $id, array( 'status' => 'fixed' ) );
		$change = Activity::for_report( $id )[0];
		assert_true( ! Activity::can_delete( $change, $admin ), 'history entries cannot be deleted' );
		assert_true( str_starts_with( $delete( $change['id'], $admin ), 'die:' ) );
		wp_set_current_user( 0 );
	}
);

fixthisplz_test(
	'activity: timeline_merges_notes_and_changes_newest_first_and_escapes_notes',
	function () {
		global $wpdb;
		$author = activity_user( 'editor', true, 'Nora Note' );
		$id     = fixthisplz_admin_insert( 'timeline' );
		$note   = Activity::add_note( $id, $author, "  <b>bold</b> & co\nsecond line\n" );
		$repo   = new Reports_Repository();
		$repo->update( $id, array( 'priority' => 'high' ) );
		$wpdb->insert( Activity::table(), array( 'report_id' => $id, 'user_id' => $author, 'type' => 'note', 'content' => '<script>alert(1)</script>', 'created_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
		$wpdb->insert( Activity::table(), array( 'report_id' => $id, 'user_id' => 0, 'type' => 'change', 'field' => 'status', 'old_value' => 'gone_key', 'new_value' => 'seen', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$wpdb->insert( Activity::table(), array( 'report_id' => $id, 'user_id' => null, 'type' => 'change', 'field' => 'assigned_to', 'old_value' => null, 'new_value' => '999999999', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) );

		assert_same( "<b>bold</b> & co\nsecond line", Activity::find( $note )['content'], 'stored as typed, trimmed' );
		assert_same( Activity::NOTE_MAX, mb_strlen( Activity::find( Activity::add_note( $id, $author, str_repeat( 'é', Activity::NOTE_MAX + 10 ) ) )['content'] ), 'length capped' );
		$wpdb->delete( Activity::table(), array( 'report_id' => $id, 'content' => str_repeat( 'é', Activity::NOTE_MAX ) ) );
		$types = array_column( Activity::for_report( $id ), 'type' );
		assert_same( array( 'note', 'change', 'note', 'change', 'change' ), $types, 'newest first, notes and changes merged' );

		$html = fixthisplz_capture( fn() => Activity_Box::render( $id ) );
		assert_true( str_contains( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ), 'note escaped' );
		assert_true( str_contains( $html, '&lt;b&gt;bold&lt;/b&gt; &amp; co<br />' ), 'typed tags shown as text, line breaks kept' );
		assert_true( str_contains( $html, esc_html( 'Priority: ' . Priorities::label( 'normal' ) . ' → ' . Priorities::label( 'high' ) ) ), 'labels of the settings' );
		assert_true( str_contains( $html, esc_html( 'Status: gone_key → ' . Statuses::label( 'seen' ) ) ), 'unknown key shown as is' );
		assert_true( str_contains( $html, 'Assigned to: Unassigned → Deleted user' ), 'deleted assignee' );
		assert_true( str_contains( $html, '<strong>System</strong>' ) && str_contains( $html, '<strong>Deleted user</strong>' ) && str_contains( $html, '<strong>Nora Note</strong>' ), 'authors' );
		$positions = array_map( static fn( string $s ): int => (int) strpos( $html, $s ), array( '&lt;script', 'Priority:', '&lt;b&gt;bold', 'Status:', 'Assigned to:' ) );
		$sorted    = $positions;
		sort( $sorted );
		assert_same( $sorted, $positions, 'rendered in the same order' );
		assert_true( str_contains( fixthisplz_capture( fn() => Detail_Page::render( $id ) ), 'Notes and history' ), 'box on the detail page' );
	}
);

fixthisplz_test(
	'activity: deleting_a_report_deletes_its_activity',
	function () {
		$repo = new Reports_Repository();
		$id   = fixthisplz_admin_insert( 'cascade' );
		$keep = fixthisplz_admin_insert( 'cascade-keep' );
		Activity::add_note( $id, 1, 'gone' );
		$repo->update( $id, array( 'status' => 'fixed' ) );
		Activity::add_note( $keep, 1, 'kept' );
		assert_true( $repo->delete( $id ) );
		assert_same( array(), Activity::for_report( $id ) );
		assert_same( 1, count( Activity::for_report( $keep ) ) );
	}
);

fixthisplz_test(
	'activity: deleting_a_user_unassigns_their_reports_and_keeps_their_notes',
	function () {
		$gone = activity_user( 'editor', true, 'Gone Soon' );
		$repo = new Reports_Repository();
		$id   = fixthisplz_admin_insert( 'user-delete' );
		activity_mails( fn() => assert_true( null !== $repo->update( $id, array( 'assigned_to' => $gone ) ) ) );
		$note = Activity::add_note( $id, $gone, 'my note' );
		assert_true( wp_delete_user( $gone ) );
		assert_same( null, $repo->find( $id )['assigned_to'], 'unassigned' );
		assert_same( array( 'assigned_to', (string) $gone, null, 0 ), activity_changes( $id )[0], 'recorded as a System change' );
		assert_same( 'my note', Activity::find( $note )['content'], 'note kept' );
		assert_true( str_contains( fixthisplz_capture( fn() => Activity_Box::render( $id ) ), '<strong>Deleted user</strong>' ) );
		assert_true( false !== has_action( 'remove_user_from_blog', array( Team::class, 'on_user_deleted' ) ), 'multisite removal unassigns too' );
	}
);

fixthisplz_test(
	'activity: privacy_exports_and_anonymises_the_notes_and_changes_of_a_user',
	function () {
		$author = activity_user();
		$other  = activity_user();
		$id     = fixthisplz_admin_insert( 'privacy-notes' );
		$mine   = Activity::add_note( $id, $author, 'my private note' );
		Activity::add_note( $id, $other, 'not mine' );
		wp_set_current_user( $author );
		activity_mails( fn() => ( new Reports_Repository() )->update( $id, array( 'assigned_to' => $other ) ) );
		wp_set_current_user( 0 );
		$change = Activity::for_report( $id )[0];
		$email  = get_userdata( $author )->user_email;

		assert_true( is_callable( Privacy::register_exporter( array() )['fixthisplz-notes']['callback'] ) );
		assert_true( is_callable( Privacy::register_eraser( array() )['fixthisplz-notes']['callback'] ) );
		$export = Privacy::export_notes( $email, 1 );
		assert_true( $export['done'] );
		assert_same( array( 'bug-activity-' . $mine, 'bug-activity-' . $change['id'] ), array_column( $export['data'], 'item_id' ), 'their note and their change' );
		assert_same( array( '#' . $id, 'my private note' ), array( $export['data'][0]['data'][0]['value'], $export['data'][0]['data'][2]['value'] ) );
		assert_same( array( 'Change', Report_Format::change( 'assigned_to', null, (string) $other ) ), array_values( $export['data'][1]['data'][2] ) );
		assert_same( array( 'data' => array(), 'done' => true ), Privacy::export_notes( 'nobody-' . wp_rand() . '@example.test', 1 ) );

		for ( $i = 0; $i < 50; $i++ ) {
			Activity::add_note( $id, $author, 'page ' . $i );
		}
		$one = Privacy::export_notes( $email, 1 );
		$two = Privacy::export_notes( $email, 2 );
		assert_same( array( 50, false ), array( count( $one['data'] ), $one['done'] ) );
		assert_same( array( 2, true ), array( count( $two['data'] ), $two['done'] ), 'page 2 has the rest' );

		$erase = Privacy::erase_notes( $email, 1 );
		assert_same( array( true, false, true ), array( $erase['items_removed'], $erase['items_retained'], $erase['done'] ) );
		assert_same( null, Activity::find( $mine )['user_id'], 'author removed' );
		assert_same( null, Activity::find( $change['id'] )['user_id'], 'from their changes too' );
		assert_same( 'my private note', Activity::find( $mine )['content'], 'note kept' );
		assert_same( array(), Privacy::export_notes( $email, 1 )['data'], 'export and erase cover the same entries' );
		assert_same( 1, count( array_filter( Activity::for_report( $id ), static fn( $e ) => null !== $e['user_id'] ) ), 'others untouched' );
	}
);

fixthisplz_test(
	'activity: bulk_assignment_sends_one_summary_per_assignee_and_never_to_the_actor',
	function () {
		$me   = activity_user( 'editor', true, 'Bulk Boss' );
		$bob  = activity_user( 'editor', true, 'Bob Bulk' );
		$ids  = array( fixthisplz_admin_insert( 'batch 1' ), fixthisplz_admin_insert( 'batch 2' ), fixthisplz_admin_insert( 'batch 3' ) );
		$repo = new Reports_Repository();
		wp_set_current_user( $me );
		activity_mails( fn() => $repo->update( $ids[2], array( 'assigned_to' => $bob ) ) );

		$mails = activity_mails( fn() => assert_same( array( 'updated', 2 ), Bugs_Page::apply_bulk( 'assign_' . $bob, $ids ), 'only real changes count' ) );
		assert_same( 1, count( $mails ), 'one e-mail for the batch' );
		assert_same( array( get_userdata( $bob )->user_email ), $mails[0]['to'] );
		assert_true( str_ends_with( $mails[0]['subject'], '] 2 reports assigned to you' ), 'subject: ' . $mails[0]['subject'] );
		foreach ( array( $ids[0], $ids[1] ) as $id ) {
			assert_true( str_contains( $mails[0]['message'], 'report=' . $id . '"' ) && str_contains( $mails[0]['message'], esc_html( Report_Format::title( $id ) ) ), 'title and link of #' . $id );
		}
		assert_true( ! str_contains( $mails[0]['message'], 'report=' . $ids[2] . '"' ), 'the report already assigned is not listed' );

		Bugs_Page::apply_bulk( 'unassign', $ids );
		$mails = activity_mails( fn() => Bugs_Page::apply_bulk( 'assign_' . $bob, array( $ids[0] ) ) );
		assert_same( 1, count( $mails ), 'a single report: the single e-mail' );
		assert_true( str_ends_with( $mails[0]['subject'], '] Assigned to you: ' . Report_Format::title( $ids[0] ) ) );
		assert_same( array(), activity_mails( fn() => Bugs_Page::apply_bulk( 'assign_' . $me, $ids ) ), 'never to the actor' );
		wp_set_current_user( 0 );
	}
);

fixthisplz_test(
	'activity: opening_a_new_report_records_new_to_seen',
	function () {
		$me = activity_user();
		$id = fixthisplz_admin_insert( 'seen-history' );
		wp_set_current_user( $me );
		fixthisplz_capture( fn() => Detail_Page::render( $id ) );
		fixthisplz_capture( fn() => Detail_Page::render( $id ) );
		wp_set_current_user( 0 );
		assert_same( array( array( 'status', 'new', 'seen', $me ) ), activity_changes( $id ), 'recorded once, for the person who opened it' );
	}
);

fixthisplz_test(
	'activity: report_changes_keeps_only_real_valid_changes',
	function () {
		$member  = activity_user();
		$current = array( 'status' => 'zz_gone', 'priority' => 'normal', 'assigned_to' => null, 'description' => 'x' );
		assert_same( null, Report_Changes::from( $current, array() ), 'nothing editable' );
		assert_same( null, Report_Changes::from( $current, array( 'description' => 'y' ) ) );
		assert_same( array(), Report_Changes::from( $current, array( 'status' => 'zz_gone', 'priority' => 'normal', 'assigned_to' => 0 ) ), 'unchanged, unknown status kept' );
		assert_same( null, Report_Changes::from( $current, array( 'status' => 'zz_other' ) ), 'an unknown status is never newly set' );
		assert_same( null, Report_Changes::from( $current, array( 'priority' => 'high', 'assigned_to' => 999999999 ) ), 'one invalid value rejects all' );
		assert_same(
			array( 'status' => array( 'zz_gone', 'fixed' ), 'assigned_to' => array( null, (string) $member ) ),
			Report_Changes::from( $current, array( 'status' => 'fixed', 'priority' => 'normal', 'assigned_to' => (string) $member ) )
		);
		assert_same( array( 'assigned_to' => array( (string) $member, null ) ), Report_Changes::from( array( 'assigned_to' => $member ), array( 'assigned_to' => null ) ), 'null or 0 unassigns' );
	}
);

fixthisplz_test(
	'activity: assignee_filters_list_column_kanban_initials_and_bulk_actions',
	function () {
		$me   = activity_user( 'editor', true, 'Kim Kanban' );
		$repo = new Reports_Repository();
		$mark = 'assignee' . wp_generate_password( 6, false );
		$mine = fixthisplz_admin_insert( $mark . ' mine' );
		$free = fixthisplz_admin_insert( $mark . ' free' );
		activity_mails( fn() => $repo->update( $mine, array( 'assigned_to' => $me ) ) );
		$ids = static fn( array $args ) => array_column( $repo->query( $args + array( 'search' => $mark ) )['items'], 'id' );
		assert_same( array( $mine ), $ids( array( 'assigned' => (string) $me ) ) );
		assert_same( array( $free ), $ids( array( 'assigned' => 'none' ) ) );
		assert_same( array( $free, $mine ), $ids( array() ) );

		assert_same( 'none', FixThisPlz\Admin\Report_Query_Args::read_filters( array( 'assigned' => 'none' ) )['assigned'] );
		assert_same( '12', FixThisPlz\Admin\Report_Query_Args::read_filters( array( 'assigned' => '12' ) )['assigned'] );
		assert_same( '', FixThisPlz\Admin\Report_Query_Args::read_filters( array( 'assigned' => 'x' ) )['assigned'] );

		$filters = array( 'status' => '', 'priority' => '', 'assigned' => '', 'from' => '', 'to' => '', 'search' => $mark );
		$board   = fixthisplz_capture( fn() => Kanban::render( $filters ) );
		assert_true( str_contains( $board, 'aria-label="Assigned to Kim Kanban">KK</span>' ), 'initials on the card' );

		wp_set_current_user( $me );
		$table   = new List_Table();
		$actions = ( new ReflectionMethod( $table, 'get_bulk_actions' ) )->invoke( $table );
		assert_same( 'Assign to: Kim Kanban', $actions[ 'assign_' . $me ] ?? null );
		assert_true( isset( $actions['unassign'] ) );
		$cell = ( new ReflectionMethod( $table, 'column_assigned' ) )->invoke( $table, $repo->find_many( array( $mine ) )[0] );
		assert_same( 'Kim Kanban', $cell );
		wp_set_current_user( 0 );
	}
);

fixthisplz_test(
	'activity: the_assignment_email_is_written_in_the_language_of_the_assignee',
	function () {
		$me  = activity_user( 'editor', true, 'Locale Boss' );
		$ana = activity_user( 'editor', true, 'Ana Locale' );
		update_user_meta( $ana, 'locale', 'fr_FR' );
		$ids  = array( fixthisplz_admin_insert( 'locale 1' ), fixthisplz_admin_insert( 'locale 2' ) );
		$repo = new Reports_Repository();
		wp_set_current_user( $me );

		$mails = activity_mails( fn() => $repo->update( $ids[0], array( 'assigned_to' => $ana ) ) );
		assert_true( str_contains( $mails[0]['subject'], '] Vous est attribué : ' ), 'single: ' . $mails[0]['subject'] );
		$repo->update( $ids[0], array( 'assigned_to' => 0 ) );
		$mails = activity_mails( fn() => Bugs_Page::apply_bulk( 'assign_' . $ana, $ids ) );
		assert_true( str_ends_with( $mails[0]['subject'], '] 2 signalements vous sont attribués' ), 'batch: ' . $mails[0]['subject'] );
		assert_same( 'Status', __( 'Status', 'fixthisplz' ), 'the request language comes back afterwards' );
		wp_set_current_user( 0 );
	}
);
