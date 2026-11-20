<?php

namespace Bug_Spotter\Frontend;

use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * CSS of the floating button. The settings are validated again here, by the same Settings::validate_button()
 * as a save: nothing user-supplied reaches the output unchecked, and this class only formats.
 */
final class Button_Style {

	/** Below this width the mobile position and "hide on mobile" apply (also sent to the loader). */
	public const MOBILE_QUERY = '(max-width: 782px)';

	private const SELECTOR = '#bugspot-button';
	private const SIZES    = array(
		's' => 40,
		'm' => 52,
		'l' => 64,
	);
	private const RADII    = array(
		'circle'  => '50%',
		'rounded' => '12px',
		'pill'    => '999px',
	);
	private const SHADOWS  = array(
		'none'   => 'none',
		'light'  => '0 1px 3px rgba(0,0,0,.2)',
		'medium' => '0 4px 12px rgba(0,0,0,.25)',
		'strong' => '0 8px 24px rgba(0,0,0,.35)',
	);
	// The distance from the edges for the preset anchors.
	private const PRESET_OFFSET = '24px';

	/** @param array<string,mixed> $button The "button" block of the settings (possibly partial or untrusted). */
	public static function css( array $button, bool $hide_on_mobile = false ): string {
		$b      = Settings::validate_button( $button );
		$size   = self::SIZES[ $b['size'] ];
		$radius = 'custom' === $b['radius_mode'] ? $b['radius_px'] . 'px' : self::RADII[ $b['shape'] ];
		$media  = '@media ' . self::MOBILE_QUERY;

		$decl  = 'background:' . $b['color_bg'] . ';color:' . $b['color_fg'] . ';height:' . $size . 'px;';
		$decl .= 'pill' === $b['shape']
			? 'min-width:' . $size . 'px;padding:0 ' . (int) round( $size * 0.4 ) . 'px;'
			: 'width:' . $size . 'px;padding:0;';
		$decl .= 'border-radius:' . $radius . ';box-shadow:' . self::shadow( $b ) . ';z-index:' . $b['z_index'] . ';';

		$css  = self::SELECTOR . '{' . $decl . self::position( $b['position'] ) . '}';
		$css .= self::SELECTOR . ':hover{background:' . $b['color_bg_hover'] . '}';

		if ( $b['mobile_position_enabled'] ) {
			$css .= $media . '{' . self::SELECTOR . '{' . self::position( $b['position_mobile'] ) . '}}';
		}
		if ( $hide_on_mobile ) {
			$css .= $media . '{' . self::SELECTOR . '{display:none}}';
		}
		return $css;
	}

	/** @param array<string,mixed> $b Validated button block. */
	private static function shadow( array $b ): string {
		if ( 'custom' !== $b['shadow'] ) {
			return self::SHADOWS[ $b['shadow'] ];
		}
		$c = $b['shadow_custom'];
		return $c['x'] . 'px ' . $c['y'] . 'px ' . $c['blur'] . 'px ' . $c['spread'] . 'px ' . $c['color'];
	}

	/**
	 * All four edges and the transform are always emitted, so a mobile position fully overrides the desktop one.
	 *
	 * @param array<string,mixed> $p Validated position.
	 */
	private static function position( array $p ): string {
		$edges     = array(
			'top'    => 'auto',
			'right'  => 'auto',
			'bottom' => 'auto',
			'left'   => 'auto',
		);
		$transform = 'none';

		if ( 'custom' === $p['anchor'] ) {
			$edges[ $p['edge_x'] ] = $p['x'] . $p['x_unit'];
			$edges[ $p['edge_y'] ] = $p['y'] . $p['y_unit'];
		} else {
			list( $vertical, $horizontal ) = explode( '-', $p['anchor'] );
			$edges[ $horizontal ]          = self::PRESET_OFFSET;
			if ( 'middle' === $vertical ) {
				$edges['top'] = '50%';
				$transform    = 'translateY(-50%)';
			} else {
				$edges[ $vertical ] = self::PRESET_OFFSET;
			}
		}

		$out = '';
		foreach ( $edges as $edge => $value ) {
			$out .= $edge . ':' . $value . ';';
		}
		return $out . 'transform:' . $transform . ';';
	}
}
