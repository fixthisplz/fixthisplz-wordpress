<?php

use FixThisPlz\Installer;
use FixThisPlz\Report_Submission;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Settings;

/**
 * Runs $fn( $settings, $ip, &$sent ) with the plugin enabled for guests and subscribers, a fresh client IP
 * (its own limiter bucket) and mails captured. The saved option is restored afterwards.
 */
function submission_env( callable $fn, array $over = array() ): void {
	with_settings_option(
		function () use ( $fn, $over ) {
			$s                               = Settings::get();
			$s['enabled']                    = true;
			$s['visibility']['guests']       = true;
			$s['visibility']['roles']        = array( 'administrator', 'subscriber' );
			$s['captcha']['provider']        = 'none';
			$s['dialog']['allow_screenshot'] = true;
			foreach ( $over as $block => $values ) {
				$s[ $block ] = array_merge( $s[ $block ], $values );
			}
			update_option( Settings::OPTION, $s );

			$sent = array();
			$mail = function ( $short, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			};
			add_filter( 'pre_wp_mail', $mail, 10, 2 );
			try {
				$fn( Settings::get(), '198.51.100.' . wp_rand( 1, 254 ) . '-' . wp_generate_password( 6, false ), $sent );
			} finally {
				remove_filter( 'pre_wp_mail', $mail, 10 );
			}
		}
	);
}

function submission_input( array $over = array() ): array {
	return array_merge(
		array(
			'description'    => "  It breaks\r\nhere\x07  ",
			'reporter_email' => '',
			'context'        => '{"url":"http://site.test/page/","capture":"dom"}',
			'captcha_token'  => '',
			'screenshot'     => null,
		),
		$over
	);
}

function submission_count(): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table() );
}

fixthisplz_test(
	'submission: guest_report_is_stored_cleaned_and_mailed',
	function () {
		submission_env(
			function ( array $settings, string $ip, array &$sent ) {
				$id = Report_Submission::submit( $settings, submission_input( array( 'reporter_email' => ' guest@example.org ' ) ), null, $ip );
				assert_true( is_int( $id ) && $id > 0, 'an id is returned' );
				$row = ( new Reports_Repository() )->find( $id );
				assert_same( "It breaks\nhere", $row['description'], 'control chars dropped, newlines normalised' );
				assert_same( 'guest@example.org', $row['reporter_email'] );
				assert_same( null, $row['user_id'] );
				assert_same( 'http://site.test/page/', $row['page_url'] );
				assert_true( ! isset( $row['context']['capture'] ), 'capture method dropped without a screenshot' );
				assert_same( 1, count( $sent ), 'one notification' );
			}
		);
	}
);

fixthisplz_test(
	'submission: logged_in_sender_is_stored_by_account_and_email_ignored',
	function () {
		submission_env(
			function ( array $settings, string $ip ) {
				$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
				$id     = Report_Submission::submit( $settings, submission_input( array( 'reporter_email' => 'not an email' ) ), $admins[0], $ip );
				assert_true( is_int( $id ), 'an invalid e-mail is not even read for a logged-in sender' );
				$row = ( new Reports_Repository() )->find( $id );
				assert_same( (int) $admins[0]->ID, (int) $row['user_id'] );
				assert_same( null, $row['reporter_email'] );
			}
		);
	}
);

fixthisplz_test(
	'submission: refuses_guests_when_visibility_excludes_them',
	function () {
		submission_env(
			function ( array $settings, string $ip ) {
				$before = submission_count();
				assert_wp_error( Report_Submission::submit( $settings, submission_input(), null, $ip ), 'fixthisplz_not_allowed' );
				assert_wp_error( Report_Submission::submit( $settings, submission_input(), new WP_User( 0 ), $ip ), 'fixthisplz_not_allowed' );
				assert_same( $before, submission_count() );
			},
			array( 'visibility' => array( 'guests' => false ) )
		);
	}
);

fixthisplz_test(
	'submission: validation_errors_return_422_and_store_nothing',
	function () {
		submission_env(
			function ( array $settings, string $ip ) {
				$before = submission_count();
				$cases  = array(
					'empty description'  => array( 'description' => "  \n " ),
					'too long'           => array( 'description' => str_repeat( 'a', 5001 ) ),
					'not a string'       => array( 'description' => array( 'x' ) ),
					'bad guest e-mail'   => array( 'reporter_email' => 'nope' ),
					'context too large'  => array( 'context' => '{"url":"http://site.test/","x":"' . str_repeat( 'a', 70000 ) . '"}' ),
				);
				foreach ( $cases as $label => $over ) {
					$result = Report_Submission::submit( $settings, submission_input( $over ), null, $ip );
					assert_wp_error( $result, 'fixthisplz_invalid' );
					assert_same( 422, $result->get_error_data()['status'], $label );
				}
				assert_same( $before, submission_count() );
			},
			array( 'protection' => array( 'per_hour' => 1000, 'per_day' => 10000 ) )
		);
	}
);

fixthisplz_test(
	'submission: send_limit_counts_every_attempt_of_the_same_ip',
	function () {
		submission_env(
			function ( array $settings, string $ip ) {
				assert_true( is_int( Report_Submission::submit( $settings, submission_input(), null, $ip ) ) );
				$second = Report_Submission::submit( $settings, submission_input(), null, $ip );
				assert_wp_error( $second, 'fixthisplz_rate_limited' );
				assert_same( 429, $second->get_error_data()['status'] );
				assert_true( is_int( Report_Submission::submit( $settings, submission_input(), null, $ip . '-other' ) ), 'another IP has its own bucket' );
			},
			array( 'protection' => array( 'per_hour' => 1 ) )
		);
	}
);

fixthisplz_test(
	'submission: screenshot_errors_and_disabled_screenshots',
	function () {
		submission_env(
			function ( array $settings, string $ip ) {
				$too_big = array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '' );
				assert_wp_error( Report_Submission::submit( $settings, submission_input( array( 'screenshot' => $too_big ) ), null, $ip ), 'fixthisplz_too_large' );
				$partial = array( 'error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '' );
				assert_wp_error( Report_Submission::submit( $settings, submission_input( array( 'screenshot' => $partial ) ), null, $ip ), 'fixthisplz_upload_failed' );

				// Screenshots turned off: an upload is ignored, the report is still saved without one.
				$settings['dialog']['allow_screenshot'] = false;
				$id = Report_Submission::submit( $settings, submission_input( array( 'screenshot' => $too_big ) ), null, $ip );
				assert_true( is_int( $id ) );
				assert_same( null, ( new Reports_Repository() )->find( $id )['screenshot'] );
			}
		);
	}
);
