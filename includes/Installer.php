<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_VERSION        = '3';
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

	/**
	 * Version 2: priority varchar(20) (configurable priorities). Version 3: assigned_to column and the activity table
	 * (notes and history). dbDelta only widens or adds, rows are kept.
	 */
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

		$table    = self::table();
		$activity = Activity::table();
		$charset  = $wpdb->get_charset_collate();
		dbDelta(
			array(
				"CREATE TABLE {$table} (
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
  assigned_to bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at),
  KEY user_id (user_id),
  KEY reporter_email_hash (reporter_email_hash),
  KEY assigned_to (assigned_to)
) {$charset};",
				// user_id: 0 for a change made by the plugin itself, NULL once the author's data was erased.
				"CREATE TABLE {$activity} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  report_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NULL,
  type varchar(10) NOT NULL DEFAULT 'note',
  field varchar(20) NULL,
  old_value varchar(20) NULL,
  new_value varchar(20) NULL,
  content text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY report_id (report_id),
  KEY user_id (user_id)
) {$charset};",
			)
		);
		// dbDelta() reports no failure: the version is stored only once the schema has its current shape, so an
		// upgrade that did not apply is tried again on the next load.
		if ( self::schema_applied() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	private static function schema_applied(): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema checks.
		$priority = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table(), 'priority' ), ARRAY_A );
		$assigned = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table(), 'assigned_to' ), ARRAY_A );
		$activity = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Activity::table() ) ) );
		// phpcs:enable
		return is_array( $priority ) && 'varchar(20)' === strtolower( (string) $priority['Type'] ) && is_array( $assigned ) && Activity::table() === $activity;
	}
}
