<?php

namespace Bug_Spotter;

use Bug_Spotter\Captcha\Factory;

defined( 'ABSPATH' ) || exit;

/** Turns untrusted settings input (form post, REST preview, stored option) into the complete, validated shape of Settings::defaults(). */
final class Settings_Sanitizer {

	private const MAX_SELECTORS = 50;

	/**
	 * register_setting() callback. Posted input is applied on top of the saved settings
	 * (a settings tab posts only its own fields), then every field is validated.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = Settings::get();
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
	public static function merge( array $base, array $over ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $over ) ) {
				continue;
			}
			$value = $over[ $key ];
			if ( is_array( $default ) && ! array_is_list( $default ) ) {
				$base[ $key ] = is_array( $value ) ? self::merge( $default, $value ) : $default;
			} elseif ( is_array( $default ) ) {
				// Lists may also arrive as a newline-separated string (parsed by the section validators).
				$base[ $key ] = is_array( $value ) || is_string( $value ) ? $value : $default;
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/** The administrator always keeps the management capability, whatever the settings say. @param string[] $roles @return string[] */
	public static function with_administrator( array $roles ): array {
		return in_array( 'administrator', $roles, true ) ? $roles : array_merge( $roles, array( 'administrator' ) );
	}

	/**
	 * The "button" block, complete and validated: every value is checked (colors by regex, numbers cast and
	 * clamped, enums from whitelists), missing keys take their default. Also used by Button_Style on output.
	 *
	 * @param mixed $b Possibly partial, untrusted.
	 * @return array<string,mixed>
	 */
	public static function validate_button( $b ): array {
		$bd = Settings::defaults()['button'];
		$b  = self::merge( $bd, is_array( $b ) ? $b : array() );
		$sc = $b['shadow_custom'];

		return array(
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
	}

	/**
	 * @param array<string,mixed> $s Merged settings (values untrusted).
	 * @return array<string,mixed>
	 */
	private static function validate( array $s ): array {
		$d = Settings::defaults();
		return array(
			'enabled'       => self::bool( $s['enabled'] ),
			'visibility'    => self::validate_visibility( $s['visibility'] ),
			'button'        => self::validate_button( $s['button'] ),
			'dialog'        => self::validate_dialog( $s['dialog'], $d['dialog'] ),
			'captcha'       => self::validate_captcha( $s['captcha'], $d['captcha'] ),
			'notifications' => self::validate_notifications( $s['notifications'] ),
			'protection'    => self::validate_protection( $s['protection'], $d['protection'] ),
			'context'       => self::validate_context( $s['context'] ),
			'data'          => self::validate_data( $s['data'], $d['data'] ),
			'management'    => self::validate_management( $s['management'] ),
			'statuses'      => Statuses::sanitize( $s['statuses'] ),
		);
	}

	/** @param array<string,mixed> $v @return array<string,mixed> */
	private static function validate_visibility( array $v ): array {
		return array(
			'roles'                 => self::roles( $v['roles'] ),
			'guests'                => self::bool( $v['guests'] ),
			'excluded_pages'        => self::page_ids( $v['excluded_pages'] ),
			'excluded_url_prefixes' => self::lines( $v['excluded_url_prefixes'] ),
			'hide_on_mobile'        => self::bool( $v['hide_on_mobile'] ),
			'show_in_admin'         => self::bool( $v['show_in_admin'] ),
		);
	}

	/** @param array<string,mixed> $g @param array<string,mixed> $d @return array<string,mixed> */
	private static function validate_dialog( array $g, array $d ): array {
		$formats = array_values( array_unique( array_intersect( (array) $g['formats'], array( 'md', 'json', 'txt' ) ) ) );
		return array(
			'title'            => self::text_field( $g['title'] ),
			'instructions'     => self::textarea( $g['instructions'] ),
			'privacy_text'     => self::textarea( $g['privacy_text'] ),
			'privacy_url'      => is_string( $g['privacy_url'] ) ? esc_url_raw( $g['privacy_url'] ) : '',
			'allow_screenshot' => self::bool( $g['allow_screenshot'] ),
			'capture_method'   => self::enum( $g['capture_method'], Screenshot_Store::METHODS, $d['capture_method'] ),
			'mask_capture'     => self::bool( $g['mask_capture'] ),
			'ask_email_guests' => self::bool( $g['ask_email_guests'] ),
			'formats'          => array() === $formats ? $d['formats'] : $formats,
		);
	}

	/** @param array<string,mixed> $c @param array<string,mixed> $d @return array<string,mixed> */
	private static function validate_captcha( array $c, array $d ): array {
		return array(
			'provider'     => self::enum( $c['provider'], array_merge( array( 'none' ), Factory::PROVIDERS ), 'none' ),
			'site_key'     => self::text_field( $c['site_key'] ),
			'secret_key'   => self::text_field( $c['secret_key'] ),
			'v3_threshold' => is_numeric( $c['v3_threshold'] ) ? max( 0.0, min( 1.0, (float) $c['v3_threshold'] ) ) : $d['v3_threshold'],
		);
	}

	/** @param array<string,mixed> $n @return array<string,mixed> */
	private static function validate_notifications( array $n ): array {
		return array(
			'enabled'           => self::bool( $n['enabled'] ),
			'recipients'        => self::emails( $n['recipients'] ),
			'attach_screenshot' => self::bool( $n['attach_screenshot'] ),
		);
	}

	/** @param array<string,mixed> $p @param array<string,mixed> $d @return array<string,mixed> */
	private static function validate_protection( array $p, array $d ): array {
		return array(
			'per_hour'     => self::int( $p['per_hour'], 1, 1000, $d['per_hour'] ),
			'per_day'      => self::int( $p['per_day'], 1, 10000, $d['per_day'] ),
			'proxy_header' => self::enum( $p['proxy_header'], array( '', 'x-forwarded-for', 'cf-connecting-ip' ), '' ),
		);
	}

	/** @param array<string,mixed> $x @return array<string,mixed> */
	private static function validate_context( array $x ): array {
		return array(
			'console'             => self::bool( $x['console'] ),
			'network'             => self::bool( $x['network'] ),
			'sensitive_selectors' => self::lines( $x['sensitive_selectors'] ),
		);
	}

	/** @param array<string,mixed> $t @param array<string,mixed> $d @return array<string,mixed> */
	private static function validate_data( array $t, array $d ): array {
		return array(
			'retention_months'    => self::int( $t['retention_months'], 0, 120, $d['retention_months'] ),
			'delete_on_uninstall' => self::bool( $t['delete_on_uninstall'] ),
		);
	}

	/** @param array<string,mixed> $m @return array<string,mixed> */
	private static function validate_management( array $m ): array {
		return array(
			'roles'      => self::with_administrator( self::roles( $m['roles'] ) ),
			'menu_badge' => self::bool( $m['menu_badge'] ),
		);
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

	/** Strict #rgb / #rrggbb, trimmed; null for anything else (safe in inline CSS). @param mixed $v */
	public static function hex_color( $v ): ?string {
		$v = is_string( $v ) ? trim( $v ) : '';
		return 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v ) ? $v : null;
	}

	/** Strict #rgb / #rrggbb / rgba(r,g,b,a); anything else falls back to the default (no CSS injection). @param mixed $v */
	private static function color( $v, string $default ): string {
		$hex = self::hex_color( $v );
		if ( null !== $hex ) {
			return $hex;
		}
		if ( ! is_string( $v ) ) {
			return $default;
		}
		$v = trim( $v );
		if ( 1 === preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0|1|0?\.\d{1,3}|1\.0{1,3})\s*\)$/', $v, $m )
			&& (int) $m[1] <= 255 && (int) $m[2] <= 255 && (int) $m[3] <= 255 ) {
			return $v;
		}
		return $default;
	}

	/** @param mixed $v @return array<string,mixed> */
	private static function position( $v, array $default ): array {
		$v      = array_merge( $default, is_array( $v ) ? $v : array() );
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
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
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
