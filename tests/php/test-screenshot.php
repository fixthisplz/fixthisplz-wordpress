<?php

use FixThisPlz\Installer;
use FixThisPlz\Reports_Repository;
use FixThisPlz\Screenshot_Store;

/** Writes a temp file with $bytes and returns its path. */
function shot_tmp( string $bytes ): string {
	$path = wp_tempnam( 'fixthisplz-shot' );
	file_put_contents( $path, $bytes );
	return $path;
}

/** Returns PNG bytes of a w x h GD image. */
function shot_png( int $w = 50, int $h = 50 ): string {
	$im = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, imagecolorallocate( $im, 200, 30, 30 ) );
	ob_start();
	imagepng( $im );
	return (string) ob_get_clean();
}

fixthisplz_test(
	'screenshot: store_reencodes_png_to_private_jpeg',
	function () {
		$tmp    = shot_tmp( shot_png() );
		$name   = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_true( is_string( $name ), 'expected a file name' );
		assert_true( 1 === preg_match( '/^[a-f0-9]{32}\.jpg$/', $name ), 'bad name ' . $name );
		$path = Installer::private_dir() . '/' . $name;
		assert_true( file_exists( $path ), 'file missing' );
		$info = getimagesize( $path );
		assert_same( 'image/jpeg', $info['mime'] );
		assert_same( $path, Screenshot_Store::path( $name ) );
		Screenshot_Store::delete( $name );
		assert_true( ! file_exists( $path ), 'delete failed' );
	}
);

fixthisplz_test(
	'screenshot: store_rejects_text_file_renamed_png',
	function () {
		$tmp = shot_tmp( "not an image at all\n" );
		$png = $tmp . '.png';
		rename( $tmp, $png );
		$res = Screenshot_Store::store( $png );
		unlink( $png );
		assert_wp_error( $res, 'fixthisplz_bad_type' );
		assert_same( 422, $res->get_error_data()['status'] );
	}
);

fixthisplz_test(
	'screenshot: store_rejects_missing_file',
	function () {
		assert_wp_error( Screenshot_Store::store( '/nonexistent/file.png' ), 'fixthisplz_bad_type' );
	}
);

fixthisplz_test(
	'screenshot: store_rejects_gif',
	function () {
		$im = imagecreatetruecolor( 10, 10 );
		ob_start();
		imagegif( $im );
		$tmp = shot_tmp( (string) ob_get_clean() );
		$res = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_wp_error( $res, 'fixthisplz_bad_type' );
	}
);

fixthisplz_test(
	'screenshot: store_rejects_oversized',
	function () {
		$tmp = shot_tmp( shot_png() . str_repeat( "\0", Screenshot_Store::MAX_BYTES ) );
		$res = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_wp_error( $res, 'fixthisplz_too_large' );
		assert_same( 413, $res->get_error_data()['status'] );
	}
);

fixthisplz_test(
	'screenshot: store_rejects_decompression_bomb_dimensions',
	function () {
		// Tiny file whose header declares 10000x10000 (100 MP): hand-built PNG header + IDAT-less stream.
		$ihdr  = pack( 'NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0 );
		$chunk = static function ( string $type, string $data ): string {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		$tmp = shot_tmp( "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', $ihdr ) . $chunk( 'IEND', '' ) );
		$res = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_wp_error( $res, 'fixthisplz_bad_type' );
		assert_same( 422, $res->get_error_data()['status'] );
	}
);

fixthisplz_test(
	'screenshot: path_rejects_traversal_and_bad_names',
	function () {
		assert_same( null, Screenshot_Store::path( '../../wp-config.php' ) );
		assert_same( null, Screenshot_Store::path( '' ) );
		assert_same( null, Screenshot_Store::path( str_repeat( 'a', 32 ) . '.php' ) );
		assert_same( null, Screenshot_Store::path( str_repeat( 'A', 32 ) . '.jpg' ) );
		assert_same( null, Screenshot_Store::path( str_repeat( 'a', 32 ) . ".jpg\n" ) );
	}
);

fixthisplz_test(
	'screenshot: delete_ignores_invalid_and_missing_names',
	function () {
		Screenshot_Store::delete( '../../wp-config.php' );
		Screenshot_Store::delete( str_repeat( 'b', 32 ) . '.jpg' );
		assert_true( true );
	}
);

fixthisplz_test(
	'screenshot: repository_delete_removes_stored_file',
	function () {
		$tmp  = shot_tmp( shot_png() );
		$name = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_true( is_string( $name ) );
		$repo = new Reports_Repository();
		$id   = $repo->insert( array( 'description' => 'Bug shot', 'page_url' => 'https://example.test/s', 'screenshot' => $name ) );
		$path = Screenshot_Store::path( $name );
		assert_true( file_exists( $path ), 'file should exist before delete' );
		$repo->delete( $id );
		assert_true( ! file_exists( $path ), 'file should be removed with the report' );
	}
);

/** JPEG bytes carrying an EXIF APP1 segment and a COM comment, both with a distinctive marker. */
function shot_jpeg_with_metadata(): string {
	$im = imagecreatetruecolor( 40, 40 );
	ob_start();
	imagejpeg( $im );
	$jpeg = (string) ob_get_clean();
	$exif = "Exif\0\0" . 'FIXTHISPLZ-SECRET-EXIF';
	$app1 = "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;
	$com  = 'FIXTHISPLZ-SECRET-EXIF';
	$comm = "\xFF\xFE" . pack( 'n', strlen( $com ) + 2 ) . $com;
	return substr( $jpeg, 0, 2 ) . $app1 . $comm . substr( $jpeg, 2 );
}

function shot_assert_stripped( string $editor_class = '' ): void {
	$filter = '' === $editor_class ? null : static fn() => array( $editor_class );
	if ( $filter ) {
		add_filter( 'wp_image_editors', $filter, 1000 );
	}
	try {
		$tmp = shot_tmp( shot_jpeg_with_metadata() );
		assert_true( false !== strpos( (string) file_get_contents( $tmp ), 'FIXTHISPLZ-SECRET-EXIF' ), 'fixture lacks marker' );
		$name = Screenshot_Store::store( $tmp );
		unlink( $tmp );
		assert_true( is_string( $name ), 'store failed' );
		$bytes = (string) file_get_contents( Screenshot_Store::path( $name ) );
		Screenshot_Store::delete( $name );
		assert_true( false === strpos( $bytes, 'FIXTHISPLZ-SECRET-EXIF' ), 'marker survived' );
		assert_true( false === strpos( $bytes, "Exif\0\0" ), 'EXIF header survived' );
	} finally {
		if ( $filter ) {
			remove_filter( 'wp_image_editors', $filter, 1000 );
		}
	}
}

fixthisplz_test(
	'screenshot: store_strips_exif_and_comment_default_editor',
	function () {
		shot_assert_stripped();
	}
);

fixthisplz_test(
	'screenshot: store_strips_exif_and_comment_gd_editor',
	function () {
		shot_assert_stripped( 'WP_Image_Editor_GD' );
	}
);
