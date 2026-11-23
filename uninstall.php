<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads only this file, not the
 * plugin bootstrap, so the autoloader is required directly.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'FIXTHISPLZ_DIR' ) ) {
	define( 'FIXTHISPLZ_DIR', plugin_dir_path( __FILE__ ) );
}
require_once FIXTHISPLZ_DIR . 'includes/autoload.php';

FixThisPlz\Uninstaller::run();
