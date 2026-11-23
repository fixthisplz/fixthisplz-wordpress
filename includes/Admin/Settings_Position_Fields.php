<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** Anchor select and custom-position sentences of the button, for the computer and the mobile positions (Settings_Button_Tab). */
final class Settings_Position_Fields {

	/** @param string $key "position" or "position_mobile". @param array<string,mixed> $p */
	public static function render( string $key, array $p ): void {
		$anchor = array( 'button', $key, 'anchor' );
		if ( 'position_mobile' === $key ) {
			echo '<label for="' . esc_attr( F::id( $anchor ) ) . '" class="screen-reader-text">' . esc_html__( 'Position on mobile', 'bug-spotter' ) . '</label>';
		}
		F::select(
			$anchor,
			$p['anchor'],
			array(
				'bottom-right' => __( 'Bottom right', 'bug-spotter' ),
				'bottom-left'  => __( 'Bottom left', 'bug-spotter' ),
				'top-right'    => __( 'Top right', 'bug-spotter' ),
				'top-left'     => __( 'Top left', 'bug-spotter' ),
				'middle-right' => __( 'Middle right', 'bug-spotter' ),
				'middle-left'  => __( 'Middle left', 'bug-spotter' ),
				'custom'       => __( 'Custom', 'bug-spotter' ),
			)
		);

		$units = array(
			'px' => 'px',
			'%'  => '%',
		);
		echo '<fieldset class="bugspot-sub bugspot-inline-fields"' . F::show_if( $anchor, '=', 'custom' ) . '><legend>' . esc_html__( 'Custom position', 'bug-spotter' ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		foreach ( array( 'x', 'y' ) as $axis ) {
			$edge  = array( 'button', $key, 'edge_' . $axis );
			$value = array( 'button', $key, $axis );
			$unit  = array( 'button', $key, $axis . '_unit' );

			// Each control is rendered (escaped) with its screen-reader label, then placed in one translatable sentence.
			ob_start();
			echo '<label class="screen-reader-text" for="' . esc_attr( F::id( $value ) ) . '">' . esc_html( 'x' === $axis ? __( 'Horizontal distance', 'bug-spotter' ) : __( 'Vertical distance', 'bug-spotter' ) ) . '</label>';
			F::input( 'number', $value, (string) $p[ $axis ], array( 'min' => '0', 'max' => '10000', 'class' => 'small-text' ) );
			$distance_html = (string) ob_get_clean();

			ob_start();
			echo '<label class="screen-reader-text" for="' . esc_attr( F::id( $unit ) ) . '">' . esc_html( 'x' === $axis ? __( 'Horizontal unit', 'bug-spotter' ) : __( 'Vertical unit', 'bug-spotter' ) ) . '</label>';
			F::select( $unit, $p[ $axis . '_unit' ], $units );
			$unit_html = (string) ob_get_clean();

			ob_start();
			echo '<label class="screen-reader-text" for="' . esc_attr( F::id( $edge ) ) . '">' . esc_html( 'x' === $axis ? __( 'Starting edge (left or right)', 'bug-spotter' ) : __( 'Starting edge (top or bottom)', 'bug-spotter' ) ) . '</label>';
			F::select(
				$edge,
				$p[ 'edge_' . $axis ],
				'x' === $axis
					? array(
						'left'  => _x( 'left', 'edge of the window', 'bug-spotter' ),
						'right' => _x( 'right', 'edge of the window', 'bug-spotter' ),
					)
					: array(
						'top'    => _x( 'top', 'edge of the window', 'bug-spotter' ),
						'bottom' => _x( 'bottom', 'edge of the window', 'bug-spotter' ),
					)
			);
			$edge_html = (string) ob_get_clean();

			$sentence = 'x' === $axis
				/* translators: 1: distance input, 2: unit select (px or %), 3: edge select (left or right). */
				? __( 'Horizontal: %1$s %2$s from the %3$s edge', 'bug-spotter' )
				/* translators: 1: distance input, 2: unit select (px or %), 3: edge select (top or bottom). */
				: __( 'Vertical: %1$s %2$s from the %3$s edge', 'bug-spotter' );
			echo '<span class="bugspot-axis">';
			// strtr() rather than printf(): a faulty translation (missing or extra placeholder) cannot cause a fatal error.
			echo strtr( // phpcs:ignore WordPress.Security.EscapeOutput -- the sentence is escaped, the controls are built from escaped parts.
				esc_html( $sentence ),
				array(
					'%1$s' => $distance_html,
					'%2$s' => $unit_html,
					'%3$s' => $edge_html,
				)
			);
			echo '</span>';
		}
		F::description( __( 'Percentages are relative to the browser window (0 to 100).', 'bug-spotter' ) );
		echo '</fieldset>';
	}
}
