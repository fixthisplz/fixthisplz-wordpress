<?php

namespace Bug_Spotter\Frontend;

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Rest\Reports_Controller;
use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** Enqueues the loader, the button CSS and the JSON config, only where the button must appear (spec 6.1). */
final class Assets {

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		// Whether the admin is allowed is decided by Visibility (show_in_admin), not by the hook.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$settings = Settings::get();
		$is_admin = is_admin();
		$user     = wp_get_current_user();

		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path    = is_string( $raw_uri ) ? wp_parse_url( $raw_uri, PHP_URL_PATH ) : '/';
		$path    = is_string( $path ) && '' !== $path ? $path : '/';

		// On archives the queried object is a term or user: its ID must not be compared with post IDs.
		$queried = ! $is_admin && get_queried_object() instanceof \WP_Post ? (int) get_queried_object_id() : 0;

		if ( ! Visibility::should_load( $settings, $user instanceof \WP_User ? $user : null, $path, $queried, $is_admin ) ) {
			return;
		}

		$button = $settings['button'];
		$config = self::config( $settings, $user instanceof \WP_User && $user->exists() );

		wp_enqueue_style( 'bugspot-front', BUGSPOT_URL . 'assets/css/front.css', array(), BUGSPOT_VERSION );
		wp_add_inline_style( 'bugspot-front', Button_Style::css( $button, ! empty( $settings['visibility']['hide_on_mobile'] ) ) );

		if ( 'dashicon' === $button['icon_type'] ) {
			wp_enqueue_style( 'dashicons' );
		}

		wp_enqueue_script(
			'bugspot-loader',
			BUGSPOT_URL . 'assets/js/loader.js',
			array(),
			BUGSPOT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'bugspot-loader', 'window.bugSpotterConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Public configuration handed to the browser. Never contains the captcha secret key.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function config( array $settings, bool $logged_in ): array {
		$button  = $settings['button'];
		$dialog  = $settings['dialog'];
		$captcha = $settings['captcha'];
		$context = $settings['context'];

		$svg_url = '';
		if ( 'svg' === $button['icon_type'] && $button['svg_attachment_id'] > 0
			&& 'image/svg+xml' === get_post_mime_type( (int) $button['svg_attachment_id'] ) ) {
			$svg_url = (string) wp_get_attachment_url( (int) $button['svg_attachment_id'] );
		}

		$provider = Factory::from_settings( $captcha );

		return array(
			'restUrl'            => rest_url( Reports_Controller::NAMESPACE . '/' ),
			'nonce'              => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
			'moduleUrl'          => BUGSPOT_URL . 'assets/js/reporter.js?ver=' . rawurlencode( BUGSPOT_VERSION ),
			'vendorUrl'          => BUGSPOT_URL . 'assets/vendor/modern-screenshot.mjs?ver=' . rawurlencode( BUGSPOT_VERSION ),
			'version'            => BUGSPOT_VERSION,
			'hideOnMobile'       => (bool) $settings['visibility']['hide_on_mobile'],
			'mobileQuery'        => '(max-width: 782px)',
			'collect'            => array(
				'console' => (bool) $context['console'],
				'network' => (bool) $context['network'],
			),
			'sensitiveSelectors' => array_values( $context['sensitive_selectors'] ),
			'button'             => array(
				'iconType' => $button['icon_type'],
				'dashicon' => 'dashicon' === $button['icon_type'] ? $button['dashicon'] : '',
				'svgUrl'   => $svg_url,
				'shape'    => $button['shape'],
				'label'    => Settings::text( 'button.label' ),
				'tooltip'  => Settings::text( 'button.tooltip' ),
			),
			'dialog'             => array(
				'title'           => Settings::text( 'dialog.title' ),
				'instructions'    => Settings::text( 'dialog.instructions' ),
				'privacyText'     => Settings::text( 'dialog.privacy_text' ),
				'privacyUrl'      => $dialog['privacy_url'],
				'allowScreenshot' => (bool) $dialog['allow_screenshot'],
				'askEmail'        => (bool) $dialog['ask_email_guests'] && ! $logged_in,
				'formats'         => array_values( $dialog['formats'] ),
			),
			'captcha'            => array(
				'provider'  => $provider->id(),
				'siteKey'   => $captcha['site_key'],
				'scriptUrl' => $provider->script_url(),
			),
			'isLoggedIn'         => $logged_in,
			'wpPage'             => self::wp_page(),
			'i18n'               => self::i18n(),
		);
	}

	/** Short description of the current WordPress page (max 64 chars), e.g. "page:42" or "home". */
	private static function wp_page(): string {
		if ( is_admin() ) {
			return 'admin';
		}
		if ( is_front_page() ) {
			return 'front-page';
		}
		if ( is_singular() ) {
			$type = get_post_type();
			return substr( ( is_string( $type ) ? $type : 'post' ) . ':' . get_queried_object_id(), 0, 64 );
		}
		foreach ( array( 'home', 'archive', 'search', '404' ) as $type ) {
			if ( call_user_func( 'is_' . $type ) ) {
				return $type;
			}
		}
		return '';
	}

	/** @return array<string,string> Every label the loader and the reporter UI show. */
	private static function i18n(): array {
		return array(
			'loading'            => __( 'Loading…', 'bug-spotter' ),
			'close'              => __( 'Close', 'bug-spotter' ),
			'cancel'             => __( 'Cancel', 'bug-spotter' ),
			'submit'             => __( 'Submit', 'bug-spotter' ),
			'sending'            => __( 'Sending…', 'bug-spotter' ),
			'wholePage'          => __( 'Whole page', 'bug-spotter' ),
			'select'             => __( 'Select', 'bug-spotter' ),
			'inspectHint'        => __( 'Click the element that has a problem, or choose "Whole page". Press Escape to cancel.', 'bug-spotter' ),
			'changeElement'      => __( 'Change element', 'bug-spotter' ),
			'selectedElement'    => __( 'Selected element', 'bug-spotter' ),
			'description'        => __( 'Description', 'bug-spotter' ),
			'descriptionHint'    => __( 'What went wrong?', 'bug-spotter' ),
			'descriptionError'   => __( 'Please describe the problem.', 'bug-spotter' ),
			'email'              => __( 'Your e-mail (optional)', 'bug-spotter' ),
			'attachScreenshot'   => __( 'Attach a screenshot', 'bug-spotter' ),
			'screenshotPreview'  => __( 'Screenshot preview', 'bug-spotter' ),
			'removeScreenshot'   => __( 'Remove', 'bug-spotter' ),
			'retakeScreenshot'   => __( 'Retake', 'bug-spotter' ),
			'technicalContext'   => __( 'Technical details sent with your report', 'bug-spotter' ),
			'privacyLink'        => __( 'Privacy policy', 'bug-spotter' ),
			'sentTitle'          => __( 'Report sent', 'bug-spotter' ),
			/* translators: %s: report number, for example #42. */
			'sentNumber'         => __( 'Report %s sent.', 'bug-spotter' ),
			'download'           => __( 'Download', 'bug-spotter' ),
			'downloadScreenshot' => __( 'Download the screenshot', 'bug-spotter' ),
			'done'               => __( 'Done', 'bug-spotter' ),
			'errorGeneric'       => __( 'The report could not be sent. Your text was kept; you can try again or download it.', 'bug-spotter' ),
			'errorRateLimit'     => __( 'Too many reports in a short time. Please try again later.', 'bug-spotter' ),
			'errorCaptcha'       => __( 'The anti-spam check failed. Please try again.', 'bug-spotter' ),
			'errorLoad'          => __( 'The bug reporter could not be loaded.', 'bug-spotter' ),
		);
	}
}
