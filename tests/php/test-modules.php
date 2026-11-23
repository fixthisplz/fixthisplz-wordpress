<?php

use FixThisPlz\Admin\Settings_Assets;
use FixThisPlz\Frontend\Assets;
use FixThisPlz\Settings;

/**
 * Runs $enqueue with fresh script and module registries, then returns what WordPress prints for script modules:
 * the import map and, concatenated, the preloads and module tags.
 *
 * @return array{0: array<string,string>, 1: string}
 */
function mod_printed( callable $enqueue ): array {
	global $wp_scripts, $wp_styles, $wp_script_modules;
	$old               = array( $wp_scripts, $wp_styles, $wp_script_modules );
	$wp_scripts        = new WP_Scripts();
	$wp_styles         = new WP_Styles();
	$wp_script_modules = new WP_Script_Modules();
	try {
		$enqueue();
		ob_start();
		wp_script_modules()->print_import_map();
		$html = (string) ob_get_clean();
		ob_start();
		wp_script_modules()->print_script_module_preloads();
		wp_script_modules()->print_enqueued_script_modules();
		$others = (string) ob_get_clean();
	} finally {
		list( $wp_scripts, $wp_styles, $wp_script_modules ) = $old;
	}
	preg_match( '#<script[^>]*type="importmap"[^>]*>(.*)</script>#s', $html, $m );
	$map = json_decode( $m[1] ?? '', true );
	return array( is_array( $map ) ? (array) $map['imports'] : array(), $others );
}

/** Every lib module must be remapped to its ?ver= URL, and nothing of ours preloaded. */
function mod_assert_versioned_map( array $imports, string $others ): void {
	$files = glob( FIXTHISPLZ_DIR . 'assets/js/lib/*.js' );
	assert_true( count( $files ) > 0, 'lib modules exist' );
	foreach ( $files as $file ) {
		$url = FIXTHISPLZ_URL . 'assets/js/lib/' . basename( $file );
		assert_same( $url . '?ver=' . FIXTHISPLZ_VERSION, $imports[ $url ] ?? null, basename( $file ) );
	}
	assert_true( false === strpos( $others, 'fixthisplz' ), 'no preload or module tag of ours: ' . $others );
}

$mod_wp_7 = version_compare( get_bloginfo( 'version' ), '7.0', '>=' );

fixthisplz_test(
	'front_import_map_versions_every_lib_module',
	function () use ( $mod_wp_7 ) {
		if ( ! $mod_wp_7 ) {
			return; // module_dependencies on classic scripts arrived in WordPress 7.0.
		}
		with_settings_option(
			function () {
				$prev = wp_get_current_user();
				wp_set_current_user( 0 );
				update_option( Settings::OPTION, array( 'enabled' => true, 'visibility' => array( 'guests' => true ) ) );
				$loaded = false;
				list( $imports, $others ) = mod_printed(
					function () use ( &$loaded ) {
						Assets::enqueue();
						$loaded = wp_script_is( 'fixthisplz-loader', 'enqueued' );
					}
				);
				wp_set_current_user( $prev->ID );
				assert_true( $loaded, 'loader still enqueued' );
				mod_assert_versioned_map( $imports, $others );
			}
		);
	}
);

fixthisplz_test(
	'settings_import_map_versions_every_lib_module',
	function () use ( $mod_wp_7 ) {
		if ( ! $mod_wp_7 ) {
			return;
		}
		// Declared by the anti-bot test script, loaded on the Spam protection tab only.
		$saved_get = $_GET;
		$_GET      = array( 'page' => 'fixthisplz-settings', 'tab' => 'spam' );
		try {
			list( $imports, $others ) = mod_printed( array( Settings_Assets::class, 'enqueue' ) );
		} finally {
			$_GET = $saved_get;
		}
		mod_assert_versioned_map( $imports, $others );
	}
);
