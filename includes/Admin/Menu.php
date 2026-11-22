<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Installer;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** Registers the plugin's top-level menu (name and position from the settings) and its sub-menus. */
final class Menu {

	public const SLUG          = 'bug-spotter';
	public const SETTINGS_SLUG = 'bug-spotter-settings';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_filter( 'set_screen_option_' . Bugs_Page::PER_PAGE_OPTION, array( Bugs_Page::class, 'save_screen_option' ), 10, 3 );
		Csv_Export::register_hooks();
		Dashboard_Widget::register_hooks();
		Detail_Page::register_hooks();
		Settings_Page::register_hooks();
	}

	public static function register(): void {
		// Page titles are escaped by WordPress; menu titles are printed as HTML, so they are escaped here.
		$list = Settings::text( 'management.list_label' );
		$hook = add_menu_page(
			Settings::text( 'management.menu_label' ),
			self::menu_title(),
			Installer::CAPABILITY,
			self::SLUG,
			array( Bugs_Page::class, 'render' ),
			'dashicons-flag',
			Settings::menu_position( Settings::get()['management']['menu_position'] )
		);
		add_submenu_page(
			self::SLUG,
			$list,
			esc_html( $list ),
			Installer::CAPABILITY,
			self::SLUG,
			array( Bugs_Page::class, 'render' )
		);
		// Settings can change who may manage bugs, so they stay behind a core capability.
		add_submenu_page(
			self::SLUG,
			__( 'Bug Spotter settings', 'bug-spotter' ),
			__( 'Settings', 'bug-spotter' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( self::class, 'render_settings' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( Bugs_Page::class, 'on_load' ) );
		}
	}

	/** Menu label, with the "new reports" bubble when enabled and non-zero. */
	public static function menu_title(): string {
		$title = esc_html( Settings::text( 'management.menu_label' ) );
		if ( empty( Settings::get()['management']['menu_badge'] ) || ! current_user_can( Installer::CAPABILITY ) ) {
			return $title;
		}
		$new = ( new Reports_Repository() )->count_by_status( array() )['new'] ?? 0;
		if ( $new < 1 ) {
			return $title;
		}
		return sprintf(
			'%1$s <span class="awaiting-mod count-%2$d"><span class="pending-count">%2$d</span></span>',
			$title,
			$new
		);
	}

	public static function render_settings(): void {
		Settings_Page::render();
	}

	public static function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( self::SLUG !== $page && self::SETTINGS_SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'bug-spotter-admin', BUGSPOT_URL . 'assets/css/admin.css', array(), BUGSPOT_VERSION );

		// The board script is only needed on the Kanban view of the list (not on a detail page).
		$kanban = isset( $_GET['view'] ) && 'kanban' === $_GET['view'] && ! isset( $_GET['report'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( self::SLUG === $page && $kanban ) {
			Kanban::enqueue_assets();
		}
		if ( self::SETTINGS_SLUG === $page && current_user_can( 'manage_options' ) ) {
			Settings_Assets::enqueue();
		}
	}
}
