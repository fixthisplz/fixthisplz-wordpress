<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Installer;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Single-report screen. Everything stored in a report comes from a visitor and is untrusted: it is escaped at
 * output. ip_hash and reporter_email_hash are internal and are never displayed.
 */
final class Detail_Page {

	public const UPDATE_ACTION = 'bugspot_update_report';
	public const DELETE_ACTION = 'bugspot_delete_report';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( self::class, 'handle_update' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
	}

	public static function url( int $id, array $args = array() ): string {
		return Bugs_Page::page_url( array_merge( array( 'report' => $id ), $args ) );
	}

	public static function handle_update(): void {
		$id = self::posted_id();
		check_admin_referer( self::UPDATE_ACTION . '_' . $id );
		self::require_capability();

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$fields = array();
		foreach ( array( 'status', 'priority' ) as $key ) {
			if ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) {
				$fields[ $key ] = sanitize_key( $post[ $key ] );
			}
		}
		// The repository rejects invalid values as a whole; an unknown id is a plain failure too.
		$ok = $fields && ( new Reports_Repository() )->update( $id, $fields );
		wp_safe_redirect( self::url( $id, array( 'bugspot_notice' => $ok ? 'updated' : 'failed' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		$id = self::posted_id();
		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		self::require_capability();

		list( $type, $count ) = Bugs_Page::apply_bulk( 'delete', array( $id ) );
		wp_safe_redirect( Bugs_Page::page_url( '' === $type ? array() : array( 'bugspot_notice' => $type, 'bugspot_count' => $count ) ) );
		exit;
	}

	private static function posted_id(): int {
		return isset( $_POST['report'] ) && is_scalar( $_POST['report'] ) ? absint( wp_unslash( $_POST['report'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right after, by id.
	}

	private static function require_capability(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bug-spotter' ), 403 );
		}
	}

	public static function render( int $id ): void {
		$repo = new Reports_Repository();
		// Not right after an update/failed redirect: choosing "New" on purpose must stick.
		if ( ! isset( $_GET['bugspot_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display logic only.
			$repo->mark_seen( $id ); // Before reading, so the page already shows "Seen".
		}
		$report = $repo->find( $id );

		echo '<div class="wrap bugspot-admin bugspot-detail">';
		if ( null === $report ) {
			echo '<h1>' . esc_html__( 'Bugs', 'bug-spotter' ) . '</h1>';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Report not found.', 'bug-spotter' ) . '</p></div>';
			self::render_back_link();
			echo '</div>';
			return;
		}

		$context = $report['context'];
		/* translators: %d: report number. */
		echo '<h1 class="wp-heading-inline">' . esc_html( sprintf( __( 'Bug #%d', 'bug-spotter' ), $id ) ) . '</h1>';
		echo '<hr class="wp-header-end">';
		self::render_back_link();
		self::render_notice();

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
		echo '<div id="post-body-content">';

		self::box( __( 'Description', 'bug-spotter' ), static function () use ( $report ): void {
			echo '<p class="bugspot-description">' . nl2br( esc_html( (string) $report['description'] ) ) . '</p>';
		} );
		self::box( __( 'Reporter', 'bug-spotter' ), static fn() => self::render_reporter( $report ) );
		self::box( __( 'Page', 'bug-spotter' ), static fn() => self::render_page( $report, $context ) );
		self::box( __( 'Element', 'bug-spotter' ), static fn() => self::render_element( $context['element'] ?? null ) );
		self::box( __( 'Browser', 'bug-spotter' ), static fn() => self::render_browser( $context ) );
		self::box( __( 'Console errors', 'bug-spotter' ), static fn() => self::render_console( $context['console'] ?? array() ) );
		self::box( __( 'Failed requests', 'bug-spotter' ), static fn() => self::render_network( $context['network'] ?? array() ) );
		self::box( __( 'Screenshot', 'bug-spotter' ), static fn() => self::render_screenshot( $report ) );
		self::box( __( 'Raw data', 'bug-spotter' ), static function () use ( $context ): void {
			$json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			echo '<details><summary>' . esc_html__( 'Show the raw JSON', 'bug-spotter' ) . '</summary>';
			echo '<pre class="bugspot-json">' . esc_html( is_string( $json ) ? $json : '' ) . '</pre></details>';
		} );

		echo '</div><div id="postbox-container-1" class="postbox-container">';
		self::render_sidebar( $report );
		echo '</div></div></div></div>';
	}

	private static function render_back_link(): void {
		echo '<p><a href="' . esc_url( Bugs_Page::page_url() ) . '">&larr; ' . esc_html__( 'Back to the list', 'bug-spotter' ) . '</a></p>';
	}

	private static function render_notice(): void {
		$type = isset( $_GET['bugspot_notice'] ) && is_string( $_GET['bugspot_notice'] ) ? sanitize_key( wp_unslash( $_GET['bugspot_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'updated' === $type ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Report updated.', 'bug-spotter' ) . '</p></div>';
		} elseif ( 'failed' === $type ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The report could not be updated.', 'bug-spotter' ) . '</p></div>';
		}
	}

	private static function box( string $title, callable $body ): void {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div><div class="inside">';
		$body();
		echo '</div></div>';
	}

	private static function none(): void {
		echo '<p class="bugspot-muted">' . esc_html__( 'None', 'bug-spotter' ) . '</p>';
	}

	/** A key / value table whose empty values are skipped. */
	private static function render_pairs( array $pairs ): void {
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

	private static function render_reporter( array $report ): void {
		$who = Bugs_Page::reporter( $report );
		if ( '' !== $who['url'] ) {
			echo '<p><a href="' . esc_url( $who['url'] ) . '">' . esc_html( $who['name'] ) . '</a></p>';
		} else {
			echo '<p>' . esc_html( $who['name'] ) . '</p>';
		}
		if ( '' !== $who['email'] ) {
			echo '<p>' . esc_html__( 'Contact e-mail:', 'bug-spotter' ) . ' ' . esc_html( $who['email'] ) . '</p>';
		}
	}

	private static function render_page( array $report, array $context ): void {
		$url = (string) $report['page_url'];
		if ( '' !== $url ) {
			echo '<p>' . self::link_or_text( $url ) . '</p>';
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

	private static function render_element( mixed $element ): void {
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

	private static function render_browser( array $context ): void {
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

	private static function render_console( mixed $entries ): void {
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

	private static function render_network( mixed $entries ): void {
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

	private static function render_screenshot( array $report ): void {
		if ( ! is_string( $report['screenshot'] ?? null ) || '' === $report['screenshot'] ) {
			echo '<p class="bugspot-muted">' . esc_html__( 'No screenshot was attached.', 'bug-spotter' ) . '</p>';
			return;
		}
		// An <img> cannot send X-WP-Nonce: cookie authentication accepts it as a query argument instead.
		$src = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'bug-spotter/v1/reports/' . (int) $report['id'] . '/screenshot' ) );
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img class="bugspot-screenshot" src="%1$s" alt="%2$s" loading="lazy"></a>',
			esc_url( $src ),
			esc_attr__( 'Screenshot of the page (click for full size)', 'bug-spotter' )
		);
	}

	private static function render_sidebar( array $report ): void {
		$id = (int) $report['id'];

		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Status', 'bug-spotter' ) . '</h2></div><div class="inside">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::UPDATE_ACTION ) . '">';
		echo '<input type="hidden" name="report" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( self::UPDATE_ACTION . '_' . $id );
		echo '<p><label for="bugspot-status"><strong>' . esc_html__( 'Status', 'bug-spotter' ) . '</strong></label><br>';
		self::select( 'status', 'bugspot-status', Statuses::all(), (string) $report['status'] );
		echo '</p><p><label for="bugspot-priority-select"><strong>' . esc_html__( 'Priority', 'bug-spotter' ) . '</strong></label><br>';
		self::select( 'priority', 'bugspot-priority-select', Statuses::priorities(), (string) $report['priority'] );
		echo '</p><p><button type="submit" class="button button-primary">' . esc_html__( 'Update', 'bug-spotter' ) . '</button></p></form>';
		echo '</div></div>';

		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Dates', 'bug-spotter' ) . '</h2></div><div class="inside">';
		self::render_pairs(
			array(
				__( 'Created', 'bug-spotter' )        => self::format_date( $report['created_at'] ?? null ),
				__( 'Seen', 'bug-spotter' )           => self::format_date( $report['seen_at'] ?? null ),
				__( 'Status changed', 'bug-spotter' ) => self::format_date( $report['status_changed_at'] ?? null ),
				__( 'Updated', 'bug-spotter' )        => self::format_date( $report['updated_at'] ?? null ),
			)
		);
		echo '</div></div>';

		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Delete', 'bug-spotter' ) . '</h2></div><div class="inside">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Delete this report permanently? This cannot be undone.', 'bug-spotter' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_ACTION ) . '">';
		echo '<input type="hidden" name="report" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( self::DELETE_ACTION . '_' . $id );
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete this report', 'bug-spotter' ) . '</button></form>';
		echo '</div></div>';
	}

	private static function select( string $name, string $id, array $options, string $current ): void {
		printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $id ) );
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $key ), selected( $current, (string) $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/** UTC "Y-m-d H:i:s" => site-timezone display; '' when absent. */
	private static function format_date( mixed $utc ): string {
		if ( ! is_string( $utc ) || '' === $utc ) {
			return '';
		}
		$time = date_create_immutable( $utc . ' UTC' );
		return $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time->getTimestamp() ) : '';
	}
}
