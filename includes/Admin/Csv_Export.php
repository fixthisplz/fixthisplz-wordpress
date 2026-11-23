<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Priorities;
use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;
use Bug_Spotter\Team;

defined( 'ABSPATH' ) || exit;

/** CSV export of the filtered list. Deliberately omits ip_hash and reporter_email_hash. */
final class Csv_Export {

	public const ACTION = 'bugspot_export';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function url( array $filters, string $orderby = 'created_at', string $order = 'DESC' ): string {
		$args = array_merge(
			array( 'action' => self::ACTION ),
			Report_Query_Args::filter_query_args( $filters ),
			array( 'orderby' => $orderby, 'order' => strtolower( $order ) )
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		Request::require_manager();

		$request                 = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$filters                 = Report_Query_Args::read_filters( $request );
		list( $orderby, $order ) = Report_Query_Args::read_sort( $request );

		// Another plugin's notice or a stray buffer would corrupt the BOM / first row.
		if ( headers_sent() ) {
			wp_die( esc_html__( 'The export could not start because output was already sent.', 'bug-spotter' ) );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bugs-' . wp_date( 'Ymd-Hi' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		self::write( $out, $filters, $orderby, $order );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is an output stream, not a file; WP_Filesystem does not apply.
		exit;
	}

	/**
	 * Streams the whole filtered result set (in pages of 200) to $stream.
	 *
	 * @param resource $stream
	 */
	public static function write( $stream, array $filters, string $orderby = 'created_at', string $order = 'DESC' ): void {
		$repo = new Reports_Repository();
		fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes to a stream (php://output), not a file. UTF-8 BOM so spreadsheets detect the encoding.
		self::put_row(
			$stream,
			array(
				__( 'No.', 'bug-spotter' ),
				__( 'Date', 'bug-spotter' ),
				__( 'Status', 'bug-spotter' ),
				__( 'Priority', 'bug-spotter' ),
				__( 'Assigned to', 'bug-spotter' ),
				__( 'Page', 'bug-spotter' ),
				__( 'Reporter', 'bug-spotter' ),
				__( 'Description', 'bug-spotter' ),
			)
		);

		$page = 1;
		$sent = 0;
		do {
			$result = $repo->query( array_merge( $filters, array( 'orderby' => $orderby, 'order' => $order, 'per_page' => Reports_Repository::MAX_PER_PAGE, 'page' => $page ) ) );
			cache_users( array_filter( array_column( $result['items'], 'assigned_to' ) ) ); // One query for the "Assigned to" column.
			foreach ( $result['items'] as $item ) {
				$time = Reports_Repository::timestamp( $item['created_at'] );
				self::put_row(
					$stream,
					array(
						(string) (int) $item['id'],
						null !== $time ? wp_date( 'Y-m-d H:i:s', $time ) : '',
						Statuses::label( (string) $item['status'] ),
						Priorities::label( (string) $item['priority'] ),
						null !== $item['assigned_to'] ? Team::name( (int) $item['assigned_to'] ) : '',
						(string) $item['page_url'],
						Report_Format::reporter_label( $item ),
						(string) $item['description'],
					)
				);
			}
			$sent += count( $result['items'] );
			++$page;
		} while ( $result['items'] && $sent < $result['total'] );
	}

	/** Neutralises spreadsheet formula injection: a leading = + - @ tab or CR gets a "'" prefix. */
	public static function safe_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/** @param resource $stream */
	private static function put_row( $stream, array $cells ): void {
		// Explicit separator/enclosure/escape: the default escape char is deprecated since PHP 8.4.
		fputcsv( $stream, array_map( array( self::class, 'safe_cell' ), array_map( 'strval', $cells ) ), ';', '"', '' );
	}
}
