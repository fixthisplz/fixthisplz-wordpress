<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** SQL fragments of the report queries (Reports_Repository), built from prepare() only. */
final class Report_Query {

	/** "ORDER BY" term that sorts by priority in the order of the settings (keys unknown to them come lowest). */
	public static function priority_order( string $dir ): string {
		global $wpdb;
		$keys = array_keys( Priorities::all() );
		return $wpdb->prepare( 'FIELD(priority, ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') ', $keys ) . ( 'ASC' === $dir ? 'ASC' : 'DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s placeholder per key, the keys passed as one array.
	}

	/** Builds a WHERE clause (possibly empty) from prepared fragments only. */
	public static function where( array $args ): string {
		global $wpdb;
		$clauses = array();

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = $wpdb->prepare( 'status = %s', (string) $args['status'] );
		}
		if ( ! empty( $args['priority'] ) ) {
			$clauses[] = $wpdb->prepare( 'priority = %s', (string) $args['priority'] );
		}
		$assigned = (string) ( $args['assigned'] ?? '' );
		if ( 'none' === $assigned ) {
			$clauses[] = 'assigned_to IS NULL';
		} elseif ( absint( $assigned ) > 0 ) {
			$clauses[] = $wpdb->prepare( 'assigned_to = %d', absint( $assigned ) );
		}
		$from = self::local_day_bound( $args['from'] ?? '', '00:00:00' );
		if ( null !== $from ) {
			$clauses[] = $wpdb->prepare( 'created_at >= %s', $from );
		}
		$to = self::local_day_bound( $args['to'] ?? '', '23:59:59' );
		if ( null !== $to ) {
			$clauses[] = $wpdb->prepare( 'created_at <= %s', $to );
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = $wpdb->prepare( '(description LIKE %s OR page_url LIKE %s)', $like, $like );
		}

		return $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
	}

	/** A site-timezone "Y-m-d" day at $time, converted to a UTC datetime string; null when the date is invalid. */
	private static function local_day_bound( mixed $date, string $time ): ?string {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $time, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
