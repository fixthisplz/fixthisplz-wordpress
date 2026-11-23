<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live data.

/**
 * SQL access to the activity table: the internal notes of a report and the history of its status, priority and
 * assignment. Notes are plain text, stored as typed and escaped at output; they are never shown to the reporter.
 * Author (user_id): a user ID, 0 for the plugin itself ("System"), NULL once erased ("Deleted user").
 */
final class Activity {

	public const NOTE   = 'note';
	public const CHANGE = 'change';

	/** Longest note kept, in characters. */
	public const NOTE_MAX = 5000;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fixthisplz_activity';
	}

	/**
	 * Called by Reports_Repository only, the single place that changes those fields.
	 *
	 * @param array<string,array{0:?string,1:?string}> $changes field => [old value, new value].
	 */
	public static function record_changes( int $report_id, array $changes, int $user_id ): void {
		global $wpdb;
		foreach ( $changes as $field => list( $old, $new ) ) {
			$wpdb->insert(
				self::table(),
				array(
					'report_id'  => $report_id,
					'user_id'    => $user_id,
					'type'       => self::CHANGE,
					'field'      => $field,
					'old_value'  => $old,
					'new_value'  => $new,
					'created_at' => Reports_Repository::now(),
				)
			);
		}
	}

	/** One "System" change for every report whose $field is $from, before Reports_Repository moves them all to $to. */
	public static function record_moves( string $field, string $from, ?string $to ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				// IF(): prepare() has no NULL placeholder; new_value is NULL when $to is null (unassigned).
				'INSERT INTO %i (report_id, user_id, type, field, old_value, new_value, created_at) SELECT id, 0, %s, %s, %s, IF(%d, NULL, %s), %s FROM %i WHERE %i = %s',
				self::table(),
				self::CHANGE,
				$field,
				$from,
				null === $to ? 1 : 0,
				(string) $to,
				Reports_Repository::now(),
				Installer::table(),
				$field,
				$from
			)
		);
	}

	/** Stores $content as typed (invalid UTF-8 removed, at most NOTE_MAX characters). @return int New note id, 0 when empty or failed. */
	public static function add_note( int $report_id, int $user_id, string $content ): int {
		global $wpdb;
		$content = mb_substr( trim( wp_check_invalid_utf8( $content, true ) ), 0, self::NOTE_MAX );
		if ( '' === $content ) {
			return 0;
		}
		$data = array(
			'report_id'  => $report_id,
			'user_id'    => $user_id,
			'type'       => self::NOTE,
			'content'    => $content,
			'created_at' => Reports_Repository::now(),
		);
		return false === $wpdb->insert( self::table(), $data ) ? 0 : (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/** A note can be deleted by its author or by an administrator; a change, never. */
	public static function can_delete( array $entry, int $user_id ): bool {
		if ( self::NOTE !== $entry['type'] || $user_id < 1 ) {
			return false;
		}
		return $user_id === $entry['user_id'] || user_can( $user_id, 'manage_options' );
	}

	public static function delete_note( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id, 'type' => self::NOTE ), array( '%d', '%s' ) );
	}

	/** @return array[] Notes and changes of a report, newest first. */
	public static function for_report( int $report_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE report_id = %d ORDER BY created_at DESC, id DESC', self::table(), $report_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	public static function delete_for_report( int $report_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'report_id' => $report_id ), array( '%d' ) );
	}

	/** @return array{items: array[], total: int} Notes and changes signed by $user_id, oldest first (privacy export). */
	public static function by_user( int $user_id, int $per_page, int $page ): array {
		global $wpdb;
		$table = self::table();
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $user_id ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d', $table, $user_id, $per_page, ( max( 1, $page ) - 1 ) * $per_page ), ARRAY_A );
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/** Removes $user_id as the author of their notes and changes (kept as "Deleted user"). @return int Rows changed. */
	public static function anonymise_user( int $user_id ): int {
		global $wpdb;
		$rows = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', self::table(), $user_id ) );
		return is_int( $rows ) ? $rows : 0;
	}

	private static function hydrate( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['report_id'] = (int) $row['report_id'];
		$row['user_id']   = null !== $row['user_id'] ? (int) $row['user_id'] : null;
		return $row;
	}
}
