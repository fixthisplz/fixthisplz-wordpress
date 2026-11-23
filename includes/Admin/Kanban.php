<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Priorities;
use FixThisPlz\Report_Format;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Rest\Reports_Controller;
use FixThisPlz\Statuses;
use FixThisPlz\Team;

defined( 'ABSPATH' ) || exit;

/** Kanban board: one column per status, cards draggable between columns (see assets/js/admin-kanban.js). */
final class Kanban {

	private const STEP           = 50;
	private const MAX_LIMIT      = 1000;
	private const EXCERPT_LENGTH = 100;

	public static function enqueue_assets(): void {
		wp_enqueue_script( 'fixthisplz-kanban', FIXTHISPLZ_URL . 'assets/js/admin-kanban.js', array( 'jquery', 'jquery-ui-sortable', 'wp-api-fetch' ), FIXTHISPLZ_VERSION, true );
		wp_localize_script(
			'fixthisplz-kanban',
			'fixThisPlzKanban',
			array(
				'path'         => '/' . Reports_Controller::NAMESPACE . '/reports/',
				'genericError' => __( 'The report could not be moved. Please try again.', 'fixthisplz' ),
				'dismiss'      => __( 'Dismiss this notice.', 'fixthisplz' ),
			)
		);
	}

	/** Cards currently requested for a column: `{status}_limit`, clamped to 50-1000. */
	public static function column_limit( string $status ): int {
		$key   = $status . '_limit';
		$value = isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : self::STEP; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display setting.
		return max( self::STEP, min( self::MAX_LIMIT, $value ) );
	}

	public static function render( array $filters ): void {
		$repo     = new Reports_Repository();
		$criteria = array_diff_key( $filters, array( 'status' => true ) ); // Columns replace the status filter.
		$counts   = $repo->count_by_status( $criteria );

		$columns = array();
		foreach ( array_keys( Statuses::all() ) as $status ) {
			$columns[ $status ] = self::fetch( $repo, $criteria, $status, self::column_limit( $status ) );
		}
		cache_users( array_filter( array_column( array_merge( ...array_values( $columns ) ), 'assigned_to' ) ) ); // One query for the initials.

		echo '<div class="fixthisplz-board-wrap"><div class="fixthisplz-board">';
		foreach ( $columns as $status => $items ) {
			self::render_column( $criteria, $status, $items, $counts[ $status ] ?? 0 );
		}
		echo '</div></div>';
	}

	/** @param array[] $items The cards of the column, already fetched. */
	private static function render_column( array $criteria, string $status, array $items, int $total ): void {
		$limit = self::column_limit( $status );

		echo '<section class="fixthisplz-column" data-status="' . esc_attr( $status ) . '">';
		echo '<h2 class="fixthisplz-column-title">' . wp_kses( Report_Format::pill( Statuses::class, $status ), Report_Format::PILL_HTML ) . ' <span class="fixthisplz-count" data-status="' . esc_attr( $status ) . '">' . esc_html( (string) $total ) . '</span></h2>';
		echo '<ul class="fixthisplz-cards" data-status="' . esc_attr( $status ) . '">';
		foreach ( $items as $item ) {
			self::render_card( $item );
		}
		echo '</ul>';

		if ( $total > $limit && $limit < self::MAX_LIMIT ) {
			$args = self::current_limits();
			$args[ $status . '_limit' ] = min( self::MAX_LIMIT, $limit + self::STEP );
			$url  = Report_Format::list_link( array_merge( Report_Query_Args::filter_query_args( $criteria ), array( 'view' => 'kanban' ), $args ) );
			echo '<p class="fixthisplz-more"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Show more', 'fixthisplz' ) . '</a></p>';
		}
		echo '</section>';
	}

	/** Limits already in the URL for the other columns, so "Show more" on one column keeps the rest. */
	private static function current_limits(): array {
		$args = array();
		foreach ( array_keys( Statuses::all() ) as $status ) {
			if ( isset( $_GET[ $status . '_limit' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display setting.
				$args[ $status . '_limit' ] = self::column_limit( $status );
			}
		}
		return $args;
	}

	/** @return list<array> Newest first, at most $limit items (fetched in chunks, the repository caps one page). */
	private static function fetch( Reports_Repository $repo, array $criteria, string $status, int $limit ): array {
		$items = array();
		$page  = 1;
		while ( count( $items ) < $limit ) {
			$result = $repo->query( array_merge( $criteria, array( 'status' => $status, 'with_email' => false, 'orderby' => 'created_at', 'order' => 'DESC', 'per_page' => min( Reports_Repository::MAX_PER_PAGE, $limit - count( $items ) ), 'page' => $page ) ) );
			if ( ! $result['items'] ) {
				break;
			}
			$items = array_merge( $items, $result['items'] );
			++$page;
			if ( count( $items ) >= $result['total'] ) {
				break;
			}
		}
		return array_slice( $items, 0, $limit );
	}

	private static function render_card( array $item ): void {
		$id       = (int) $item['id'];
		$excerpt  = Report_Format::excerpt( trim( (string) preg_replace( '/\s+/u', ' ', (string) $item['description'] ) ), self::EXCERPT_LENGTH );
		$path     = wp_parse_url( (string) $item['page_url'], PHP_URL_PATH );
		$path     = is_string( $path ) && '' !== $path ? $path : (string) $item['page_url'];
		$created  = Reports_Repository::timestamp( $item['created_at'] );

		echo '<li class="fixthisplz-card" data-id="' . esc_attr( (string) $id ) . '" data-status="' . esc_attr( (string) $item['status'] ) . '">';
		echo '<div class="fixthisplz-card-head"><a class="fixthisplz-card-id" href="' . esc_url( Report_Format::link( $id ) ) . '">#' . esc_html( (string) $id ) . '</a>';
		if ( is_string( $item['screenshot'] ?? null ) && '' !== $item['screenshot'] ) {
			echo ' <span class="dashicons dashicons-camera" title="' . esc_attr__( 'Has a screenshot', 'fixthisplz' ) . '" aria-label="' . esc_attr__( 'Has a screenshot', 'fixthisplz' ) . '"></span>';
		}
		echo ' ' . wp_kses( Report_Format::pill( Priorities::class, (string) $item['priority'] ), Report_Format::PILL_HTML );
		if ( null !== $item['assigned_to'] ) {
			$name = Team::name( $item['assigned_to'] );
			/* translators: %s: name of the person the report is assigned to. */
			$label = sprintf( __( 'Assigned to %s', 'fixthisplz' ), $name );
			echo '<span class="fixthisplz-assignee" title="' . esc_attr( $label ) . '" role="img" aria-label="' . esc_attr( $label ) . '">' . esc_html( Team::initials( $name ) ) . '</span>';
		}
		echo '</div>';
		echo '<p class="fixthisplz-card-text">' . esc_html( $excerpt ) . '</p>';
		echo '<p class="fixthisplz-card-meta fixthisplz-muted"><span class="fixthisplz-card-page">' . esc_html( $path ) . '</span>';
		if ( null !== $created ) {
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			$ago = sprintf( __( '%s ago', 'fixthisplz' ), human_time_diff( $created, time() ) );
			echo ' &middot; <span title="' . esc_attr( wp_date( 'Y-m-d H:i', $created ) ) . '">' . esc_html( $ago ) . '</span>';
		}
		echo '</p></li>';
	}
}
