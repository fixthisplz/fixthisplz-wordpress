<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "Form" tab of the settings screen: what visitors see once they click the button. */
final class Settings_Form_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$g = $s['dialog'];
		self::texts( $g );
		self::screenshot( $g );

		F::section( __( 'Form fields', 'fixthisplz' ), __( 'Extra field offered to visitors who are not logged in.', 'fixthisplz' ) );
		F::row_open( __( 'Contact e-mail', 'fixthisplz' ) );
		F::checkbox( array( 'dialog', 'ask_email_guests' ), (bool) $g['ask_email_guests'], __( 'Ask guests for an optional contact e-mail address', 'fixthisplz' ) );
		F::row_close();
		F::table_close();

		self::formats( $g );
		self::privacy_notice( $g );
	}

	/** @param array<string,mixed> $g */
	private static function texts( array $g ): void {
		F::section( __( 'Texts', 'fixthisplz' ), __( 'Texts shown at the top of the report form.', 'fixthisplz' ) );

		$id = F::id( array( 'dialog', 'title' ) );
		F::row_open( __( 'Form title', 'fixthisplz' ), $id );
		F::input( 'text', array( 'dialog', 'title' ), $g['title'], array( 'placeholder' => __( 'Report a bug', 'fixthisplz' ), 'class' => 'regular-text' ) );
		F::row_close();

		$id = F::id( array( 'dialog', 'instructions' ) );
		F::row_open( __( 'Prompt text', 'fixthisplz' ), $id );
		F::textarea( array( 'dialog', 'instructions' ), $g['instructions'], 3, __( 'Describe what went wrong and what you expected to happen.', 'fixthisplz' ), true );
		F::description( __( 'Shown above the description field.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $g */
	private static function screenshot( array $g ): void {
		F::section( __( 'Screenshot', 'fixthisplz' ), __( 'Let visitors attach an image of the page to their report.', 'fixthisplz' ) );

		F::row_open( __( 'Allow screenshots', 'fixthisplz' ) );
		F::checkbox( array( 'dialog', 'allow_screenshot' ), (bool) $g['allow_screenshot'], __( 'Let reporters attach a screenshot of the page', 'fixthisplz' ) );
		F::row_close();

		$only_with_screenshot = F::show_if( array( 'dialog', 'allow_screenshot' ), '=', '1' );

		F::row_open( __( 'Capture method', 'fixthisplz' ), '', true, $only_with_screenshot );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Capture method', 'fixthisplz' ) . '</legend>';
		F::radio( array( 'dialog', 'capture_method' ), 'tab', $g['capture_method'], __( 'Real capture of the tab (recommended) — exact image, the browser asks the visitor for permission', 'fixthisplz' ) );
		F::radio( array( 'dialog', 'capture_method' ), 'dom', $g['capture_method'], __( 'Page reconstruction — no permission prompt, may differ slightly from the screen', 'fixthisplz' ) );
		F::description( __( 'On phones and in browsers without screen sharing, the reconstruction is always used.', 'fixthisplz' ) );
		echo '</fieldset>';
		F::row_close();

		// Stays here rather than in Privacy: show_if only links fields of the same form.
		F::row_open( __( 'Masking', 'fixthisplz' ), '', true, $only_with_screenshot );
		F::checkbox( array( 'dialog', 'mask_capture' ), (bool) $g['mask_capture'], __( 'Hide typed fields and sensitive areas in the screenshot', 'fixthisplz' ) );
		F::description( __( 'On a real capture, masking works only when the visitor shares this tab (not a window or the whole screen). Add other areas to mask in Privacy › Areas to mask.', 'fixthisplz' ) );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $g */
	private static function formats( array $g ): void {
		F::section( __( 'Downloadable copy', 'fixthisplz' ), __( 'Visitors can download a copy of their report once it is sent, or if sending fails.', 'fixthisplz' ) );
		F::row_open( __( 'Formats offered', 'fixthisplz' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Formats offered', 'fixthisplz' ) . '</legend>';
		F::list_marker( array( 'dialog', 'formats' ) );
		$formats = array(
			'md'   => __( 'Markdown (.md)', 'fixthisplz' ),
			'json' => __( 'JSON (.json)', 'fixthisplz' ),
			'txt'  => __( 'Plain text (.txt)', 'fixthisplz' ),
		);
		foreach ( $formats as $format => $label ) {
			F::checkbox_item( array( 'dialog', 'formats' ), $format, in_array( $format, $g['formats'], true ), $label );
		}
		F::description( __( 'If none is checked, all of them are offered.', 'fixthisplz' ) );
		echo '</fieldset>';
		F::row_close();
		F::table_close();
	}

	/** @param array<string,mixed> $g */
	private static function privacy_notice( array $g ): void {
		F::section( __( 'Information notice (GDPR)', 'fixthisplz' ), __( 'Shown at the bottom of the form, before the send button, with a link to your privacy policy.', 'fixthisplz' ) );

		$id = F::id( array( 'dialog', 'privacy_text' ) );
		F::row_open( __( 'Notice text', 'fixthisplz' ), $id );
		F::textarea( array( 'dialog', 'privacy_text' ), $g['privacy_text'], 3, __( 'Your report includes technical details about this page and your browser.', 'fixthisplz' ) );
		F::row_close();

		$id      = F::id( array( 'dialog', 'privacy_url' ) );
		$privacy = get_privacy_policy_url();
		F::row_open( __( 'Privacy policy link', 'fixthisplz' ), $id );
		F::input( 'url', array( 'dialog', 'privacy_url' ), $g['privacy_url'], array( 'placeholder' => '' !== $privacy ? $privacy : 'https://', 'class' => 'regular-text code' ), true );
		F::description( __( 'Leave empty to use the privacy policy page set in the WordPress settings (Settings › Privacy).', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		F::table_close();
	}
}
