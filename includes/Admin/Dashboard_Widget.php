<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Installer;
use Bug_Spotter\Report_Format;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** WordPress dashboard widget (spec 8), for the bug managers only: open reports per status and the latest reports. */
final class Dashboard_Widget {

	public const ID = 'bugspot_dashboard';

	private const LATEST         = 5;
	private const EXCERPT_LENGTH = 60;

	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register' ) );
	}

	public static function register(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}
		// The widget title is printed as HTML by WordPress.
		wp_add_dashboard_widget( self::ID, esc_html( Settings::text( 'management.menu_label' ) ), array( self::class, 'render' ) );
		wp_enqueue_style( 'bug-spotter-admin', BUGSPOT_URL . 'assets/css/admin.css', array(), BUGSPOT_VERSION );
	}

	public static function render(): void {
		$repo   = new Reports_Repository();
		$counts = $repo->count_by_status( array() );
		$closed = Statuses::closed();

		echo '<div class="bugspot-widget"><ul class="bugspot-widget-counts">';
		foreach ( array_keys( Statuses::all() ) as $status ) {
			if ( in_array( $status, $closed, true ) ) {
				continue;
			}
			printf(
				'<li><a href="%1$s">%2$s <span class="bugspot-count">%3$s</span></a></li>',
				esc_url( Bugs_Page::page_url( array( 'status' => $status ) ) ),
				Report_Format::pill( Statuses::class, $status ), // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				esc_html( number_format_i18n( $counts[ $status ] ?? 0 ) )
			);
		}
		echo '</ul>';

		$latest = $repo->query( array( 'per_page' => self::LATEST, 'with_email' => false ) )['items'];
		echo '<h3>' . esc_html__( 'Latest reports', 'bug-spotter' ) . '</h3>';
		if ( ! $latest ) {
			echo '<p class="bugspot-muted">' . esc_html__( 'No reports found.', 'bug-spotter' ) . '</p>';
		} else {
			echo '<ul class="bugspot-widget-latest">';
			foreach ( $latest as $item ) {
				$id = (int) $item['id'];
				printf(
					'<li><a href="%1$s">%2$s</a> %3$s <span class="bugspot-muted">%4$s</span></li>',
					esc_url( Detail_Page::url( $id ) ),
					esc_html( Report_Format::title( $id ) ),
					Report_Format::pill( Statuses::class, (string) $item['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
					esc_html( Report_Format::excerpt( trim( (string) preg_replace( '/\s+/u', ' ', (string) $item['description'] ) ), self::EXCERPT_LENGTH ) )
				);
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( Bugs_Page::page_url() ) . '">' . esc_html( Settings::text( 'management.list_label' ) ) . '</a></p></div>';
	}
}
