<?php

namespace FixThisPlz\Admin;

use FixThisPlz\List_Editor;
use FixThisPlz\Priorities;
use FixThisPlz\Settings;
use FixThisPlz\Settings_Sanitizer;
use FixThisPlz\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Bugs › Settings (spec 7): one Settings API option, seven tabs (the Tracking tab has three views), one <form> per
 * tab or view that posts only its own fields. Settings_Sanitizer::sanitize() merges the post on the saved settings, so the other tabs are kept.
 * Every checkbox posts a hidden "0" first and every list posts an empty marker first, so "all unchecked"
 * reaches the sanitizer as a value instead of being missing.
 */
final class Settings_Page {

	public const GROUP = 'fixthisplz_settings_group';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_notices', array( self::class, 'guest_warning' ) );
		List_Editor::register_hooks();
	}

	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings_Sanitizer::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/** Slugs of the tabs before the 2026-11-23 redesign => [tab, view]: bookmarks and old links keep working. */
	private const ALIASES = array(
		'visibility'    => array( 'general', '' ),
		'dialog'        => array( 'form', '' ),
		'captcha'       => array( 'spam', '' ),
		'protection'    => array( 'spam', '' ),
		'notifications' => array( 'alerts', '' ),
		'integrations'  => array( 'alerts', '' ),
		'context'       => array( 'privacy', '' ),
		'data'          => array( 'privacy', '' ),
		'management'    => array( 'tracking', 'team' ),
		'statuses'      => array( 'tracking', 'statuses' ),
		'priorities'    => array( 'tracking', 'priorities' ),
	);

	/** @return array<string,string> Tab slug => label, in display order. */
	public static function tabs(): array {
		return array(
			'general'  => __( 'General', 'fixthisplz' ),
			'button'   => __( 'Button', 'fixthisplz' ),
			'form'     => __( 'Form', 'fixthisplz' ),
			'tracking' => __( 'Tracking', 'fixthisplz' ),
			'alerts'   => __( 'Alerts', 'fixthisplz' ),
			'spam'     => __( 'Spam protection', 'fixthisplz' ),
			'privacy'  => __( 'Privacy', 'fixthisplz' ),
		);
	}

	/** @return array<string,string> Views of the Tracking tab (one form each: admin-lists.js handles one list per page). */
	public static function tracking_views(): array {
		return array(
			'team'       => __( 'Team and menu', 'fixthisplz' ),
			'statuses'   => __( 'Statuses', 'fixthisplz' ),
			'priorities' => __( 'Priorities', 'fixthisplz' ),
		);
	}

	public static function current_tab(): string {
		return self::current()[0];
	}

	/** The Tracking view shown ('' on the other tabs). */
	public static function current_view(): string {
		return self::current()[1];
	}

	/** @return array{0:string,1:string} [tab, view] asked by the URL, old slugs translated. */
	private static function current(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable
		if ( isset( self::ALIASES[ $tab ] ) ) {
			list( $tab, $alias_view ) = self::ALIASES[ $tab ];
			$view                     = '' !== $alias_view ? $alias_view : $view;
		}
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			$tab = 'general';
		}
		return array( $tab, self::view_of( $tab, $view ) );
	}

	/** A valid view for $tab: the asked Tracking view, else the first one; '' for the other tabs. */
	private static function view_of( string $tab, string $view ): string {
		if ( 'tracking' !== $tab ) {
			return '';
		}
		return array_key_exists( $view, self::tracking_views() ) ? $view : 'team';
	}

	/** Address of a tab (and Tracking view) of this screen. */
	public static function tab_url( string $tab, string $view = '' ): string {
		$args = array(
			'page' => Menu::SETTINGS_SLUG,
			'tab'  => $tab,
		);
		$view = self::view_of( $tab, $view );
		if ( '' !== $view ) {
			$args['view'] = $view;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'fixthisplz' ), 403 );
		}
		list( $tab, $view ) = self::current();

		echo '<div class="wrap fixthisplz-admin fixthisplz-settings">';
		echo '<h1>' . esc_html__( 'FixThisPlz settings', 'fixthisplz' ) . '</h1>';
		settings_errors();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'fixthisplz' ) . '">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::tab_url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( 'tracking' === $tab ) {
			self::tracking_nav( $view );
		}

		self::render_tab_form( $tab, Settings::get(), $view );
		echo '</div>';
	}

	/** Sub-navigation of the Tracking tab (WordPress "subsubsub" links). */
	private static function tracking_nav( string $view ): void {
		$views = self::tracking_views();
		$last  = array_key_last( $views );
		echo '<nav class="fixthisplz-subnav" aria-label="' . esc_attr__( 'Tracking pages', 'fixthisplz' ) . '"><ul class="subsubsub">';
		foreach ( $views as $slug => $label ) {
			// The separator sits inside the item, like the WordPress core views (WP_List_Table::views()).
			printf(
				'<li><a href="%1$s"%2$s>%3$s</a>%4$s</li>',
				esc_url( self::tab_url( 'tracking', $slug ) ),
				$slug === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$slug === $last ? '' : ' |'
			);
		}
		echo '</ul></nav>';
	}

	/**
	 * @param array<string,mixed> $s    Current settings.
	 * @param string              $view Tracking view; ignored on the other tabs.
	 */
	public static function render_tab_form( string $tab, array $s, string $view = '' ): void {
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			return;
		}
		$view = self::view_of( $tab, $view );
		echo '<form method="post" action="options.php" class="fixthisplz-settings-form" data-tab="' . esc_attr( $tab ) . '">';
		// settings_fields(), except that the page shown after saving is the canonical address of this tab (not an old slug).
		echo '<input type="hidden" name="option_page" value="' . esc_attr( self::GROUP ) . '"><input type="hidden" name="action" value="update">';
		wp_nonce_field( self::GROUP . '-options', '_wpnonce', false );
		echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_make_link_relative( self::tab_url( $tab, $view ) ) ) . '">';
		match ( $tab ) {
			'general'  => Settings_General_Tab::render( $s ),
			'button'   => Settings_Button_Tab::render( $s ),
			'form'     => Settings_Form_Tab::render( $s ),
			'tracking' => match ( $view ) {
				'statuses'   => Settings_List_Tab::render( Statuses::class, $s ),
				'priorities' => Settings_List_Tab::render( Priorities::class, $s ),
				default      => Settings_Management_Tab::render( $s ),
			},
			'alerts'   => Settings_Alerts_Tab::render( $s ),
			'spam'     => Settings_Spam_Tab::render( $s ),
			'privacy'  => Settings_Privacy_Tab::render( $s ),
		};
		submit_button();
		echo '</form>';
		if ( 'spam' === $tab ) {
			// The provider widget adds its own hidden inputs: kept outside the form so they are never posted.
			echo '<div class="fixthisplz-captcha-widget"></div>';
		}
	}

	/**
	 * Whether guests (not logged in) can send reports with no spam check: the warning of guest_warning() and of the
	 * summary of the General tab.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	public static function guests_unprotected( array $s ): bool {
		return ! empty( $s['enabled'] ) && ! empty( $s['visibility']['guests'] ) && self::no_spam_check( $s );
	}

	/** Whether no spam check service is chosen (the warning under the "Guests" box of the General tab). @param array<string,mixed> $s */
	public static function no_spam_check( array $s ): bool {
		return 'none' === $s['captcha']['provider'];
	}

	/** Permanent warning on the plugin screens while guests can report without any anti-bot (spec 7). */
	public static function guest_warning(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( Menu::SLUG !== $page && Menu::SETTINGS_SLUG !== $page ) {
			return;
		}
		if ( ! self::guests_unprotected( Settings::get() ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Guests (not logged in) can send bug reports, but no spam check is configured. Expect spam.', 'fixthisplz' );
		if ( current_user_can( 'manage_options' ) ) {
			echo ' <a href="' . esc_url( self::tab_url( 'spam' ) ) . '">' . esc_html__( 'Set up spam protection', 'fixthisplz' ) . '</a>';
		}
		echo '</p></div>';
	}
}
