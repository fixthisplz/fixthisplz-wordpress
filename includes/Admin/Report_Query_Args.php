<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** List filters and sort read from a request, and written back as URL parameters. Shared by the list, the Kanban and the CSV export. */
final class Report_Query_Args {

	/**
	 * Sanitised list filters from an already-unslashed request array.
	 *
	 * @return array{status:string,priority:string,from:string,to:string,search:string}
	 */
	public static function read_filters( array $src ): array {
		$text = static fn( string $key ): string => isset( $src[ $key ] ) && is_string( $src[ $key ] ) ? $src[ $key ] : '';
		$date = static function ( string $value ): string {
			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : '';
		};

		$status   = sanitize_key( $text( 'status' ) );
		$priority = sanitize_key( $text( 'priority' ) );

		return array(
			'status'   => Statuses::is_valid( $status ) ? $status : '',
			'priority' => Statuses::is_valid_priority( $priority ) ? $priority : '',
			'from'     => $date( $text( 'from' ) ),
			'to'       => $date( $text( 'to' ) ),
			'search'   => trim( sanitize_text_field( $text( 's' ) ) ),
		);
	}

	/** @return array{0:string,1:string} orderby (created_at|priority) and order (ASC|DESC). */
	public static function read_sort( array $src ): array {
		$orderby = isset( $src['orderby'] ) && 'priority' === $src['orderby'] ? 'priority' : 'created_at';
		$order   = isset( $src['order'] ) && is_string( $src['order'] ) && 'asc' === strtolower( $src['order'] ) ? 'ASC' : 'DESC';
		return array( $orderby, $order );
	}

	/** Filters as URL parameters (empty ones dropped). */
	public static function filter_query_args( array $filters ): array {
		$args = array(
			'status'   => $filters['status'] ?? '',
			'priority' => $filters['priority'] ?? '',
			'from'     => $filters['from'] ?? '',
			'to'       => $filters['to'] ?? '',
			's'        => $filters['search'] ?? '',
		);
		return array_filter( $args, static fn( $v ) => '' !== $v );
	}
}
