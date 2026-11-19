<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Installer;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** CSV export of the filtered list. Deliberately omits ip_hash and reporter_email_hash. */
final class Csv_Export {

	public const ACTION = 'bugspot_export';
	private const BATCH = 200;

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function url( array $filters, string $orderby = 'created_at', string $order = 'DESC' ): string {
		$args = array_merge(
			array( 'action' => self::ACTION ),
			Bugs_Page::filter_query_args( $filters ),
			array( 'orderby' => $orderby, 'order' => strtolower( $order ) )
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bug-spotter' ), 403 );
		}

		$request                 = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$filters                 = Bugs_Page::read_filters( $request );
		list( $orderby, $order ) = Bugs_Page::read_sort( $request );

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
		fclose( $out );
		exit;
	}

	/**
	 * Streams the whole filtered result set (in pages of 200) to $stream.
	 *
	 * @param resource $stream
	 */
	public static function write( $stream, array $filters, string $orderby = 'created_at', string $order = 'DESC' ): void {
		$repo = new Reports_Repository();
		fwrite( $stream, "\xEF\xBB\xBF" ); // UTF-8 BOM so spreadsheets detect the encoding.
		self::put_row(
			$stream,
			array(
				__( 'No.', 'bug-spotter' ),
				__( 'Date', 'bug-spotter' ),
				__( 'Status', 'bug-spotter' ),
				__( 'Priority', 'bug-spotter' ),
				__( 'Page', 'bug-spotter' ),
				__( 'Reporter', 'bug-spotter' ),
				__( 'Description', 'bug-spotter' ),
			)
		);

		$statuses   = Statuses::all();
		$priorities = Statuses::priorities();
		$page       = 1;
		$sent       = 0;
		do {
			$result = $repo->query( array_merge( $filters, array( 'orderby' => $orderby, 'order' => $order, 'per_page' => self::BATCH, 'page' => $page ) ) );
			foreach ( $result['items'] as $item ) {
				$who  = Bugs_Page::reporter( $item );
				$name = '' !== $who['email'] ? $who['name'] . ' (' . $who['email'] . ')' : $who['name'];
				$time = date_create_immutable( (string) $item['created_at'] . ' UTC' );
				self::put_row(
					$stream,
					array(
						(string) (int) $item['id'],
						$time ? wp_date( 'Y-m-d H:i:s', $time->getTimestamp() ) : '',
						$statuses[ $item['status'] ] ?? (string) $item['status'],
						$priorities[ $item['priority'] ] ?? (string) $item['priority'],
						(string) $item['page_url'],
						$name,
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
