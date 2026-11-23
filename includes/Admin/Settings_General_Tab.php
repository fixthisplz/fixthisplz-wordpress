<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "General" tab of the settings screen: the summary, the main switch, who can report and where the button appears. */
final class Settings_General_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$v = $s['visibility'];

		Settings_Summary::render( $s );

		F::section( __( 'Activation', 'bug-spotter' ), __( 'Turn the report button on or off for the whole site.', 'bug-spotter' ), Settings_Summary::ACTIVATION );
		F::row_open( __( 'Bug reporting', 'bug-spotter' ) );
		F::checkbox( array( 'enabled' ), (bool) $s['enabled'], __( 'Show the bug report button', 'bug-spotter' ) );
		F::row_close();
		F::table_close();

		F::section( __( 'Who can report', 'bug-spotter' ), __( 'Choose who sees the button and can send a report.', 'bug-spotter' ), Settings_Summary::WHO );
		F::row_open( __( 'Who sees the button', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Who sees the button', 'bug-spotter' ) . '</legend>';
		F::list_marker( array( 'visibility', 'roles' ) );
		foreach ( F::role_names() as $role => $label ) {
			F::checkbox_item( array( 'visibility', 'roles' ), $role, in_array( $role, $v['roles'], true ), $label );
		}
		F::checkbox( array( 'visibility', 'guests' ), (bool) $v['guests'], __( 'Guests (not logged in)', 'bug-spotter' ) );
		if ( Settings_Page::no_spam_check( $s ) ) {
			// Shown while the box is ticked: the service is chosen in another tab, so the saved one is what counts.
			echo '<p class="description bugspot-warning"' . F::show_if( array( 'visibility', 'guests' ), '=', '1' ) . '>' . esc_html__( 'No spam check is configured: expect unwanted reports.', 'bug-spotter' ) . ' <a href="' . esc_url( Settings_Page::tab_url( 'spam' ) ) . '">' . esc_html__( 'Set up spam protection', 'bug-spotter' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- show_if() is escaped.
		}
		echo '</fieldset>';
		F::row_close();
		F::table_close();

		F::section( __( 'Where the button appears', 'bug-spotter' ), __( 'Hide the button on some pages, or also show it in the administration.', 'bug-spotter' ) );
		self::excluded_pages_row( $v );

		$id = F::id( array( 'visibility', 'excluded_url_prefixes' ) );
		F::row_open( __( 'Hidden on URLs starting with', 'bug-spotter' ), $id );
		F::textarea( array( 'visibility', 'excluded_url_prefixes' ), implode( "\n", $v['excluded_url_prefixes'] ), 4, '/checkout/', true );
		F::description( __( 'One path per line, for example /checkout/ or /my-account/. The button is hidden on every URL that starts with one of them.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Administration', 'bug-spotter' ) );
		F::checkbox( array( 'visibility', 'show_in_admin' ), (bool) $v['show_in_admin'], __( 'Also show the button in the WordPress administration', 'bug-spotter' ) );
		F::row_close();
		F::table_close();
	}

	/** @param array<string,mixed> $v */
	private static function excluded_pages_row( array $v ): void {
		$id    = F::id( array( 'visibility', 'excluded_pages' ) );
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'menu_order,post_title',
			)
		);
		$known = array();
		F::row_open( __( 'Excluded pages', 'bug-spotter' ), $id );
		F::list_marker( array( 'visibility', 'excluded_pages' ) );
		echo '<select multiple size="8" class="bugspot-wide" id="' . esc_attr( $id ) . '" name="' . esc_attr( F::name( array( 'visibility', 'excluded_pages' ) ) ) . '[]" aria-describedby="' . esc_attr( $id ) . '-desc">';
		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			$known[] = (int) $page->ID;
			F::option( (string) $page->ID, self::page_label( (int) $page->ID ), in_array( (int) $page->ID, $v['excluded_pages'], true ) );
		}
		// Saved pages that are no longer published stay selected rather than being dropped silently.
		foreach ( array_diff( $v['excluded_pages'], $known ) as $page_id ) {
			F::option( (string) $page_id, self::page_label( (int) $page_id ), true );
		}
		echo '</select>';
		F::description( __( 'Hold Ctrl (or Cmd) to select several pages, or to unselect one.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();
	}

	private static function page_label( int $page_id ): string {
		$title = get_the_title( $page_id );
		/* translators: %d: page ID. */
		return '' !== $title ? $title : sprintf( __( 'Page #%d (no title)', 'bug-spotter' ), $page_id );
	}
}
