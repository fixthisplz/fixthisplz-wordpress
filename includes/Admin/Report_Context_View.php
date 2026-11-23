<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Report_Format;
use FixThisPlz\Rest\Reports_Controller;
use FixThisPlz\Screenshot_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only parts of the detail screen: reporter, page, element, browser, console, network, screenshot, raw JSON.
 * Everything stored in a report comes from a visitor and is untrusted: it is escaped here, at output.
 */
final class Report_Context_View {

	private static function none(): void {
		echo '<p class="fixthisplz-muted">' . esc_html__( 'None', 'fixthisplz' ) . '</p>';
	}

	/** A key / value table whose empty values are skipped. */
	public static function render_pairs( array $pairs ): void {
		$pairs = array_filter( $pairs, static fn( $v ) => '' !== $v );
		if ( ! $pairs ) {
			self::none();
			return;
		}
		echo '<table class="fixthisplz-pairs"><tbody>';
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
			echo '<p>' . esc_html__( 'Contact e-mail:', 'fixthisplz' ) . ' ' . esc_html( $who['email'] ) . '</p>';
		}
	}

	public static function render_page( array $report, array $context ): void {
		$url = (string) $report['page_url'];
		if ( '' !== $url ) {
			echo '<p>';
			self::link_or_text( $url );
			echo '</p>';
		}
		$title = isset( $context['title'] ) && is_string( $context['title'] ) ? $context['title'] : '';
		self::render_pairs(
			array(
				__( 'Title', 'fixthisplz' )       => $title,
				__( 'WordPress page', 'fixthisplz' ) => isset( $context['wp_page'] ) && is_string( $context['wp_page'] ) ? $context['wp_page'] : '',
			)
		);
	}

	/** Only http(s) URLs become links; anything else (javascript:, data:…) stays inert text. */
	private static function link_or_text( string $url ): void {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			echo esc_html( $url );
			return;
		}
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
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
				__( 'Selector', 'fixthisplz' ) => $get( 'selector' ),
				__( 'Tag', 'fixthisplz' )      => $get( 'tag' ),
				__( 'Role', 'fixthisplz' )     => $get( 'role' ),
				__( 'Name', 'fixthisplz' )     => $get( 'name' ),
				__( 'Text', 'fixthisplz' )     => $get( 'text' ),
				__( 'Position', 'fixthisplz' ) => $position,
			)
		);
	}

	public static function render_browser( array $context ): void {
		$get = static fn( string $key ): string => isset( $context[ $key ] ) && is_string( $context[ $key ] ) ? $context[ $key ] : '';
		self::render_pairs(
			array(
				__( 'Language', 'fixthisplz' )       => $get( 'locale' ),
				__( 'Time zone', 'fixthisplz' )      => $get( 'timezone' ),
				__( 'User agent', 'fixthisplz' )     => $get( 'user_agent' ),
				__( 'Viewport', 'fixthisplz' )       => $get( 'viewport' ),
				__( 'Plugin version', 'fixthisplz' ) => $get( 'plugin_version' ),
			)
		);
	}

	public static function render_console( mixed $entries ): void {
		if ( ! is_array( $entries ) || ! $entries ) {
			self::none();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Level', 'fixthisplz' ) . '</th><th>' . esc_html__( 'Message', 'fixthisplz' ) . '</th><th>' . esc_html__( 'Time', 'fixthisplz' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( self::str( $entry, 'level' ) ) . '</td><td class="fixthisplz-wrap">' . esc_html( self::str( $entry, 'message' ) ) . '</td><td>' . esc_html( self::str( $entry, 'at' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_network( mixed $entries ): void {
		if ( ! is_array( $entries ) || ! $entries ) {
			self::none();
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Method', 'fixthisplz' ) . '</th><th>' . esc_html__( 'URL', 'fixthisplz' ) . '</th><th>' . esc_html__( 'Status', 'fixthisplz' ) . '</th><th>' . esc_html__( 'Time', 'fixthisplz' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( self::str( $entry, 'method' ) ) . '</td><td class="fixthisplz-wrap">' . esc_html( self::str( $entry, 'url' ) ) . '</td><td>' . esc_html( self::str( $entry, 'status' ) ) . '</td><td>' . esc_html( self::str( $entry, 'at' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function str( array $entry, string $key ): string {
		$v = $entry[ $key ] ?? '';
		return is_string( $v ) || is_int( $v ) ? (string) $v : '';
	}

	public static function render_screenshot( array $report ): void {
		if ( ! is_string( $report['screenshot'] ?? null ) || '' === $report['screenshot'] ) {
			echo '<p class="fixthisplz-muted">' . esc_html__( 'No screenshot was attached.', 'fixthisplz' ) . '</p>';
			return;
		}
		$method = Screenshot_Store::capture_label( $report['context'] );
		if ( '' !== $method ) {
			echo '<p class="fixthisplz-muted">' . esc_html( $method ) . '</p>';
		}
		// An <img> cannot send X-WP-Nonce: cookie authentication accepts it as a query argument instead.
		$src = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( Reports_Controller::NAMESPACE . '/reports/' . (int) $report['id'] . '/screenshot' ) );
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img class="fixthisplz-screenshot" src="%1$s" alt="%2$s" loading="lazy"></a>',
			esc_url( $src ),
			esc_attr__( 'Screenshot of the page (click for full size)', 'fixthisplz' )
		);
	}

	public static function render_raw( array $context ): void {
		$json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		echo '<details><summary>' . esc_html__( 'Show the raw JSON', 'fixthisplz' ) . '</summary>';
		echo '<pre class="fixthisplz-json">' . esc_html( is_string( $json ) ? $json : '' ) . '</pre></details>';
	}
}
