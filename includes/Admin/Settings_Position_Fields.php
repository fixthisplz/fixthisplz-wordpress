<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** Anchor select and custom-position sentences of the button, for the computer and the mobile positions (Settings_Button_Tab). */
final class Settings_Position_Fields {

	/** @param string $key "position" or "position_mobile". @param array<string,mixed> $p */
	public static function render( string $key, array $p ): void {
		$anchor = array( 'button', $key, 'anchor' );
		if ( 'position_mobile' === $key ) {
			echo '<label for="' . esc_attr( F::id( $anchor ) ) . '" class="screen-reader-text">' . esc_html__( 'Position on mobile', 'fixthisplz' ) . '</label>';
		}
		F::select(
			$anchor,
			$p['anchor'],
			array(
				'bottom-right' => __( 'Bottom right', 'fixthisplz' ),
				'bottom-left'  => __( 'Bottom left', 'fixthisplz' ),
				'top-right'    => __( 'Top right', 'fixthisplz' ),
				'top-left'     => __( 'Top left', 'fixthisplz' ),
				'middle-right' => __( 'Middle right', 'fixthisplz' ),
				'middle-left'  => __( 'Middle left', 'fixthisplz' ),
				'custom'       => __( 'Custom', 'fixthisplz' ),
			)
		);

		$units = array(
			'px' => 'px',
			'%'  => '%',
		);
		echo '<fieldset class="fixthisplz-sub fixthisplz-inline-fields"';
		F::show_if( $anchor, '=', 'custom' );
		echo '><legend>' . esc_html__( 'Custom position', 'fixthisplz' ) . '</legend>';
		foreach ( array( 'x', 'y' ) as $axis ) {
			$edge  = array( 'button', $key, 'edge_' . $axis );
			$value = array( 'button', $key, $axis );
			$unit  = array( 'button', $key, $axis . '_unit' );

			// Each control, with its screen-reader label, takes the place of its placeholder in one translatable sentence.
			$controls = array(
				'%1$s' => static function () use ( $axis, $value, $p ): void {
					self::screen_reader_label( $value, 'x' === $axis ? __( 'Horizontal distance', 'fixthisplz' ) : __( 'Vertical distance', 'fixthisplz' ) );
					F::input( 'number', $value, (string) $p[ $axis ], array( 'min' => '0', 'max' => '10000', 'class' => 'small-text' ) );
				},
				'%2$s' => static function () use ( $axis, $unit, $p, $units ): void {
					self::screen_reader_label( $unit, 'x' === $axis ? __( 'Horizontal unit', 'fixthisplz' ) : __( 'Vertical unit', 'fixthisplz' ) );
					F::select( $unit, $p[ $axis . '_unit' ], $units );
				},
				'%3$s' => static function () use ( $axis, $edge, $p ): void {
					self::screen_reader_label( $edge, 'x' === $axis ? __( 'Starting edge (left or right)', 'fixthisplz' ) : __( 'Starting edge (top or bottom)', 'fixthisplz' ) );
					F::select(
						$edge,
						$p[ 'edge_' . $axis ],
						'x' === $axis
							? array(
								'left'  => _x( 'left', 'edge of the window', 'fixthisplz' ),
								'right' => _x( 'right', 'edge of the window', 'fixthisplz' ),
							)
							: array(
								'top'    => _x( 'top', 'edge of the window', 'fixthisplz' ),
								'bottom' => _x( 'bottom', 'edge of the window', 'fixthisplz' ),
							)
					);
				},
			);

			$sentence = 'x' === $axis
				/* translators: 1: distance input, 2: unit select (px or %), 3: edge select (left or right). */
				? __( 'Horizontal: %1$s %2$s from the %3$s edge', 'fixthisplz' )
				/* translators: 1: distance input, 2: unit select (px or %), 3: edge select (top or bottom). */
				: __( 'Vertical: %1$s %2$s from the %3$s edge', 'fixthisplz' );
			echo '<span class="fixthisplz-axis">';
			// Split on the placeholders rather than printf(): a faulty translation (missing or extra placeholder) cannot cause a fatal error.
			foreach ( preg_split( '/(%[1-3]\$s)/', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE ) as $part ) {
				if ( isset( $controls[ $part ] ) ) {
					$controls[ $part ]();
				} else {
					echo esc_html( $part );
				}
			}
			echo '</span>';
		}
		F::description( __( 'Percentages are relative to the browser window (0 to 100).', 'fixthisplz' ) );
		echo '</fieldset>';
	}

	/** @param string[] $path */
	private static function screen_reader_label( array $path, string $label ): void {
		echo '<label class="screen-reader-text" for="' . esc_attr( F::id( $path ) ) . '">' . esc_html( $label ) . '</label>';
	}
}
