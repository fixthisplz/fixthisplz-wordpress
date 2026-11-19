<?php

use Bug_Spotter\Frontend\Assets;
use Bug_Spotter\Frontend\Button_Style;
use Bug_Spotter\Frontend\Visibility;
use Bug_Spotter\Settings;

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

bugspot_test(
	'visibility_hidden_when_disabled',
	function () {
		$s = fe_settings( array( 'guests' => true ), false );
		assert_same( false, Visibility::should_load( $s, null, '/', 0, false ) );
	}
);

bugspot_test(
	'visibility_guest_only_when_guests_checked',
	function () {
		assert_same( false, Visibility::should_load( fe_settings(), null, '/', 0, false ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'guests' => true ) ), null, '/', 0, false ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'guests' => true ) ), new WP_User( 0 ), '/', 0, false ), 'user id 0 is a guest' );
	}
);

bugspot_test(
	'visibility_role_match',
	function () {
		$s = fe_settings( array( 'roles' => array( 'editor' ) ) );
		assert_same( true, Visibility::should_load( $s, fe_user( array( 'editor' ) ), '/', 0, false ) );
		assert_same( false, Visibility::should_load( $s, fe_user( array( 'subscriber' ) ), '/', 0, false ) );
		assert_same( false, Visibility::should_load( $s, null, '/', 0, false ) );
	}
);

bugspot_test(
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

bugspot_test(
	'visibility_admin_only_when_show_in_admin',
	function () {
		$admin = fe_user( array( 'administrator' ) );
		assert_same( false, Visibility::should_load( fe_settings(), $admin, '/wp-admin/', 0, true ) );
		assert_same( true, Visibility::should_load( fe_settings( array( 'show_in_admin' => true ) ), $admin, '/wp-admin/', 0, true ) );
		assert_same( false, Visibility::should_load( fe_settings( array( 'show_in_admin' => true, 'roles' => array( 'editor' ) ) ), $admin, '/wp-admin/', 0, true ), 'role rules still apply' );
	}
);

bugspot_test(
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
		// Every rule must be on #bugspot-button, and braces must balance.
		assert_same( substr_count( $css, '{' ), substr_count( $css, '}' ), 'balanced braces' );
		preg_match_all( '/([^{}]+)\{/', $css, $m );
		foreach ( $m[1] as $sel ) {
			$sel = trim( $sel );
			assert_true( 0 === strpos( $sel, '#bugspot-button' ) || 0 === strpos( $sel, '@media (max-width: 782px)' ), 'unexpected selector: ' . $sel );
		}
		assert_true( false === strpos( $css, 'huge' ) && false === strpos( $css, 'em;' ) );
	}
);

bugspot_test(
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
		assert_true( false !== strpos( Button_Style::css( $d ), '#bugspot-button:hover' ) );
	}
);

bugspot_test(
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

bugspot_test(
	'button_css_mobile_hide_media_query',
	function () {
		$b = Settings::defaults()['button'];
		assert_true( false === strpos( Button_Style::css( $b ), 'display:none' ) );
		$b['mobile_position_enabled']         = true;
		$b['position_mobile']['anchor']       = 'top-left';
		$css                                  = Button_Style::css( $b );
		assert_true( 1 === preg_match( '/@media \(max-width: 782px\)\{#bugspot-button\{[^}]*top:24px[^}]*left:24px/', $css ), $css );
		assert_true( false !== strpos( Button_Style::css( $b, true ), '@media (max-width: 782px){#bugspot-button{display:none}}' ) );
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

bugspot_test(
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
						$loaded = wp_script_is( 'bugspot-loader', 'enqueued' );
						wp_set_current_user( $prev->ID );
						assert_same( false, $loaded );
					}
				);
			}
		);
	}
);

bugspot_test(
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
						$before = $wp_scripts->get_data( 'bugspot-loader', 'before' );
						$ok     = wp_script_is( 'bugspot-loader', 'enqueued' ) && wp_style_is( 'bugspot-front', 'enqueued' );
						$defer  = $wp_scripts->get_data( 'bugspot-loader', 'strategy' );
						wp_set_current_user( $prev->ID );
						assert_true( $ok, 'loader and css enqueued' );
						assert_same( 'defer', $defer );
						$js = implode( "\n", (array) $before );
						assert_true( false !== strpos( $js, 'window.bugSpotterConfig = ' ) );
						assert_true( false === strpos( $js, 'TOPSECRETVALUE' ) && false === strpos( $js, 'secret' ), 'no secret in config' );
						$json   = trim( substr( $js, strlen( 'window.bugSpotterConfig = ' ) ), "; \n" );
						$config = json_decode( $json, true );
						assert_true( is_array( $config ), 'config is valid JSON' );
						foreach ( array( 'restUrl', 'nonce', 'moduleUrl', 'vendorUrl', 'version', 'hideOnMobile', 'mobileQuery', 'collect', 'sensitiveSelectors', 'button', 'dialog', 'captcha', 'isLoggedIn', 'wpPage', 'i18n' ) as $k ) {
							assert_true( array_key_exists( $k, $config ), 'missing key ' . $k );
						}
						assert_same( '', $config['nonce'] );
						assert_true( 'bug-spotter/v1/' === substr( $config['restUrl'], -15 ), 'restUrl is the namespace base: ' . $config['restUrl'] );
						assert_same( 'dashicons-flag', $config['button']['dashicon'] );
						assert_same( 'pub', $config['captcha']['siteKey'] );
						assert_true( '' !== $config['captcha']['scriptUrl'] );
						assert_true( false !== strpos( $config['moduleUrl'], 'assets/js/reporter.js' ) );
						assert_true( false !== strpos( $config['vendorUrl'], 'assets/vendor/modern-screenshot.mjs' ) );
						assert_same( true, $config['dialog']['askEmail'] );
					}
				);
			}
		);
	}
);

bugspot_test(
	'enqueue_ignores_term_id_on_archives_and_blanks_dashicon_for_svg',
	function () {
		with_settings_option(
			function () {
				fe_with_clean_assets(
					function () {
						$prev = wp_get_current_user();
						wp_set_current_user( 0 );
						$term = wp_insert_term( 'bugspot-test-term', 'category' );
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
						$loaded = wp_script_is( 'bugspot-loader', 'enqueued' );
						global $wp_scripts;
						$js = implode( "\n", (array) $wp_scripts->get_data( 'bugspot-loader', 'before' ) );
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
