<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Rest\Reports_Controller;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** Kanban board: one column per status, cards draggable between columns (see assets/js/admin-kanban.js). */
final class Kanban {

	private const STEP           = 50;
	private const MAX_LIMIT      = 1000;
	private const EXCERPT_LENGTH = 100;

	public static function enqueue_assets(): void {
		wp_enqueue_script( 'bug-spotter-kanban', BUGSPOT_URL . 'assets/js/admin-kanban.js', array( 'jquery', 'jquery-ui-sortable', 'wp-api-fetch' ), BUGSPOT_VERSION, true );
		wp_localize_script(
			'bug-spotter-kanban',
			'bugspotKanban',
			array(
				'path'         => '/' . Reports_Controller::NAMESPACE . '/reports/',
				'genericError' => __( 'The report could not be moved. Please try again.', 'bug-spotter' ),
				'dismiss'      => __( 'Dismiss this notice.', 'bug-spotter' ),
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

		echo '<div class="bugspot-board-wrap"><div class="bugspot-board">';
		foreach ( array_keys( Statuses::all() ) as $status ) {
			self::render_column( $repo, $criteria, $status, $counts[ $status ] ?? 0 );
		}
		echo '</div></div>';
	}

	private static function render_column( Reports_Repository $repo, array $criteria, string $status, int $total ): void {
		$limit = self::column_limit( $status );

		echo '<section class="bugspot-column" data-status="' . esc_attr( $status ) . '">';
		echo '<h2 class="bugspot-column-title">' . Report_Format::status_pill( $status ) . ' <span class="bugspot-count" data-status="' . esc_attr( $status ) . '">' . esc_html( (string) $total ) . '</span></h2>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<ul class="bugspot-cards" data-status="' . esc_attr( $status ) . '">';
		foreach ( self::fetch( $repo, $criteria, $status, $limit ) as $item ) {
			self::render_card( $item );
		}
		echo '</ul>';

		if ( $total > $limit && $limit < self::MAX_LIMIT ) {
			$args = self::current_limits();
			$args[ $status . '_limit' ] = min( self::MAX_LIMIT, $limit + self::STEP );
			$url  = Bugs_Page::page_url( array_merge( Report_Query_Args::filter_query_args( $criteria ), array( 'view' => 'kanban' ), $args ) );
			echo '<p class="bugspot-more"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Show more', 'bug-spotter' ) . '</a></p>';
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
		$priority = (string) $item['priority'];
		$labels   = Statuses::priorities();

		echo '<li class="bugspot-card" data-id="' . esc_attr( (string) $id ) . '" data-status="' . esc_attr( (string) $item['status'] ) . '">';
		echo '<div class="bugspot-card-head"><a class="bugspot-card-id" href="' . esc_url( Detail_Page::url( $id ) ) . '">#' . esc_html( (string) $id ) . '</a>';
		if ( is_string( $item['screenshot'] ?? null ) && '' !== $item['screenshot'] ) {
			echo ' <span class="dashicons dashicons-camera" title="' . esc_attr__( 'Has a screenshot', 'bug-spotter' ) . '" aria-label="' . esc_attr__( 'Has a screenshot', 'bug-spotter' ) . '"></span>';
		}
		echo ' <span class="bugspot-pill bugspot-priority-' . esc_attr( $priority ) . '">' . esc_html( $labels[ $priority ] ?? $priority ) . '</span></div>';
		echo '<p class="bugspot-card-text">' . esc_html( $excerpt ) . '</p>';
		echo '<p class="bugspot-card-meta bugspot-muted"><span class="bugspot-card-page">' . esc_html( $path ) . '</span>';
		if ( null !== $created ) {
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			$ago = sprintf( __( '%s ago', 'bug-spotter' ), human_time_diff( $created, time() ) );
			echo ' &middot; <span title="' . esc_attr( wp_date( 'Y-m-d H:i', $created ) ) . '">' . esc_html( $ago ) . '</span>';
		}
		echo '</p></li>';
	}
}
