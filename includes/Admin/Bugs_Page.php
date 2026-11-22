<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Installer;
use Bug_Spotter\Priorities;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** Controller of the "All bugs" screen: filters, actions, tabs, and dispatch to list / kanban / detail. */
final class Bugs_Page {

	public const PER_PAGE_OPTION = 'bugspot_per_page';
	public const DEFAULT_PER_PAGE = 20;
	private const NOTICES        = array( 'updated', 'deleted' );

	/** `load-{hook}`: runs before any output, so actions can redirect. */
	public static function on_load(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && ! isset( $_GET['report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_screen_option( 'per_page', array( 'label' => __( 'Bugs per page', 'bug-spotter' ), 'default' => self::DEFAULT_PER_PAGE, 'option' => self::PER_PAGE_OPTION ) );
			add_filter( "manage_{$screen->id}_columns", array( List_Table::class, 'columns' ) );
		}
		// A detail page carries its own _wpnonce / action parameters: never treat them as list actions.
		if ( ! isset( $_GET['report'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::handle_requests();
		}
	}

	public static function save_screen_option( mixed $keep, string $option, mixed $value ): int {
		return max( 1, min( Reports_Repository::MAX_PER_PAGE, (int) $value ) );
	}

	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Menu::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Applies a bulk action (`status_{key}`, `priority_{key}`, `delete`).
	 *
	 * @return array{0:string,1:int} notice type ('' when nothing happened) and affected count.
	 */
	public static function apply_bulk( string $action, array $ids ): array {
		$repo  = new Reports_Repository();
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$count = 0;

		if ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				$count += $repo->delete( $id ) ? 1 : 0;
			}
			return array( $count > 0 ? 'deleted' : '', $count );
		}

		$fields = null;
		if ( str_starts_with( $action, 'status_' ) ) {
			$fields = array( 'status' => substr( $action, 7 ) );
		} elseif ( str_starts_with( $action, 'priority_' ) ) {
			$fields = array( 'priority' => substr( $action, 9 ) );
		}
		if ( null === $fields ) {
			return array( '', 0 );
		}
		foreach ( $ids as $id ) {
			$count += $repo->update( $id, $fields ) ? 1 : 0;
		}
		return array( $count > 0 ? 'updated' : '', $count );
	}

	private static function handle_requests(): void {
		$request = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonces are verified per action below.
		$back    = self::redirect_args( $request );

		// Row action "Delete".
		if ( isset( $request['bugspot_action'], $request['bug'] ) && 'delete' === $request['bugspot_action'] && is_scalar( $request['bug'] ) ) {
			$id = absint( $request['bug'] );
			check_admin_referer( 'bugspot_delete_' . $id );
			if ( ! current_user_can( Installer::CAPABILITY ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bug-spotter' ), 403 );
			}
			list( $type, $count ) = self::apply_bulk( 'delete', array( $id ) );
			self::redirect( array_merge( $back, self::notice_args( $type, $count ) ) );
		}

		// Bulk actions.
		$action = self::bulk_action_name( $request );
		if ( '' !== $action ) {
			check_admin_referer( 'bulk-bugs' );
			if ( ! current_user_can( Installer::CAPABILITY ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bug-spotter' ), 403 );
			}
			$ids = isset( $request['bug'] ) && is_array( $request['bug'] ) ? $request['bug'] : array();
			list( $type, $count ) = self::apply_bulk( $action, $ids );
			self::redirect( array_merge( $back, self::notice_args( $type, $count ) ) );
		}

		// Tidy the URL after a plain filter / sort submit (drops the bulk nonce and referer fields).
		if ( isset( $request['_wp_http_referer'] ) || isset( $request['_wpnonce'] ) || isset( $request['action'] ) || isset( $request['action2'] ) ) {
			self::redirect( $back );
		}
	}

	/** The bulk action chosen in the top or bottom selector ('' for none). */
	private static function bulk_action_name( array $request ): string {
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $request[ $key ] ) && is_string( $request[ $key ] ) && '' !== $request[ $key ] && '-1' !== $request[ $key ] ) {
				return sanitize_key( $request[ $key ] );
			}
		}
		return '';
	}

	/** Query args that keep the user's filters, sort, view and page after an action. */
	private static function redirect_args( array $request ): array {
		list( $orderby, $order ) = Report_Query_Args::read_sort( $request );
		$args                    = Report_Query_Args::filter_query_args( Report_Query_Args::read_filters( $request ) );
		if ( isset( $request['orderby'] ) ) {
			$args['orderby'] = $orderby;
			$args['order']   = strtolower( $order );
		}
		if ( isset( $request['paged'] ) && absint( $request['paged'] ) > 1 ) {
			$args['paged'] = absint( $request['paged'] );
		}
		if ( isset( $request['view'] ) && 'kanban' === $request['view'] ) {
			$args['view'] = 'kanban';
		}
		return $args;
	}

	public static function notice_args( string $type, int $count ): array {
		return '' === $type ? array() : array( 'bugspot_notice' => $type, 'bugspot_count' => $count );
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'bug-spotter' ), 403 );
		}

		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.

		if ( isset( $query['report'] ) && is_scalar( $query['report'] ) ) {
			Detail_Page::render( absint( $query['report'] ) );
			return;
		}

		$filters = Report_Query_Args::read_filters( $query );
		$kanban  = isset( $query['view'] ) && 'kanban' === $query['view'];

		echo '<div class="wrap bugspot-admin"><h1 class="wp-heading-inline">' . esc_html( Settings::text( 'management.menu_label' ) ) . '</h1>';
		echo '<hr class="wp-header-end">';
		self::render_notice( $query );
		self::render_tabs( $filters, $kanban );

		if ( $kanban ) {
			echo '<form method="get" class="bugspot-filters">';
			self::render_filter_form( $filters, $query, true );
			echo '</form>';
			Kanban::render( $filters );
			echo '</div>';
			return;
		}

		$table = new List_Table();
		$table->prepare_items();

		echo '<form method="get" class="bugspot-filters">';
		self::render_filter_form( $filters, $query, false );
		$table->views();
		$table->display();
		echo '</form></div>';
	}

	private static function render_notice( array $query ): void {
		$type = isset( $query['bugspot_notice'] ) && is_string( $query['bugspot_notice'] ) ? $query['bugspot_notice'] : '';
		if ( ! in_array( $type, self::NOTICES, true ) ) {
			return;
		}
		$count   = isset( $query['bugspot_count'] ) ? absint( $query['bugspot_count'] ) : 0;
		$message = 'deleted' === $type
			/* translators: %d: number of reports. */
			? _n( '%d report deleted.', '%d reports deleted.', $count, 'bug-spotter' )
			/* translators: %d: number of reports. */
			: _n( '%d report updated.', '%d reports updated.', $count, 'bug-spotter' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $message, $count ) ) . '</p></div>';
	}

	private static function render_tabs( array $filters, bool $kanban ): void {
		$args = Report_Query_Args::filter_query_args( $filters );
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( self::page_url( $args ) ),
			$kanban ? '' : ' nav-tab-active',
			esc_html__( 'List', 'bug-spotter' )
		);
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( self::page_url( array_merge( $args, array( 'view' => 'kanban' ) ) ) ),
			$kanban ? ' nav-tab-active' : '',
			esc_html__( 'Kanban', 'bug-spotter' )
		);
		echo '</nav>';
	}

	/** Filter controls shared by both views (the caller owns the <form>). */
	private static function render_filter_form( array $filters, array $query, bool $kanban ): void {
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::SLUG ) . '">';
		if ( $kanban ) {
			echo '<input type="hidden" name="view" value="kanban">';
		}
		if ( '' !== $filters['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '">';
		}
		if ( isset( $query['orderby'] ) ) {
			list( $orderby, $order ) = Report_Query_Args::read_sort( $query );
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
			echo '<input type="hidden" name="order" value="' . esc_attr( strtolower( $order ) ) . '">';
		}

		echo '<div class="bugspot-filter-bar">';
		echo '<label>' . esc_html__( 'From', 'bug-spotter' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'bug-spotter' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label> ';
		echo '<label class="screen-reader-text" for="bugspot-priority">' . esc_html__( 'Priority', 'bug-spotter' ) . '</label>';
		echo '<select name="priority" id="bugspot-priority"><option value="">' . esc_html__( 'All priorities', 'bug-spotter' ) . '</option>';
		foreach ( Priorities::all() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $filters['priority'], $key, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="bugspot-search">' . esc_html__( 'Search reports', 'bug-spotter' ) . '</label>';
		echo '<input type="search" id="bugspot-search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search description or URL', 'bug-spotter' ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'bug-spotter' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( Csv_Export::url( $filters, ...Report_Query_Args::read_sort( $query ) ) ) . '">' . esc_html__( 'Export CSV', 'bug-spotter' ) . '</a>';
		echo '</div>';
	}
}
