<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();
		Rest\Reports_Controller::register_hooks();
		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'bug-spotter', false, dirname( plugin_basename( BUGSPOT_FILE ) ) . '/languages' );
	}
}
