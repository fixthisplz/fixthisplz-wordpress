<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bugs › Settings (spec 7): one Settings API option, one tab per block, one <form> per tab that posts only
 * that tab's fields. Settings::sanitize() merges the post on the saved settings, so the other tabs are kept.
 * Every checkbox posts a hidden "0" first and every list posts an empty marker first, so "all unchecked"
 * reaches the sanitizer as a value instead of being missing.
 */
final class Settings_Page {

	public const GROUP = 'bugspot_settings_group';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_notices', array( self::class, 'guest_warning' ) );
	}

	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/** @return array<string,string> Tab slug => label, in display order. */
	public static function tabs(): array {
		return array(
			'visibility'    => __( 'Visibility', 'bug-spotter' ),
			'button'        => __( 'Button', 'bug-spotter' ),
			'dialog'        => __( 'Dialog', 'bug-spotter' ),
			'captcha'       => __( 'Anti-bot', 'bug-spotter' ),
			'notifications' => __( 'Notifications', 'bug-spotter' ),
			'protection'    => __( 'Protection', 'bug-spotter' ),
			'context'       => __( 'Collected context', 'bug-spotter' ),
			'data'          => __( 'Data and privacy', 'bug-spotter' ),
			'management'    => __( 'Management', 'bug-spotter' ),
		);
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'visibility';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'bug-spotter' ), 403 );
		}
		$tab = self::current_tab();

		echo '<div class="wrap bugspot-admin bugspot-settings">';
		echo '<h1>' . esc_html__( 'Bug Spotter settings', 'bug-spotter' ) . '</h1>';
		settings_errors();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'bug-spotter' ) . '">';
		foreach ( self::tabs() as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => Menu::SETTINGS_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				$slug === $tab ? ' nav-tab-active' : '',
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		self::render_tab_form( $tab, Settings::get() );
		echo '</div>';
	}

	/** @param array<string,mixed> $s Current settings. */
	public static function render_tab_form( string $tab, array $s ): void {
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			return;
		}
		echo '<form method="post" action="options.php" class="bugspot-settings-form" data-tab="' . esc_attr( $tab ) . '">';
		settings_fields( self::GROUP );
		match ( $tab ) {
			'visibility'    => Settings_Tabs::visibility( $s ),
			'button'        => Settings_Button_Tab::render( $s ),
			'dialog'        => Settings_Tabs::dialog( $s ),
			'captcha'       => Settings_Tabs::captcha( $s ),
			'notifications' => Settings_Tabs::notifications( $s ),
			'protection'    => Settings_Tabs::protection( $s ),
			'context'       => Settings_Tabs::context( $s ),
			'data'          => Settings_Tabs::data( $s ),
			'management'    => Settings_Tabs::management( $s ),
		};
		submit_button();
		echo '</form>';
		if ( 'captcha' === $tab ) {
			// The provider widget adds its own hidden inputs: kept outside the form so they are never posted.
			echo '<div class="bugspot-captcha-widget"></div>';
		}
	}

	/** Permanent warning on the plugin screens while guests can report without any anti-bot (spec 7). */
	public static function guest_warning(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( Menu::SLUG !== $page && Menu::SETTINGS_SLUG !== $page ) {
			return;
		}
		$s = Settings::get();
		if ( empty( $s['enabled'] ) || empty( $s['visibility']['guests'] ) || 'none' !== $s['captcha']['provider'] ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Guests (not logged in) can send bug reports, but no anti-bot check is configured. Expect spam.', 'bug-spotter' );
		if ( current_user_can( 'manage_options' ) ) {
			$url = add_query_arg(
				array(
					'page' => Menu::SETTINGS_SLUG,
					'tab'  => 'captcha',
				),
				admin_url( 'admin.php' )
			);
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure an anti-bot provider', 'bug-spotter' ) . '</a>';
		}
		echo '</p></div>';
	}
}
