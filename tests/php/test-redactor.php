<?php

use Bug_Spotter\Redactor;

bugspot_test(
	'redactor_masks_email_and_tokens',
	function () {
		$out = Redactor::text( 'contact jean@ex.test Bearer abc.def eyJa.b.c ' . str_repeat( 'a', 40 ), 5000 );
		assert_true( false === strpos( $out, 'jean@' ), 'email leaked' );
		assert_true( false === strpos( $out, 'abc.def' ), 'bearer leaked' );
		assert_true( false === strpos( $out, 'eyJa' ), 'jwt leaked' );
		assert_true( false === strpos( $out, 'aaaa' ), 'long run leaked' );
		assert_true( false !== strpos( $out, '[email]' ) && false !== strpos( $out, '[jeton]' ) );
		assert_true( false !== strpos( $out, 'Bearer [jeton]' ) );
	}
);

bugspot_test(
	'redactor_keeps_short_alphanumeric_runs',
	function () {
		assert_same( 'abc ' . str_repeat( 'b', 31 ), Redactor::text( 'abc ' . str_repeat( 'b', 31 ), 5000 ) );
	}
);

bugspot_test(
	'redactor_url_strips_query_values_fragment_credentials',
	function () {
		assert_same( "https://site.test/p?token=\u{2026}&x=\u{2026}", Redactor::url( 'https://u:p@site.test/p?token=abc&x=1#frag', 2048 ) );
	}
);

bugspot_test(
	'redactor_url_masks_email_and_token_in_path',
	function () {
		$out = Redactor::url( 'https://site.test/u/jean@ex.test/' . str_repeat( 'k', 40 ), 2048 );
		assert_same( 'https://site.test/u/[email]/[jeton]', $out );
	}
);

bugspot_test(
	'redactor_url_returns_empty_when_unparsable',
	function () {
		assert_same( '', Redactor::url( 'not a url', 2048 ) );
		assert_same( '', Redactor::url( 'javascript:alert(1)', 2048 ) );
		assert_same( '', Redactor::url( 'http:///nohost', 2048 ) );
	}
);

bugspot_test(
	'redactor_truncates_multibyte_without_breaking',
	function () {
		$out = Redactor::text( str_repeat( 'é😀', 3000 ), 5000 );
		assert_same( 5000, mb_strlen( $out ) );
		assert_true( mb_check_encoding( $out, 'UTF-8' ) );
	}
);

bugspot_test(
	'redactor_replaces_invalid_utf8',
	function () {
		$out = Redactor::text( "bad \xC3\x28 bytes", 100 );
		assert_true( mb_check_encoding( $out, 'UTF-8' ) );
		assert_true( 0 === strpos( $out, 'bad ' ) );
	}
);

bugspot_test(
	'redactor_truncates_after_redaction_so_no_token_fragment_survives',
	function () {
		$out = Redactor::text( 'x ' . str_repeat( 'a', 40 ), 10 );
		assert_true( false === strpos( $out, 'aaaa' ), 'token fragment survived truncation' );
	}
);

bugspot_test(
	'redactor_url_masks_percent_encoded_email_in_path',
	function () {
		assert_same( 'https://h.test/path/[email]', Redactor::url( 'https://h.test/path/jean%40ex.test', 2048 ) );
	}
);

bugspot_test(
	'redactor_url_masks_percent_encoded_email_in_query_name',
	function () {
		$out = Redactor::url( 'https://h.test/p?jean%40ex.test=1&ok=2', 2048 );
		assert_same( "https://h.test/p?[email]=\u{2026}&ok=\u{2026}", $out );
	}
);

bugspot_test(
	'redactor_hostile_email_like_input_is_not_lost',
	function () {
		$out = Redactor::text( 'Title ' . 'a@' . str_repeat( 'a.', 25000 ), 255 );
		assert_true( '' !== $out && 0 === strpos( $out, 'Title ' ), 'input was dropped' );
		assert_true( mb_strlen( $out ) <= 255 );
		$url = Redactor::url( 'https://h.test/a@' . str_repeat( 'a.', 25000 ), 2048 );
		assert_true( 0 === strpos( $url, 'https://h.test/' ), 'url was dropped' );
	}
);
