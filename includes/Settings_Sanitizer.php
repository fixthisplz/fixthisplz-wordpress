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

		// The Statuses and Priorities tabs post editor rows (keys made or kept server-side) rather than the stored list.
		return self::validate( self::merge( $current, List_Editor::apply_post( $input ) ) );
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
	 * @param array<string,mixed> $s Merged settings (values untrusted).
	 * @return array<string,mixed>
	 */
	private static function validate( array $s ): array {
		$d = Settings::defaults();
		return array(
			'enabled'       => self::bool( $s['enabled'] ),
			'visibility'    => self::validate_visibility( $s['visibility'] ),
			'button'        => Button_Sanitizer::validate( $s['button'] ),
			'dialog'        => self::validate_dialog( $s['dialog'], $d['dialog'] ),
			'captcha'       => self::validate_captcha( $s['captcha'], $d['captcha'] ),
			'notifications' => self::validate_notifications( $s['notifications'] ),
			'protection'    => self::validate_protection( $s['protection'], $d['protection'] ),
			'context'       => self::validate_context( $s['context'] ),
			'data'          => self::validate_data( $s['data'], $d['data'] ),
			'management'    => self::validate_management( $s['management'] ),
			'statuses'      => Statuses::sanitize( $s['statuses'] ),
			'priorities'    => Priorities::sanitize( $s['priorities'] ),
			'webhooks'      => Webhooks::sanitize( $s['webhooks'] ),
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
			'roles'         => self::with_administrator( self::roles( $m['roles'] ) ),
			'menu_badge'    => self::bool( $m['menu_badge'] ),
			'menu_label'    => mb_substr( self::text_field( $m['menu_label'] ), 0, Settings::MENU_LABEL_MAX ),
			'list_label'    => mb_substr( self::text_field( $m['list_label'] ), 0, Settings::MENU_LABEL_MAX ),
			'item_label'    => mb_substr( self::text_field( $m['item_label'] ), 0, Settings::MENU_LABEL_MAX ),
			'menu_position' => Settings::menu_position( $m['menu_position'] ),
		);
	}

	/** A posted checkbox ("1"/"0", "on", "true"…) or a stored boolean. @param mixed $v */
	public static function bool( $v ): bool {
		return is_string( $v ) ? filter_var( $v, FILTER_VALIDATE_BOOLEAN ) : (bool) $v;
	}

	/** A number clamped to [$min, $max]; $default when not numeric. @param mixed $v */
	public static function int( $v, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $v ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	/** $v when it is one of $allowed, else $default. @param mixed $v */
	public static function enum( $v, array $allowed, string $default ): string {
		return is_string( $v ) && in_array( $v, $allowed, true ) ? $v : $default;
	}

	/** One line of plain text. @param mixed $v */
	public static function text_field( $v ): string {
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
	}

	/** @param mixed $v */
	private static function textarea( $v ): string {
		return is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
	}

	/** Strict #rgb / #rrggbb, trimmed; null for anything else (safe in inline CSS). @param mixed $v */
	public static function hex_color( $v ): ?string {
		$v = is_string( $v ) ? trim( $v ) : '';
		return 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v ) ? $v : null;
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
