<?php
/**
 * PSR-style autoloader for the Bug_Spotter\ namespace. Shared by the main plugin file and uninstall.php
 * (WordPress loads only uninstall.php on removal, never the plugin bootstrap). Requires BUGSPOT_DIR.
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Bug_Spotter\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = BUGSPOT_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
