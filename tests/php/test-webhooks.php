<?php

use Bug_Spotter\Admin\Settings_Page;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Secret;
use Bug_Spotter\Settings;
use Bug_Spotter\Webhook_Payload;
use Bug_Spotter\Webhooks;

require_once __DIR__ . '/test-settings-page.php';
require_once __DIR__ . '/test-admin-list.php';

/** A public address: an IP literal, so validating it needs no DNS lookup (and nothing is ever sent, see lib.php). */
const WEBHOOK_PUBLIC_URL = 'https://93.184.215.14/hook';

/** Runs $fn with development hosts allowed or not (`bugspot_allow_dev_webhooks`), whatever the environment type. */
function webhook_dev_hosts( bool $allowed, callable $fn ): void {
	$filter = $allowed ? '__return_true' : '__return_false';
	add_filter( 'bugspot_allow_dev_webhooks', $filter );
	try {
		$fn();
	} finally {
		remove_filter( 'bugspot_allow_dev_webhooks', $filter );
	}
}

/** Runs $fn with development hosts allowed and the webhooks saved as Webhooks::sanitize( $rows ) (the option is isolated). */
function webhook_env( array $rows, callable $fn ): void {
	webhook_dev_hosts(
		true,
		static fn() => with_settings_option(
			static function () use ( $rows, $fn ) {
				update_option( Settings::OPTION, array( 'webhooks' => Webhooks::sanitize( $rows ) ) );
				$fn();
			}
		)
	);
}

/** A webhook row as the settings form posts it. */
function webhook_row( array $over = array() ): array {
	return array_merge(
		array(
			'url'     => WEBHOOK_PUBLIC_URL,
			'format'  => 'generic',
			'events'  => array( '', 'created', 'status_changed', 'assigned' ),
			'enabled' => '1',
		),
		$over
	);
}

/**
 * Runs $fn with every HTTP request captured and answered by $answer (a response array or a WP_Error).
 *
 * @return array<int,array{url:string,args:array}>
 */
function webhook_requests( callable $fn, $answer = null ): array {
	$sent = array();
	$fake = static function ( $pre, $args, $url ) use ( &$sent, $answer ) {
		$sent[] = array( 'url' => $url, 'args' => $args );
		return $answer ?? array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $fake, 10, 3 );
	try {
		$fn();
	} finally {
		remove_filter( 'pre_http_request', $fake, 10 );
	}
	return $sent;
}

bugspot_test(
	'webhooks: sanitize_accepts_https_public_and_development_addresses_only',
	function () {
		$urls = array(
			WEBHOOK_PUBLIC_URL              => true,
			'http://93.184.215.14/hook'     => false, // http to a public host.
			'https://10.0.0.5/hook'         => false, // private ranges.
			'https://192.168.1.2/hook'      => false,
			'https://169.254.169.254/x'     => false, // cloud metadata.
			'https://93.184.215.14:9999/x'  => false, // unsafe port.
			'http://localhost:8765/hook'    => true,
			'http://127.0.0.1:8765/'        => true,
			'http://receiver.test/hook'     => true,
			'https://app.localhost/hook'    => true,
			'ftp://93.184.215.14/x'         => false,
			'javascript:alert(1)'           => false,
		);
		webhook_dev_hosts(
			true,
			static function () use ( $urls ) {
				foreach ( $urls as $url => $valid ) {
					$out = Webhooks::sanitize( array( webhook_row( array( 'url' => $url ) ) ) );
					assert_same( $valid ? 1 : 0, count( $out ), $url );
				}
			}
		);
		assert_same( array(), Webhooks::sanitize( array( webhook_row( array( 'url' => '  ' ) ), 'junk', null ) ), 'empty rows dropped' );
		assert_same( array(), Webhooks::sanitize( 'not a list' ) );
	}
);

settings_page_test(
	'webhooks: development_hosts_only_where_allowed_and_one_notice_per_address',
	function () {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		global $wp_settings_errors;
		$saved_errors = $wp_settings_errors;
		try {
			webhook_dev_hosts(
				false,
				function () {
					assert_true( ! Webhooks::dev_hosts_allowed() && ! Webhooks::is_dev_url( 'http://localhost:8765/' ) );
					foreach ( array( 'http://localhost:8765/', 'http://127.0.0.1:8765/', 'http://receiver.test/', 'https://app.localhost/' ) as $url ) {
						assert_same( array(), Webhooks::sanitize( array( webhook_row( array( 'url' => $url ) ) ) ), $url );
					}
					$GLOBALS['wp_settings_errors'] = array();
					Webhooks::sanitize( array( webhook_row( array( 'url' => 'http://localhost:8765/' ) ) ) );
					Webhooks::sanitize( array( webhook_row( array( 'url' => 'http://localhost:8765/' ) ) ) );
					$errors = get_settings_errors( Settings::OPTION );
					assert_same( 1, count( $errors ), 'sanitized twice, one notice' );
					assert_true( ! str_contains( $errors[0]['message'], '*.test' ), 'no development hint: ' . $errors[0]['message'] );
					$html = settings_page_tab_html( 'alerts' );
					assert_true( ! str_contains( $html, 'localhost' ) && ! str_contains( $html, '*.test' ), 'tab text without development hosts' );
				}
			);
			webhook_dev_hosts(
				true,
				function () {
					assert_true( Webhooks::is_dev_url( 'http://127.0.0.1:8765/' ) );
					assert_true( str_contains( settings_page_tab_html( 'alerts' ), 'http://localhost and *.test' ), 'tab mentions them when allowed' );
				}
			);
		} finally {
			$GLOBALS['wp_settings_errors'] = $saved_errors;
		}
	}
);

bugspot_test(
	'webhooks: sanitize_keeps_five_rows_known_formats_and_events',
	function () {
		$rows = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$rows[] = webhook_row( array( 'url' => 'http://localhost:800' . $i . '/' ) );
		}
		webhook_dev_hosts( true, static fn() => assert_same( 5, count( Webhooks::sanitize( $rows ) ), 'at most 5' ) );

		$row = Webhooks::sanitize(
			array(
				webhook_row(
					array(
						'format'  => 'irc',
						'events'  => array( 'assigned', 'deleted', array( 'x' ), 'created', 'created' ),
						'enabled' => '0',
					)
				),
			)
		)[0];
		assert_same( 'generic', $row['format'], 'unknown format' );
		assert_same( array( 'created', 'assigned' ), $row['events'], 'known events, in order, once' );
		assert_same( false, $row['enabled'] );
		foreach ( Webhooks::FORMATS as $format ) {
			assert_same( $format, Webhooks::sanitize( array( webhook_row( array( 'format' => $format ) ) ) )[0]['format'] );
		}
		assert_same( array(), Webhooks::sanitize( array( webhook_row( array( 'events' => array( '' ) ) ) ) )[0]['events'], 'no event ticked' );
	}
);

bugspot_test(
	'webhooks: secret_is_generated_kept_and_regenerated',
	function () {
		$row    = Webhooks::sanitize( array( webhook_row() ) )[0];
		$secret = Webhooks::secret( $row );
		assert_true( 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $secret ), 'random 64-hex secret' );
		assert_true( ! str_contains( $row['secret'], (string) $secret ), 'stored encrypted' );

		$again = Webhooks::sanitize( array( array_merge( webhook_row(), array( 'secret' => $row['secret'] ) ) ) )[0];
		assert_same( $row['secret'], $again['secret'], 'posted back: kept' );

		$forged = Webhooks::sanitize( array( array_merge( webhook_row(), array( 'secret' => Secret::encrypt( 'weak' ) ) ) ) )[0];
		assert_true( 'weak' !== Webhooks::secret( $forged ) && null !== Webhooks::secret( $forged ), 'not a valid secret: replaced' );
		$tampered = Webhooks::sanitize( array( array_merge( webhook_row(), array( 'secret' => 'xx' . $row['secret'] ) ) ) )[0];
		assert_true( $secret !== Webhooks::secret( $tampered ), 'unreadable: replaced' );

		$new = Webhooks::sanitize( array( array_merge( webhook_row(), array( 'secret' => $row['secret'], 'regenerate' => '1' ) ) ) )[0];
		assert_true( $secret !== Webhooks::secret( $new ) && null !== Webhooks::secret( $new ), 'regenerate ticked' );
	}
);

settings_page_test(
	'webhooks: integrations_tab_round_trips_and_other_tabs_keep_the_secret',
	fn() => webhook_dev_hosts( true, 'webhook_round_trip' )
);

function webhook_round_trip(): void {
		update_option( Settings::OPTION, array( 'webhooks' => array( webhook_row(), webhook_row( array( 'url' => 'http://receiver.test/', 'format' => 'slack', 'enabled' => '0' ) ) ) ) );
		$before = Settings::get();
		assert_same( 2, count( $before['webhooks'] ) );
		assert_true( 'Alerts' === ( Settings_Page::tabs()['alerts'] ?? null ), 'tab listed' );

		$html = settings_page_tab_html( 'alerts' );
		assert_same( 5, substr_count( $html, '<fieldset class="bugspot-webhook">' ), 'five blocks' );
		assert_same( 2, substr_count( $html, 'bugspot-webhook-test' ), 'a test button per saved webhook' );
		assert_true( str_contains( $html, Webhooks::secret( $before['webhooks'][0] ) ), 'secret shown to the administrator' );

		$post = settings_page_form_post( $html );
		update_option( Settings::OPTION, $post[ Settings::OPTION ] );
		assert_same( $before, Settings::get(), 're-posting the tab changes nothing' );

		update_option( Settings::OPTION, settings_page_form_post( settings_page_tab_html( 'privacy' ) )[ Settings::OPTION ] );
		assert_same( $before['webhooks'], Settings::get()['webhooks'], 'another tab keeps the webhooks' );

		$post = settings_page_form_post( settings_page_tab_html( 'alerts' ) );
		$post[ Settings::OPTION ]['webhooks'][0]['url'] = '';
		update_option( Settings::OPTION, $post[ Settings::OPTION ] );
		assert_same( array( $before['webhooks'][1] ), Settings::get()['webhooks'], 'emptied address: removed' );
}

/** A stored report with every private field filled. */
function webhook_report(): array {
	$id = bugspot_admin_insert(
		'Broken <b>cart</b> & @everyone',
		array(
			'reporter_email' => 'private.reporter@example.test',
			'ip_hash'        => 'iphash-should-stay-private',
			'screenshot'     => 'shot-should-stay-private.jpg',
			'context'        => array( 'url' => 'https://example.test/', 'userAgent' => 'context-should-stay-private' ),
		)
	);
	return ( new Reports_Repository() )->find( $id );
}

bugspot_test(
	'webhooks: payload_shapes_per_format_without_private_data',
	function () {
		$report = webhook_report();
		$data   = Webhook_Payload::data( 'status_changed', $report, 'Status: New → Fixed' );
		assert_same( 'report.status_changed', $data['event'] );
		assert_same( array( 'id', 'title', 'description', 'page_url', 'status', 'priority', 'assignee', 'admin_url' ), array_keys( $data['report'] ) );
		assert_same( 'Bug #' . $report['id'], $data['report']['title'] );
		assert_same( array( 'key' => 'new', 'label' => 'New' ), $data['report']['status'] );
		assert_same( null, $data['report']['assignee'] );
		assert_true( str_ends_with( $data['report']['admin_url'], 'page=bug-spotter&report=' . $report['id'] ) );

		$bodies = array();
		foreach ( Webhooks::FORMATS as $format ) {
			$bodies[ $format ] = json_decode( Webhook_Payload::body( $format, $data ), true );
			$raw               = Webhook_Payload::body( $format, $data );
			foreach ( array( 'private.reporter', 'iphash-should', 'shot-should', 'context-should' ) as $private ) {
				assert_true( ! str_contains( $raw, $private ), "$format leaks $private" );
			}
		}
		assert_same( $data, $bodies['generic'] );
		assert_same( 'Status changed: Bug #' . $report['id'], $bodies['slack']['text'] );
		$slack = $bodies['slack']['blocks'][0]['text']['text'];
		assert_true( str_contains( $slack, 'Broken &lt;b&gt;cart&lt;/b&gt; &amp; @everyone' ), 'slack escapes & < >' );
		assert_true( str_contains( $slack, '*Change:* Status: New → Fixed' ) );
		assert_same( array( 'parse' => array() ), $bodies['discord']['allowed_mentions'], 'discord never pings' );
		assert_same( 'Broken \<b\>cart\</b\> & @everyone', $bodies['discord']['embeds'][0]['fields'][0]['value'], 'discord Markdown escaped' );
		assert_same( 'a\*b\_c\[d\]\(e\)\`f\` \\\\', json_decode( Webhook_Payload::body( 'teams', Webhook_Payload::data( 'created', array_merge( $report, array( 'description' => 'a*b_c[d](e)`f` \\' ) ) ) ), true )['attachments'][0]['content']['body'][1]['facts'][0]['value'], 'teams Markdown escaped' );
		assert_same( $data['report']['admin_url'], $bodies['discord']['embeds'][0]['url'] );
		assert_same( 'message', $bodies['teams']['type'] );
		$card = $bodies['teams']['attachments'][0];
		assert_same( 'application/vnd.microsoft.card.adaptive', $card['contentType'] );
		assert_same( 'AdaptiveCard', $card['content']['type'] );
		assert_same( 'FactSet', $card['content']['body'][1]['type'] );
		assert_same( $data['report']['admin_url'], $card['content']['actions'][0]['url'] );

		$summary = Webhook_Payload::summary( 'status_changed', 12, 'Fixed' );
		assert_same( '12 reports: status → Fixed', json_decode( Webhook_Payload::body( 'slack', $summary ), true )['text'] );
		assert_true( str_contains( Webhook_Payload::body( 'slack', $summary ), '|Open the list>' ), 'slack summary links the list' );
		assert_same( Bug_Spotter\Report_Format::list_link(), json_decode( Webhook_Payload::body( 'discord', $summary ), true )['embeds'][0]['url'] );
		$card = json_decode( Webhook_Payload::body( 'teams', $summary ), true )['attachments'][0]['content'];
		assert_same( array( 1, 'Open the list' ), array( count( $card['body'] ), $card['actions'][0]['title'] ), 'teams summary: no facts, list link' );
		assert_same( '3 reports assigned to Ada', Webhook_Payload::summary( 'assigned', 3, 'Ada' )['summary']['text'] );

		$test = Webhook_Payload::data( 'test', null );
		assert_same( array( 'test', null ), array( $test['event'], $test['report'] ) );
		foreach ( Webhooks::FORMATS as $format ) {
			assert_true( str_contains( Webhook_Payload::body( $format, $test ), 'Test message from Bug Spotter' ) || 'generic' === $format, "$format test" );
		}
	}
);
