<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'bugspot_settings';

	private const MAX_SELECTORS = 50;

	/** Keeps the bugspot_manage capability in line with the "management" roles whenever the option is written. */
	public static function register_hooks(): void {
		add_action( 'add_option_' . self::OPTION, array( self::class, 'sync_capabilities' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'sync_capabilities' ) );
	}

	/** Hook callback: reads the option as just saved (administrator is always kept). */
	public static function sync_capabilities(): void {
		$roles = (array) self::get()['management']['roles'];
		if ( ! in_array( 'administrator', $roles, true ) ) {
			$roles[] = 'administrator';
		}
		Installer::sync_capabilities( $roles );
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
		return self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
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

	/**
	 * register_setting() callback. Posted input is applied on top of the saved settings
	 * (a settings tab posts only its own fields), then every field is validated.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::get();
		if ( ! is_array( $input ) ) {
			return self::validate( $current );
		}

		// The secret field is displayed empty: blank means "keep the stored value".
		if ( isset( $input['captcha'] ) && is_array( $input['captcha'] ) ) {
			$secret = $input['captcha']['secret_key'] ?? '';
			if ( ! is_scalar( $secret ) || '' === trim( (string) $secret ) ) {
				unset( $input['captcha']['secret_key'] );
			}
		}

		return self::validate( self::merge( $current, $input ) );
	}

	/**
	 * @param array<string,mixed> $base Complete array whose shape is authoritative.
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	private static function merge( array $base, array $over ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $over ) ) {
				continue;
			}
			$value = $over[ $key ];
			if ( is_array( $default ) && ! self::is_list( $default ) ) {
				$base[ $key ] = is_array( $value ) ? self::merge( $default, $value ) : $default;
			} elseif ( is_array( $default ) ) {
				// Lists may also arrive as a newline-separated string (parsed in validate()).
				$base[ $key ] = is_array( $value ) || is_string( $value ) ? $value : $default;
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * @param array<string,mixed> $s Merged settings (values untrusted).
	 * @return array<string,mixed>
	 */
	private static function validate( array $s ): array {
		$d = self::defaults();

		$out            = array();
		$out['enabled'] = self::bool( $s['enabled'] );

		$v                          = $s['visibility'];
		$out['visibility']          = array(
			'roles'                 => self::roles( $v['roles'] ),
			'guests'                => self::bool( $v['guests'] ),
			'excluded_pages'        => self::page_ids( $v['excluded_pages'] ),
			'excluded_url_prefixes' => self::lines( $v['excluded_url_prefixes'] ),
			'hide_on_mobile'        => self::bool( $v['hide_on_mobile'] ),
			'show_in_admin'         => self::bool( $v['show_in_admin'] ),
		);

		$b  = $s['button'];
		$bd = $d['button'];
		$sc = $b['shadow_custom'];

		$out['button'] = array(
			'icon_type'               => self::enum( $b['icon_type'], array( 'dashicon', 'svg' ), $bd['icon_type'] ),
			'dashicon'                => self::dashicon( $b['dashicon'], $bd['dashicon'] ),
			'svg_attachment_id'       => absint( $b['svg_attachment_id'] ),
			'shape'                   => self::enum( $b['shape'], array( 'circle', 'rounded', 'pill' ), $bd['shape'] ),
			'label'                   => self::text_field( $b['label'] ),
			'tooltip'                 => self::text_field( $b['tooltip'] ),
			'size'                    => self::enum( $b['size'], array( 's', 'm', 'l' ), $bd['size'] ),
			'color_bg'                => self::color( $b['color_bg'], $bd['color_bg'] ),
			'color_fg'                => self::color( $b['color_fg'], $bd['color_fg'] ),
			'color_bg_hover'          => self::color( $b['color_bg_hover'], $bd['color_bg_hover'] ),
			'shadow'                  => self::enum( $b['shadow'], array( 'none', 'light', 'medium', 'strong', 'custom' ), $bd['shadow'] ),
			'shadow_custom'           => array(
				'x'      => self::int( $sc['x'], -100, 100, $bd['shadow_custom']['x'] ),
				'y'      => self::int( $sc['y'], -100, 100, $bd['shadow_custom']['y'] ),
				'blur'   => self::int( $sc['blur'], 0, 200, $bd['shadow_custom']['blur'] ),
				'spread' => self::int( $sc['spread'], -100, 100, $bd['shadow_custom']['spread'] ),
				'color'  => self::color( $sc['color'], $bd['shadow_custom']['color'] ),
			),
			'radius_mode'             => self::enum( $b['radius_mode'], array( 'preset', 'custom' ), $bd['radius_mode'] ),
			'radius_px'               => self::int( $b['radius_px'], 0, 200, $bd['radius_px'] ),
			'position'                => self::position( $b['position'], $bd['position'] ),
			'mobile_position_enabled' => self::bool( $b['mobile_position_enabled'] ),
			'position_mobile'         => self::position( $b['position_mobile'], $bd['position_mobile'] ),
			'z_index'                 => self::int( $b['z_index'], 0, 2147483647, $bd['z_index'] ),
		);

		$g                = $s['dialog'];
		$formats          = array_values( array_unique( array_intersect( (array) $g['formats'], array( 'md', 'json', 'txt' ) ) ) );
		$out['dialog']    = array(
			'title'            => self::text_field( $g['title'] ),
			'instructions'     => self::textarea( $g['instructions'] ),
			'privacy_text'     => self::textarea( $g['privacy_text'] ),
			'privacy_url'      => is_string( $g['privacy_url'] ) ? esc_url_raw( $g['privacy_url'] ) : '',
			'allow_screenshot' => self::bool( $g['allow_screenshot'] ),
			'ask_email_guests' => self::bool( $g['ask_email_guests'] ),
			'formats'          => array() === $formats ? $d['dialog']['formats'] : $formats,
		);

		$c               = $s['captcha'];
		$out['captcha']  = array(
			'provider'     => self::enum( $c['provider'], array( 'none', 'turnstile', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha' ), 'none' ),
			'site_key'     => self::text_field( $c['site_key'] ),
			'secret_key'   => self::text_field( $c['secret_key'] ),
			'v3_threshold' => is_numeric( $c['v3_threshold'] ) ? max( 0.0, min( 1.0, (float) $c['v3_threshold'] ) ) : $d['captcha']['v3_threshold'],
		);

		$n                      = $s['notifications'];
		$out['notifications']   = array(
			'enabled'           => self::bool( $n['enabled'] ),
			'recipients'        => self::emails( $n['recipients'] ),
			'attach_screenshot' => self::bool( $n['attach_screenshot'] ),
		);

		$p                    = $s['protection'];
		$out['protection']    = array(
			'per_hour'     => self::int( $p['per_hour'], 1, 1000, $d['protection']['per_hour'] ),
			'per_day'      => self::int( $p['per_day'], 1, 10000, $d['protection']['per_day'] ),
			'proxy_header' => self::enum( $p['proxy_header'], array( '', 'x-forwarded-for', 'cf-connecting-ip' ), '' ),
		);

		$x                 = $s['context'];
		$out['context']    = array(
			'console'             => self::bool( $x['console'] ),
			'network'             => self::bool( $x['network'] ),
			'sensitive_selectors' => self::lines( $x['sensitive_selectors'] ),
		);

		$t              = $s['data'];
		$out['data']    = array(
			'retention_months'    => self::int( $t['retention_months'], 0, 120, $d['data']['retention_months'] ),
			'delete_on_uninstall' => self::bool( $t['delete_on_uninstall'] ),
		);

		$m                   = $s['management'];
		$manage_roles        = self::roles( $m['roles'] );
		if ( ! in_array( 'administrator', $manage_roles, true ) ) {
			$manage_roles[] = 'administrator';
		}
		$out['management']   = array(
			'roles'      => $manage_roles,
			'menu_badge' => self::bool( $m['menu_badge'] ),
		);

		return $out;
	}

	/** @param mixed $v */
	private static function bool( $v ): bool {
		return is_string( $v ) ? filter_var( $v, FILTER_VALIDATE_BOOLEAN ) : (bool) $v;
	}

	/** @param mixed $v */
	private static function int( $v, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $v ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	/** @param mixed $v */
	private static function enum( $v, array $allowed, string $default ): string {
		return is_string( $v ) && in_array( $v, $allowed, true ) ? $v : $default;
	}

	/** @param mixed $v */
	private static function text_field( $v ): string {
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
	}

	/** @param mixed $v */
	private static function textarea( $v ): string {
		return is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
	}

	/** @param mixed $v */
	private static function dashicon( $v, string $default ): string {
		return is_string( $v ) && 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $v ) ? $v : $default;
	}

	/** Strict #rgb / #rrggbb / rgba(r,g,b,a); anything else falls back to the default (no CSS injection). @param mixed $v */
	private static function color( $v, string $default ): string {
		if ( ! is_string( $v ) ) {
			return $default;
		}
		$v = trim( $v );
		if ( 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v ) ) {
			return $v;
		}
		if ( 1 === preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0|1|0?\.\d{1,3}|1\.0{1,3})\s*\)$/', $v, $m )
			&& (int) $m[1] <= 255 && (int) $m[2] <= 255 && (int) $m[3] <= 255 ) {
			return $v;
		}
		return $default;
	}

	/** @param mixed $v @return array<string,mixed> */
	private static function position( $v, array $default ): array {
		$v      = is_array( $v ) ? $v : array();
		$v      = array_merge( $default, $v );
		$x_unit = self::enum( $v['x_unit'], array( 'px', '%' ), 'px' );
		$y_unit = self::enum( $v['y_unit'], array( 'px', '%' ), 'px' );
		return array(
			'anchor' => self::enum( $v['anchor'], array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'middle-right', 'middle-left', 'custom' ), 'bottom-right' ),
			'edge_x' => self::enum( $v['edge_x'], array( 'left', 'right' ), 'right' ),
			'edge_y' => self::enum( $v['edge_y'], array( 'top', 'bottom' ), 'bottom' ),
			'x'      => self::int( $v['x'], 0, '%' === $x_unit ? 100 : 10000, $default['x'] ),
			'x_unit' => $x_unit,
			'y'      => self::int( $v['y'], 0, '%' === $y_unit ? 100 : 10000, $default['y'] ),
			'y_unit' => $y_unit,
		);
	}

	/** @param mixed $v @return string[] */
	private static function roles( $v ): array {
		$known = array_keys( wp_roles()->get_names() );
		return array_values( array_unique( array_intersect( array_filter( (array) $v, 'is_string' ), $known ) ) );
	}

	/** @param mixed $v @return int[] */
	private static function page_ids( $v ): array {
		$ids = array_filter( array_map( 'absint', (array) $v ) );
		return array_values( array_unique( $ids ) );
	}

	/** One entry per line (array or newline-separated string), sanitized, max 50. @param mixed $v @return string[] */
	private static function lines( $v ): array {
		if ( is_string( $v ) ) {
			$v = preg_split( '/\R/', $v ) ?: array();
		}
		$out = array();
		foreach ( (array) $v as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = sanitize_text_field( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_SELECTORS );
	}

	/** @param mixed $v */
	private static function emails( $v ): string {
		$valid = array();
		foreach ( explode( ',', is_scalar( $v ) ? (string) $v : '' ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( '' !== $email && is_email( $email ) ) {
				$valid[] = $email;
			}
		}
		return implode( ', ', $valid );
	}
}
