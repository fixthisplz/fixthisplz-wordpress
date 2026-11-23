<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** Removes every trace of the plugin, only when the administrator opted in (data.delete_on_uninstall). */
final class Uninstaller {

	public static function run(): void {
		$settings = get_option( Settings::OPTION, array() );
		$data     = is_array( $settings ) && isset( $settings['data'] ) && is_array( $settings['data'] ) ? $settings['data'] : array();
		if ( empty( $data['delete_on_uninstall'] ) ) {
			return;
		}

		global $wpdb;
		foreach ( array( Installer::table(), Activity::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes the plugin's own tables on uninstall.
		}

		delete_option( Settings::OPTION );
		delete_option( Installer::DB_VERSION_OPTION );
		delete_option( Secret::OPTION );
		self::delete_rate_limit_transients();
		delete_metadata( 'user', 0, 'bugspot_per_page', '', true ); // Screen option of the list (Admin\Bugs_Page::PER_PAGE_OPTION; the admin is not loaded here).

		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( Installer::CAPABILITY ) ) {
				$role->remove_cap( Installer::CAPABILITY );
			}
		}

		self::delete_private_dir();
		wp_clear_scheduled_hook( Installer::CRON_HOOK );
	}

	/** Goes through delete_transient() so an external object cache is cleared too; short-lived anyway. */
	private static function delete_rate_limit_transients(): void {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_bugspot_rl_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes the rate-limit transients by prefix, no API for that.
		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	/** Deletes uploads/bug-spotter recursively; refuses anything that does not resolve to exactly that directory. */
	private static function delete_private_dir(): void {
		$base = realpath( wp_upload_dir( null, false )['basedir'] );
		$dir  = Installer::private_dir();
		if ( false === $base || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		$real = realpath( $dir );
		if ( false === $real || $real !== $base . DIRECTORY_SEPARATOR . 'bug-spotter' ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			// Symlinks are removed themselves, never followed.
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
