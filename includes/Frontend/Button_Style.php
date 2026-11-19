<?php

namespace Bug_Spotter\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * CSS of the floating button. Settings are already sanitized on save, but every value is validated
 * again here (colors by regex, numbers cast and clamped, enums from whitelists): nothing user-supplied
 * reaches the output unchecked.
 */
final class Button_Style {

	private const SELECTOR = '#bugspot-button';
	private const MOBILE   = '@media (max-width: 782px)';
	private const SIZES    = array(
		's' => 40,
		'm' => 52,
		'l' => 64,
	);
	private const SHADOWS  = array(
		'none'   => 'none',
		'light'  => '0 1px 3px rgba(0,0,0,.2)',
		'medium' => '0 4px 12px rgba(0,0,0,.25)',
		'strong' => '0 8px 24px rgba(0,0,0,.35)',
	);

	/** @param array<string,mixed> $button The "button" block of the settings. */
	public static function css( array $button, bool $hide_on_mobile = false ): string {
		$size   = self::SIZES[ self::enum( $button['size'] ?? 'm', array_keys( self::SIZES ), 'm' ) ];
		$shape  = self::enum( $button['shape'] ?? 'circle', array( 'circle', 'rounded', 'pill' ), 'circle' );
		$bg     = self::color( $button['color_bg'] ?? null, '#d63638' );
		$fg     = self::color( $button['color_fg'] ?? null, '#ffffff' );
		$hover  = self::color( $button['color_bg_hover'] ?? null, '#b32d2e' );
		$z      = self::int( $button['z_index'] ?? 99999, 0, 2147483647, 99999 );

		if ( 'custom' === ( $button['radius_mode'] ?? 'preset' ) ) {
			$radius = self::int( $button['radius_px'] ?? 8, 0, 200, 8 ) . 'px';
		} else {
			$radius = array(
				'circle'  => '50%',
				'rounded' => '12px',
				'pill'    => '999px',
			)[ $shape ];
		}

		$decl  = 'background:' . $bg . ';color:' . $fg . ';height:' . $size . 'px;';
		$decl .= 'pill' === $shape
			? 'min-width:' . $size . 'px;padding:0 ' . (int) round( $size * 0.4 ) . 'px;'
			: 'width:' . $size . 'px;padding:0;';
		$decl .= 'border-radius:' . $radius . ';box-shadow:' . self::shadow( $button ) . ';z-index:' . $z . ';';

		$position = is_array( $button['position'] ?? null ) ? $button['position'] : array();
		$css      = self::SELECTOR . '{' . $decl . self::position( $position ) . '}';
		$css     .= self::SELECTOR . ':hover{background:' . $hover . '}';

		if ( ! empty( $button['mobile_position_enabled'] ) && is_array( $button['position_mobile'] ?? null ) ) {
			$css .= self::MOBILE . '{' . self::SELECTOR . '{' . self::position( $button['position_mobile'] ) . '}}';
		}
		if ( $hide_on_mobile ) {
			$css .= self::MOBILE . '{' . self::SELECTOR . '{display:none}}';
		}
		return $css;
	}

	/** @param array<string,mixed> $button */
	private static function shadow( array $button ): string {
		$name = self::enum( $button['shadow'] ?? 'medium', array_merge( array_keys( self::SHADOWS ), array( 'custom' ) ), 'medium' );
		if ( 'custom' !== $name ) {
			return self::SHADOWS[ $name ];
		}
		$c = is_array( $button['shadow_custom'] ?? null ) ? $button['shadow_custom'] : array();
		return self::int( $c['x'] ?? 0, -100, 100, 0 ) . 'px '
			. self::int( $c['y'] ?? 4, -100, 100, 4 ) . 'px '
			. self::int( $c['blur'] ?? 12, 0, 200, 12 ) . 'px '
			. self::int( $c['spread'] ?? 0, -100, 100, 0 ) . 'px '
			. self::color( $c['color'] ?? null, 'rgba(0,0,0,0.25)' );
	}

	/**
	 * All four edges and the transform are always emitted, so a mobile position fully overrides the desktop one.
	 *
	 * @param array<string,mixed> $p
	 */
	private static function position( array $p ): string {
		$anchor = self::enum( $p['anchor'] ?? 'bottom-right', array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'middle-right', 'middle-left', 'custom' ), 'bottom-right' );
		$edges  = array(
			'top'    => 'auto',
			'right'  => 'auto',
			'bottom' => 'auto',
			'left'   => 'auto',
		);
		$transform = 'none';

		if ( 'custom' === $anchor ) {
			$x_unit = self::enum( $p['x_unit'] ?? 'px', array( 'px', '%' ), 'px' );
			$y_unit = self::enum( $p['y_unit'] ?? 'px', array( 'px', '%' ), 'px' );
			$edge_x = self::enum( $p['edge_x'] ?? 'right', array( 'left', 'right' ), 'right' );
			$edge_y = self::enum( $p['edge_y'] ?? 'bottom', array( 'top', 'bottom' ), 'bottom' );

			$edges[ $edge_x ] = self::int( $p['x'] ?? 24, 0, '%' === $x_unit ? 100 : 10000, 24 ) . $x_unit;
			$edges[ $edge_y ] = self::int( $p['y'] ?? 24, 0, '%' === $y_unit ? 100 : 10000, 24 ) . $y_unit;
		} else {
			list( $vertical, $horizontal ) = explode( '-', $anchor );
			$edges[ $horizontal ]          = '24px';
			if ( 'middle' === $vertical ) {
				$edges['top'] = '50%';
				$transform    = 'translateY(-50%)';
			} else {
				$edges[ $vertical ] = '24px';
			}
		}

		$out = '';
		foreach ( $edges as $edge => $value ) {
			$out .= $edge . ':' . $value . ';';
		}
		return $out . 'transform:' . $transform . ';';
	}

	/** @param mixed $v */
	private static function int( $v, int $min, int $max, int $default ): int {
		return is_numeric( $v ) ? max( $min, min( $max, (int) $v ) ) : $default;
	}

	/** @param mixed $v @param string[] $allowed */
	private static function enum( $v, array $allowed, string $default ): string {
		return is_string( $v ) && in_array( $v, $allowed, true ) ? $v : $default;
	}

	/** @param mixed $v */
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
}
