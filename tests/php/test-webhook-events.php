<?php

use Bug_Spotter\Admin\Bugs_Page;
use Bug_Spotter\Report_Submission;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;
use Bug_Spotter\Webhooks;

require_once __DIR__ . '/test-webhooks.php';
require_once __DIR__ . '/test-activity.php';
require_once __DIR__ . '/test-submission.php';

/** Runs $fn and counts the `bugspot_report_changed` actions it fires. */
function webhook_changed_count( callable $fn ): int {
	$count   = 0;
	$counter = static function () use ( &$count ): void {
		++$count;
	};
	add_action( 'bugspot_report_changed', $counter );
	try {
		$fn();
	} finally {
		remove_action( 'bugspot_report_changed', $counter );
	}
	return $count;
}

/** The "event" of each captured generic JSON request. @param array[] $sent */
function webhook_events( array $sent ): array {
	return array_map( static fn( array $r ): string => (string) json_decode( $r['args']['body'], true )['event'], $sent );
}

bugspot_test(
	'webhook-events: every_real_change_fires_once_on_every_path',
	function () {
		$me   = activity_user( 'editor', true, 'Hook Tester' );
		$repo = new Reports_Repository();
		$id   = bugspot_admin_insert( 'hook-paths' );
		$two  = bugspot_admin_insert( 'hook-paths-2' );
		wp_set_current_user( $me );

		assert_same( 1, webhook_changed_count( fn() => $repo->update( $id, array( 'status' => 'confirmed' ) ) ), 'update' );
		assert_same( 0, webhook_changed_count( fn() => $repo->update( $id, array( 'status' => 'confirmed', 'priority' => 'normal' ) ) ), 'no-op' );
		assert_same( 0, webhook_changed_count( fn() => $repo->update( $id, array( 'status' => 'nope' ) ) ), 'invalid' );
		assert_same( 1, webhook_changed_count( fn() => $repo->update( $id, array( 'status' => 'fixed', 'assigned_to' => $me ) ) ), 'two fields, one action' );
		assert_same( 2, webhook_changed_count( fn() => Bugs_Page::apply_bulk( 'priority_high', array( $id, $two ) ) ), 'bulk: one per report' );
		assert_same( 0, webhook_changed_count( fn() => Bugs_Page::apply_bulk( 'priority_high', array( $id, $two ) ) ), 'bulk no-op' );
		assert_same( 1, webhook_changed_count( fn() => $repo->mark_seen( $two ) ), 'automatic new -> seen' );
		assert_same( 0, webhook_changed_count( fn() => $repo->mark_seen( $two ) ), 'already seen' );

		rest_get_server();
		$kanban = static function ( string $status ) use ( $id ): void {
			$req = new WP_REST_Request( 'PATCH', '/bug-spotter/v1/reports/' . $id );
			$req->set_body_params( array( 'status' => $status ) );
			assert_same( 200, rest_do_request( $req )->get_status() );
		};
		assert_same( 1, webhook_changed_count( fn() => $kanban( 'in_progress' ) ), 'Kanban' );
		assert_same( 0, webhook_changed_count( fn() => $kanban( 'in_progress' ) ), 'Kanban no-op' );
		wp_set_current_user( 0 );
	}
);

bugspot_test(
	'webhook-events: a_new_report_is_posted_once_and_signed',
	function () {
		submission_env(
			function ( $settings, $ip ) {
				$created = 0;
				$count   = static function () use ( &$created ): void {
					++$created;
				};
				add_action( 'bugspot_report_created', $count );
				try {
					$sent = webhook_requests( fn() => assert_true( is_int( Report_Submission::submit( Settings::get(), submission_input( array( 'reporter_email' => 'private.reporter@example.test' ) ), null, $ip ) ) ) );
				} finally {
					remove_action( 'bugspot_report_created', $count );
				}
				assert_same( 1, $created, 'bugspot_report_created fired once' );
				assert_same( 1, count( $sent ) );
				assert_same( WEBHOOK_PUBLIC_URL, $sent[0]['url'] );
				$args = $sent[0]['args'];
				assert_same( array( false, 2, 0, true ), array( $args['blocking'], $args['timeout'], $args['redirection'], $args['reject_unsafe_urls'] ) );
				assert_same( 'report.created', $args['headers']['X-Bug-Spotter-Event'] );
				$secret = Webhooks::secret( Webhooks::all()[0] );
				assert_same( 'sha256=' . hash_hmac( 'sha256', $args['body'], (string) $secret ), $args['headers']['X-Bug-Spotter-Signature'], 'signature verifies' );
				assert_true( ! str_contains( $args['body'], 'private.reporter' ), 'no reporter e-mail' );
			},
			array( 'webhooks' => Webhooks::sanitize( array( webhook_row() ) ) )
		);
	}
);

bugspot_test(
	'webhook-events: events_follow_each_webhook_choice_and_switch',
	function () {
		$me = activity_user( 'editor', true, 'Event Picker' );
		webhook_env(
			array(
				webhook_row(),
				webhook_row( array( 'url' => 'http://localhost:8765/created-only', 'format' => 'slack', 'events' => array( 'created' ) ) ),
				webhook_row( array( 'url' => 'http://localhost:8765/off', 'enabled' => '0' ) ),
			),
			function () use ( $me ) {
				$repo = new Reports_Repository();
				$id   = bugspot_admin_insert( 'hook-events' );
				wp_set_current_user( $me );

				$sent = webhook_requests( fn() => $repo->update( $id, array( 'status' => 'confirmed', 'assigned_to' => $me ) ) );
				assert_same( array( WEBHOOK_PUBLIC_URL, WEBHOOK_PUBLIC_URL ), array_column( $sent, 'url' ), 'only the generic webhook chose these events' );
				assert_same( array( 'report.status_changed', 'report.assigned' ), webhook_events( $sent ) );
				$body = json_decode( $sent[1]['args']['body'], true );
				assert_same( 'Event Picker', $body['report']['assignee'] );
				assert_same( 'Assigned to: Unassigned → Event Picker', $body['change'] );

				assert_same( array(), webhook_requests( fn() => $repo->update( $id, array( 'assigned_to' => 0, 'priority' => 'high' ) ) ), 'unassign and priority: no event' );
				assert_same( array(), webhook_requests( fn() => $repo->update( $id, array( 'status' => 'confirmed' ) ) ), 'no-op: nothing sent' );

				$created = webhook_requests( fn() => do_action( 'bugspot_report_created', $id ) );
				assert_same( array( WEBHOOK_PUBLIC_URL, 'http://localhost:8765/created-only' ), array_column( $created, 'url' ), 'disabled webhook never called' );
				assert_same( false, $created[1]['args']['reject_unsafe_urls'], 'development host' );
				assert_true( ! isset( $created[1]['args']['headers']['X-Bug-Spotter-Signature'] ), 'only the generic JSON is signed' );
				wp_set_current_user( 0 );
			}
		);
	}
);

bugspot_test(
	'webhook-events: new_to_seen_sends_nothing_but_an_assignment_still_goes_out',
	function () {
		$me = activity_user( 'editor', true, 'Seen Opener' );
		webhook_env(
			array( webhook_row() ),
			function () use ( $me ) {
				$repo = new Reports_Repository();
				$id   = bugspot_admin_insert( 'hook-seen' );
				$two  = bugspot_admin_insert( 'hook-seen-2' );
				wp_set_current_user( $me );
				$fired = 0;
				$sent  = webhook_requests(
					function () use ( $repo, $id, &$fired ) {
						$fired = webhook_changed_count( fn() => $repo->mark_seen( $id ) );
					}
				);
				assert_same( array( 1, array() ), array( $fired, $sent ), 'opening: history action, no webhook' );
				assert_same( array(), webhook_requests( fn() => $repo->update( $two, array( 'status' => 'seen' ) ) ), 'manual New -> Seen: nothing' );
				$sent = webhook_requests( fn() => $repo->update( $two, array( 'status' => 'new' ) ) );
				assert_same( array( 'report.status_changed' ), webhook_events( $sent ), 'Seen -> New is a real change' );
				$sent = webhook_requests( fn() => $repo->update( $two, array( 'status' => 'seen', 'assigned_to' => $me ) ) );
				assert_same( array( 'report.assigned' ), webhook_events( $sent ), 'the assignment of the same update goes out' );
				wp_set_current_user( 0 );
			}
		);
	}
);

bugspot_test(
	'webhook-events: bulk_actions_send_one_summary_per_chat_webhook_and_every_generic_event',
	function () {
		$me = activity_user( 'editor', true, 'Bulk Boss' );
		webhook_env(
			array(
				webhook_row(),
				webhook_row( array( 'url' => 'http://localhost:8765/slack', 'format' => 'slack' ) ),
				webhook_row( array( 'url' => 'http://localhost:8765/teams', 'format' => 'teams', 'events' => array( 'assigned' ) ) ),
			),
			function () use ( $me ) {
				$ids = array( bugspot_admin_insert( 'bulk-1' ), bugspot_admin_insert( 'bulk-2' ), bugspot_admin_insert( 'bulk-3' ) );
				wp_set_current_user( $me );

				$sent = webhook_requests( fn() => Bugs_Page::apply_bulk( 'status_fixed', $ids ) );
				$by   = array_count_values( array_column( $sent, 'url' ) );
				assert_same( array( WEBHOOK_PUBLIC_URL => 3, 'http://localhost:8765/slack' => 1 ), $by, 'generic: one per report; slack: one summary' );
				$slack = json_decode( end( $sent )['args']['body'], true );
				assert_same( '3 reports: status → Fixed', $slack['text'] );
				assert_true( str_contains( $slack['blocks'][0]['text']['text'], 'page=bug-spotter|Open the list>' ), 'summary links the list' );

				$sent = webhook_requests( fn() => Bugs_Page::apply_bulk( 'assign_' . $me, $ids ) );
				$teams = array_values( array_filter( $sent, static fn( array $r ): bool => 'http://localhost:8765/teams' === $r['url'] ) );
				assert_same( 1, count( $teams ), 'one Teams summary' );
				assert_same( '3 reports assigned to Bulk Boss', json_decode( $teams[0]['args']['body'], true )['attachments'][0]['content']['body'][0]['text'] );
				assert_same( 5, count( $sent ), 'generic 3 + one Slack and one Teams summary' );

				$sent = webhook_requests( fn() => Bugs_Page::apply_bulk( 'status_confirmed', array( $ids[0], 999999999 ) ) );
				$slack = json_decode( end( $sent )['args']['body'], true );
				assert_same( 2, count( $sent ), 'a single changed report' );
				assert_same( 'Status changed: Bug #' . $ids[0], $slack['text'], 'gets the usual message' );
				assert_same( array(), webhook_requests( fn() => Bugs_Page::apply_bulk( 'status_confirmed', array( $ids[0] ) ) ), 'bulk no-op: nothing' );
				wp_set_current_user( 0 );
			}
		);
	}
);

bugspot_test(
	'webhook-events: a_failing_webhook_breaks_neither_the_change_nor_the_others',
	function () {
		webhook_env(
			array( webhook_row( array( 'url' => 'http://localhost:8765/broken' ) ), webhook_row() ),
			function () {
				$id    = bugspot_admin_insert( 'hook-broken' );
				$calls = array();
				$boom  = static function ( $pre, $args, $url ) use ( &$calls ) {
					$calls[] = $url;
					if ( str_contains( $url, 'broken' ) ) {
						throw new RuntimeException( 'receiver exploded' );
					}
					return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
				};
				add_filter( 'pre_http_request', $boom, 10, 3 );
				try {
					$changes = ( new Reports_Repository() )->update( $id, array( 'status' => 'fixed' ) );
				} finally {
					remove_filter( 'pre_http_request', $boom, 10 );
				}
				assert_same( array( 'status' => array( 'new', 'fixed' ) ), $changes, 'the change is saved' );
				assert_same( array( 'http://localhost:8765/broken', WEBHOOK_PUBLIC_URL ), $calls, 'the next webhook is still called' );
			}
		);
	}
);

bugspot_test(
	'webhook-events: test_endpoint_needs_manage_options_and_reports_the_answer',
	function () {
		$editor = activity_user( 'editor', true );
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		webhook_env(
			array( webhook_row( array( 'enabled' => '0' ) ) ),
			function () use ( $editor, $admins ) {
				rest_get_server();
				$call = static function ( $index ): WP_REST_Response {
					$req = new WP_REST_Request( 'POST', '/bug-spotter/v1/webhook-test' );
					$req->set_body_params( array( 'index' => $index ) );
					return rest_do_request( $req );
				};
				wp_set_current_user( $editor );
				$sent = webhook_requests( fn() => assert_same( 403, $call( 0 )->get_status(), 'bug manager without manage_options' ) );
				assert_same( array(), $sent );

				wp_set_current_user( (int) $admins[0] );
				assert_same( 422, $call( 3 )->get_status(), 'no saved webhook there' );
				$ok   = null;
				$sent = webhook_requests(
					function () use ( $call, &$ok ) {
						$ok = $call( 0 );
					}
				);
				assert_same( 200, $ok->get_status() );
				assert_same( array( true, 200 ), array( $ok->get_data()['ok'], $ok->get_data()['code'] ) );
				assert_same( 'The receiver answered: HTTP 200 OK', $ok->get_data()['message'] );
				assert_same( array( true, 5 ), array( $sent[0]['args']['blocking'], $sent[0]['args']['timeout'] ), 'test waits, even when inactive' );
				assert_same( 'test', json_decode( $sent[0]['args']['body'], true )['event'] );

				$refused = null;
				webhook_requests( function () use ( $call, &$refused ) { $refused = $call( 0 ); }, array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array() ) );
				assert_same( array( false, 404 ), array( $refused->get_data()['ok'], $refused->get_data()['code'] ) );
				$failed = null;
				webhook_requests( function () use ( $call, &$failed ) { $failed = $call( 0 ); }, new WP_Error( 'http_request_failed', 'cURL error 7: refused' ) );
				assert_same( 502, $failed->get_status() );
				assert_same( 'cURL error 7: refused', $failed->get_data()['message'] );
				wp_set_current_user( 0 );
			}
		);
	}
);
