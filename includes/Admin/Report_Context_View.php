<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Report_Format;
use Bug_Spotter\Rest\Reports_Controller;
use Bug_Spotter\Screenshot_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only parts of the detail screen: reporter, page, element, browser, console, network, screenshot, raw JSON.
 * Everything stored in a report comes from a visitor and is untrusted: it is escaped here, at output.
 */
final class Report_Context_View {

	private static function none(): void {
		echo '<p class="bugspot-muted">' . esc_html__( 'None', 'bug-spotter' ) . '</p>';
	}

	/** A key / value table whose empty values are skipped. */
	public static function render_pairs( array $pairs ): void {
		$pairs = array_filter( $pairs, static fn( $v ) => '' !== $v );
		if ( ! $pairs ) {
			self::none();
			return;
		}
		echo '<table class="bugspot-pairs"><tbody>';
		foreach ( $pairs as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_reporter( array $report ): void {
		$who = Report_Format::reporter( $report );
		if ( '' !== $who['url'] ) {
			echo '<p><a href="' . esc_url( $who['url'] ) . '">' . esc_html( $who['name'] ) . '</a></p>';
		} else {
			echo '<p>' . esc_html( $who['name'] ) . '</p>';
		}
		if ( '' !== $who['email'] ) {
			echo '<p>' . esc_html__( 'Contact e-mail:', 'bug-spotter' ) . ' ' . esc_html( $who['email'] ) . '</p>';
		}
	}

	public static function render_page( array $report, array $context ): void {
		$url = (string) $report['page_url'];
		if ( '' !== $url ) {
			echo '<p>' . self::link_or_text( $url ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- link_or_text() escapes.
		}
		$title = isset( $context['title'] ) && is_string( $context['title'] ) ? $context['title'] : '';
		self::render_pairs(
			array(
				__( 'Title', 'bug-spotter' )       => $title,
				__( 'WordPress page', 'bug-spotter' ) => isset( $context['wp_page'] ) && is_string( $context['wp_page'] ) ? $context['wp_page'] : '',
			)
		);
	}

	/** Only http(s) URLs become links; anything else (javascript:, data:…) stays inert text. */
	private static function link_or_text( string $url ): string {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return esc_html( $url );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
	}

	public static function render_element( mixed $element ): void {
		if ( ! is_array( $element ) ) {
			self::none();
			return;
		}
		$rect     = isset( $element['rect'] ) && is_array( $element['rect'] ) ? $element['rect'] : null;
		$position = '';
		if ( $rect ) {
			$position = sprintf( 'x %d, y %d — %d × %d px', (int) ( $rect['x'] ?? 0 ), (int) ( $rect['y'] ?? 0 ), (int) ( $rect['width'] ?? 0 ), (int) ( $rect['height'] ?? 0 ) );
		}
		$get = static fn( string $key ): string => isset( $element[ $key ] ) && is_string( $element[ $key ] ) ? $element[ $key ] : '';
		self::render_pairs(
			array(
				__( 'Selector', 'bug-spotter' ) => $get( 'selector' ),
				__( 'Tag', 'bug-spotter' )      => $get( 'tag' ),
				__( 'Role', 'bug-spotter' )     => $get( 'role' ),
				__( 'Name', 'bug-spotter' )     => $get( 'name' ),
				__( 'Text', 'bug-spotter' )     => $get( 'text' ),
				__( 'Position', 'bug-spotter' ) => $position,
			)
		);
	}

	public static function render_browser( array $context ): void {
		$get = static fn( string $key ): string => isset( $context[ $key ] ) && is_string( $context[ $key ] ) ? $context[ $key ] : '';
		self::render_pairs(
			array(
				__( 'Language', 'bug-spotter' )       => $get( 'locale' ),
				__( 'Time zone', 'bug-spotter' )      => $get( 'timezone' ),
				__( 'User agent', 'bug-spotter' )     => $get( 'user_agent' ),
				__( 'Viewport', 'bug-spotter' )       => $get( 'viewport' ),
				__( 'Plugin version', 'bug-spotter' ) => $get( 'plugin_version' ),
			)
		);
	}

	public static function render_console( mixed $entries ): void {
		if ( ! is_array( $entries ) || ! $entries ) {
			self::none();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Level', 'bug-spotter' ) . '</th><th>' . esc_html__( 'Message', 'bug-spotter' ) . '</th><th>' . esc_html__( 'Time', 'bug-spotter' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( self::str( $entry, 'level' ) ) . '</td><td class="bugspot-wrap">' . esc_html( self::str( $entry, 'message' ) ) . '</td><td>' . esc_html( self::str( $entry, 'at' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_network( mixed $entries ): void {
		if ( ! is_array( $entries ) || ! $entries ) {
			self::none();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Method', 'bug-spotter' ) . '</th><th>' . esc_html__( 'URL', 'bug-spotter' ) . '</th><th>' . esc_html__( 'Status', 'bug-spotter' ) . '</th><th>' . esc_html__( 'Time', 'bug-spotter' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( self::str( $entry, 'method' ) ) . '</td><td class="bugspot-wrap">' . esc_html( self::str( $entry, 'url' ) ) . '</td><td>' . esc_html( self::str( $entry, 'status' ) ) . '</td><td>' . esc_html( self::str( $entry, 'at' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function str( array $entry, string $key ): string {
		$v = $entry[ $key ] ?? '';
		return is_string( $v ) || is_int( $v ) ? (string) $v : '';
	}

	public static function render_screenshot( array $report ): void {
		if ( ! is_string( $report['screenshot'] ?? null ) || '' === $report['screenshot'] ) {
			echo '<p class="bugspot-muted">' . esc_html__( 'No screenshot was attached.', 'bug-spotter' ) . '</p>';
			return;
		}
		$method = Screenshot_Store::capture_label( $report['context'] );
		if ( '' !== $method ) {
			echo '<p class="bugspot-muted">' . esc_html( $method ) . '</p>';
		}
		// An <img> cannot send X-WP-Nonce: cookie authentication accepts it as a query argument instead.
		$src = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( Reports_Controller::NAMESPACE . '/reports/' . (int) $report['id'] . '/screenshot' ) );
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img class="bugspot-screenshot" src="%1$s" alt="%2$s" loading="lazy"></a>',
			esc_url( $src ),
			esc_attr__( 'Screenshot of the page (click for full size)', 'bug-spotter' )
		);
	}

	public static function render_raw( array $context ): void {
		$json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		echo '<details><summary>' . esc_html__( 'Show the raw JSON', 'bug-spotter' ) . '</summary>';
		echo '<pre class="bugspot-json">' . esc_html( is_string( $json ) ? $json : '' ) . '</pre></details>';
	}
}
