<?php

use Bug_Spotter\Admin\Settings_Page;
use Bug_Spotter\Installer;
use Bug_Spotter\Settings;
use Bug_Spotter\Settings_Sanitizer;

/**
 * What a browser would post for the first <form> of $html: every named input/select/textarea, in document
 * order (checked boxes and radios only, selected options only), parsed like PHP parses $_POST.
 *
 * @return array<string,mixed>
 */
function settings_page_form_post( string $html ): array {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$form = $doc->getElementsByTagName( 'form' )->item( 0 );
	assert_true( null !== $form, 'no <form> rendered' );

	$pairs = array();
	$xpath = new DOMXPath( $doc );
	foreach ( $xpath->query( './/input|.//select|.//textarea', $form ) as $field ) {
		$name = $field->getAttribute( 'name' );
		if ( '' === $name || $field->hasAttribute( 'disabled' ) ) {
			continue;
		}
		$tag = strtolower( $field->nodeName );
		if ( 'input' === $tag ) {
			$type = strtolower( $field->getAttribute( 'type' ) );
			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $field->hasAttribute( 'checked' ) ) {
				continue;
			}
			if ( in_array( $type, array( 'submit', 'button' ), true ) ) {
				continue;
			}
			$pairs[] = array( $name, $field->hasAttribute( 'value' ) ? $field->getAttribute( 'value' ) : ( 'checkbox' === $type ? 'on' : '' ) );
		} elseif ( 'textarea' === $tag ) {
			$pairs[] = array( $name, $field->textContent );
		} else {
			foreach ( $xpath->query( './/option', $field ) as $option ) {
				if ( $option->hasAttribute( 'selected' ) ) {
					$pairs[] = array( $name, $option->getAttribute( 'value' ) );
				}
			}
		}
	}
	$query = implode( '&', array_map( static fn( $p ) => rawurlencode( $p[0] ) . '=' . rawurlencode( $p[1] ), $pairs ) );
	parse_str( $query, $post );
	return $post;
}

function settings_page_tab_html( string $tab ): string {
	ob_start();
	Settings_Page::render_tab_form( $tab, Settings::get() );
	return (string) ob_get_clean();
}

/** Runs $fn with the setting registered (sanitize callback active), the option isolated and an admin user. */
function settings_page_test( string $name, callable $fn ): void {
	bugspot_test(
		$name,
		static function () use ( $fn ) {
			$user   = get_current_user_id();
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			wp_set_current_user( (int) $admins[0] );
			try {
				// The original option is put back once the setting is unregistered: never through the sanitizer,
				// which would merge it on the test values (a blank secret key keeps the stored one).
				with_settings_option(
					static function () use ( $fn ) {
						Settings_Page::register_setting();
						try {
							$fn();
						} finally {
							unregister_setting( Settings_Page::GROUP, Settings::OPTION );
						}
					}
				);
			} finally {
				Installer::sync_capabilities( Settings::get()['management']['roles'] );
				wp_set_current_user( $user );
			}
		}
	);
}

settings_page_test(
	'settings-page: saving_one_tab_keeps_other_tabs',
	function () {
		update_option(
			Settings::OPTION,
			array(
				'enabled'    => true,
				'visibility' => array( 'guests' => true, 'roles' => array( 'administrator', 'editor' ) ),
				'captcha'    => array( 'provider' => 'turnstile', 'site_key' => 'site-123', 'secret_key' => 'secret-456' ),
				'button'     => array( 'shape' => 'pill', 'shadow' => 'strong' ),
			)
		);
		$before = Settings::get();

		foreach ( Settings_Page::tabs() as $tab => $label ) {
			$post   = settings_page_form_post( settings_page_tab_html( $tab ) );
			$fields = $post[ Settings::OPTION ] ?? array();
			assert_same( Settings_Page::GROUP, $post['option_page'] ?? null, "$tab: option_page" );
			update_option( Settings::OPTION, $fields );
			assert_same( $before, Settings::get(), "$tab: re-posting the rendered form must not change anything" );
		}

		// Only the button tab is posted, with a change: every other block is kept as is.
		$post                                       = settings_page_form_post( settings_page_tab_html( 'button' ) );
		$post[ Settings::OPTION ]['button']['shape'] = 'rounded';
		assert_same( array( 'button' ), array_keys( $post[ Settings::OPTION ] ), 'button tab posts only button fields' );
		update_option( Settings::OPTION, $post[ Settings::OPTION ] );
		$after = Settings::get();
		assert_same( 'rounded', $after['button']['shape'] );
		assert_same( 'strong', $after['button']['shadow'] );
		foreach ( array( 'enabled', 'visibility', 'captcha', 'dialog', 'notifications', 'protection', 'context', 'data', 'management' ) as $block ) {
			assert_same( $before[ $block ], $after[ $block ], "$block must be kept" );
		}
		assert_same( 'secret-456', $after['captcha']['secret_key'], 'blank secret field keeps the secret' );

		// Unchecking everything is expressible (hidden "0" and empty list markers).
		$post   = settings_page_form_post( settings_page_tab_html( 'visibility' ) );
		$fields = $post[ Settings::OPTION ];
		$fields['visibility']['roles'] = array( '' );
		$fields['visibility']['guests'] = '0';
		unset( $fields['enabled'] );
		$fields['enabled'] = '0';
		update_option( Settings::OPTION, $fields );
		assert_same( array(), Settings::get()['visibility']['roles'] );
		assert_same( false, Settings::get()['visibility']['guests'] );
		assert_same( false, Settings::get()['enabled'] );
	}
);

settings_page_test(
	'settings-page: unchecked_boxes_and_empty_lists_are_posted',
	function () {
		update_option( Settings::OPTION, array( 'management' => array( 'roles' => array( 'administrator' ), 'menu_badge' => false ) ) );
		$post = settings_page_form_post( settings_page_tab_html( 'management' ) );
		assert_same( '0', $post[ Settings::OPTION ]['management']['menu_badge'] ?? null, 'unchecked box posts "0"' );
		assert_true( isset( $post[ Settings::OPTION ]['management']['roles'] ), 'empty role list still posted' );

		$post = settings_page_form_post( settings_page_tab_html( 'visibility' ) );
		assert_true( isset( $post[ Settings::OPTION ]['visibility']['excluded_pages'] ), 'empty page list still posted' );
	}
);

settings_page_test(
	'settings-page: saving_management_roles_syncs_capability',
	function () {
		update_option( Settings::OPTION, array( 'management' => array( 'roles' => array( 'editor' ) ) ) );
		assert_same( array( 'editor', 'administrator' ), Settings::get()['management']['roles'] );
		assert_true( get_role( 'editor' )->has_cap( Installer::CAPABILITY ), 'editor granted on save' );
		assert_true( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ), 'administrator always kept' );

		update_option( Settings::OPTION, array( 'management' => array( 'roles' => array( '' ) ) ) );
		assert_true( ! get_role( 'editor' )->has_cap( Installer::CAPABILITY ), 'editor revoked on save' );
		assert_true( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ), 'administrator still kept' );
	}
);

settings_page_test(
	'settings-page: captcha_test_route_requires_manage_options',
	function () {
		rest_get_server();
		$editor = wp_insert_user(
			array(
				'user_login' => 'bugspot_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => wp_generate_password( 8, false ) . '@example.org',
				'role'       => 'editor',
			)
		);
		get_role( 'editor' )->add_cap( Installer::CAPABILITY ); // A bug manager is still not a settings admin.
		$admin = get_current_user_id();

		$request = static function ( string $route, array $body ): WP_REST_Response {
			$req = new WP_REST_Request( 'POST', '/bug-spotter/v1/' . $route );
			$req->set_body_params( $body );
			return rest_do_request( $req );
		};
		$body = array( 'provider' => 'turnstile', 'site_key' => 'x', 'secret_key' => 'y', 'token' => 'z' );

		wp_set_current_user( 0 );
		assert_same( 401, $request( 'captcha-test', $body )->get_status(), 'anonymous' );
		wp_set_current_user( $editor );
		assert_same( 403, $request( 'captcha-test', $body )->get_status(), 'bug manager without manage_options' );
		assert_same( 403, $request( 'button-preview', array( 'button' => array() ) )->get_status(), 'preview: bug manager' );

		wp_set_current_user( $admin );
		$none = $request( 'captcha-test', array( 'provider' => 'none', 'site_key' => 'x', 'token' => 'z' ) );
		assert_same( 422, $none->get_status(), 'provider none cannot be tested' );

		$preview = $request( 'button-preview', array( 'button' => array( 'shape' => 'pill', 'color_bg' => 'red;}body{x:y' ) ) );
		assert_same( 200, $preview->get_status(), 'preview for admin' );
		$css = $preview->get_data()['css'];
		assert_true( str_contains( $css, '#bugspot-button{' ), 'preview uses the front-end CSS' );
		assert_true( str_contains( $css, 'min-width:52px' ), 'pill shape applied' );
		assert_true( ! str_contains( $css, 'body{' ), 'no CSS injection' );
		$expected = Bug_Spotter\Frontend\Button_Style::css( Settings_Sanitizer::sanitize( array( 'button' => array( 'shape' => 'pill', 'color_bg' => 'red;}body{x:y' ) ) )['button'] );
		assert_same( $expected, $css, 'preview CSS is exactly the front-end CSS' );
	}
);

settings_page_test(
	'settings-page: captcha_tab_never_prints_the_secret',
	function () {
		update_option( Settings::OPTION, array( 'captcha' => array( 'provider' => 'hcaptcha', 'site_key' => 'pub-key', 'secret_key' => 'very-secret-value' ) ) );
		$html = settings_page_tab_html( 'captcha' );
		assert_true( ! str_contains( $html, 'very-secret-value' ), 'secret leaked into the page' );
		assert_true( str_contains( $html, 'pub-key' ), 'site key shown' );
		$form_end = strpos( $html, '</form>' );
		$widget   = strpos( $html, 'bugspot-captcha-widget' );
		assert_true( false !== $widget && $widget > $form_end, 'provider widget container is outside the form' );
	}
);

settings_page_test(
	'settings-page: warning_shown_when_guests_without_captcha',
	function () {
		$saved_get = $_GET;
		try {
			$notice = static function (): string {
				ob_start();
				Settings_Page::guest_warning();
				return (string) ob_get_clean();
			};
			update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => true ), 'captcha' => array( 'provider' => 'none' ) ) );

			$_GET['page'] = 'bug-spotter-settings';
			assert_true( str_contains( $notice(), 'notice-warning' ), 'settings screen' );
			$_GET['page'] = 'bug-spotter';
			assert_true( str_contains( $notice(), 'notice-warning' ), 'bugs screen' );
			$_GET['page'] = 'some-other-plugin';
			assert_same( '', $notice(), 'other screens untouched' );

			$_GET['page'] = 'bug-spotter';
			update_option( Settings::OPTION, array( 'captcha' => array( 'provider' => 'turnstile', 'site_key' => 'a', 'secret_key' => 'b' ) ) );
			assert_same( '', $notice(), 'anti-bot configured' );
			update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => false ), 'captcha' => array( 'provider' => 'none' ) ) );
			assert_same( '', $notice(), 'guests not allowed' );
			update_option( Settings::OPTION, array( 'enabled' => false, 'visibility' => array( 'guests' => true ), 'captcha' => array( 'provider' => 'none' ) ) );
			assert_same( '', $notice(), 'button disabled' );
		} finally {
			$_GET = $saved_get;
		}
	}
);

settings_page_test(
	'settings-page: captcha_test_reuses_saved_secret_only_for_saved_provider',
	function () {
		rest_get_server();
		update_option( Settings::OPTION, array( 'captcha' => array( 'provider' => 'turnstile', 'site_key' => 'site', 'secret_key' => 'saved-secret' ) ) );
		$sent  = array();
		$fake  = static function ( $pre, $args, $url ) use ( &$sent ) {
			$sent[] = array( $url, $args['body']['secret'] ?? null );
			return array(
				'headers'  => array(),
				'body'     => '{"success":true}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$test = static function ( array $body ): WP_REST_Response {
				$req = new WP_REST_Request( 'POST', '/bug-spotter/v1/captcha-test' );
				$req->set_body_params( $body );
				return rest_do_request( $req );
			};

			$other = $test( array( 'provider' => 'hcaptcha', 'site_key' => 'k', 'secret_key' => '', 'token' => 't' ) );
			assert_same( 422, $other->get_status(), 'blank secret for another provider' );
			assert_same( array(), $sent, 'nothing sent to the provider' );

			$same = $test( array( 'provider' => 'turnstile', 'site_key' => 'k', 'secret_key' => '', 'token' => 't' ) );
			assert_same( 200, $same->get_status(), 'blank secret for the saved provider' );
			assert_same( 'saved-secret', $sent[0][1] ?? null, 'saved secret used' );
			assert_true( str_contains( (string) $sent[0][0], 'challenges.cloudflare.com' ), 'turnstile endpoint' );

			$typed = $test( array( 'provider' => 'hcaptcha', 'site_key' => 'k', 'secret_key' => 'typed', 'token' => 't' ) );
			assert_same( 200, $typed->get_status(), 'typed secret' );
			assert_same( 'typed', $sent[1][1] ?? null, 'typed secret wins' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}
	}
);

settings_page_test(
	'settings-page: dialog_tab_offers_the_capture_method_and_masking',
	function () {
		$html = settings_page_tab_html( 'dialog' );
		assert_true( 1 === preg_match( '/<input type="radio"[^>]*name="bugspot_settings\[dialog\]\[capture_method\]"[^>]*value="tab"[^>]* checked/', $html ), 'tab radio checked by default' );
		assert_true( 1 === preg_match( '/<input type="radio"[^>]*name="bugspot_settings\[dialog\]\[capture_method\]"[^>]*value="dom"(?![^>]* checked)/', $html ), 'dom radio present, unchecked' );
		assert_true( str_contains( $html, 'Real capture of the tab (recommended)' ) && str_contains( $html, 'Page reconstruction — no permission prompt' ), 'both choices labelled' );
		assert_true( str_contains( $html, 'On phones and in browsers without screen sharing, the reconstruction is always used.' ), 'permanent mobile notice' );
		assert_true( str_contains( $html, 'Hide typed fields and sensitive areas in the screenshot' ), 'mask checkbox' );
		assert_true( str_contains( $html, 'masking works only when the visitor shares this tab' ), 'mask help text' );
		assert_true( str_contains( $html, 'data-bugspot-show-if="bugspot_settings[dialog][allow_screenshot]"' ), 'shown only when screenshots are allowed' );
		assert_true( ! str_contains( $html, 'mask_capture][]' ) && 1 === preg_match( '/type="hidden" name="bugspot_settings\[dialog\]\[mask_capture\]" value="0"/', $html ), 'hidden 0 marker' );

		update_option( Settings::OPTION, array( 'dialog' => array( 'capture_method' => 'dom', 'mask_capture' => true ) ) );
		$post = settings_page_form_post( settings_page_tab_html( 'dialog' ) );
		assert_same( 'dom', $post[ Settings::OPTION ]['dialog']['capture_method'] );
		assert_same( '1', $post[ Settings::OPTION ]['dialog']['mask_capture'] );
	}
);

settings_page_test(
	'settings-page: button_tab_position_sentences_keep_each_control_with_its_label',
	function () {
		$html = settings_page_tab_html( 'button' );
		foreach ( array( 'position', 'position_mobile' ) as $key ) {
			foreach ( array( 'x', 'y' ) as $axis ) {
				foreach ( array( $axis, $axis . '_unit', 'edge_' . $axis ) as $field ) {
					$name = 'bugspot_settings[button][' . $key . '][' . $field . ']';
					$id   = Bug_Spotter\Admin\Settings_Fields::id( array( 'button', $key, $field ) );
					assert_true( 1 === preg_match( '/<(input|select)[^>]*id="' . preg_quote( $id, '/' ) . '"[^>]*name="' . preg_quote( $name, '/' ) . '"/', $html ), "$name control with id $id" );
					assert_true( str_contains( $html, '<label class="screen-reader-text" for="' . $id . '">' ), "$name has a label for $id" );
				}
			}
		}
	}
);
