<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** Form-table markup helpers of the settings screen. Every value is escaped here. */
final class Settings_Fields {

	/** @param string[] $path */
	public static function name( array $path ): string {
		return Settings::OPTION . '[' . implode( '][', $path ) . ']';
	}

	/** @param string[] $path */
	public static function id( array $path ): string {
		return 'bugspot-' . str_replace( '_', '-', implode( '-', $path ) );
	}

	/**
	 * data-* attribute read by admin-settings.js to show a block only for some values of another field.
	 * Everything is visible without JavaScript.
	 *
	 * @param string[] $path
	 */
	public static function show_if( array $path, string $operator, string $value ): string {
		return ' data-bugspot-show-if="' . esc_attr( self::name( $path ) ) . '" data-bugspot-op="' . esc_attr( $operator ) . '" data-bugspot-value="' . esc_attr( $value ) . '"';
	}

	public static function table_open(): void {
		echo '<table class="form-table" role="presentation"><tbody>';
	}

	public static function table_close(): void {
		echo '</tbody></table>';
	}

	/** @param string $attrs Pre-escaped attributes (from show_if()). */
	public static function row_open( string $label, string $for = '', bool $group = false, string $attrs = '' ): void {
		echo '<tr' . $attrs . '><th scope="row">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $attrs comes from show_if().
		if ( '' !== $for && ! $group ) {
			echo '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html( $label );
		}
		echo '</th><td>';
	}

	public static function row_close(): void {
		echo '</td></tr>';
	}

	public static function description( string $text, string $id = '' ): void {
		echo '<p class="description"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $text ) . '</p>';
	}

	/**
	 * @param string[]             $path
	 * @param array<string,string> $attrs
	 * @param bool                 $described True when the caller prints a description with id "{id}-desc".
	 */
	public static function input( string $type, array $path, string $value, array $attrs = array(), bool $described = false ): void {
		$id   = self::id( $path );
		$html = '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( $value ) . '"';
		foreach ( $attrs as $key => $attr ) {
			$html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $attr ) . '"';
		}
		if ( $described ) {
			$html .= ' aria-describedby="' . esc_attr( $id ) . '-desc"';
		}
		echo $html . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/** @param string[] $path @param bool $described True when the caller prints a description with id "{id}-desc". */
	public static function textarea( array $path, string $value, int $rows, string $placeholder = '', bool $described = false ): void {
		$id = self::id( $path );
		printf(
			'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code" placeholder="%4$s"%6$s>%5$s</textarea>',
			esc_attr( $id ),
			esc_attr( self::name( $path ) ),
			(int) $rows,
			esc_attr( $placeholder ),
			esc_textarea( $value ),
			$described ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : ''
		);
	}

	/** @param string[] $path */
	public static function labelled_number( array $path, int $value, string $label, int $min, int $max ): void {
		$id = self::id( $path );
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> ';
		self::input( 'number', $path, (string) $value, array( 'min' => (string) $min, 'max' => (string) $max, 'class' => 'small-text' ) );
		echo ' ';
	}

	/** Checkbox posting "1", preceded by a hidden "0" so that unchecking is saved. @param string[] $path */
	public static function checkbox( array $path, bool $checked, string $label ): void {
		$id = self::id( $path );
		echo '<input type="hidden" name="' . esc_attr( self::name( $path ) ) . '" value="0">';
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label><br>',
			esc_attr( $id ),
			esc_attr( self::name( $path ) ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/** Empty first item of a list field: an all-unchecked list still reaches the sanitizer and replaces the saved one. @param string[] $path */
	public static function list_marker( array $path ): void {
		echo '<input type="hidden" name="' . esc_attr( self::name( $path ) ) . '[]" value="">';
	}

	/** @param string[] $path */
	public static function checkbox_item( array $path, string $value, bool $checked, string $label, bool $locked = false ): void {
		$id = self::id( $path ) . '-' . sanitize_html_class( $value );
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s%5$s> %6$s</label><br>',
			esc_attr( $id ),
			esc_attr( self::name( $path ) ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$locked ? ' disabled' : '',
			esc_html( $label )
		);
	}

	/** @param string[] $path */
	public static function radio( array $path, string $value, string $current, string $label ): void {
		$id = self::id( $path ) . '-' . sanitize_html_class( $value );
		printf(
			'<label for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s> %5$s</label><br>',
			esc_attr( $id ),
			esc_attr( self::name( $path ) ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * @param string[]             $path
	 * @param array<string,string> $choices
	 */
	public static function select( array $path, string $current, array $choices ): void {
		echo '<select id="' . esc_attr( self::id( $path ) ) . '" name="' . esc_attr( self::name( $path ) ) . '">';
		foreach ( $choices as $value => $label ) {
			self::option( (string) $value, $label, (string) $value === $current );
		}
		echo '</select>';
	}

	/**
	 * @param string[]             $path
	 * @param array<string,string> $choices
	 */
	public static function select_row( string $label, array $path, string $current, array $choices ): void {
		self::row_open( $label, self::id( $path ) );
		self::select( $path, $current, $choices );
		self::row_close();
	}

	public static function option( string $value, string $label, bool $selected ): void {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $label ) . '</option>';
	}
}
