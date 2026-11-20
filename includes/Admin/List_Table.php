<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** Filterable, sortable list of reports. Never touches ip_hash / reporter_email_hash. */
final class List_Table extends \WP_List_Table {

	private const EXCERPT_LENGTH = 120;

	private array $filters = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bug',
				'plural'   => 'bugs',
				'ajax'     => false,
			)
		);
	}

	/** @return array<string,string> */
	public static function columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'id'          => __( 'No.', 'bug-spotter' ),
			'description' => __( 'Description', 'bug-spotter' ),
			'page'        => __( 'Page', 'bug-spotter' ),
			'reporter'    => __( 'Reporter', 'bug-spotter' ),
			'priority'    => __( 'Priority', 'bug-spotter' ),
			'status'      => __( 'Status', 'bug-spotter' ),
			'created_at'  => __( 'Date', 'bug-spotter' ),
		);
	}

	public function get_columns(): array {
		return self::columns();
	}

	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'priority'   => array( 'priority', false ),
		);
	}

	protected function get_default_primary_column_name(): string {
		return 'description';
	}

	protected function get_bulk_actions(): array {
		$actions = array();
		foreach ( Statuses::all() as $key => $label ) {
			/* translators: %s: status label. */
			$actions[ 'status_' . $key ] = sprintf( __( 'Set status: %s', 'bug-spotter' ), $label );
		}
		foreach ( Statuses::priorities() as $key => $label ) {
			/* translators: %s: priority label. */
			$actions[ 'priority_' . $key ] = sprintf( __( 'Set priority: %s', 'bug-spotter' ), $label );
		}
		$actions['delete'] = __( 'Delete', 'bug-spotter' );
		return $actions;
	}

	public function prepare_items(): void {
		$request = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$this->filters = Bugs_Page::read_filters( $request );
		list( $orderby, $order ) = Bugs_Page::read_sort( $request );

		$per_page = (int) $this->get_items_per_page( Bugs_Page::PER_PAGE_OPTION, Bugs_Page::DEFAULT_PER_PAGE );
		$result   = ( new Reports_Repository() )->query(
			array_merge(
				$this->filters,
				array(
					'orderby'  => $orderby,
					'order'    => $order,
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);

		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	protected function get_views(): array {
		$counts  = ( new Reports_Repository() )->count_by_status( $this->filters );
		$base    = $this->filters;
		$current = $this->filters['status'];

		$make = static function ( string $status, string $label, int $count ) use ( $base, $current ): string {
			$base['status'] = $status;
			return sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( Bugs_Page::page_url( Bugs_Page::filter_query_args( $base ) ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		};

		$views = array( 'all' => $make( '', __( 'All', 'bug-spotter' ), array_sum( $counts ) ) );
		foreach ( Statuses::all() as $key => $label ) {
			$views[ $key ] = $make( $key, $label, $counts[ $key ] ?? 0 );
		}
		return $views;
	}

	public function no_items(): void {
		esc_html_e( 'No reports found.', 'bug-spotter' );
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="bug[]" value="%d" />', (int) $item['id'] );
	}

	protected function column_id( $item ): string {
		return esc_html( '#' . (int) $item['id'] );
	}

	protected function column_description( $item ): string {
		$id      = (int) $item['id'];
		$text    = (string) $item['description'];
		$excerpt = Report_Format::excerpt( $text, self::EXCERPT_LENGTH );
		$view    = Bugs_Page::page_url( array( 'report' => $id ) );
		$delete  = wp_nonce_url(
			Bugs_Page::page_url( array_merge( Bugs_Page::filter_query_args( $this->filters ), array( 'bugspot_action' => 'delete', 'bug' => $id ) ) ),
			'bugspot_delete_' . $id
		);

		$title = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $view ), esc_html( $excerpt ) );
		$rows  = $this->row_actions(
			array(
				'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'bug-spotter' ) ),
				'delete' => sprintf(
					'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
					esc_url( $delete ),
					esc_js( __( 'Delete this report permanently?', 'bug-spotter' ) ),
					esc_html__( 'Delete', 'bug-spotter' )
				),
			)
		);
		return $title . $rows;
	}

	protected function column_page( $item ): string {
		$url  = (string) $item['page_url'];
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$href = esc_url( $url );
		if ( '' === $href ) {
			return esc_html( $path );
		}
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $href, esc_html( $path ) );
	}

	protected function column_reporter( $item ): string {
		$who = Report_Format::reporter( $item );
		if ( $who['is_user'] && '' !== $who['url'] ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( $who['url'] ), esc_html( $who['name'] ) );
		}
		$html = esc_html( $who['name'] );
		if ( '' !== $who['email'] ) {
			$html .= '<br><span class="bugspot-muted">' . esc_html( $who['email'] ) . '</span>';
		}
		return $html;
	}

	protected function column_priority( $item ): string {
		return $this->pill( 'priority', (string) $item['priority'], Statuses::priorities() );
	}

	protected function column_status( $item ): string {
		return $this->pill( 'status', (string) $item['status'], Statuses::all() );
	}

	protected function column_created_at( $item ): string {
		return esc_html( Report_Format::date( $item['created_at'] ) );
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}

	/** @param array<string,string> $labels */
	private function pill( string $kind, string $key, array $labels ): string {
		return sprintf(
			'<span class="bugspot-pill bugspot-%1$s bugspot-%1$s-%2$s">%3$s</span>',
			esc_attr( $kind ),
			esc_attr( $key ),
			esc_html( $labels[ $key ] ?? $key )
		);
	}
}
