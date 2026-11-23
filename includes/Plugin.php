<?php

namespace FixThisPlz;

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
		Mailer::register_hooks();
		Webhook_Sender::register_hooks();
		Team::register_hooks();
		if ( is_admin() ) {
			Admin\Menu::register_hooks();
		}
		add_action( 'init', array( self::class, 'load_textdomain' ) );
	}

	public static function load_textdomain(): void {
		// Just-in-time loading only reads wp-content/languages; this registers the bundled /languages folder too.
		load_plugin_textdomain( 'fixthisplz', false, dirname( plugin_basename( FIXTHISPLZ_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- needed for the bundled translations.
	}

	/** Writes a failure that the plugin absorbs (the visitor's report is kept) to the PHP log, only when WP_DEBUG is on. */
	public static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FixThisPlz: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debugging aid, only when WP_DEBUG is on.
		}
	}
}
