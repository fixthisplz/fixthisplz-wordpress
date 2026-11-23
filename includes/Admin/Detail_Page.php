<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Priorities;
use FixThisPlz\Report_Format;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Settings;
use FixThisPlz\Statuses;
use FixThisPlz\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Single-report screen (its read-only parts are in Report_Context_View). Everything stored in a report comes from a
 * visitor and is untrusted: it is escaped at output. ip_hash and reporter_email_hash are internal and are never
 * displayed.
 */
final class Detail_Page {

	public const UPDATE_ACTION = 'fixthisplz_update_report';
	public const DELETE_ACTION = 'fixthisplz_delete_report';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( self::class, 'handle_update' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
		Activity_Box::register_hooks();
	}

	public static function handle_update(): void {
		$id = Request::posted_id( 'report' );
		check_admin_referer( self::UPDATE_ACTION . '_' . $id );
		Request::require_manager();

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$fields = array();
		foreach ( array( 'status', 'priority' ) as $key ) {
			if ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) {
				$fields[ $key ] = sanitize_key( $post[ $key ] );
			}
		}
		if ( isset( $post['assigned_to'] ) && is_scalar( $post['assigned_to'] ) ) {
			$fields['assigned_to'] = absint( $post['assigned_to'] );
		}
		// The repository rejects invalid values as a whole; an unknown id is a plain failure too. Nothing changed: no notice.
		$changes = ( new Reports_Repository() )->update( $id, $fields );
		$notice  = null === $changes ? array( 'fixthisplz_notice' => 'failed' ) : ( $changes ? array( 'fixthisplz_notice' => 'updated' ) : array() );
		wp_safe_redirect( Report_Format::link( $id, $notice ) );
		exit;
	}

	public static function handle_delete(): void {
		$id = Request::posted_id( 'report' );
		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		Request::require_manager();

		list( $type, $count ) = Bugs_Page::apply_bulk( 'delete', array( $id ) );
		wp_safe_redirect( Report_Format::list_link( Bugs_Page::notice_args( $type, $count ) ) );
		exit;
	}

	public static function render( int $id ): void {
		$repo = new Reports_Repository();
		// Not right after an update/failed redirect: choosing "New" on purpose must stick.
		if ( ! isset( $_GET['fixthisplz_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display logic only.
			$repo->mark_seen( $id ); // Before reading, so the page already shows "Seen".
		}
		$report = $repo->find( $id );

		echo '<div class="wrap fixthisplz-admin fixthisplz-detail">';
		if ( null === $report ) {
			echo '<h1>' . esc_html( Settings::text( 'management.menu_label' ) ) . '</h1>';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Report not found.', 'fixthisplz' ) . '</p></div>';
			self::render_back_link();
			echo '</div>';
			return;
		}

		$context = $report['context'];
		echo '<h1 class="wp-heading-inline">' . esc_html( Report_Format::title( $id ) ) . '</h1> ' . wp_kses( Report_Format::pill( Statuses::class, (string) $report['status'] ), Report_Format::PILL_HTML );
		echo '<hr class="wp-header-end">';
		self::render_back_link();
		self::render_notice();

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
		echo '<div id="post-body-content">';

		self::box( __( 'Description', 'fixthisplz' ), static function () use ( $report ): void {
			echo '<p class="fixthisplz-description">' . nl2br( esc_html( (string) $report['description'] ) ) . '</p>';
		} );
		self::box( __( 'Notes and history', 'fixthisplz' ), static fn() => Activity_Box::render( $id ) );
		self::box( __( 'Reporter', 'fixthisplz' ), static fn() => Report_Context_View::render_reporter( $report ) );
		self::box( __( 'Page', 'fixthisplz' ), static fn() => Report_Context_View::render_page( $report, $context ) );
		self::box( __( 'Element', 'fixthisplz' ), static fn() => Report_Context_View::render_element( $context['element'] ?? null ) );
		self::box( __( 'Browser', 'fixthisplz' ), static fn() => Report_Context_View::render_browser( $context ) );
		self::box( __( 'Console errors', 'fixthisplz' ), static fn() => Report_Context_View::render_console( $context['console'] ?? array() ) );
		self::box( __( 'Failed requests', 'fixthisplz' ), static fn() => Report_Context_View::render_network( $context['network'] ?? array() ) );
		self::box( __( 'Screenshot', 'fixthisplz' ), static fn() => Report_Context_View::render_screenshot( $report ) );
		self::box( __( 'Raw data', 'fixthisplz' ), static fn() => Report_Context_View::render_raw( $context ) );

		echo '</div><div id="postbox-container-1" class="postbox-container">';
		self::render_sidebar( $report );
		echo '</div></div></div></div>';
	}

	private static function render_back_link(): void {
		echo '<p><a href="' . esc_url( Report_Format::list_link() ) . '">&larr; ' . esc_html__( 'Back to the list', 'fixthisplz' ) . '</a></p>';
	}

	private static function render_notice(): void {
		$type     = isset( $_GET['fixthisplz_notice'] ) && is_string( $_GET['fixthisplz_notice'] ) ? sanitize_key( wp_unslash( $_GET['fixthisplz_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$messages = array(
			'updated'      => array( 'success', __( 'Report updated.', 'fixthisplz' ) ),
			'failed'       => array( 'error', __( 'The report could not be updated.', 'fixthisplz' ) ),
			'note_added'   => array( 'success', __( 'Note added.', 'fixthisplz' ) ),
			'note_deleted' => array( 'success', __( 'Note deleted.', 'fixthisplz' ) ),
			'note_failed'  => array( 'error', __( 'The note could not be added.', 'fixthisplz' ) ),
		);
		if ( isset( $messages[ $type ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $type ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $type ][1] ) . '</p></div>';
		}
	}

	private static function box( string $title, callable $body ): void {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div><div class="inside">';
		$body();
		echo '</div></div>';
	}

	private static function render_sidebar( array $report ): void {
		$id = (int) $report['id'];

		self::box(
			__( 'Status', 'fixthisplz' ),
			static function () use ( $report, $id ): void {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::UPDATE_ACTION ) . '">';
				echo '<input type="hidden" name="report" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( self::UPDATE_ACTION . '_' . $id );
				echo '<p><label for="fixthisplz-status"><strong>' . esc_html__( 'Status', 'fixthisplz' ) . '</strong></label><br>';
				$status   = (string) $report['status'];
				$priority = (string) $report['priority'];
				// A value unknown to the settings stays listed (under its key) until changed.
				self::select( 'status', 'fixthisplz-status', Statuses::all() + array( $status => Statuses::label( $status ) ), $status );
				echo '</p><p><label for="fixthisplz-priority-select"><strong>' . esc_html__( 'Priority', 'fixthisplz' ) . '</strong></label><br>';
				self::select( 'priority', 'fixthisplz-priority-select', Priorities::all() + array( $priority => Priorities::label( $priority ) ), $priority );
				echo '</p><p><label for="fixthisplz-assigned"><strong>' . esc_html__( 'Assigned to', 'fixthisplz' ) . '</strong></label><br>';
				$assignee = (int) $report['assigned_to'];
				// Like an unknown status: someone who left the team stays listed until the report is reassigned.
				$people = array( 0 => __( '— Unassigned —', 'fixthisplz' ) ) + Team::members() + ( $assignee > 0 ? array( $assignee => Team::name( $assignee ) ) : array() );
				self::select( 'assigned_to', 'fixthisplz-assigned', $people, (string) $assignee );
				echo '</p><p><button type="submit" class="button button-primary">' . esc_html__( 'Update', 'fixthisplz' ) . '</button></p></form>';
			}
		);

		self::box(
			__( 'Dates', 'fixthisplz' ),
			static fn() => Report_Context_View::render_pairs(
				array(
					__( 'Created', 'fixthisplz' )        => Report_Format::date( $report['created_at'] ?? null ),
					__( 'Seen', 'fixthisplz' )           => Report_Format::date( $report['seen_at'] ?? null ),
					__( 'Status changed', 'fixthisplz' ) => Report_Format::date( $report['status_changed_at'] ?? null ),
					__( 'Updated', 'fixthisplz' )        => Report_Format::date( $report['updated_at'] ?? null ),
				)
			)
		);

		self::box(
			__( 'Delete', 'fixthisplz' ),
			static function () use ( $id ): void {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Delete this report permanently? This cannot be undone.', 'fixthisplz' ) . '">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_ACTION ) . '">';
				echo '<input type="hidden" name="report" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( self::DELETE_ACTION . '_' . $id );
				echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete this report', 'fixthisplz' ) . '</button></form>';
			}
		);
	}

	private static function select( string $name, string $id, array $options, string $current ): void {
		printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $id ) );
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $key ), selected( $current, (string) $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}
