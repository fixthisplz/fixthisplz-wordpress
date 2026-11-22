<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_VERSION        = '2';
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
		self::ensure_private_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Version 2: priority varchar(20) (configurable priorities). dbDelta only widens the column, rows are kept. */
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

	/** Upload sub-directory for screenshots (path only, nothing is created). */
	public static function private_dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/bug-spotter';
	}

	/** Creates the private directory, closed to direct web access, when missing; returns its path. */
	public static function ensure_private_dir(): string {
		$dir = self::private_dir();
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
  priority varchar(20) NOT NULL DEFAULT 'normal',
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
		// dbDelta() reports no failure: the version is stored only once the table has its version-2 shape, so an
		// upgrade that did not apply is tried again on the next load.
		$priority = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'priority' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		if ( is_array( $priority ) && 'varchar(20)' === strtolower( (string) $priority['Type'] ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}
}
