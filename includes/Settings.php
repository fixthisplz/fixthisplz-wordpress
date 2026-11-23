<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** The fixthisplz_settings option: defaults, merged read access and labels. Validation lives in Settings_Sanitizer. */
final class Settings {

	public const OPTION = 'fixthisplz_settings';

	public const MENU_LABEL_MAX = 40;
	public const MENU_POSITION  = 26;

	/** Last get() result and the raw option value it was built from. @var array{saved:mixed,merged:array}|null */
	private static ?array $memo = null;

	/** Keeps the fixthisplz_manage capability in line with the "management" roles whenever the option is written. */
	public static function register_hooks(): void {
		add_action( 'add_option_' . self::OPTION, array( self::class, 'sync_capabilities' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'sync_capabilities' ) );
	}

	/** Hook callback and activation: grants the capability to the saved "management" roles. */
	public static function sync_capabilities(): void {
		Installer::sync_capabilities( Settings_Sanitizer::with_administrator( (array) self::get()['management']['roles'] ) );
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		$position = array(
			'anchor'  => 'bottom-right',
			'edge_x'  => 'right',
			'edge_y'  => 'bottom',
			'x'       => 24,
			'x_unit'  => 'px',
			'y'       => 24,
			'y_unit'  => 'px',
		);

		return array(
			'enabled'       => false,
			'visibility'    => array(
				'roles'                 => array( 'administrator' ),
				'guests'                => false,
				'excluded_pages'        => array(),
				'excluded_url_prefixes' => array(),
				'hide_on_mobile'        => false,
				'show_in_admin'         => false,
			),
			'button'        => array(
				'icon_type'               => 'dashicon',
				'dashicon'                => 'dashicons-flag',
				'svg_attachment_id'       => 0,
				'shape'                   => 'circle',
				'label'                   => '',
				'tooltip'                 => '',
				'size'                    => 'm',
				'color_bg'                => '#d63638',
				'color_fg'                => '#ffffff',
				'color_bg_hover'          => '#b32d2e',
				'shadow'                  => 'medium',
				'shadow_custom'           => array(
					'x'      => 0,
					'y'      => 4,
					'blur'   => 12,
					'spread' => 0,
					'color'  => 'rgba(0,0,0,0.25)',
				),
				'radius_mode'             => 'preset',
				'radius_px'               => 8,
				'position'                => $position,
				'mobile_position_enabled' => false,
				'position_mobile'         => $position,
				'z_index'                 => 99999,
			),
			'dialog'        => array(
				'title'            => '',
				'instructions'     => '',
				'privacy_text'     => '',
				'privacy_url'      => '',
				'allow_screenshot' => true,
				'capture_method'   => 'tab',
				'mask_capture'     => false,
				'ask_email_guests' => true,
				'formats'          => array( 'md', 'json', 'txt' ),
			),
			'captcha'       => array(
				'provider'     => 'none',
				'site_key'     => '',
				'secret_key'   => '',
				'v3_threshold' => 0.5,
			),
			'notifications' => array(
				'enabled'           => true,
				'recipients'        => '',
				'attach_screenshot' => false,
			),
			'protection'    => array(
				'per_hour'     => 5,
				'per_day'      => 20,
				'proxy_header' => '',
			),
			'context'       => array(
				'console'             => true,
				'network'             => true,
				'sensitive_selectors' => array(),
			),
			'data'          => array(
				'retention_months'    => 0,
				'delete_on_uninstall' => false,
			),
			'management'    => array(
				'roles'         => array( 'administrator' ),
				'menu_badge'    => true,
				'menu_label'    => '',
				'list_label'    => '',
				'item_label'    => '',
				'menu_position' => self::MENU_POSITION,
			),
			// Ordered list (Kanban columns, selectors); an empty label shows the translated default name.
			'statuses'      => array(
				array( 'key' => 'new', 'label' => '', 'color' => '#2271b1', 'closed' => false ),
				array( 'key' => 'seen', 'label' => '', 'color' => '#50575e', 'closed' => false ),
				array( 'key' => 'confirmed', 'label' => '', 'color' => '#50575e', 'closed' => false ),
				array( 'key' => 'in_progress', 'label' => '', 'color' => '#50575e', 'closed' => false ),
				array( 'key' => 'fixed', 'label' => '', 'color' => '#008a20', 'closed' => true ),
				array( 'key' => 'wont_fix', 'label' => '', 'color' => '#8c8f94', 'closed' => true ),
				array( 'key' => 'duplicate', 'label' => '', 'color' => '#8c8f94', 'closed' => true ),
			),
			// Lowest first: the order sorting by priority follows.
			'priorities'    => array(
				array( 'key' => 'low', 'label' => '', 'color' => '#787c82' ),
				array( 'key' => 'normal', 'label' => '', 'color' => '#2271b1' ),
				array( 'key' => 'high', 'label' => '', 'color' => '#b26200' ),
				array( 'key' => 'critical', 'label' => '', 'color' => '#d63638' ),
			),
			// Rows of Webhooks::sanitize(), at most Webhooks::MAX.
			'webhooks'      => array(),
		);
	}

	/**
	 * Saved option merged on the defaults. Lists are replaced, not merged by index;
	 * unknown keys are dropped.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		// Rebuilt only when the stored value differs, so any write, filter or site switch is seen at once.
		if ( null === self::$memo || self::$memo['saved'] !== $saved ) {
			self::$memo = array(
				'saved'  => $saved,
				'merged' => Settings_Sanitizer::merge( self::defaults(), is_array( $saved ) ? $saved : array() ),
			);
		}
		return self::$memo['merged'];
	}

	/** @return array<int,string> The named admin-menu positions offered in Settings › Tracking › Team and menu: position => label. */
	public static function menu_positions(): array {
		return array(
			3  => __( 'After Dashboard', 'fixthisplz' ),
			6  => __( 'After Posts', 'fixthisplz' ),
			11 => __( 'After Media', 'fixthisplz' ),
			21 => __( 'After Pages', 'fixthisplz' ),
			26 => __( 'After Comments', 'fixthisplz' ),
			61 => __( 'After Appearance', 'fixthisplz' ),
			66 => __( 'After Plugins', 'fixthisplz' ),
			71 => __( 'After Users', 'fixthisplz' ),
			76 => __( 'After Tools', 'fixthisplz' ),
			81 => __( 'After Settings', 'fixthisplz' ),
		);
	}

	/** A position of menu_positions(), or the default for anything else. @param mixed $v */
	public static function menu_position( $v ): int {
		return is_numeric( $v ) && array_key_exists( (int) $v, self::menu_positions() ) ? (int) $v : self::MENU_POSITION;
	}

	/** Label for a text setting ("section.key"), falling back to the translated default. */
	public static function text( string $key ): string {
		$parts = explode( '.', $key, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		$value = self::get()[ $parts[0] ][ $parts[1] ] ?? '';
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		switch ( $key ) {
			case 'dialog.title':
				return __( 'Report a bug', 'fixthisplz' );
			case 'dialog.instructions':
				return __( 'Describe what went wrong and what you expected to happen.', 'fixthisplz' );
			case 'dialog.privacy_text':
				return __( 'Your report includes technical details about this page and your browser.', 'fixthisplz' );
			case 'management.menu_label':
				return __( 'Bugs', 'fixthisplz' );
			case 'management.list_label':
				return __( 'All bugs', 'fixthisplz' );
			case 'management.item_label':
				return __( 'Bug', 'fixthisplz' );
			case 'button.label':
			case 'button.tooltip':
				return __( 'Report a bug', 'fixthisplz' );
		}
		return '';
	}
}
