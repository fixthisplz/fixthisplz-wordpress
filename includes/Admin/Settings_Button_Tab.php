<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;
use FixThisPlz\Frontend\Button_Style;
use FixThisPlz\Settings;

defined( 'ABSPATH' ) || exit;

/** "Button" tab of the settings screen: appearance, colors, positions (computer and mobile), folded advanced settings and the live preview. */
final class Settings_Button_Tab {

	/** Curated Dashicons offered for the button. */
	private const DASHICONS = array(
		'dashicons-flag', 'dashicons-warning', 'dashicons-sos', 'dashicons-megaphone',
		'dashicons-admin-tools', 'dashicons-hammer', 'dashicons-admin-generic', 'dashicons-code-standards',
		'dashicons-editor-code', 'dashicons-search', 'dashicons-visibility', 'dashicons-lightbulb',
		'dashicons-info', 'dashicons-info-outline', 'dashicons-editor-help', 'dashicons-format-chat',
		'dashicons-admin-comments', 'dashicons-testimonial', 'dashicons-feedback', 'dashicons-email',
		'dashicons-bell', 'dashicons-edit', 'dashicons-clipboard', 'dashicons-sticky',
		'dashicons-shield', 'dashicons-thumbs-down', 'dashicons-dismiss', 'dashicons-no',
		'dashicons-camera', 'dashicons-screenoptions', 'dashicons-performance', 'dashicons-star-filled',
	);

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$b = $s['button'];
		self::preview_box();

		F::section( __( 'Appearance', 'fixthisplz' ), __( 'Icon, shape, text and size of the button.', 'fixthisplz' ) );
		self::icon_row( $b );
		F::select_row(
			__( 'Shape', 'fixthisplz' ),
			array( 'button', 'shape' ),
			$b['shape'],
			array(
				'circle'  => __( 'Circle', 'fixthisplz' ),
				'rounded' => __( 'Rounded square', 'fixthisplz' ),
				'pill'    => __( 'Pill with text', 'fixthisplz' ),
			)
		);
		self::text_rows( $b );
		self::size_row( $b );
		F::table_close();

		F::section( __( 'Colors', 'fixthisplz' ), __( 'Colors of the button, at rest and on hover.', 'fixthisplz' ) );
		self::color_rows( $b );
		F::table_close();

		F::section( __( 'Position', 'fixthisplz' ), __( 'Where the button sits on a computer screen.', 'fixthisplz' ) );
		self::position_row( __( 'Placement', 'fixthisplz' ), 'position', $b['position'] );
		F::table_close();

		F::section( __( 'On mobile', 'fixthisplz' ), __( 'On screens 782 px wide or less, hide the button or give it another position.', 'fixthisplz' ) );
		F::row_open( __( 'Visibility', 'fixthisplz' ) );
		F::checkbox( array( 'visibility', 'hide_on_mobile' ), (bool) $s['visibility']['hide_on_mobile'], __( 'Hide the button on mobile', 'fixthisplz' ) );
		F::row_close();
		self::mobile_position_row( $b );
		F::table_close();

		F::advanced_open(
			__( 'Shadow, corner radius and display order. The defaults suit most sites.', 'fixthisplz' ),
			$b,
			Settings::defaults()['button'],
			array( 'shadow', 'radius_mode', 'z_index' )
		);
		self::shadow_row( $b );
		self::radius_row( $b );
		self::z_index_row( $b );
		F::advanced_close();
	}

	/** Icon type, then the Dashicon grid or the SVG picker. @param array<string,mixed> $b */
	private static function icon_row( array $b ): void {
		$type = array( 'button', 'icon_type' );
		F::row_open( __( 'Icon', 'fixthisplz' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Icon type', 'fixthisplz' ) . '</legend>';
		F::radio( $type, 'dashicon', $b['icon_type'], __( 'Dashicon', 'fixthisplz' ) );
		F::radio( $type, 'svg', $b['icon_type'], __( 'SVG image from the media library', 'fixthisplz' ) );
		echo '</fieldset>';

		self::dashicon_picker( $b );
		self::svg_picker( $b );
		F::row_close();
	}

	/** Dashicon grid (JavaScript) with a select fallback. @param array<string,mixed> $b */
	private static function dashicon_picker( array $b ): void {
		echo '<div class="fixthisplz-sub"' . F::show_if( array( 'button', 'icon_type' ), '=', 'dashicon' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$dashicon_id = F::id( array( 'button', 'dashicon' ) );
		echo '<input type="hidden" id="' . esc_attr( $dashicon_id ) . '" name="' . esc_attr( F::name( array( 'button', 'dashicon' ) ) ) . '" value="' . esc_attr( $b['dashicon'] ) . '">';
		$icons = self::DASHICONS;
		if ( ! in_array( $b['dashicon'], $icons, true ) ) {
			array_unshift( $icons, $b['dashicon'] );
		}
		echo '<div class="fixthisplz-icon-grid" role="group" aria-label="' . esc_attr__( 'Choose an icon', 'fixthisplz' ) . '" data-target="' . esc_attr( $dashicon_id ) . '" hidden>';
		foreach ( $icons as $icon ) {
			printf(
				'<button type="button" class="button fixthisplz-icon-choice" data-icon="%1$s" aria-pressed="%2$s" aria-label="%3$s" title="%3$s"><span class="dashicons %1$s" aria-hidden="true"></span></button>',
				esc_attr( $icon ),
				$icon === $b['dashicon'] ? 'true' : 'false',
				esc_attr( str_replace( array( 'dashicons-', '-' ), array( '', ' ' ), $icon ) )
			);
		}
		echo '</div>';
		// Without JavaScript the grid stays hidden and this list, posted after the hidden field, wins.
		echo '<noscript><select name="' . esc_attr( F::name( array( 'button', 'dashicon' ) ) ) . '" aria-label="' . esc_attr__( 'Choose an icon', 'fixthisplz' ) . '">';
		foreach ( $icons as $icon ) {
			F::option( $icon, $icon, $icon === $b['dashicon'] );
		}
		echo '</select></noscript>';
		echo '</div>';
	}

	/** SVG image chosen from the media library. @param array<string,mixed> $b */
	private static function svg_picker( array $b ): void {
		$svg_id  = (int) $b['svg_attachment_id'];
		$svg_url = $svg_id > 0 && 'image/svg+xml' === get_post_mime_type( $svg_id ) ? (string) wp_get_attachment_url( $svg_id ) : '';
		echo '<div class="fixthisplz-sub fixthisplz-svg-picker"' . F::show_if( array( 'button', 'icon_type' ), '=', 'svg' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<input type="hidden" id="' . esc_attr( F::id( array( 'button', 'svg_attachment_id' ) ) ) . '" name="' . esc_attr( F::name( array( 'button', 'svg_attachment_id' ) ) ) . '" value="' . esc_attr( (string) $svg_id ) . '">';
		echo '<img class="fixthisplz-svg-thumb" alt="" src="' . esc_url( $svg_url ) . '"' . ( '' === $svg_url ? ' hidden' : '' ) . '>';
		echo ' <button type="button" class="button fixthisplz-svg-choose">' . esc_html__( 'Choose an SVG', 'fixthisplz' ) . '</button>';
		echo ' <button type="button" class="button-link fixthisplz-svg-remove"' . ( '' === $svg_url ? ' hidden' : '' ) . '>' . esc_html__( 'Remove', 'fixthisplz' ) . '</button>';
		F::description( __( 'Only SVG files already in the media library are listed. WordPress blocks SVG uploads by default; FixThisPlz does not change that. Without an SVG, the Dashicon is used.', 'fixthisplz' ) );
		echo '</div>';
	}

	/** Pill text and tooltip. @param array<string,mixed> $b */
	private static function text_rows( array $b ): void {
		$id = F::id( array( 'button', 'label' ) );
		F::row_open( __( 'Button text', 'fixthisplz' ), $id );
		F::input( 'text', array( 'button', 'label' ), $b['label'], array( 'placeholder' => __( 'Report a bug', 'fixthisplz' ), 'class' => 'regular-text' ), true );
		F::description( __( 'Shown inside the pill shape, and read by screen readers for every shape.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'button', 'tooltip' ) );
		F::row_open( __( 'Tooltip', 'fixthisplz' ), $id );
		F::input( 'text', array( 'button', 'tooltip' ), $b['tooltip'], array( 'placeholder' => __( 'Report a bug', 'fixthisplz' ), 'class' => 'regular-text' ) );
		F::row_close();
	}

	/** Size select, with the real heights (Button_Style::SIZES). @param array<string,mixed> $b */
	private static function size_row( array $b ): void {
		$path = array( 'button', 'size' );
		$id   = F::id( $path );
		F::row_open( __( 'Size', 'fixthisplz' ), $id );
		F::select(
			$path,
			$b['size'],
			array(
				's' => __( 'Small', 'fixthisplz' ),
				'm' => __( 'Medium', 'fixthisplz' ),
				'l' => __( 'Large', 'fixthisplz' ),
			),
			true
		);
		$sizes = Button_Style::SIZES;
		/* translators: 1: height of the small button, 2: of the medium one, 3: of the large one, in pixels. */
		F::description( sprintf( __( 'Height: %1$d px (small), %2$d px (medium) or %3$d px (large).', 'fixthisplz' ), $sizes['s'], $sizes['m'], $sizes['l'] ), $id . '-desc' );
		F::row_close();
	}

	/** @param array<string,mixed> $b */
	private static function color_rows( array $b ): void {
		$defaults = Settings::defaults()['button'];
		$colors   = array(
			'color_bg'       => __( 'Background color', 'fixthisplz' ),
			'color_fg'       => __( 'Icon and text color', 'fixthisplz' ),
			'color_bg_hover' => __( 'Background color on hover', 'fixthisplz' ),
		);
		foreach ( $colors as $key => $label ) {
			$id = F::id( array( 'button', $key ) );
			F::row_open( $label, $id );
			F::input(
				'text',
				array( 'button', $key ),
				$b[ $key ],
				array(
					'class'              => 'fixthisplz-color-field',
					'data-default-color' => $defaults[ $key ],
				)
			);
			F::row_close();
		}
	}

	/** Shadow preset, with the custom shadow fields. @param array<string,mixed> $b */
	private static function shadow_row( array $b ): void {
		$shadow = array( 'button', 'shadow' );
		F::row_open( __( 'Shadow', 'fixthisplz' ), F::id( $shadow ), true );
		F::select(
			$shadow,
			$b['shadow'],
			array(
				'none'   => __( 'None', 'fixthisplz' ),
				'light'  => __( 'Light', 'fixthisplz' ),
				'medium' => __( 'Medium', 'fixthisplz' ),
				'strong' => __( 'Strong', 'fixthisplz' ),
				'custom' => __( 'Custom', 'fixthisplz' ),
			)
		);
		echo '<fieldset class="fixthisplz-sub fixthisplz-inline-fields"' . F::show_if( $shadow, '=', 'custom' ) . '><legend>' . esc_html__( 'Custom shadow', 'fixthisplz' ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		$sc = $b['shadow_custom'];
		F::labelled_number( array( 'button', 'shadow_custom', 'x' ), $sc['x'], __( 'Offset X (px)', 'fixthisplz' ), -100, 100 );
		F::labelled_number( array( 'button', 'shadow_custom', 'y' ), $sc['y'], __( 'Offset Y (px)', 'fixthisplz' ), -100, 100 );
		F::labelled_number( array( 'button', 'shadow_custom', 'blur' ), $sc['blur'], __( 'Blur (px)', 'fixthisplz' ), 0, 200 );
		F::labelled_number( array( 'button', 'shadow_custom', 'spread' ), $sc['spread'], __( 'Spread (px)', 'fixthisplz' ), -100, 100 );
		$cid = F::id( array( 'button', 'shadow_custom', 'color' ) );
		echo '<label for="' . esc_attr( $cid ) . '">' . esc_html__( 'Color', 'fixthisplz' ) . '</label> ';
		F::input( 'text', array( 'button', 'shadow_custom', 'color' ), $sc['color'], array( 'class' => 'regular-text fixthisplz-short', 'placeholder' => Settings::defaults()['button']['shadow_custom']['color'] ) );
		F::description( __( 'Hex color (#rrggbb) or rgba(red, green, blue, opacity), for example rgba(0,0,0,0.25).', 'fixthisplz' ) );
		echo '</fieldset>';
		F::row_close();
	}

	/** @param array<string,mixed> $b */
	private static function radius_row( array $b ): void {
		$mode = array( 'button', 'radius_mode' );
		F::row_open( __( 'Corner radius', 'fixthisplz' ), F::id( $mode ) );
		F::select(
			$mode,
			$b['radius_mode'],
			array(
				'preset' => __( 'Set by the shape', 'fixthisplz' ),
				'custom' => __( 'Custom', 'fixthisplz' ),
			)
		);
		echo '<span class="fixthisplz-sub"' . F::show_if( $mode, '=', 'custom' ) . '> '; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		F::labelled_number( array( 'button', 'radius_px' ), $b['radius_px'], __( 'Radius (px)', 'fixthisplz' ), 0, 200 );
		echo '</span>';
		F::row_close();
	}

	/** @param array<string,mixed> $b */
	private static function mobile_position_row( array $b ): void {
		$mobile = array( 'button', 'mobile_position_enabled' );
		// Pointless while the button is hidden on mobile.
		F::row_open( __( 'Position on mobile', 'fixthisplz' ), '', true, F::show_if( array( 'visibility', 'hide_on_mobile' ), '=', '0' ) );
		F::checkbox( $mobile, (bool) $b['mobile_position_enabled'], __( 'Use another position on mobile', 'fixthisplz' ) );
		echo '<div class="fixthisplz-sub"' . F::show_if( $mobile, '=', '1' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		Settings_Position_Fields::render( 'position_mobile', $b['position_mobile'] );
		echo '</div>';
		F::row_close();
	}

	/** @param array<string,mixed> $b */
	private static function z_index_row( array $b ): void {
		$id = F::id( array( 'button', 'z_index' ) );
		F::row_open( __( 'Stacking order (z-index)', 'fixthisplz' ), $id );
		F::input( 'number', array( 'button', 'z_index' ), (string) $b['z_index'], array( 'min' => '0', 'max' => '2147483647', 'class' => 'small-text fixthisplz-medium' ), true );
		F::description( __( 'Raise it if a theme element covers the button.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();
	}

	/** @param array<string,mixed> $p */
	private static function position_row( string $label, string $key, array $p ): void {
		F::row_open( $label, F::id( array( 'button', $key, 'anchor' ) ) );
		Settings_Position_Fields::render( $key, $p );
		F::row_close();
	}

	/** Live preview of the button (filled by admin-settings.js; hidden without JavaScript). */
	private static function preview_box(): void {
		echo '<div class="fixthisplz-preview" hidden>';
		echo '<div class="fixthisplz-preview-head"><h2>' . esc_html__( 'Preview', 'fixthisplz' ) . '</h2>';
		echo '<div class="fixthisplz-preview-devices" role="group" aria-label="' . esc_attr__( 'Preview screen size', 'fixthisplz' ) . '">';
		echo '<button type="button" class="button" data-device="desktop" aria-pressed="true">' . esc_html__( 'Desktop', 'fixthisplz' ) . '</button> ';
		echo '<button type="button" class="button" data-device="mobile" aria-pressed="false">' . esc_html__( 'Mobile', 'fixthisplz' ) . '</button>';
		echo '</div></div>';
		echo '<div class="fixthisplz-preview-stage"><iframe class="fixthisplz-preview-frame" title="' . esc_attr__( 'Button preview', 'fixthisplz' ) . '"></iframe></div>';
		echo '<p class="description">' . esc_html__( 'Updated as you change the settings below. The frame stands for a browser window; save to apply.', 'fixthisplz' ) . '</p>';
		echo '<p class="fixthisplz-preview-error notice notice-error inline" role="alert" hidden></p>';
		echo '</div>';
	}
}
