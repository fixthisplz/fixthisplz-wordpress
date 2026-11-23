<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** The "button" block of the settings (spec 7.2): every value checked, so that it is safe in inline CSS. */
final class Button_Sanitizer {

	/**
	 * The "button" block, complete and validated: every value is checked (colors by regex, numbers cast and
	 * clamped, enums from whitelists), missing keys take their default. Also used by Frontend\Button_Style on output.
	 *
	 * @param mixed $b Possibly partial, untrusted.
	 * @return array<string,mixed>
	 */
	public static function validate( $b ): array {
		$bd = Settings::defaults()['button'];
		$b  = Settings_Sanitizer::merge( $bd, is_array( $b ) ? $b : array() );
		$sc = $b['shadow_custom'];

		return array(
			'icon_type'               => Settings_Sanitizer::enum( $b['icon_type'], array( 'dashicon', 'svg' ), $bd['icon_type'] ),
			'dashicon'                => self::dashicon( $b['dashicon'], $bd['dashicon'] ),
			'svg_attachment_id'       => absint( $b['svg_attachment_id'] ),
			'shape'                   => Settings_Sanitizer::enum( $b['shape'], array( 'circle', 'rounded', 'pill' ), $bd['shape'] ),
			'label'                   => Settings_Sanitizer::text_field( $b['label'] ),
			'tooltip'                 => Settings_Sanitizer::text_field( $b['tooltip'] ),
			'size'                    => Settings_Sanitizer::enum( $b['size'], array( 's', 'm', 'l' ), $bd['size'] ),
			'color_bg'                => self::color( $b['color_bg'], $bd['color_bg'] ),
			'color_fg'                => self::color( $b['color_fg'], $bd['color_fg'] ),
			'color_bg_hover'          => self::color( $b['color_bg_hover'], $bd['color_bg_hover'] ),
			'shadow'                  => Settings_Sanitizer::enum( $b['shadow'], array( 'none', 'light', 'medium', 'strong', 'custom' ), $bd['shadow'] ),
			'shadow_custom'           => array(
				'x'      => Settings_Sanitizer::int( $sc['x'], -100, 100, $bd['shadow_custom']['x'] ),
				'y'      => Settings_Sanitizer::int( $sc['y'], -100, 100, $bd['shadow_custom']['y'] ),
				'blur'   => Settings_Sanitizer::int( $sc['blur'], 0, 200, $bd['shadow_custom']['blur'] ),
				'spread' => Settings_Sanitizer::int( $sc['spread'], -100, 100, $bd['shadow_custom']['spread'] ),
				'color'  => self::color( $sc['color'], $bd['shadow_custom']['color'] ),
			),
			'radius_mode'             => Settings_Sanitizer::enum( $b['radius_mode'], array( 'preset', 'custom' ), $bd['radius_mode'] ),
			'radius_px'               => Settings_Sanitizer::int( $b['radius_px'], 0, 200, $bd['radius_px'] ),
			'position'                => self::position( $b['position'], $bd['position'] ),
			'mobile_position_enabled' => Settings_Sanitizer::bool( $b['mobile_position_enabled'] ),
			'position_mobile'         => self::position( $b['position_mobile'], $bd['position_mobile'] ),
			'z_index'                 => Settings_Sanitizer::int( $b['z_index'], 0, 2147483647, $bd['z_index'] ),
		);
	}

	/** @param mixed $v */
	private static function dashicon( $v, string $default ): string {
		return is_string( $v ) && 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $v ) ? $v : $default;
	}

	/** Strict #rgb / #rrggbb / rgba(r,g,b,a); anything else falls back to the default (no CSS injection). @param mixed $v */
	private static function color( $v, string $default ): string {
		$hex = Settings_Sanitizer::hex_color( $v );
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
		$x_unit = Settings_Sanitizer::enum( $v['x_unit'], array( 'px', '%' ), 'px' );
		$y_unit = Settings_Sanitizer::enum( $v['y_unit'], array( 'px', '%' ), 'px' );
		return array(
			'anchor' => Settings_Sanitizer::enum( $v['anchor'], array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'middle-right', 'middle-left', 'custom' ), 'bottom-right' ),
			'edge_x' => Settings_Sanitizer::enum( $v['edge_x'], array( 'left', 'right' ), 'right' ),
			'edge_y' => Settings_Sanitizer::enum( $v['edge_y'], array( 'top', 'bottom' ), 'bottom' ),
			'x'      => Settings_Sanitizer::int( $v['x'], 0, '%' === $x_unit ? 100 : 10000, $default['x'] ),
			'x_unit' => $x_unit,
			'y'      => Settings_Sanitizer::int( $v['y'], 0, '%' === $y_unit ? 100 : 10000, $default['y'] ),
			'y_unit' => $y_unit,
		);
	}
}
