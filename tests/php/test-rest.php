<?php

use FixThisPlz\Installer;
use FixThisPlz\Rest\Reports_Controller;
use FixThisPlz\Reports_Repository;

/** Settings with the plugin enabled and the anti-bot off, merged with $over (top-level blocks replaced key by key). */
function rest_enable( array $over = array() ): void {
	$s            = FixThisPlz\Settings::get();
	$s['enabled'] = true;
	foreach ( $over as $block => $values ) {
		$s[ $block ] = is_array( $values ) ? array_merge( (array) ( $s[ $block ] ?? array() ), $values ) : $values;
	}
	update_option( 'fixthisplz_settings', $s );
}

function rest_post( array $params = array(), array $files = array(), array $headers = array() ): WP_REST_Response {
	rest_get_server(); // Registers the routes (rest_api_init).
	$req = new WP_REST_Request( 'POST', '/fixthisplz/v1/reports' );
	$req->set_body_params(
		array_merge(
			array(
				'description'   => 'The button does nothing',
				'context'       => '{"url":"http://site.test/page/"}',
				'website'       => '',
				'captcha_token' => '',
			),
			$params
		)
	);
	if ( $files ) {
		$req->set_file_params( $files );
	}
	foreach ( $headers as $k => $v ) {
		$req->set_header( $k, $v );
	}
	return rest_do_request( $req );
}

function rest_upload( string $bytes, int $error = UPLOAD_ERR_OK ): array {
	$tmp = wp_tempnam( 'fixthisplz-rest' );
	file_put_contents( $tmp, $bytes );
	return array(
		'screenshot' => array(
			'name'     => 'shot.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => $error,
			'size'     => strlen( $bytes ),
		),
	);
}

function rest_png(): string {
	$im = imagecreatetruecolor( 40, 40 );
	ob_start();
	imagepng( $im );
	return (string) ob_get_clean();
}

function rest_user( string $role ): int {
	return wp_insert_user(
		array(
			'user_login' => 'fixthisplz_' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password(),
			'user_email' => wp_generate_password( 8, false ) . '@example.org',
			'role'       => $role,
		)
	);
}

/** Clears the limiter counters of the CLI client IP (also from the in-memory object cache). */
function rest_reset_limiter(): void {
	$short = substr( FixThisPlz\Secret::hmac( FixThisPlz\Rate_Limiter::bucket( FixThisPlz\Client_Ip::get( '' ) ) ), 0, 16 );
	delete_transient( 'fixthisplz_rl_h_' . $short );
	delete_transient( 'fixthisplz_rl_d_' . $short );
	// Rows created inside a rolled-back test transaction can linger in the in-memory options cache.
	foreach ( array( 'fixthisplz_rl_h_', 'fixthisplz_rl_d_' ) as $prefix ) {
		wp_cache_delete( '_transient_' . $prefix . $short, 'options' );
		wp_cache_delete( '_transient_timeout_' . $prefix . $short, 'options' );
	}
}

/** Runs $fn with a clean limiter, user and mail capture; restores everything afterwards. */
function rest_env( callable $fn ): void {
	with_settings_option(
		function () use ( $fn ) {
			rest_reset_limiter();
			$sent = array();
			add_filter(
				'pre_wp_mail',
				function ( $short, $atts ) use ( &$sent ) {
					$sent[] = $atts;
					return true;
				},
				10,
				2
			);
			$prev = get_current_user_id();
			try {
				rest_enable( array( 'visibility' => array( 'guests' => true, 'roles' => array( 'administrator', 'editor', 'subscriber' ) ) ) );
				$fn( $sent );
			} finally {
				remove_all_filters( 'pre_wp_mail' );
				wp_set_current_user( $prev );
				unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp']->query_vars['rest_route'], $GLOBALS['wp_rest_auth_cookie'] );
				$_SERVER['REQUEST_METHOD'] = 'GET';
				rest_reset_limiter();
			}
		}
	);
}

fixthisplz_test(
	'rest: create_saves_report_and_returns_number',
	function () {
		rest_env(
			function () {
				wp_set_current_user( 0 );
				$res  = rest_post( array( 'reporter_email' => 'visitor@example.org' ) );
				$data = $res->get_data();
				assert_same( 201, $res->get_status() );
				assert_same( array( 'id', 'number' ), array_keys( $data ), 'response must expose only id and number' );
				assert_true( $data['id'] > 0 );
				assert_same( '#' . $data['id'], $data['number'] );
				$row = ( new Reports_Repository() )->find( $data['id'] );
				assert_same( 'The button does nothing', $row['description'] );
				assert_same( 'http://site.test/page/', $row['page_url'] );
				assert_same( 'visitor@example.org', $row['reporter_email'] );
				assert_same( null, $row['user_id'] );
				assert_same( 64, strlen( (string) $row['ip_hash'] ) );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_when_disabled_is_404',
	function () {
		rest_env(
			function () {
				rest_enable( array( 'enabled' => false ) );
				$res = rest_post();
				assert_same( 404, $res->get_status() );
				assert_same( 'fixthisplz_disabled', $res->as_error()->get_error_code() );
			}
		);
	}
);

fixthisplz_test(
	'rest: honeypot_returns_201_without_writing',
	function () {
		rest_env(
			function ( &$sent ) {
				global $wpdb;
				$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table() );
				$res    = rest_post( array( 'website' => 'http://spam.example' ) );
				assert_same( 201, $res->get_status() );
				assert_same(
					array(
						'id'     => 0,
						'number' => '',
					),
					$res->get_data()
				);
				assert_same( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table() ) );
				assert_same( 0, count( $sent ), 'no mail for a honeypot hit' );
				assert_true( false === get_transient( 'fixthisplz_rl_h_' . substr( FixThisPlz\Secret::hmac( FixThisPlz\Rate_Limiter::bucket( FixThisPlz\Client_Ip::get( '' ) ) ), 0, 16 ) ), 'honeypot must not be counted' );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_rate_limited_is_429',
	function () {
		rest_env(
			function () {
				rest_enable( array( 'protection' => array( 'per_hour' => 1 ) ) );
				assert_same( 201, rest_post()->get_status() );
				$res = rest_post();
				assert_same( 429, $res->get_status() );
				assert_same( 'fixthisplz_rate_limited', $res->as_error()->get_error_code() );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_with_failed_captcha_is_403',
	function () {
		rest_env(
			function () {
				// Turnstile with a token the provider cannot accept (no keys configured => fail-closed, never a pass).
				rest_enable( array( 'captcha' => array( 'provider' => 'turnstile', 'site_key' => 'x', 'secret_key' => 'y' ) ) );
				add_filter( 'pre_http_request', fn() => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"success":false}', 'headers' => array() ) );
				$res = rest_post( array( 'captcha_token' => 'bad' ) );
				remove_all_filters( 'pre_http_request' );
				assert_same( 403, $res->get_status() );
				assert_same( 'fixthisplz_captcha_failed', $res->as_error()->get_error_code() );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_rejects_empty_description_422',
	function () {
		rest_env(
			function () {
				assert_same( 422, rest_post( array( 'description' => "   \n" ) )->get_status() );
				assert_same( 422, rest_post( array( 'description' => str_repeat( 'é', 5001 ) ) )->get_status() );
				assert_same( 201, rest_post( array( 'description' => str_repeat( 'é', 5000 ) ) )->get_status() );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_rejects_bad_email_and_context_422',
	function () {
		rest_env(
			function () {
				wp_set_current_user( 0 );
				assert_same( 422, rest_post( array( 'reporter_email' => 'not-an-email' ) )->get_status() );
				assert_same( 422, rest_post( array( 'context' => '{"url":"ftp://x"}' ) )->get_status() );
				$res = rest_post( array( 'context' => '{"url":"http://a.example/","pad":"' . str_repeat( 'a', 70000 ) . '"}' ) );
				assert_same( 422, $res->get_status() );
				assert_same( 'fixthisplz_invalid', $res->as_error()->get_error_code() );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_ignores_reporter_email_for_logged_in_user',
	function () {
		rest_env(
			function () {
				$uid = rest_user( 'editor' );
				wp_set_current_user( $uid );
				$res = rest_post( array( 'reporter_email' => 'not-an-email' ) );
				assert_same( 201, $res->get_status() );
				$row = ( new Reports_Repository() )->find( $res->get_data()['id'] );
				assert_same( $uid, $row['user_id'] );
				assert_same( null, $row['reporter_email'] );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_stores_screenshot_and_rejects_bad_uploads',
	function () {
		rest_env(
			function () {
				$res = rest_post( array(), rest_upload( rest_png() ) );
				assert_same( 201, $res->get_status() );
				$row = ( new Reports_Repository() )->find( $res->get_data()['id'] );
				assert_true( is_file( FixThisPlz\Screenshot_Store::path( (string) $row['screenshot'] ) ) );
				FixThisPlz\Screenshot_Store::delete( (string) $row['screenshot'] );

				$bad = rest_post( array(), rest_upload( 'definitely not an image' ) );
				assert_same( 422, $bad->get_status() );
				assert_same( 'fixthisplz_bad_type', $bad->as_error()->get_error_code() );

				// Screenshots disabled: the file is ignored, the report is still saved.
				rest_enable( array( 'dialog' => array( 'allow_screenshot' => false ) ) );
				$off = rest_post( array(), rest_upload( rest_png() ) );
				assert_same( 201, $off->get_status() );
				assert_same( null, ( new Reports_Repository() )->find( $off->get_data()['id'] )['screenshot'] );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_keeps_the_capture_method_only_when_a_screenshot_is_stored',
	function () {
		rest_env(
			function () {
				$context = '{"url":"http://site.test/page/","capture":{"method":"tab","masked":true}}';
				$with    = rest_post( array( 'context' => $context ), rest_upload( rest_png() ) );
				$row     = ( new Reports_Repository() )->find( $with->get_data()['id'] );
				assert_same( 'tab', $row['context']['capture']['method'] );
				FixThisPlz\Screenshot_Store::delete( (string) $row['screenshot'] );

				$without = rest_post( array( 'context' => $context ) );
				assert_same( 201, $without->get_status() );
				$row = ( new Reports_Repository() )->find( $without->get_data()['id'] );
				assert_true( ! isset( $row['context']['capture'] ), 'capture dropped without a screenshot' );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_rejects_oversized_screenshot_with_413',
	function () {
		rest_env(
			function () {
				$res = rest_post( array(), rest_upload( '', UPLOAD_ERR_INI_SIZE ) );
				assert_same( 413, $res->get_status() );
				assert_same( 413, rest_post( array(), rest_upload( '', UPLOAD_ERR_FORM_SIZE ) )->get_status() );
				$big = rest_post( array(), rest_upload( str_repeat( 'a', FixThisPlz\Screenshot_Store::MAX_BYTES + 1 ) ) );
				assert_same( 413, $big->get_status() );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_with_stale_nonce_saves_without_user',
	function () {
		rest_env(
			function () {
				$uid = rest_user( 'editor' );
				wp_set_current_user( $uid );
				$GLOBALS['wp_rest_auth_cookie']                = true; // As set by core's auth_cookie_valid hook on a real cookie-authenticated request.
				$_SERVER['HTTP_X_WP_NONCE']                    = 'deadbeef';
				$GLOBALS['wp']->query_vars['rest_route']       = '/fixthisplz/v1/reports';
				$_SERVER['REQUEST_METHOD']                     = 'POST';

				// Observed: core itself rejects a stale nonce with 403 before any route runs.
				$core = rest_cookie_check_errors( null );
				assert_wp_error( $core, 'rest_cookie_invalid_nonce' );

				// Our filter, hooked after core, lets this one route through as anonymous.
				wp_set_current_user( $uid );
				$result = apply_filters( 'rest_authentication_errors', null );
				assert_true( ! is_wp_error( $result ), 'stale nonce must not block the public submit route' );
				assert_same( 0, get_current_user_id() );

				$res = rest_post( array(), array(), array( 'X-WP-Nonce' => 'deadbeef' ) );
				assert_same( 201, $res->get_status() );
				assert_same( null, ( new Reports_Repository() )->find( $res->get_data()['id'] )['user_id'] );
			}
		);
	}
);

fixthisplz_test(
	'rest: stale_nonce_still_rejected_on_other_routes',
	function () {
		rest_env(
			function () {
				$uid = rest_user( 'administrator' );
				wp_set_current_user( $uid );
				$GLOBALS['wp_rest_auth_cookie']          = true;
				$_SERVER['HTTP_X_WP_NONCE']              = 'deadbeef';
				$_SERVER['REQUEST_METHOD']               = 'PATCH';
				$GLOBALS['wp']->query_vars['rest_route'] = '/fixthisplz/v1/reports/1';
				assert_wp_error( apply_filters( 'rest_authentication_errors', null ), 'rest_cookie_invalid_nonce' );
				// Same route as the public one but another method: still strict.
				$_SERVER['REQUEST_METHOD']               = 'GET';
				$GLOBALS['wp']->query_vars['rest_route'] = '/fixthisplz/v1/reports';
				wp_set_current_user( $uid );
				assert_wp_error( apply_filters( 'rest_authentication_errors', null ), 'rest_cookie_invalid_nonce' );
				$_SERVER['REQUEST_METHOD'] = 'POST';
				unset( $_SERVER['HTTP_X_WP_NONCE'] );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_with_valid_nonce_saves_user',
	function () {
		rest_env(
			function () {
				$uid = rest_user( 'editor' );
				wp_set_current_user( $uid );
				$GLOBALS['wp_rest_auth_cookie'] = true;
				$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );
				$core                       = rest_cookie_check_errors( null );
				assert_true( ! is_wp_error( $core ), 'valid nonce accepted by core' );
				$res = rest_post( array(), array(), array( 'X-WP-Nonce' => $_SERVER['HTTP_X_WP_NONCE'] ) );
				assert_same( 201, $res->get_status() );
				assert_same( $uid, ( new Reports_Repository() )->find( $res->get_data()['id'] )['user_id'] );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_sends_mail_to_admin_email_by_default',
	function () {
		rest_env(
			function ( &$sent ) {
				$res = rest_post();
				assert_same( 201, $res->get_status() );
				assert_same( 1, count( $sent ) );
				assert_same( array( get_option( 'admin_email' ) ), (array) $sent[0]['to'] );
				$id = $res->get_data()['id'];
				assert_true( false !== strpos( $sent[0]['subject'], '#' . $id ), 'subject has the number' );
				assert_true( false !== strpos( $sent[0]['message'], admin_url( 'admin.php?page=fixthisplz&report=' . $id ) ) || false !== strpos( $sent[0]['message'], esc_url( admin_url( 'admin.php?page=fixthisplz&#038;report=' . $id ) ) ), 'link in body' );
			}
		);
	}
);

fixthisplz_test(
	'rest: mailer_uses_recipients_escapes_html_and_skips_when_disabled',
	function () {
		rest_env(
			function ( &$sent ) {
				rest_enable( array( 'notifications' => array( 'recipients' => 'a@example.org, b@example.org' ) ) );
				$res = rest_post( array( 'description' => '<script>alert(1)</script> ' . str_repeat( 'x', 400 ) ) );
				assert_same( array( 'a@example.org', 'b@example.org' ), (array) $sent[0]['to'] );
				assert_true( false === strpos( $sent[0]['message'], '<script>' ), 'description is escaped in the HTML part' );
				assert_true( false === strpos( $sent[0]['message'], str_repeat( 'x', 301 ) ), 'excerpt is cut at 300 characters' );

				$sent = array();
				rest_enable( array( 'notifications' => array( 'enabled' => false ) ) );
				rest_post();
				assert_same( 0, count( $sent ) );
				assert_same( false, FixThisPlz\Mailer::notify( $res->get_data()['id'] ) );
			}
		);
	}
);

fixthisplz_test(
	'rest: patch_requires_capability',
	function () {
		rest_env(
			function () {
				wp_set_current_user( 0 );
				$id = ( new Reports_Repository() )->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => array() ) );
				rest_get_server();
				wp_set_current_user( rest_user( 'subscriber' ) );
				$req = new WP_REST_Request( 'PATCH', '/fixthisplz/v1/reports/' . $id );
				$req->set_body_params( array( 'status' => 'fixed' ) );
				assert_same( 403, rest_do_request( $req )->get_status() );
				wp_set_current_user( 0 );
				assert_same( 401, rest_do_request( $req )->get_status() );
			}
		);
	}
);

fixthisplz_test(
	'rest: patch_changes_status',
	function () {
		rest_env(
			function () {
				$id = ( new Reports_Repository() )->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => array(), 'reporter_email' => 'p@example.org' ) );
				rest_get_server();
				$admin = rest_user( 'administrator' );
				wp_set_current_user( $admin );
				$patch = function ( int $id, array $body ): WP_REST_Response {
					$req = new WP_REST_Request( 'PATCH', '/fixthisplz/v1/reports/' . $id );
					$req->set_body_params( $body );
					return rest_do_request( $req );
				};
				$reads = 0;
				$count = static function ( string $sql ) use ( &$reads ): string {
					$reads += str_starts_with( $sql, 'SELECT * FROM `' . Installer::table() . '` WHERE id' ) ? 1 : 0;
					return $sql;
				};
				add_filter( 'query', $count );
				$res = $patch( $id, array( 'status' => 'fixed', 'priority' => 'high' ) );
				remove_filter( 'query', $count );
				assert_same( 1, $reads, 'the report is read once per update' );
				assert_same( 200, $res->get_status() );
				assert_same(
					array(
						'id'       => $id,
						'status'   => 'fixed',
						'priority' => 'high',
					),
					$res->get_data()
				);
				assert_same( 'fixed', ( new Reports_Repository() )->find( $id )['status'] );
				assert_same( 422, $patch( $id, array( 'status' => 'bogus' ) )->get_status() );
				assert_same( 422, $patch( $id, array( 'priority' => 'urgent' ) )->get_status() );
				assert_same( 422, $patch( $id, array() )->get_status() );
				global $wpdb;
				$wpdb->update( Installer::table(), array( 'status' => 'retired' ), array( 'id' => $id ) );
				assert_same( 200, $patch( $id, array( 'status' => 'retired', 'priority' => 'low' ) )->get_status(), 'a stored unknown status may be kept, as in every edit' );
				assert_same( 'low', ( new Reports_Repository() )->find( $id )['priority'] );
				assert_same( 404, $patch( 999999, array( 'status' => 'seen' ) )->get_status() );
			}
		);
	}
);

fixthisplz_test(
	'rest: screenshot_requires_capability',
	function () {
		rest_env(
			function () {
				$tmp  = wp_tempnam( 'fixthisplz-rest' );
				file_put_contents( $tmp, rest_png() );
				$name = FixThisPlz\Screenshot_Store::store( $tmp );
				unlink( $tmp );
				assert_true( is_string( $name ) );
				$id = ( new Reports_Repository() )->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => array(), 'screenshot' => $name ) );
				try {
					$server = rest_get_server();
					$get    = fn() => rest_do_request( new WP_REST_Request( 'GET', '/fixthisplz/v1/reports/' . $id . '/screenshot' ) );

					wp_set_current_user( rest_user( 'subscriber' ) );
					assert_same( 403, $get()->get_status() );
					wp_set_current_user( 0 );
					assert_same( 401, $get()->get_status() );

					wp_set_current_user( rest_user( 'administrator' ) );
					$res = $get();
					assert_same( 200, $res->get_status() );
					$h = $res->get_headers();
					assert_same( 'image/jpeg', $h['Content-Type'] );
					assert_same( 'private, no-store', $h['Cache-Control'] );
					assert_same( 'nosniff', $h['X-Content-Type-Options'] );
					ob_start();
					$served = apply_filters( 'rest_pre_serve_request', false, $res, new WP_REST_Request( 'GET', '/' ), $server );
					$body   = (string) ob_get_clean();
					assert_same( true, $served );
					assert_same( 'image/jpeg', getimagesizefromstring( $body )['mime'] );

					$none = ( new Reports_Repository() )->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => array() ) );
					$res  = rest_do_request( new WP_REST_Request( 'GET', '/fixthisplz/v1/reports/' . $none . '/screenshot' ) );
					assert_same( 404, $res->get_status() );
				} finally {
					FixThisPlz\Screenshot_Store::delete( $name );
				}
			}
		);
	}
);

fixthisplz_test(
	'rest: oversized_body_dropped_by_php_gives_413_and_is_not_counted',
	function () {
		rest_env(
			function () {
				$had = array_key_exists( 'CONTENT_LENGTH', $_SERVER ) ? $_SERVER['CONTENT_LENGTH'] : null;
				try {
					$_SERVER['CONTENT_LENGTH'] = (string) ( wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) + 1 );
					rest_get_server();
					$req = new WP_REST_Request( 'POST', '/fixthisplz/v1/reports' );
					$res = rest_do_request( $req );
					assert_same( 413, $res->get_status() );
					assert_same( 'fixthisplz_too_large', $res->as_error()->get_error_code() );
					$short = substr( FixThisPlz\Secret::hmac( FixThisPlz\Rate_Limiter::bucket( FixThisPlz\Client_Ip::get( '' ) ) ), 0, 16 );
					assert_true( false === get_transient( 'fixthisplz_rl_h_' . $short ), 'not counted by the limiter' );
					// A normal body with a big CONTENT_LENGTH is not mistaken for a dropped one.
					assert_same( 201, rest_post()->get_status() );
				} finally {
					if ( null === $had ) {
						unset( $_SERVER['CONTENT_LENGTH'] );
					} else {
						$_SERVER['CONTENT_LENGTH'] = $had;
					}
				}
			}
		);
	}
);

fixthisplz_test(
	'rest: honeypot_array_value_is_a_hit',
	function () {
		rest_env(
			function ( &$sent ) {
				global $wpdb;
				$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table() );
				$res    = rest_post( array( 'website' => array( 'x' ) ) );
				assert_same( 201, $res->get_status() );
				assert_same( 0, $res->get_data()['id'] );
				assert_same( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table() ) );
				assert_same( 0, count( $sent ) );
			}
		);
	}
);

fixthisplz_test(
	'rest: array_context_is_bounded_like_string_context',
	function () {
		rest_env(
			function () {
				$big = rest_post( array( 'context' => array( 'url' => 'http://a.example/', 'pad' => str_repeat( 'a', 70000 ) ) ) );
				assert_same( 422, $big->get_status() );
				assert_same( 'fixthisplz_invalid', $big->as_error()->get_error_code() );
				assert_same( 201, rest_post( array( 'context' => array( 'url' => 'http://a.example/' ) ) )->get_status() );
			}
		);
	}
);

fixthisplz_test(
	'rest: throwing_mail_hook_does_not_turn_saved_report_into_500',
	function () {
		rest_env(
			function () {
				add_filter(
					'pre_wp_mail',
					function () {
						throw new RuntimeException( 'mail boom' );
					}
				);
				$res = rest_post();
				assert_same( 201, $res->get_status() );
				assert_true( null !== ( new Reports_Repository() )->find( $res->get_data()['id'] ), 'report stored' );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_enforces_visibility_roles_and_guests',
	function () {
		rest_env(
			function () {
				global $wpdb;
				$table = $wpdb->prefix . 'fixthisplz_reports';
				$count = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB

				rest_enable( array( 'visibility' => array( 'guests' => false, 'roles' => array( 'administrator' ) ) ) );
				wp_set_current_user( 0 );
				$before = $count();
				$res    = rest_post();
				assert_same( 403, $res->get_status() );
				assert_same( 'fixthisplz_not_allowed', $res->as_error()->get_error_code() );
				assert_same( $before, $count(), 'nothing written for a refused guest' );

				wp_set_current_user( rest_user( 'subscriber' ) );
				$res = rest_post();
				assert_same( 403, $res->get_status() );
				assert_same( 'fixthisplz_not_allowed', $res->as_error()->get_error_code() );
				assert_same( $before, $count(), 'nothing written for a role not checked' );

				wp_set_current_user( rest_user( 'administrator' ) );
				assert_same( 201, rest_post()->get_status(), 'checked role is allowed' );

				rest_enable( array( 'visibility' => array( 'guests' => true ) ) );
				wp_set_current_user( 0 );
				assert_same( 201, rest_post()->get_status(), 'guests allowed when checked' );
			}
		);
	}
);

fixthisplz_test(
	'rest: create_keeps_description_as_typed',
	function () {
		rest_env(
			function () {
				wp_set_current_user( 0 );
				$typed = 'The <h1> title overlaps <nav>. https://ex.test/a%20b 3<5 & ok';
				$res   = rest_post( array( 'description' => $typed ) );
				assert_same( 201, $res->get_status() );
				assert_same( $typed, ( new Reports_Repository() )->find( $res->get_data()['id'] )['description'] );

				$res = rest_post( array( 'description' => "line1\r\nline2\x01\x07 end" ) );
				assert_same( 201, $res->get_status() );
				assert_same( "line1\nline2 end", ( new Reports_Repository() )->find( $res->get_data()['id'] )['description'] );

				assert_same( 422, rest_post( array( 'description' => "\x01\x02" ) )->get_status(), 'only control chars is empty' );
			}
		);
	}
);

fixthisplz_test(
	'rest: mailer_states_the_capture_method_only_with_a_screenshot',
	function () {
		rest_env(
			function ( &$sent ) {
				$repo    = new Reports_Repository();
				$context = array( 'capture' => array( 'method' => 'tab', 'masked' => true ) );
				$with    = $repo->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => $context, 'screenshot' => str_repeat( 'b', 32 ) . '.jpg' ) );
				$without = $repo->insert( array( 'description' => 'd', 'page_url' => 'http://x/', 'context' => $context ) );
				FixThisPlz\Mailer::notify( $with );
				FixThisPlz\Mailer::notify( $without );
				assert_true( false !== strpos( $sent[0]['message'], '<strong>Screenshot:</strong> Real capture (masked)' ), 'HTML line' );
				assert_true( false === strpos( $sent[1]['message'], 'Real capture' ), 'no line without screenshot' );
				$repo->delete( $with );
				$repo->delete( $without );
			}
		);
	}
);
