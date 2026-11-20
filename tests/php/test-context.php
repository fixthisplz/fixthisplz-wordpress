<?php

use Bug_Spotter\Context_Validator;

function bugspot_ctx( array $over = array() ): array {
	return array_merge( array( 'url' => 'https://site.test/page?a=1' ), $over );
}

bugspot_test(
	'context_ignores_unknown_keys',
	function () {
		$out = Context_Validator::validate( bugspot_ctx( array( 'cookies' => 'sid=1', 'title' => 'T' ) ) );
		assert_true( is_array( $out ) );
		assert_true( ! array_key_exists( 'cookies', $out ) );
		assert_same( 'T', $out['title'] );
		assert_same( null, $out['element'] );
	}
);

bugspot_test(
	'context_requires_http_url',
	function () {
		$err = Context_Validator::validate( array( 'url' => 'javascript:alert(1)' ) );
		assert_wp_error( $err, 'bugspot_invalid_context' );
		assert_same( 422, $err->get_error_data()['status'] );
		assert_wp_error( Context_Validator::validate( array( 'title' => 'x' ) ), 'bugspot_invalid_context' );
		assert_wp_error( Context_Validator::validate( array( 'url' => array( 'https://a.test' ) ) ), 'bugspot_invalid_context' );
		assert_wp_error( Context_Validator::validate( 'not json' ), 'bugspot_invalid_context' );
	}
);

bugspot_test(
	'context_keeps_last_20_console_entries',
	function () {
		$entries = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$entries[] = array( 'level' => 'error', 'message' => 'm' . $i, 'at' => '10:00' );
		}
		$out = Context_Validator::validate( bugspot_ctx( array( 'console' => $entries ) ) );
		assert_same( 20, count( $out['console'] ) );
		assert_same( 'm6', $out['console'][0]['message'] );
		assert_same( 'm25', $out['console'][19]['message'] );
	}
);

bugspot_test(
	'context_drops_console_entry_with_invalid_level',
	function () {
		$out = Context_Validator::validate( bugspot_ctx( array( 'console' => array( array( 'level' => 'log', 'message' => 'x' ), array( 'level' => 'WARN', 'message' => 'y' ) ) ) ) );
		assert_same( 1, count( $out['console'] ) );
		assert_same( 'warn', $out['console'][0]['level'] );
	}
);

bugspot_test(
	'context_drops_network_entry_with_invalid_status',
	function () {
		$out = Context_Validator::validate(
			bugspot_ctx(
				array(
					'network' => array(
						array( 'method' => 'get', 'url' => 'https://a.test/x?k=v', 'status' => 700 ),
						array( 'method' => 'post!', 'url' => 'https://a.test/y?k=v', 'status' => '500' ),
						array( 'method' => 'GET', 'url' => 'https://a.test/z', 'status' => 'abc' ),
						array( 'method' => 'GET', 'url' => 'https://a.test/w', 'status' => 0 ),
					),
				)
			)
		);
		assert_same( 2, count( $out['network'] ) );
		assert_same( 'POST', $out['network'][0]['method'] );
		assert_same( 500, $out['network'][0]['status'] );
		assert_same( "https://a.test/y?k=\u{2026}", $out['network'][0]['url'] );
		assert_same( 0, $out['network'][1]['status'] );
	}
);

bugspot_test(
	'context_accepts_json_string',
	function () {
		$out = Context_Validator::validate( wp_json_encode( bugspot_ctx( array( 'locale' => 'fr_FR' ) ) ) );
		assert_true( is_array( $out ) );
		assert_same( 'fr_FR', $out['locale'] );
		assert_same( "https://site.test/page?a=\u{2026}", $out['url'] );
	}
);

bugspot_test(
	'context_redacts_and_bounds_texts_and_element',
	function () {
		$out = Context_Validator::validate(
			bugspot_ctx(
				array(
					'title'   => 'Mail jean@ex.test ' . str_repeat( 'x ', 200 ),
					'wp_page' => array( 'type' => 'page', 'id' => 12, 'pad' => str_repeat( 'z ', 100 ) ),
					'element' => array(
						'selector' => '#a',
						'tag'      => 'button',
						'text'     => str_repeat( 'é', 500 ),
						'rect'     => array( 'x' => '10.7', 'y' => 5, 'width' => 'bad', 'height' => 1e30 ),
					),
				)
			)
		);
		assert_true( false === strpos( $out['title'], 'jean@' ) );
		assert_true( mb_strlen( $out['title'] ) >= 254 && mb_strlen( $out['title'] ) <= 255, 'title bound (trim may drop a trailing space)' );
		assert_true( mb_strlen( $out['wp_page'] ) <= 64 && mb_strlen( $out['wp_page'] ) >= 63 );
		assert_same( 300, mb_strlen( $out['element']['text'] ) );
		assert_same( array( 'x' => 10, 'y' => 5, 'width' => 0, 'height' => 1000000 ), $out['element']['rect'] );
	}
);

bugspot_test(
	'context_casts_non_strings_without_warnings',
	function () {
		$out = Context_Validator::validate( bugspot_ctx( array( 'title' => array( 'x' ), 'locale' => 5, 'element' => 'str', 'console' => 'str', 'network' => null ) ) );
		assert_same( '', $out['title'] );
		assert_same( '5', $out['locale'] );
		assert_same( null, $out['element'] );
		assert_same( array(), $out['console'] );
		assert_same( array(), $out['network'] );
	}
);

bugspot_test(
	'context_keeps_a_valid_capture_and_drops_an_invalid_one',
	function () {
		$out = Context_Validator::validate( bugspot_ctx( array( 'capture' => array( 'method' => 'tab', 'masked' => true, 'extra' => 'x' ) ) ) );
		assert_same( array( 'method' => 'tab', 'masked' => true ), $out['capture'] );
		$out = Context_Validator::validate( bugspot_ctx( array( 'capture' => array( 'method' => 'dom', 'masked' => false ) ) ) );
		assert_same( array( 'method' => 'dom', 'masked' => false ), $out['capture'] );
		assert_true( ! array_key_exists( 'capture', Context_Validator::validate( bugspot_ctx() ) ), 'absent when not sent' );
		foreach ( array(
			array( 'method' => 'window', 'masked' => false ),
			array( 'method' => 'tab', 'masked' => 'yes' ),
			array( 'method' => 'tab' ),
			array( 'masked' => true ),
			'tab',
			array( 'method' => array( 'tab' ), 'masked' => false ),
		) as $bad ) {
			$out = Context_Validator::validate( bugspot_ctx( array( 'capture' => $bad ) ) );
			assert_true( is_array( $out ), 'never a 422 for capture' );
			assert_true( ! array_key_exists( 'capture', $out ), 'invalid capture dropped' );
		}
	}
);
