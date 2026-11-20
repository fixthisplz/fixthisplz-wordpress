<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** The bugspot_settings option: defaults, merged read access and labels. Validation lives in Settings_Sanitizer. */
final class Settings {

	public const OPTION = 'bugspot_settings';

	/** Last get() result and the raw option value it was built from. @var array{saved:mixed,merged:array}|null */
	private static ?array $memo = null;

	/** Keeps the bugspot_manage capability in line with the "management" roles whenever the option is written. */
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
				'roles'      => array( 'administrator' ),
				'menu_badge' => true,
			),
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
				return __( 'Report a bug', 'bug-spotter' );
			case 'dialog.instructions':
				return __( 'Describe what went wrong and what you expected to happen.', 'bug-spotter' );
			case 'dialog.privacy_text':
				return __( 'Your report includes technical details about this page and your browser.', 'bug-spotter' );
			case 'button.label':
			case 'button.tooltip':
				return __( 'Report a bug', 'bug-spotter' );
		}
		return '';
	}
}
