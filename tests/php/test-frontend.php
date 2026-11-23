<?php

use FixThisPlz\Frontend\Assets;
use FixThisPlz\Frontend\Button_Style;
use FixThisPlz\Frontend\Visibility;
use FixThisPlz\Settings;

function fe_settings( array $visibility = array(), bool $enabled = true ): array {
	$s                = Settings::defaults();
	$s['enabled']     = $enabled;
	$s['visibility']  = array_merge( $s['visibility'], $visibility );
	return $s;
}

function fe_user( array $roles ): WP_User {
	$u        = new WP_User();
	$u->ID    = 99999;
	$u->roles = $roles;
	return $u;
}

fixthisplz_test(
	'visibility_hidden_when_disabled',
	function () {
		$s = fe_settings( array( 'guests' => true ), false );
		assert_same( false, Visibility::should_load( $s, null, '/', 0, false ) );
	}
);

fixthisplz_test(
	'visibility_guest_only_when_guests_checked',
	function () {
		assert_same( false, Visibility::should_load( fe_settings(), null, '/', 0, false ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'guests' => true ) ), null, '/', 0, false ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'guests' => true ) ), new WP_User( 0 ), '/', 0, false ), 'user id 0 is a guest' );
	}
);

fixthisplz_test(
	'visibility_role_match',
	function () {
		$s = fe_settings( array( 'roles' => array( 'editor' ) ) );
		assert_same( true, Visibility::should_load( $s, fe_user( array( 'editor' ) ), '/', 0, false ) );
		assert_same( false, Visibility::should_load( $s, fe_user( array( 'subscriber' ) ), '/', 0, false ) );
		assert_same( false, Visibility::should_load( $s, null, '/', 0, false ) );
	}
);

fixthisplz_test(
	'visibility_excluded_page_and_prefix',
	function () {
		$s = fe_settings( array( 'guests' => true, 'excluded_pages' => array( 42 ), 'excluded_url_prefixes' => array( 'checkout', '/private/' ) ) );
		assert_same( false, Visibility::should_load( $s, null, '/x', 42, false ), 'excluded post id' );
		assert_same( true, Visibility::should_load( $s, null, '/x', 43, false ) );
		assert_same( false, Visibility::should_load( $s, null, '/checkout/step-2', 0, false ), 'prefix without leading slash' );
		assert_same( false, Visibility::should_load( $s, null, '/private/area', 0, false ), 'prefix with slashes' );
		assert_same( true, Visibility::should_load( $s, null, '/public', 0, false ) );
	}
);

fixthisplz_test(
	'visibility_admin_only_when_show_in_admin',
	function () {
		$admin = fe_user( array( 'administrator' ) );
		assert_same( false, Visibility::should_load( fe_settings(), $admin, '/wp-admin/', 0, true ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'show_in_admin' => true ) ), $admin, '/wp-admin/', 0, true ) );
		assert_same( false, Visibility::should_load( fe_settings( array( 'show_in_admin' => true, 'roles' => array( 'editor' ) ) ), $admin, '/wp-admin/', 0, true ), 'role rules still apply' );
	}
);

fixthisplz_test(
	'button_css_never_contains_raw_user_input',
	function () {
		$b                           = Settings::defaults()['button'];
		$b['color_bg']               = '};body{x';
		$b['color_fg']               = 'red;}body{y';
		$b['color_bg_hover']         = '1;}';
		$b['shadow']                 = 'custom';
		$b['shadow_custom']          = array( 'x' => '1;}', 'y' => 'a', 'blur' => '9999', 'spread' => '0', 'color' => 'x;}body{z' );
		$b['radius_mode']            = 'custom';
		$b['radius_px']              = '5;}body{q';
		$b['z_index']                = '1;}body{w';
		$b['size']                   = 'huge;}';
		$b['shape']                  = 'x}body{';
		$b['position']['x']          = '1;}';
		$b['position']['x_unit']     = 'em;}body{';
		$b['position']['anchor']     = 'custom';
		$css                         = Button_Style::css( $b );
		assert_true( '' !== $css );
		assert_true( false === strpos( $css, 'body{' ), 'no injected selector' );
		assert_true( 0 === preg_match( '/;\s*;/', $css ), 'no empty declarations from injected input' );
		// Every rule must be on #fixthisplz-button, and braces must balance.
		assert_same( substr_count( $css, '{' ), substr_count( $css, '}' ), 'balanced braces' );
		preg_match_all( '/([^{}]+)\{/', $css, $m );
		foreach ( $m[1] as $sel ) {
			$sel = trim( $sel );
			assert_true( 0 === strpos( $sel, '#fixthisplz-button' ) || 0 === strpos( $sel, '@media (max-width: 782px)' ), 'unexpected selector: ' . $sel );
		}
		assert_true( false === strpos( $css, 'huge' ) && false === strpos( $css, 'em;' ) );
	}
);

fixthisplz_test(
	'button_css_sizes_shapes_shadows',
	function () {
		$d = Settings::defaults()['button'];
		assert_true( false !== strpos( Button_Style::css( array_merge( $d, array( 'size' => 's' ) ) ), '40px' ) );
		assert_true( false !== strpos( Button_Style::css( array_merge( $d, array( 'size' => 'l' ) ) ), '64px' ) );
		assert_true( false !== strpos( Button_Style::css( $d ), 'border-radius:50%' ) );
		assert_true( false !== strpos( Button_Style::css( array_merge( $d, array( 'shape' => 'rounded' ) ) ), 'border-radius:12px' ) );
		assert_true( false !== strpos( Button_Style::css( array_merge( $d, array( 'shape' => 'pill' ) ) ), 'border-radius:999px' ) );
		assert_true( false !== strpos( Button_Style::css( $d ), 'box-shadow:0 4px 12px rgba(0,0,0,.25)' ) );
		assert_true( false !== strpos( Button_Style::css( array_merge( $d, array( 'shadow' => 'none' ) ) ), 'box-shadow:none' ) );
		assert_true( false !== strpos( Button_Style::css( $d ), 'bottom:24px' ) );
		assert_true( false !== strpos( Button_Style::css( $d ), 'z-index:99999' ) );
		assert_true( false !== strpos( Button_Style::css( $d ), '#fixthisplz-button:hover' ) );
	}
);

fixthisplz_test(
	'button_css_custom_position_uses_units',
	function () {
		$b             = Settings::defaults()['button'];
		$b['position'] = array( 'anchor' => 'custom', 'edge_x' => 'left', 'edge_y' => 'top', 'x' => 10, 'x_unit' => '%', 'y' => 30, 'y_unit' => 'px' );
		$css           = Button_Style::css( $b );
		assert_true( false !== strpos( $css, 'left:10%' ), $css );
		assert_true( false !== strpos( $css, 'top:30px' ), $css );
		$b['position']['anchor'] = 'middle-right';
		$css                     = Button_Style::css( $b );
		assert_true( false !== strpos( $css, 'top:50%' ) && false !== strpos( $css, 'translateY(-50%)' ) );
	}
);

fixthisplz_test(
	'button_css_mobile_hide_media_query',
	function () {
		$b = Settings::defaults()['button'];
		assert_true( false === strpos( Button_Style::css( $b ), 'display:none' ) );
		$b['mobile_position_enabled']         = true;
		$b['position_mobile']['anchor']       = 'top-left';
		$css                                  = Button_Style::css( $b );
		assert_true( 1 === preg_match( '/@media \(max-width: 782px\)\{#fixthisplz-button\{[^}]*top:24px[^}]*left:24px/', $css ), $css );
		assert_true( false !== strpos( Button_Style::css( $b, true ), '@media (max-width: 782px){#fixthisplz-button{display:none}}' ) );
	}
);

/** Runs $fn with a clean script/style registry (what wp_enqueue_scripts would see), restored afterwards. */
function fe_with_clean_assets( callable $fn ): void {
	global $wp_scripts, $wp_styles;
	$old_scripts = $wp_scripts;
	$old_styles  = $wp_styles;
	$wp_scripts  = new WP_Scripts();
	$wp_styles   = new WP_Styles();
	try {
		$fn();
	} finally {
		$wp_scripts = $old_scripts;
		$wp_styles  = $old_styles;
	}
}

fixthisplz_test(
	'enqueue_outputs_nothing_when_not_visible',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => false ) ) );
						Assets::enqueue();
						$loaded = wp_script_is( 'fixthisplz-loader', 'enqueued' );
						wp_set_current_user( $prev->ID );
						assert_same( false, $loaded );
					}
				);
			}
		);
	}
);

fixthisplz_test(
	'enqueue_matches_excluded_prefixes_on_the_request_path',
	function () {
		with_settings_option(
			function () {
				$saved = $_SERVER['REQUEST_URI'] ?? null;
				$prev  = wp_get_current_user();
				wp_set_current_user( 0 );
				update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => true, 'excluded_url_prefixes' => array( '/caf%C3%A9' ) ) ) );
				$loads = static function ( string $uri ): bool {
					$_SERVER['REQUEST_URI'] = $uri;
					$loaded                 = false;
					fe_with_clean_assets(
						static function () use ( &$loaded ): void {
							Assets::enqueue();
							$loaded = wp_script_is( 'fixthisplz-loader', 'enqueued' );
						}
					);
					return $loaded;
				};
				$results = array( $loads( '/caf%C3%A9/menu?x=1&y=<b>' ), $loads( '/shop/caf%C3%A9' ), $loads( '/' ) );
				$_SERVER['REQUEST_URI'] = $saved;
				wp_set_current_user( $prev->ID );
				assert_same( array( false, true, true ), $results );
			}
		);
	}
);

fixthisplz_test(
	'enqueue_adds_loader_config_and_css_for_guests',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						update_option(
							Settings::OPTION,
							array(
								'enabled'    => true,
								'visibility' => array( 'guests' => true ),
								'captcha'    => array( 'provider' => 'turnstile', 'site_key' => 'pub', 'secret_key' => 'TOPSECRETVALUE' ),
							)
						);
						Assets::enqueue();
						global $wp_scripts;
						$before = $wp_scripts->get_data( 'fixthisplz-loader', 'before' );
						$ok     = wp_script_is( 'fixthisplz-loader', 'enqueued' ) && wp_style_is( 'fixthisplz-front', 'enqueued' );
						$defer  = $wp_scripts->get_data( 'fixthisplz-loader', 'strategy' );
						wp_set_current_user( $prev->ID );
						assert_true( $ok, 'loader and css enqueued' );
						assert_same( 'defer', $defer );
						$js = implode( "\n", (array) $before );
						assert_true( false !== strpos( $js, 'window.fixThisPlzConfig = ' ) );
						assert_true( false === strpos( $js, 'TOPSECRETVALUE' ) && false === strpos( $js, 'secret' ), 'no secret in config' );
						$json   = trim( substr( $js, strlen( 'window.fixThisPlzConfig = ' ) ), "; \n" );
						$config = json_decode( $json, true );
						assert_true( is_array( $config ), 'config is valid JSON' );
						foreach ( array( 'restUrl', 'nonce', 'moduleUrl', 'vendorUrl', 'version', 'hideOnMobile', 'mobileQuery', 'collect', 'sensitiveSelectors', 'button', 'dialog', 'captcha', 'isLoggedIn', 'wpPage', 'i18n' ) as $k ) {
							assert_true( array_key_exists( $k, $config ), 'missing key ' . $k );
						}
						assert_same( '', $config['nonce'] );
						assert_true( str_ends_with( $config['restUrl'], 'fixthisplz/v1/' ), 'restUrl is the namespace base: ' . $config['restUrl'] );
						assert_same( 'dashicons-flag', $config['button']['dashicon'] );
						assert_same( 'pub', $config['captcha']['siteKey'] );
						assert_true( '' !== $config['captcha']['scriptUrl'] );
						assert_true( false !== strpos( $config['moduleUrl'], 'assets/js/reporter.js' ) );
						assert_true( false !== strpos( $config['vendorUrl'], 'assets/vendor/modern-screenshot.js' ) );
						assert_same( true, $config['dialog']['askEmail'] );
					}
				);
			}
		);
	}
);

fixthisplz_test(
	'enqueue_ignores_term_id_on_archives_and_blanks_dashicon_for_svg',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						$term = wp_insert_term( 'fixthisplz-test-term', 'category' );
						$tid  = is_array( $term ) ? (int) $term['term_id'] : 0;
						update_option(
							Settings::OPTION,
							array(
								'enabled'    => true,
								'visibility' => array( 'guests' => true, 'excluded_pages' => array( $tid ) ),
								'button'     => array( 'icon_type' => 'svg' ),
							)
						);
						global $wp_query;
						$old = $wp_query;
						$wp_query = new WP_Query();
						$wp_query->queried_object    = get_term( $tid, 'category' );
						$wp_query->queried_object_id = $tid;
						Assets::enqueue();
						$loaded = wp_script_is( 'fixthisplz-loader', 'enqueued' );
						global $wp_scripts;
						$js = implode( "\n", (array) $wp_scripts->get_data( 'fixthisplz-loader', 'before' ) );
						$wp_query = $old;
						wp_set_current_user( $prev->ID );
						assert_true( $loaded, 'term id equal to an excluded page id must not hide the button' );
						assert_true( false !== strpos( $js, '"dashicon":""' ), 'no dashicon for svg icon type' );
					}
				);
			}
		);
	}
);

fixthisplz_test(
	'enqueue_privacy_url_falls_back_to_wp_privacy_page',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						$fallback = static fn() => 'https://example.test/privacy-policy/';
						add_filter( 'privacy_policy_url', $fallback );
						$read = function ( array $dialog ): string {
							update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => true ), 'dialog' => $dialog ) );
							Assets::enqueue();
							global $wp_scripts;
							$js     = implode( "\n", (array) $wp_scripts->get_data( 'fixthisplz-loader', 'before' ) );
							$config = json_decode( trim( substr( $js, strlen( 'window.fixThisPlzConfig = ' ) ), "; \n" ), true );
							return (string) $config['dialog']['privacyUrl'];
						};
						$empty = $read( array( 'privacy_url' => '' ) );
						wp_dequeue_script( 'fixthisplz-loader' );
						wp_deregister_script( 'fixthisplz-loader' );
						$own = $read( array( 'privacy_url' => 'https://example.test/own/' ) );
						remove_filter( 'privacy_policy_url', $fallback );
						wp_set_current_user( $prev->ID );
						assert_same( 'https://example.test/privacy-policy/', $empty );
						assert_same( 'https://example.test/own/', $own );
					}
				);
			}
		);
	}
);

fixthisplz_test(
	'enqueue_config_carries_capture_method_and_masking',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						$read = function ( array $dialog ): array {
							update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => true ), 'dialog' => $dialog ) );
							Assets::enqueue();
							global $wp_scripts;
							$js = implode( "\n", (array) $wp_scripts->get_data( 'fixthisplz-loader', 'before' ) );
							wp_dequeue_script( 'fixthisplz-loader' );
							wp_deregister_script( 'fixthisplz-loader' );
							return json_decode( trim( substr( $js, strlen( 'window.fixThisPlzConfig = ' ) ), "; \n" ), true )['dialog'];
						};
						$default = $read( array() );
						$custom  = $read( array( 'capture_method' => 'dom', 'mask_capture' => true ) );
						wp_set_current_user( $prev->ID );
						assert_same( 'tab', $default['captureMethod'] );
						assert_same( false, $default['maskCapture'] );
						assert_same( 'dom', $custom['captureMethod'] );
						assert_same( true, $custom['maskCapture'] );
					}
				);
			}
		);
	}
);
