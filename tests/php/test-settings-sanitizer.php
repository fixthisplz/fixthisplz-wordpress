<?php

use FixThisPlz\Settings;
use FixThisPlz\Settings_Sanitizer;

fixthisplz_test(
	'settings-sanitizer: merge_keeps_the_base_shape_and_replaces_lists',
	function () {
		$base = array(
			'map'  => array( 'a' => 1, 'b' => 2 ),
			'list' => array( 'x', 'y' ),
			'n'    => 3,
		);
		$out  = Settings_Sanitizer::merge(
			$base,
			array(
				'map'     => array( 'b' => 'B', 'zz' => 'dropped' ),
				'list'    => array( 'z' ),
				'unknown' => true,
			)
		);
		assert_same( array( 'map' => array( 'a' => 1, 'b' => 'B' ), 'list' => array( 'z' ), 'n' => 3 ), $out );
		assert_same( $base['map'], Settings_Sanitizer::merge( $base, array( 'map' => 'scalar' ) )['map'], 'a scalar never replaces a map' );
		assert_same( "one\ntwo", Settings_Sanitizer::merge( $base, array( 'list' => "one\ntwo" ) )['list'], 'a list may arrive as text' );
		assert_same( $base['list'], Settings_Sanitizer::merge( $base, array( 'list' => 5 ) )['list'] );
	}
);

fixthisplz_test(
	'settings-sanitizer: with_administrator_adds_the_role_once',
	function () {
		assert_same( array( 'editor', 'administrator' ), Settings_Sanitizer::with_administrator( array( 'editor' ) ) );
		assert_same( array( 'administrator', 'editor' ), Settings_Sanitizer::with_administrator( array( 'administrator', 'editor' ) ) );
	}
);

fixthisplz_test(
	'settings-sanitizer: every_section_survives_wrong_types_with_the_defaults_shape',
	function () {
		with_settings_option(
			function () {
				$d     = Settings::defaults();
				$input = array();
				foreach ( array_keys( $d ) as $section ) {
					$input[ $section ] = 'garbage';
				}
				$out = Settings_Sanitizer::sanitize( $input );
				assert_same( array_keys( $d ), array_keys( $out ) );
				foreach ( $d as $section => $values ) {
					if ( is_array( $values ) ) {
						assert_same( array_keys( $values ), array_keys( $out[ $section ] ), $section );
					}
				}
				assert_same( false, $out['enabled'], '"garbage" is not a true boolean string' );
				assert_same( array( 'administrator' ), $out['management']['roles'] );
			}
		);
	}
);

fixthisplz_test(
	'settings-sanitizer: section_values_are_validated_field_by_field',
	function () {
		with_settings_option(
			function () {
				$out = Settings_Sanitizer::sanitize(
					array(
						'visibility'    => array( 'excluded_pages' => array( '3', 'x', 3, -4 ), 'excluded_url_prefixes' => "/a\n\n/b\n/a" ),
						'dialog'        => array( 'privacy_url' => 'javascript:alert(1)', 'formats' => array( 'txt', 'txt', 'exe' ) ),
						'captcha'       => array( 'provider' => 'evil', 'v3_threshold' => 'x' ),
						'notifications' => array( 'recipients' => 'a@example.org, nope, b@example.org' ),
						'protection'    => array( 'proxy_header' => 'x-real-ip' ),
						'context'       => array( 'sensitive_selectors' => array( '#card', array( 'nested' ), '' ) ),
						'data'          => array( 'retention_months' => 500 ),
						'management'    => array( 'roles' => array( 'editor', 'ghost' ) ),
					)
				);
				assert_same( array( 3, 4 ), $out['visibility']['excluded_pages'] );
				assert_same( array( '/a', '/b' ), $out['visibility']['excluded_url_prefixes'] );
				assert_same( '', $out['dialog']['privacy_url'] );
				assert_same( array( 'txt' ), $out['dialog']['formats'] );
				assert_same( 'none', $out['captcha']['provider'] );
				assert_same( 0.5, $out['captcha']['v3_threshold'] );
				assert_same( 'a@example.org, b@example.org', $out['notifications']['recipients'] );
				assert_same( '', $out['protection']['proxy_header'] );
				assert_same( array( '#card' ), $out['context']['sensitive_selectors'] );
				assert_same( 120, $out['data']['retention_months'] );
				assert_same( array( 'editor', 'administrator' ), $out['management']['roles'] );
			}
		);
	}
);
