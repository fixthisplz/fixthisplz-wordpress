<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();
		Settings::register_hooks();
		Rest\Reports_Controller::register_hooks();
		Rest\Settings_Controller::register_hooks();
		Frontend\Assets::register_hooks();
		Privacy::register_hooks();
		Retention::register_hooks();
		if ( is_admin() ) {
			Admin\Menu::register_hooks();
		}
		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'bug-spotter', false, dirname( plugin_basename( BUGSPOT_FILE ) ) . '/languages' );
	}
}
