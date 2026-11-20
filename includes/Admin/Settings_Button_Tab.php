<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;
use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** "Button" tab of the settings screen: icon, shape, colors, shadow, radius, positions and the live preview. */
final class Settings_Button_Tab {

	/** Curated Dashicons offered for the button. */
	private const DASHICONS = array(
		'dashicons-flag',
		'dashicons-warning',
		'dashicons-sos',
		'dashicons-megaphone',
		'dashicons-admin-tools',
		'dashicons-hammer',
		'dashicons-admin-generic',
		'dashicons-code-standards',
		'dashicons-editor-code',
		'dashicons-search',
		'dashicons-visibility',
		'dashicons-lightbulb',
		'dashicons-info',
		'dashicons-info-outline',
		'dashicons-editor-help',
		'dashicons-format-chat',
		'dashicons-admin-comments',
		'dashicons-testimonial',
		'dashicons-feedback',
		'dashicons-email',
		'dashicons-bell',
		'dashicons-edit',
		'dashicons-clipboard',
		'dashicons-sticky',
		'dashicons-shield',
		'dashicons-thumbs-down',
		'dashicons-dismiss',
		'dashicons-no',
		'dashicons-camera',
		'dashicons-screenoptions',
		'dashicons-performance',
		'dashicons-star-filled',
	);

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$b = $s['button'];
		self::preview_box();
		F::table_open();

		$type = array( 'button', 'icon_type' );
		F::row_open( __( 'Icon', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Icon type', 'bug-spotter' ) . '</legend>';
		F::radio( $type, 'dashicon', $b['icon_type'], __( 'Dashicon', 'bug-spotter' ) );
		F::radio( $type, 'svg', $b['icon_type'], __( 'SVG image from the media library', 'bug-spotter' ) );
		echo '</fieldset>';

		$show_dashicon = F::show_if( $type, '=', 'dashicon' );
		echo '<div class="bugspot-sub"' . $show_dashicon . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$dashicon_id = F::id( array( 'button', 'dashicon' ) );
		echo '<input type="hidden" id="' . esc_attr( $dashicon_id ) . '" name="' . esc_attr( F::name( array( 'button', 'dashicon' ) ) ) . '" value="' . esc_attr( $b['dashicon'] ) . '">';
		$icons = self::DASHICONS;
		if ( ! in_array( $b['dashicon'], $icons, true ) ) {
			array_unshift( $icons, $b['dashicon'] );
		}
		echo '<div class="bugspot-icon-grid" role="group" aria-label="' . esc_attr__( 'Choose an icon', 'bug-spotter' ) . '" data-target="' . esc_attr( $dashicon_id ) . '" hidden>';
		foreach ( $icons as $icon ) {
			printf(
				'<button type="button" class="button bugspot-icon-choice" data-icon="%1$s" aria-pressed="%2$s" aria-label="%3$s" title="%3$s"><span class="dashicons %1$s" aria-hidden="true"></span></button>',
				esc_attr( $icon ),
				$icon === $b['dashicon'] ? 'true' : 'false',
				esc_attr( str_replace( array( 'dashicons-', '-' ), array( '', ' ' ), $icon ) )
			);
		}
		echo '</div>';
		// Without JavaScript the grid stays hidden and this list, posted after the hidden field, wins.
		echo '<noscript><select name="' . esc_attr( F::name( array( 'button', 'dashicon' ) ) ) . '" aria-label="' . esc_attr__( 'Choose an icon', 'bug-spotter' ) . '">';
		foreach ( $icons as $icon ) {
			F::option( $icon, $icon, $icon === $b['dashicon'] );
		}
		echo '</select></noscript>';
		echo '</div>';

		$svg_id  = (int) $b['svg_attachment_id'];
		$svg_url = $svg_id > 0 && 'image/svg+xml' === get_post_mime_type( $svg_id ) ? (string) wp_get_attachment_url( $svg_id ) : '';
		echo '<div class="bugspot-sub bugspot-svg-picker"' . F::show_if( $type, '=', 'svg' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<input type="hidden" id="' . esc_attr( F::id( array( 'button', 'svg_attachment_id' ) ) ) . '" name="' . esc_attr( F::name( array( 'button', 'svg_attachment_id' ) ) ) . '" value="' . esc_attr( (string) $svg_id ) . '">';
		echo '<img class="bugspot-svg-thumb" alt="" src="' . esc_url( $svg_url ) . '"' . ( '' === $svg_url ? ' hidden' : '' ) . '>';
		echo ' <button type="button" class="button bugspot-svg-choose">' . esc_html__( 'Choose an SVG', 'bug-spotter' ) . '</button>';
		echo ' <button type="button" class="button-link bugspot-svg-remove"' . ( '' === $svg_url ? ' hidden' : '' ) . '>' . esc_html__( 'Remove', 'bug-spotter' ) . '</button>';
		F::description( __( 'Only SVG files already in the media library are listed. WordPress blocks SVG uploads by default; Bug Spotter does not change that. Without an SVG, the Dashicon is used.', 'bug-spotter' ) );
		echo '</div>';
		F::row_close();

		F::select_row(
			__( 'Shape', 'bug-spotter' ),
			array( 'button', 'shape' ),
			$b['shape'],
			array(
				'circle'  => __( 'Circle', 'bug-spotter' ),
				'rounded' => __( 'Rounded square', 'bug-spotter' ),
				'pill'    => __( 'Pill with text', 'bug-spotter' ),
			)
		);

		$id = F::id( array( 'button', 'label' ) );
		F::row_open( __( 'Text', 'bug-spotter' ), $id );
		F::input( 'text', array( 'button', 'label' ), $b['label'], array( 'placeholder' => __( 'Report a bug', 'bug-spotter' ), 'class' => 'regular-text' ), true  );
		F::description( __( 'Shown inside the pill shape, and read by screen readers for every shape.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'button', 'tooltip' ) );
		F::row_open( __( 'Tooltip', 'bug-spotter' ), $id );
		F::input( 'text', array( 'button', 'tooltip' ), $b['tooltip'], array( 'placeholder' => __( 'Report a bug', 'bug-spotter' ), 'class' => 'regular-text' ) );
		F::row_close();

		F::select_row(
			__( 'Size', 'bug-spotter' ),
			array( 'button', 'size' ),
			$b['size'],
			array(
				's' => __( 'Small', 'bug-spotter' ),
				'm' => __( 'Medium', 'bug-spotter' ),
				'l' => __( 'Large', 'bug-spotter' ),
			)
		);

		$defaults = Settings::defaults()['button'];
		$colors   = array(
			'color_bg'       => __( 'Background color', 'bug-spotter' ),
			'color_fg'       => __( 'Icon and text color', 'bug-spotter' ),
			'color_bg_hover' => __( 'Background color on hover', 'bug-spotter' ),
		);
		foreach ( $colors as $key => $label ) {
			$id = F::id( array( 'button', $key ) );
			F::row_open( $label, $id );
			F::input(
				'text',
				array( 'button', $key ),
				$b[ $key ],
				array(
					'class'              => 'bugspot-color-field',
					'data-default-color' => $defaults[ $key ],
				)
			);
			F::row_close();
		}

		$shadow = array( 'button', 'shadow' );
		F::row_open( __( 'Shadow', 'bug-spotter' ), F::id( $shadow ), true );
		F::select(
			$shadow,
			$b['shadow'],
			array(
				'none'   => __( 'None', 'bug-spotter' ),
				'light'  => __( 'Light', 'bug-spotter' ),
				'medium' => __( 'Medium', 'bug-spotter' ),
				'strong' => __( 'Strong', 'bug-spotter' ),
				'custom' => __( 'Custom', 'bug-spotter' ),
			)
		);
		echo '<fieldset class="bugspot-sub bugspot-inline-fields"' . F::show_if( $shadow, '=', 'custom' ) . '><legend>' . esc_html__( 'Custom shadow', 'bug-spotter' ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$sc = $b['shadow_custom'];
		F::labelled_number( array( 'button', 'shadow_custom', 'x' ), $sc['x'], __( 'Offset X (px)', 'bug-spotter' ), -100, 100 );
		F::labelled_number( array( 'button', 'shadow_custom', 'y' ), $sc['y'], __( 'Offset Y (px)', 'bug-spotter' ), -100, 100 );
		F::labelled_number( array( 'button', 'shadow_custom', 'blur' ), $sc['blur'], __( 'Blur (px)', 'bug-spotter' ), 0, 200 );
		F::labelled_number( array( 'button', 'shadow_custom', 'spread' ), $sc['spread'], __( 'Spread (px)', 'bug-spotter' ), -100, 100 );
		$cid = F::id( array( 'button', 'shadow_custom', 'color' ) );
		echo '<label for="' . esc_attr( $cid ) . '">' . esc_html__( 'Color', 'bug-spotter' ) . '</label> ';
		F::input( 'text', array( 'button', 'shadow_custom', 'color' ), $sc['color'], array( 'class' => 'regular-text bugspot-short', 'placeholder' => $defaults['shadow_custom']['color'] ) );
		F::description( __( 'Hex color (#rrggbb) or rgba(red, green, blue, opacity), for example rgba(0,0,0,0.25).', 'bug-spotter' ) );
		echo '</fieldset>';
		F::row_close();

		$mode = array( 'button', 'radius_mode' );
		F::row_open( __( 'Corner radius', 'bug-spotter' ), F::id( $mode ) );
		F::select(
			$mode,
			$b['radius_mode'],
			array(
				'preset' => __( 'Set by the shape', 'bug-spotter' ),
				'custom' => __( 'Custom', 'bug-spotter' ),
			)
		);
		echo '<span class="bugspot-sub"' . F::show_if( $mode, '=', 'custom' ) . '> '; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		F::labelled_number( array( 'button', 'radius_px' ), $b['radius_px'], __( 'Radius (px)', 'bug-spotter' ), 0, 200 );
		echo '</span>';
		F::row_close();

		self::position_row( __( 'Position', 'bug-spotter' ), 'position', $b['position'] );

		$mobile = array( 'button', 'mobile_position_enabled' );
		F::row_open( __( 'Position on mobile', 'bug-spotter' ), '', true );
		F::checkbox( $mobile, (bool) $b['mobile_position_enabled'], __( 'Use a different position on small screens (782 px wide or less)', 'bug-spotter' ) );
		echo '<div class="bugspot-sub"' . F::show_if( $mobile, '=', '1' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		self::position_fields( 'position_mobile', $b['position_mobile'] );
		echo '</div>';
		F::row_close();

		$id = F::id( array( 'button', 'z_index' ) );
		F::row_open( __( 'Stacking order (z-index)', 'bug-spotter' ), $id );
		F::input( 'number', array( 'button', 'z_index' ), (string) $b['z_index'], array( 'min' => '0', 'max' => '2147483647', 'class' => 'small-text bugspot-medium' ), true  );
		F::description( __( 'Raise it if a theme element covers the button.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $p */
	private static function position_row( string $label, string $key, array $p ): void {
		F::row_open( $label, F::id( array( 'button', $key, 'anchor' ) ) );
		self::position_fields( $key, $p );
		F::row_close();
	}

	/** @param array<string,mixed> $p */
	private static function position_fields( string $key, array $p ): void {
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
			echo '<span class="bugspot-axis">';
			echo '<label for="' . esc_attr( F::id( $edge ) ) . '">' . esc_html( 'x' === $axis ? __( 'Distance from the', 'bug-spotter' ) : __( 'and from the', 'bug-spotter' ) ) . '</label> ';
			F::select(
				$edge,
				$p[ 'edge_' . $axis ],
				'x' === $axis
					? array(
						'left'  => __( 'left edge', 'bug-spotter' ),
						'right' => __( 'right edge', 'bug-spotter' ),
					)
					: array(
						'top'    => __( 'top edge', 'bug-spotter' ),
						'bottom' => __( 'bottom edge', 'bug-spotter' ),
					)
			);
			echo ' <label class="screen-reader-text" for="' . esc_attr( F::id( $value ) ) . '">' . esc_html( 'x' === $axis ? __( 'Horizontal distance', 'bug-spotter' ) : __( 'Vertical distance', 'bug-spotter' ) ) . '</label>';
			F::input( 'number', $value, (string) $p[ $axis ], array( 'min' => '0', 'max' => '10000', 'class' => 'small-text' ) );
			echo ' <label class="screen-reader-text" for="' . esc_attr( F::id( $unit ) ) . '">' . esc_html__( 'Unit', 'bug-spotter' ) . '</label>';
			F::select( $unit, $p[ $axis . '_unit' ], $units );
			echo '</span>';
		}
		F::description( __( 'Percentages are relative to the browser window (0 to 100).', 'bug-spotter' ) );
		echo '</fieldset>';
	}

	/** Live preview of the button (filled by admin-settings.js; hidden without JavaScript). */
	private static function preview_box(): void {
		echo '<div class="bugspot-preview" hidden>';
		echo '<div class="bugspot-preview-head"><h2>' . esc_html__( 'Preview', 'bug-spotter' ) . '</h2>';
		echo '<div class="bugspot-preview-devices" role="group" aria-label="' . esc_attr__( 'Preview screen size', 'bug-spotter' ) . '">';
		echo '<button type="button" class="button" data-device="desktop" aria-pressed="true">' . esc_html__( 'Desktop', 'bug-spotter' ) . '</button> ';
		echo '<button type="button" class="button" data-device="mobile" aria-pressed="false">' . esc_html__( 'Mobile', 'bug-spotter' ) . '</button>';
		echo '</div></div>';
		echo '<div class="bugspot-preview-stage"><iframe class="bugspot-preview-frame" title="' . esc_attr__( 'Button preview', 'bug-spotter' ) . '"></iframe></div>';
		echo '<p class="description">' . esc_html__( 'Updated as you change the settings below. The frame stands for a browser window; save to apply.', 'bug-spotter' ) . '</p>';
		echo '<p class="bugspot-preview-error notice notice-error inline" role="alert" hidden></p>';
		echo '</div>';
	}
}
