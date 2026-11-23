<?php

namespace FixThisPlz\Image;

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/** Imagick editor that drops every profile and comment (EXIF, GPS, ICC, XMP...) before writing. */
final class Stripping_Imagick_Editor extends \WP_Image_Editor_Imagick {

	protected function _save( $image, $filename = null, $mime_type = null ) {
		if ( $image instanceof \Imagick ) {
			$image->stripImage();
		}
		return parent::_save( $image, $filename, $mime_type );
	}
}
