<?php

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Client_Ip;
use Bug_Spotter\Rate_Limiter;

/**
 * Runs $fn with every outgoing HTTP request answered by $responder (no real network).
 * $responder( $url, $args ) returns a pre_http_request response array or a WP_Error.
 */
function with_fake_http( callable $responder, callable $fn ): void {
	$calls  = array();
	$filter = static function ( $pre, $args, $url ) use ( $responder, &$calls ) {
		$calls[] = array( $url, $args );
		return $responder( $url, $args );
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try {
		$fn( $calls );
	} finally {
		remove_filter( 'pre_http_request', $filter, 10 );
	}
}

function fake_json( array $body, int $code = 200 ): array {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
	);
}

function captcha_settings( string $provider, array $extra = array() ): array {
	return array_merge(
		array(
			'provider'     => $provider,
			'site_key'     => 'site',
			'secret_key'   => 'secret',
			'v3_threshold' => 0.5,
		),
		$extra
	);
}

/** Unique per-test hash; removes both transients afterwards. */
function with_rate_hash( callable $fn ): void {
	$hash  = bin2hex( random_bytes( 16 ) );
	$short = substr( $hash, 0, 16 );
	try {
		$fn( $hash );
	} finally {
		delete_transient( 'bugspot_rl_h_' . $short );
		delete_transient( 'bugspot_rl_d_' . $short );
	}
}

bugspot_test(
	'turnstile_success',
	function () {
		with_fake_http(
			fn() => fake_json( array( 'success' => true ) ),
			function () {
				$provider = Factory::from_settings( captcha_settings( 'turnstile' ) );
				assert_same( 'turnstile', $provider->id() );
				assert_same( true, $provider->verify( 'tok', '203.0.113.9' ) );
			}
		);
	}
);

bugspot_test(
	'turnstile_sends_secret_token_and_ip_to_official_endpoint',
	function () {
		with_fake_http(
			fn() => fake_json( array( 'success' => true ) ),
			function () {
				$seen = null;
				add_filter(
					'pre_http_request',
					$spy = static function ( $pre, $args, $url ) use ( &$seen ) {
						$seen = array( $url, $args );
						return $pre;
					},
					5,
					3
				);
				try {
					Factory::from_settings( captcha_settings( 'turnstile' ) )->verify( 'tok', '203.0.113.9' );
				} finally {
					remove_filter( 'pre_http_request', $spy, 5 );
				}
				assert_same( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $seen[0] );
				assert_same(
					array(
						'secret'   => 'secret',
						'response' => 'tok',
						'remoteip' => '203.0.113.9',
					),
					$seen[1]['body']
				);
			}
		);
	}
);

bugspot_test(
	'turnstile_failure_is_403',
	function () {
		with_fake_http(
			fn() => fake_json( array( 'success' => false ) ),
			function () {
				$result = Factory::from_settings( captcha_settings( 'turnstile' ) )->verify( 'tok', '203.0.113.9' );
				assert_wp_error( $result, 'bugspot_captcha_failed' );
				assert_same( 403, $result->get_error_data()['status'] );
			}
		);
	}
);

bugspot_test(
	'provider_unreachable_is_503',
	function () {
		with_fake_http(
			fn() => new WP_Error( 'http_request_failed', 'timeout' ),
			function () {
				$result = Factory::from_settings( captcha_settings( 'hcaptcha' ) )->verify( 'tok', '203.0.113.9' );
				assert_wp_error( $result, 'bugspot_captcha_unavailable' );
				assert_same( 503, $result->get_error_data()['status'] );
			}
		);
	}
);

bugspot_test(
	'provider_non_200_or_unreadable_json_is_503',
	function () {
		with_fake_http(
			fn() => fake_json( array( 'success' => true ), 500 ),
			function () {
				assert_wp_error( Factory::from_settings( captcha_settings( 'recaptcha_v2' ) )->verify( 'tok', '203.0.113.9' ), 'bugspot_captcha_unavailable' );
			}
		);
		with_fake_http(
			fn() => array(
				'headers'  => array(),
				'body'     => '<html>not json',
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'cookies'  => array(),
			),
			function () {
				assert_wp_error( Factory::from_settings( captcha_settings( 'recaptcha_v2' ) )->verify( 'tok', '203.0.113.9' ), 'bugspot_captcha_unavailable' );
			}
		);
	}
);

bugspot_test(
	'empty_secret_on_real_provider_is_503_without_request',
	function () {
		with_fake_http(
			function () {
				throw new RuntimeException( 'no HTTP request expected' );
			},
			function () {
				$result = Factory::from_settings( captcha_settings( 'turnstile', array( 'secret_key' => '' ) ) )->verify( 'tok', '203.0.113.9' );
				assert_wp_error( $result, 'bugspot_captcha_unavailable' );
			}
		);
	}
);

bugspot_test(
	'empty_token_is_403',
	function () {
		with_fake_http(
			function () {
				throw new RuntimeException( 'no HTTP request expected' );
			},
			function () {
				foreach ( array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha' ) as $id ) {
					assert_wp_error( Factory::from_settings( captcha_settings( $id ) )->verify( '  ', '203.0.113.9' ), 'bugspot_captcha_failed' );
				}
			}
		);
	}
);

bugspot_test(
	'recaptcha_v3_accepts_good_score_and_action',
	function () {
		with_fake_http(
			fn() => fake_json(
				array(
					'success' => true,
					'score'   => 0.5,
					'action'  => 'bug_report',
				)
			),
			function () {
				assert_same( true, Factory::from_settings( captcha_settings( 'recaptcha_v3' ) )->verify( 'tok', '203.0.113.9' ) );
			}
		);
	}
);

bugspot_test(
	'recaptcha_v3_low_score_is_403',
	function () {
		with_fake_http(
			fn() => fake_json(
				array(
					'success' => true,
					'score'   => 0.3,
					'action'  => 'bug_report',
				)
			),
			function () {
				assert_wp_error( Factory::from_settings( captcha_settings( 'recaptcha_v3' ) )->verify( 'tok', '203.0.113.9' ), 'bugspot_captcha_failed' );
			}
		);
	}
);

bugspot_test(
	'recaptcha_v3_wrong_action_is_403',
	function () {
		with_fake_http(
			fn() => fake_json(
				array(
					'success' => true,
					'score'   => 0.9,
					'action'  => 'login',
				)
			),
			function () {
				assert_wp_error( Factory::from_settings( captcha_settings( 'recaptcha_v3' ) )->verify( 'tok', '203.0.113.9' ), 'bugspot_captcha_failed' );
			}
		);
	}
);

bugspot_test(
	'none_provider_always_passes_and_unknown_provider_is_none',
	function () {
		assert_same( true, Factory::from_settings( captcha_settings( 'none' ) )->verify( '', '' ) );
		$unknown = Factory::from_settings( captcha_settings( 'bogus' ) );
		assert_same( 'none', $unknown->id() );
		assert_same( '', $unknown->script_url() );
	}
);

bugspot_test(
	'script_urls_match_providers',
	function () {
		assert_same( 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', Factory::from_settings( captcha_settings( 'turnstile' ) )->script_url() );
		assert_same( 'https://www.google.com/recaptcha/api.js?render=explicit', Factory::from_settings( captcha_settings( 'recaptcha_v2' ) )->script_url() );
		assert_same( 'https://www.google.com/recaptcha/api.js?render=site', Factory::from_settings( captcha_settings( 'recaptcha_v3' ) )->script_url() );
		assert_same( 'https://js.hcaptcha.com/1/api.js?render=explicit', Factory::from_settings( captcha_settings( 'hcaptcha' ) )->script_url() );
	}
);

bugspot_test(
	'rate_limiter_blocks_sixth_attempt_in_hour',
	function () {
		with_rate_hash(
			function ( string $hash ) {
				$limiter = new Rate_Limiter( 5, 20 );
				for ( $i = 0; $i < 5; $i++ ) {
					assert_same( true, $limiter->attempt( $hash ), 'attempt ' . ( $i + 1 ) );
				}
				assert_same( false, $limiter->attempt( $hash ) );
			}
		);
	}
);

bugspot_test(
	'rate_limiter_daily_limit',
	function () {
		with_rate_hash(
			function ( string $hash ) {
				$limiter = new Rate_Limiter( 100, 3 );
				for ( $i = 0; $i < 3; $i++ ) {
					assert_same( true, $limiter->attempt( $hash ) );
				}
				assert_same( false, $limiter->attempt( $hash ) );
			}
		);
	}
);

bugspot_test(
	'rate_limiter_blocked_attempt_is_not_counted_and_isolates_hashes',
	function () {
		with_rate_hash(
			function ( string $hash ) {
				// Day window blocks first: the hour counter must not advance on blocked attempts.
				$limiter = new Rate_Limiter( 100, 1 );
				assert_same( true, $limiter->attempt( $hash ) );
				assert_same( false, $limiter->attempt( $hash ) );
				assert_same( false, $limiter->attempt( $hash ) );
				$hour = get_transient( 'bugspot_rl_h_' . substr( $hash, 0, 16 ) );
				assert_same( 1, $hour['count'] );
				assert_same( true, ( new Rate_Limiter( 100, 1 ) )->attempt( bin2hex( random_bytes( 16 ) ) ) );
			}
		);
	}
);

bugspot_test(
	'rate_limiter_window_is_not_extended_by_later_hits',
	function () {
		with_rate_hash(
			function ( string $hash ) {
				$limiter = new Rate_Limiter( 5, 20 );
				$limiter->attempt( $hash );
				$key   = 'bugspot_rl_h_' . substr( $hash, 0, 16 );
				$first = get_transient( $key )['reset_at'];
				$limiter->attempt( $hash );
				assert_same( $first, get_transient( $key )['reset_at'] );
			}
		);
	}
);

bugspot_test(
	'rate_limiter_bucket_groups_ipv6_by_64',
	function () {
		$bucket = Rate_Limiter::bucket( '2001:db8:1:2:aaaa::1' );
		assert_same( '2001:db8:1:2::/64', $bucket );
		assert_same( $bucket, Rate_Limiter::bucket( '2001:db8:1:2:ffff:eeee:dddd:cccc' ), 'same /64 shares the bucket' );
		assert_true( $bucket !== Rate_Limiter::bucket( '2001:db8:1:3::1' ), 'another /64 has its own bucket' );
		assert_same( '203.0.113.7', Rate_Limiter::bucket( '203.0.113.7' ), 'IPv4 is unchanged' );
		assert_same( '203.0.113.7', Rate_Limiter::bucket( '::ffff:203.0.113.7' ), 'IPv4-mapped IPv6 counts as its IPv4' );
		assert_true( Rate_Limiter::bucket( '::ffff:203.0.113.8' ) !== Rate_Limiter::bucket( '::ffff:203.0.113.7' ), 'mapped addresses are not pooled' );
	}
);

bugspot_test(
	'client_ip_xff_uses_rightmost_valid_entry',
	function () {
		$saved = array(
			'REMOTE_ADDR'          => $_SERVER['REMOTE_ADDR'] ?? null,
			'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
		);
		try {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

			$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.7';
			assert_same( '203.0.113.7', Client_Ip::get( 'x-forwarded-for' ), 'spoofed leftmost entry is ignored' );

			$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, garbage';
			assert_same( '203.0.113.7', Client_Ip::get( 'x-forwarded-for' ), 'invalid rightmost entries are skipped' );

			$_SERVER['HTTP_X_FORWARDED_FOR'] = 'garbage, also-bad';
			assert_same( '198.51.100.1', Client_Ip::get( 'x-forwarded-for' ), 'no valid entry falls back to REMOTE_ADDR' );
		} finally {
			foreach ( $saved as $k => $v ) {
				if ( null === $v ) {
					unset( $_SERVER[ $k ] );
				} else {
					$_SERVER[ $k ] = $v;
				}
			}
		}
	}
);

bugspot_test(
	'client_ip_uses_proxy_header_only_when_configured',
	function () {
		$saved = array(
			'REMOTE_ADDR'           => $_SERVER['REMOTE_ADDR'] ?? null,
			'HTTP_X_FORWARDED_FOR'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
			'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
		);
		try {
			$_SERVER['REMOTE_ADDR']           = '198.51.100.1';
			$_SERVER['HTTP_X_FORWARDED_FOR']  = 'garbage, 203.0.113.7, 10.0.0.1';
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';

			assert_same( '198.51.100.1', Client_Ip::get( '' ), 'header ignored when not configured' );
			assert_same( '10.0.0.1', Client_Ip::get( 'x-forwarded-for' ), 'rightmost valid entry' );
			assert_same( '2001:db8::1', Client_Ip::get( 'cf-connecting-ip' ) );

			$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
			assert_same( '198.51.100.1', Client_Ip::get( 'x-forwarded-for' ), 'invalid header falls back to REMOTE_ADDR' );
			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
			assert_same( '198.51.100.1', Client_Ip::get( 'x-forwarded-for' ), 'missing header falls back' );

			$_SERVER['REMOTE_ADDR'] = 'bogus';
			assert_same( '0.0.0.0', Client_Ip::get( '' ), 'nothing valid' );
			unset( $_SERVER['REMOTE_ADDR'] );
			assert_same( '0.0.0.0', Client_Ip::get( '' ) );
		} finally {
			foreach ( $saved as $k => $v ) {
				if ( null === $v ) {
					unset( $_SERVER[ $k ] );
				} else {
					$_SERVER[ $k ] = $v;
				}
			}
		}
	}
);
