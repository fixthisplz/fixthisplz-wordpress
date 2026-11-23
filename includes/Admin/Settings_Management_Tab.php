<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;
use FixThisPlz\Settings;

defined( 'ABSPATH' ) || exit;

/** "Tracking › Team and menu" view of the settings screen (spec 7.9): who manages the bugs, the name of one report, the name and place of their menu. */
final class Settings_Management_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$m = $s['management'];

		F::section( __( 'Who handles the reports', 'fixthisplz' ), __( 'Choose the roles that follow up the reports in the administration.', 'fixthisplz' ) );
		F::row_open( __( 'Who can manage bugs', 'fixthisplz' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Who can manage bugs', 'fixthisplz' ) . '</legend>';
		F::list_marker( array( 'management', 'roles' ) );
		foreach ( F::role_names() as $role => $label ) {
			// Administrators always keep access (the sanitizer enforces it): shown checked and locked.
			F::checkbox_item( array( 'management', 'roles' ), $role, 'administrator' === $role || in_array( $role, $m['roles'], true ), $label, 'administrator' === $role );
		}
		F::description( __( 'These roles can view the reports, assign them, change their status and priority, add notes, delete and export them. Only administrators can change these settings.', 'fixthisplz' ) );
		echo '</fieldset>';
		F::row_close();
		F::table_close();

		F::section( __( 'Menu and naming', 'fixthisplz' ), __( 'Name the reports and place their menu in the administration.', 'fixthisplz' ) );
		// The name of one report comes first: it shows everywhere (titles, e-mails, webhooks).
		self::label_row( __( 'Name of one report', 'fixthisplz' ), 'item_label', (string) $m['item_label'], __( 'Bug', 'fixthisplz' ), __( 'Used in the title of a report, such as "Bug #12", on its page and in the notification e-mail. Leave empty for the default name.', 'fixthisplz' ) );
		$menu_help = __( 'Used for the menu and the titles of the management pages. Leave empty for the default name.', 'fixthisplz' );
		self::label_row( __( 'Menu name', 'fixthisplz' ), 'menu_label', (string) $m['menu_label'], __( 'Bugs', 'fixthisplz' ), $menu_help );
		self::label_row( __( 'List sub-menu name', 'fixthisplz' ), 'list_label', (string) $m['list_label'], __( 'All bugs', 'fixthisplz' ), $menu_help );

		$positions = array();
		foreach ( Settings::menu_positions() as $position => $label ) {
			$positions[ (string) $position ] = $label;
		}
		F::select_row( __( 'Menu position', 'fixthisplz' ), array( 'management', 'menu_position' ), (string) Settings::menu_position( $m['menu_position'] ), $positions );

		F::row_open( __( 'Menu badge', 'fixthisplz' ) );
		F::checkbox( array( 'management', 'menu_badge' ), (bool) $m['menu_badge'], __( 'Show the number of new reports next to the menu name', 'fixthisplz' ) );
		F::row_close();
		F::table_close();
	}

	/** Text field of a name; the translated default name is the placeholder. */
	private static function label_row( string $label, string $key, string $value, string $default, string $help ): void {
		$path = array( 'management', $key );
		$id   = F::id( $path );
		F::row_open( $label, $id );
		F::input( 'text', $path, $value, array( 'class' => 'regular-text', 'maxlength' => (string) Settings::MENU_LABEL_MAX, 'placeholder' => $default ), true );
		F::description( $help, $id . '-desc' );
		F::row_close();
	}
}
