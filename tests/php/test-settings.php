<?php

use Bug_Spotter\Settings;
use Bug_Spotter\Statuses;

/** Registers a test whose body runs with the real bugspot_settings option saved and restored. */
function settings_test( string $name, callable $fn ): void {
	bugspot_test( $name, static function () use ( $fn ) {
		with_settings_option( $fn );
	} );
}

settings_test(
	'settings: defaults are disabled and admin only',
	function () {
		$d = Settings::defaults();
		assert_same( false, $d['enabled'] );
		assert_same( array( 'administrator' ), $d['visibility']['roles'] );
		assert_same( false, $d['visibility']['guests'] );
		assert_same( 'none', $d['captcha']['provider'] );
		assert_same( 'bottom-right', $d['button']['position']['anchor'] );
	}
);

settings_test(
	'settings: get merges partial option on defaults',
	function () {
		update_option( Settings::OPTION, array( 'enabled' => true ) );
		$s = Settings::get();
		delete_option( Settings::OPTION );
		assert_same( true, $s['enabled'] );
		assert_same( 'circle', $s['button']['shape'] );
	}
);

settings_test(
	'settings: sanitize rejects css injection in colors',
	function () {
		delete_option( Settings::OPTION );
		$out = Settings::sanitize( array( 'button' => array( 'color_bg' => 'red;}body{display:none', 'color_fg' => '#abc', 'color_bg_hover' => 'rgba(0,0,0,0.5)' ) ) );
		assert_same( '#d63638', $out['button']['color_bg'] );
		assert_same( '#abc', $out['button']['color_fg'] );
		assert_same( 'rgba(0,0,0,0.5)', $out['button']['color_bg_hover'] );
	}
);

settings_test(
	'settings: sanitize clamps limits',
	function () {
		delete_option( Settings::OPTION );
		$out = Settings::sanitize( array( 'protection' => array( 'per_hour' => -5, 'per_day' => 99999999 ), 'captcha' => array( 'v3_threshold' => 7 ) ) );
		assert_same( 1, $out['protection']['per_hour'] );
		assert_same( 10000, $out['protection']['per_day'] );
		assert_same( 1.0, (float) $out['captcha']['v3_threshold'] );
	}
);

settings_test(
	'settings: sanitize drops unknown roles and keeps admin manager',
	function () {
		delete_option( Settings::OPTION );
		$out = Settings::sanitize(
			array(
				'visibility' => array( 'roles' => array( 'editor', 'nope' ) ),
				'management' => array( 'roles' => array( 'editor', 'ghost' ) ),
			)
		);
		assert_same( array( 'editor' ), $out['visibility']['roles'] );
		assert_same( array( 'editor', 'administrator' ), $out['management']['roles'] );
	}
);

settings_test(
	'settings: sanitize keeps existing secret when blank',
	function () {
		update_option( Settings::OPTION, array( 'captcha' => array( 'secret_key' => 'abc123' ) ) );
		$out = Settings::sanitize( array( 'captcha' => array( 'secret_key' => '', 'site_key' => 'pub' ) ) );
		$new = Settings::sanitize( array( 'captcha' => array( 'secret_key' => 'zzz' ) ) );
		delete_option( Settings::OPTION );
		assert_same( 'abc123', $out['captcha']['secret_key'] );
		assert_same( 'pub', $out['captcha']['site_key'] );
		assert_same( 'zzz', $new['captcha']['secret_key'] );
	}
);

settings_test(
	'settings: sanitize never returns empty formats',
	function () {
		delete_option( Settings::OPTION );
		$out = Settings::sanitize( array( 'dialog' => array( 'formats' => array( 'exe' ) ) ) );
		assert_same( array( 'md', 'json', 'txt' ), $out['dialog']['formats'] );
		$out = Settings::sanitize( array( 'dialog' => array( 'formats' => array( 'json' ) ) ) );
		assert_same( array( 'json' ), $out['dialog']['formats'] );
	}
);

settings_test(
	'settings: sanitize merges onto saved settings and treats posted 0 as false',
	function () {
		update_option( Settings::OPTION, array( 'enabled' => true, 'button' => array( 'shape' => 'pill' ) ) );
		$out = Settings::sanitize( array( 'visibility' => array( 'guests' => '0' ) ) );
		delete_option( Settings::OPTION );
		assert_same( true, $out['enabled'] );
		assert_same( 'pill', $out['button']['shape'] );
		assert_same( false, $out['visibility']['guests'] );
	}
);

settings_test(
	'settings: sanitize parses selectors, urls, emails and enums',
	function () {
		delete_option( Settings::OPTION );
		$out = Settings::sanitize(
			array(
				'context'       => array( 'sensitive_selectors' => "#a\n\n .b " ),
				'visibility'    => array( 'excluded_url_prefixes' => array( '/shop' ) ),
				'notifications' => array( 'recipients' => 'a@example.com, bad, b@example.com' ),
				'button'        => array( 'shape' => 'star', 'size' => 'l' ),
			)
		);
		assert_same( array( '#a', '.b' ), $out['context']['sensitive_selectors'] );
		assert_same( array( '/shop' ), $out['visibility']['excluded_url_prefixes'] );
		assert_same( 'a@example.com, b@example.com', $out['notifications']['recipients'] );
		assert_same( 'circle', $out['button']['shape'] );
		assert_same( 'l', $out['button']['size'] );
		assert_same( Settings::sanitize( array() ), Settings::sanitize( 'garbage' ) );
		assert_same( 'circle', Settings::sanitize( 'garbage' )['button']['shape'] );
	}
);

settings_test(
	'settings: text falls back to translated default',
	function () {
		delete_option( Settings::OPTION );
		assert_same( 'Report a bug', Settings::text( 'dialog.title' ) );
		$out = Settings::sanitize( array( 'dialog' => array( 'title' => ' Hi ' ) ) );
		update_option( Settings::OPTION, $out );
		$t = Settings::text( 'dialog.title' );
		delete_option( Settings::OPTION );
		assert_same( 'Hi', $t );
	}
);

settings_test(
	'statuses: order matches spec',
	function () {
		assert_same( array( 'new', 'seen', 'confirmed', 'in_progress', 'fixed', 'wont_fix', 'duplicate' ), array_keys( Statuses::all() ) );
		assert_same( array( 'low', 'normal', 'high', 'critical' ), array_keys( Statuses::priorities() ) );
		assert_true( Statuses::is_valid( 'seen' ) );
		assert_true( ! Statuses::is_valid( 'x' ) );
		assert_true( Statuses::is_valid_priority( 'high' ) );
		assert_true( ! Statuses::is_valid_priority( 'urgent' ) );
	}
);

settings_test(
	'settings: invalid numeric values fall back to the field default, not the minimum',
	function () {
		$out = Settings::sanitize(
			array(
				'button' => array(
					'shadow_custom' => array( 'y' => '', 'x' => 'abc', 'blur' => array( 1 ) ),
					'z_index'       => 'abc',
					'radius_px'     => '',
				),
			)
		);
		assert_same( 4, $out['button']['shadow_custom']['y'] );
		assert_same( 0, $out['button']['shadow_custom']['x'] );
		assert_same( 12, $out['button']['shadow_custom']['blur'] );
		assert_same( 99999, $out['button']['z_index'] );
		assert_same( 8, $out['button']['radius_px'] );
		$out = Settings::sanitize( array( 'button' => array( 'z_index' => -5, 'radius_px' => 999 ) ) );
		assert_same( 0, $out['button']['z_index'] );
		assert_same( 200, $out['button']['radius_px'] );
	}
);

settings_test(
	'settings: custom position handles missing and invalid units',
	function () {
		$out = Settings::sanitize( array( 'button' => array( 'position' => array( 'anchor' => 'custom', 'x' => '12', 'x_unit' => 'em', 'y' => 'zz' ) ) ) );
		$pos = $out['button']['position'];
		assert_same( 'custom', $pos['anchor'] );
		assert_same( 12, $pos['x'] );
		assert_same( 'px', $pos['x_unit'] );
		assert_same( 24, $pos['y'] );
		assert_same( 'px', $pos['y_unit'] );
	}
);

settings_test(
	'settings: array input on scalar fields is ignored and keeps the stored secret',
	function () {
		update_option( Settings::OPTION, array( 'captcha' => array( 'secret_key' => 'keepme' ) ) );
		$out = Settings::sanitize(
			array(
				'captcha' => array( 'secret_key' => array( 'x' ), 'site_key' => array( 'y' ) ),
				'dialog'  => array( 'instructions' => array( 'z' ), 'privacy_url' => array( 'u' ), 'title' => array( 't' ) ),
				'visibility' => array( 'roles' => array( array( 'administrator' ), 'editor' ) ),
			)
		);
		assert_same( 'keepme', $out['captcha']['secret_key'] );
		assert_same( '', $out['captcha']['site_key'] );
		assert_same( '', $out['dialog']['instructions'] );
		assert_same( '', $out['dialog']['privacy_url'] );
		assert_same( array( 'editor' ), $out['visibility']['roles'] );
	}
);
