<?php

namespace Bug_Spotter;

use Bug_Spotter\Image\Stripping_Imagick_Editor;

defined( 'ABSPATH' ) || exit;

/** Private storage of report screenshots: validated by content, re-encoded with every profile and comment stripped, never web-served. */
final class Screenshot_Store {

	public const MAX_BYTES    = 5242880;
	/** Decompression-bomb guard: a small file can declare a huge canvas. */
	public const MAX_PIXELS   = 40000000;
	public const JPEG_QUALITY = 85;

	private const NAME_PATTERN = '/^[a-f0-9]{32}\.jpg$/D';

	/** @return string|\WP_Error Stored file name. */
	public static function store( string $tmp_path ): string|\WP_Error {
		if ( '' === $tmp_path || ! is_file( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return self::bad_type();
		}
		$size = filesize( $tmp_path );
		if ( false === $size ) {
			return self::bad_type();
		}
		// Checked before any decoding so an oversized upload never reaches the image libraries.
		if ( $size > self::MAX_BYTES ) {
			return new \WP_Error( 'bugspot_too_large', __( 'The screenshot is too large (5 MB maximum).', 'bug-spotter' ), array( 'status' => 413 ) );
		}

		// Real type from the content, never from the file name or the client-sent MIME type.
		$info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $info ) || ! in_array( $info['mime'] ?? '', array( 'image/png', 'image/jpeg' ), true ) ) {
			return self::bad_type();
		}
		if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			return self::bad_type();
		}

		$dir = Installer::private_dir();
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return self::store_failed();
		}

		$editor = self::get_editor( $tmp_path );
		if ( is_wp_error( $editor ) ) {
			return self::bad_type();
		}
		$editor->set_quality( self::JPEG_QUALITY );

		$target = $dir . '/' . bin2hex( random_bytes( 16 ) ) . '.jpg';
		$saved  = $editor->save( $target, 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! isset( $saved['path'] ) ) {
			if ( is_file( $target ) ) {
				wp_delete_file( $target ); // Partial file left by a failed write.
			}
			return self::store_failed();
		}
		$name = basename( (string) $saved['path'] );
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) || ! is_file( $dir . '/' . $name ) ) {
			if ( is_file( $saved['path'] ) ) {
				wp_delete_file( $saved['path'] );
			}
			return self::store_failed();
		}
		return $name;
	}

	/**
	 * Image editor for $path. Imagick is swapped for a stripping subclass for the duration of the call only,
	 * because a plain save() keeps EXIF/ICC/comments; the GD editor drops metadata by itself.
	 *
	 * @return \WP_Image_Editor|\WP_Error
	 */
	private static function get_editor( string $path ) {
		$swap = static function ( array $editors ): array {
			return array_map(
				static fn( $class ) => 'WP_Image_Editor_Imagick' === $class ? Stripping_Imagick_Editor::class : $class,
				$editors
			);
		};
		if ( class_exists( 'Imagick' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
			require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
			add_filter( 'wp_image_editors', $swap, PHP_INT_MAX );
		}
		try {
			return wp_get_image_editor( $path );
		} finally {
			remove_filter( 'wp_image_editors', $swap, PHP_INT_MAX );
		}
	}

	/** Absolute path of a stored screenshot, or null when $name is not a name this store could have produced. */
	public static function path( string $name ): ?string {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return null;
		}
		return Installer::private_dir() . '/' . $name;
	}

	/** Removes a stored screenshot; invalid names and missing files are ignored. */
	public static function delete( string $name ): void {
		$path = self::path( $name );
		if ( null !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private static function bad_type(): \WP_Error {
		return new \WP_Error( 'bugspot_bad_type', __( 'The screenshot must be a PNG or JPEG image.', 'bug-spotter' ), array( 'status' => 422 ) );
	}

	private static function store_failed(): \WP_Error {
		return new \WP_Error( 'bugspot_store_failed', __( 'The screenshot could not be stored.', 'bug-spotter' ), array( 'status' => 500 ) );
	}

	/** "Real capture" or "Page reconstruction", plus "(masked)", from the context's `capture` key; '' when absent. */
	public static function capture_label( array $context ): string {
		$capture = $context['capture'] ?? null;
		if ( ! is_array( $capture ) || ! in_array( $capture['method'] ?? null, array( 'tab', 'dom' ), true ) ) {
			return '';
		}
		$label = 'tab' === $capture['method'] ? __( 'Real capture', 'bug-spotter' ) : __( 'Page reconstruction', 'bug-spotter' );
		if ( true !== ( $capture['masked'] ?? null ) ) {
			return $label;
		}
		/* translators: %s: capture method, for example "Real capture". */
		return sprintf( __( '%s (masked)', 'bug-spotter' ), $label );
	}
}
