<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** The WHERE and ORDER BY parts of the report queries (Reports_Repository): fixed SQL with placeholders, plus their values. */
final class Report_Query {

	/**
	 * "ORDER BY" term that sorts by priority in the order of the settings (keys unknown to them come lowest).
	 *
	 * @return array{0:string,1:string[]} The term, with one %s placeholder per priority key, and the keys.
	 */
	public static function priority_order( string $dir ): array {
		$keys = array_keys( Priorities::all() );
		return array( 'FIELD(priority, ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ( 'ASC' === $dir ? ') ASC' : ') DESC' ), $keys );
	}

	/**
	 * A WHERE clause (possibly empty) made of fixed SQL and placeholders, and the values of those placeholders.
	 *
	 * @return array{0:string,1:array<int,string|int>}
	 */
	public static function where( array $args ): array {
		global $wpdb;
		$clauses = array();
		$values  = array();

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = (string) $args['status'];
		}
		if ( ! empty( $args['priority'] ) ) {
			$clauses[] = 'priority = %s';
			$values[]  = (string) $args['priority'];
		}
		$assigned = (string) ( $args['assigned'] ?? '' );
		if ( 'none' === $assigned ) {
			$clauses[] = 'assigned_to IS NULL';
		} elseif ( absint( $assigned ) > 0 ) {
			$clauses[] = 'assigned_to = %d';
			$values[]  = absint( $assigned );
		}
		$from = self::local_day_bound( $args['from'] ?? '', '00:00:00' );
		if ( null !== $from ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = $from;
		}
		$to = self::local_day_bound( $args['to'] ?? '', '23:59:59' );
		if ( null !== $to ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = $to;
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(description LIKE %s OR page_url LIKE %s)';
			array_push( $values, $like, $like );
		}

		return array( $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '', $values );
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
