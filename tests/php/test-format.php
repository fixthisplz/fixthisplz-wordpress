<?php

use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;

bugspot_test(
	'format: excerpt_cuts_at_length_with_ellipsis',
	function () {
		assert_same( 'short', Report_Format::excerpt( 'short', 5 ) );
		assert_same( 'éàü…', Report_Format::excerpt( 'éàüöï', 3 ) );
	}
);

bugspot_test(
	'format: reporter_label_is_user_name_or_visitor_with_email',
	function () {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		assert_same( $admins[0]->display_name, Report_Format::reporter_label( array( 'user_id' => $admins[0]->ID, 'reporter_email' => null ) ) );
		assert_same( 'Visitor (v@example.com)', Report_Format::reporter_label( array( 'user_id' => null, 'reporter_email' => 'v@example.com' ) ) );
		assert_same( 'Visitor', Report_Format::reporter_label( array( 'user_id' => 999999999, 'reporter_email' => null ) ) );
	}
);

bugspot_test(
	'format: timestamp_reads_stored_utc_and_rejects_garbage',
	function () {
		assert_same( 0, Reports_Repository::timestamp( '1970-01-01 00:00:00' ) );
		assert_same( null, Reports_Repository::timestamp( null ) );
		assert_same( null, Reports_Repository::timestamp( '' ) );
		assert_same( null, Reports_Repository::timestamp( 'not a date' ) );
		assert_same( '', Report_Format::date( null ) );
	}
);

bugspot_test(
	'settings: validate_button_completes_partial_input_with_defaults',
	function () {
		$d   = Settings::defaults()['button'];
		$out = Settings::validate_button( array( 'color_bg' => 'red;}', 'size' => 'l' ) );
		assert_same( array_keys( $d ), array_keys( $out ) );
		assert_same( $d['color_bg'], $out['color_bg'] );
		assert_same( 'l', $out['size'] );
		assert_same( $d, Settings::validate_button( 'not an array' ) );
	}
);

bugspot_test(
	'installer: activate_keeps_administrator_capability_even_if_the_option_omits_it',
	function () {
		// A stored option that never went through sanitize(): management roles without the administrator.
		$seed = static fn() => array( 'management' => array( 'roles' => array( 'editor' ) ) );
		add_filter( 'pre_option_' . Settings::OPTION, $seed );
		$editor_had = get_role( 'editor' )->has_cap( 'bugspot_manage' );
		try {
			get_role( 'administrator' )->remove_cap( 'bugspot_manage' );
			Bug_Spotter\Installer::activate();
			assert_true( get_role( 'administrator' )->has_cap( 'bugspot_manage' ), 'administrator kept' );
			assert_true( get_role( 'editor' )->has_cap( 'bugspot_manage' ), 'editor granted' );
		} finally {
			remove_filter( 'pre_option_' . Settings::OPTION, $seed );
			Bug_Spotter\Installer::sync_capabilities( Settings::get()['management']['roles'] );
			get_role( 'administrator' )->add_cap( 'bugspot_manage' );
			if ( ! $editor_had ) {
				get_role( 'editor' )->remove_cap( 'bugspot_manage' );
			}
		}
	}
);
