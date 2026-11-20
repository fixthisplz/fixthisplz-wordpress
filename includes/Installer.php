<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'bugspot_db_version';
	public const CAPABILITY        = 'bugspot_manage';
	public const CRON_HOOK         = 'bugspot_daily_purge';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bugspot_reports';
	}

	public static function activate(): void {
		self::create_table();
		Secret::key();
		Settings::sync_capabilities();
		self::private_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::create_table();
		}
	}

	/** Grants the management capability to $roles and removes it from every other role. */
	public static function sync_capabilities( array $roles ): void {
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( in_array( $name, $roles, true ) ) {
				$role->add_cap( self::CAPABILITY );
			} elseif ( $role->has_cap( self::CAPABILITY ) ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}

	/** Upload sub-directory for screenshots, closed to direct web access. Created on first call. */
	public static function private_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = $uploads['basedir'] . '/bug-spotter';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	private static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'new',
  priority varchar(10) NOT NULL DEFAULT 'normal',
  description text NOT NULL,
  page_url varchar(2048) NOT NULL DEFAULT '',
  context longtext NULL,
  user_id bigint(20) unsigned NULL,
  reporter_email text NULL,
  reporter_email_hash char(64) NULL,
  screenshot varchar(255) NULL,
  ip_hash char(64) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  seen_at datetime NULL,
  status_changed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at),
  KEY user_id (user_id),
  KEY reporter_email_hash (reporter_email_hash)
) {$charset};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
