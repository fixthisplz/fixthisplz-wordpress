<?php

namespace FixThisPlz\Frontend;

use FixThisPlz\Captcha\Factory;
use FixThisPlz\Rest\Reports_Controller;
use FixThisPlz\Screenshot_Store;
use FixThisPlz\Settings;

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

		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		// On archives the queried object is a term or user: its ID must not be compared with post IDs.
		$queried = ! $is_admin && get_queried_object() instanceof \WP_Post ? (int) get_queried_object_id() : 0;

		if ( ! Visibility::should_load( $settings, $user instanceof \WP_User ? $user : null, $path, $queried, $is_admin ) ) {
			return;
		}

		$button = $settings['button'];
		$config = self::config( $settings, $user instanceof \WP_User && $user->exists() );

		wp_enqueue_style( 'fixthisplz-front', FIXTHISPLZ_URL . 'assets/css/front.css', array(), FIXTHISPLZ_VERSION );
		wp_add_inline_style( 'fixthisplz-front', Button_Style::css( $button, ! empty( $settings['visibility']['hide_on_mobile'] ) ) );

		if ( 'dashicon' === $button['icon_type'] ) {
			wp_enqueue_style( 'dashicons' );
		}

		wp_enqueue_script(
			'fixthisplz-loader',
			FIXTHISPLZ_URL . 'assets/js/loader.js',
			array(),
			FIXTHISPLZ_VERSION,
			array(
				'in_footer'           => true,
				'strategy'            => 'defer',
				'module_dependencies' => self::lib_module_ids(),
			)
		);
		wp_add_inline_script( 'fixthisplz-loader', 'window.fixThisPlzConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Registers every assets/js/lib module under its unversioned URL and returns those ids.
	 *
	 * Why: reporter.js and captcha.js are loaded with ?ver=, but the browser drops that query when it
	 * resolves their relative imports ('./dialog.js'), so after an update it could mix cached old lib
	 * files with new ones. A script declaring these ids in 'module_dependencies' makes WordPress (7.0+)
	 * print its import map, which remaps each unversioned URL to its ?ver= URL, for static and dynamic
	 * imports alike, without preloading anything. Before 7.0 the arg is ignored: same behaviour as before.
	 *
	 * @return string[]
	 */
	public static function lib_module_ids(): array {
		$ids = array();
		foreach ( glob( FIXTHISPLZ_DIR . 'assets/js/lib/*.js' ) ?: array() as $file ) {
			$url = FIXTHISPLZ_URL . 'assets/js/lib/' . basename( $file );
			wp_register_script_module( $url, $url, array(), FIXTHISPLZ_VERSION );
			$ids[] = $url;
		}
		return $ids;
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
			'moduleUrl'          => FIXTHISPLZ_URL . 'assets/js/reporter.js?ver=' . rawurlencode( FIXTHISPLZ_VERSION ),
			'vendorUrl'          => FIXTHISPLZ_URL . 'assets/vendor/modern-screenshot.js?ver=' . rawurlencode( FIXTHISPLZ_VERSION ),
			'version'            => FIXTHISPLZ_VERSION,
			'hideOnMobile'       => (bool) $settings['visibility']['hide_on_mobile'],
			'mobileQuery'        => Button_Style::MOBILE_QUERY,
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
				'privacyUrl'      => '' !== $dialog['privacy_url'] ? $dialog['privacy_url'] : esc_url_raw( (string) get_privacy_policy_url() ),
				'allowScreenshot' => (bool) $dialog['allow_screenshot'],
				'captureMethod'   => $dialog['capture_method'],
				'maskCapture'     => (bool) $dialog['mask_capture'],
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
			'loading'            => __( 'Loading…', 'fixthisplz' ),
			'close'              => __( 'Close', 'fixthisplz' ),
			'cancel'             => __( 'Cancel', 'fixthisplz' ),
			'submit'             => __( 'Submit', 'fixthisplz' ),
			'sending'            => __( 'Sending…', 'fixthisplz' ),
			'wholePage'          => __( 'Whole page', 'fixthisplz' ),
			'select'             => __( 'Select', 'fixthisplz' ),
			'inspectHint'        => __( 'Click the element that has a problem, or choose "Whole page". Press Escape to cancel.', 'fixthisplz' ),
			'changeElement'      => __( 'Change element', 'fixthisplz' ),
			'selectedElement'    => __( 'Selected element', 'fixthisplz' ),
			'description'        => __( 'Description', 'fixthisplz' ),
			'descriptionHint'    => __( 'What went wrong?', 'fixthisplz' ),
			'descriptionError'   => __( 'Please describe the problem.', 'fixthisplz' ),
			'email'              => __( 'Your e-mail (optional)', 'fixthisplz' ),
			'attachScreenshot'   => __( 'Attach a screenshot', 'fixthisplz' ),
			'screenshotPreview'  => __( 'Screenshot preview', 'fixthisplz' ),
			'removeScreenshot'   => __( 'Remove', 'fixthisplz' ),
			'retakeScreenshot'   => __( 'Retake', 'fixthisplz' ),
			'annotate'           => __( 'Annotate', 'fixthisplz' ),
			'toolRectangle'      => __( 'Rectangle', 'fixthisplz' ),
			'toolArrow'          => __( 'Arrow', 'fixthisplz' ),
			'toolPen'            => __( 'Pen', 'fixthisplz' ),
			'toolText'           => __( 'Text', 'fixthisplz' ),
			'toolHide'           => __( 'Hide', 'fixthisplz' ),
			'toolSelect'         => __( 'Select', 'fixthisplz' ),
			'annotationColor'    => __( 'Color', 'fixthisplz' ),
			'colorRed'           => __( 'Red', 'fixthisplz' ),
			'colorOrange'        => __( 'Orange', 'fixthisplz' ),
			'colorYellow'        => __( 'Yellow', 'fixthisplz' ),
			'colorGreen'         => __( 'Green', 'fixthisplz' ),
			'colorBlue'          => __( 'Blue', 'fixthisplz' ),
			'colorBlack'         => __( 'Black', 'fixthisplz' ),
			'annotationSize'     => __( 'Size', 'fixthisplz' ),
			'sizeSmall'          => __( 'Small size', 'fixthisplz' ),
			'sizeMedium'         => __( 'Medium size', 'fixthisplz' ),
			'sizeLarge'          => __( 'Large size', 'fixthisplz' ),
			'deleteShape'        => __( 'Delete', 'fixthisplz' ),
			'undoShape'          => __( 'Undo last', 'fixthisplz' ),
			'clearShapes'        => __( 'Clear all', 'fixthisplz' ),
			'applyAnnotations'   => __( 'Apply', 'fixthisplz' ),
			'errorAnnotate'      => __( 'The annotations could not be applied.', 'fixthisplz' ),
			'technicalContext'   => __( 'Technical details sent with your report', 'fixthisplz' ),
			'privacyLink'        => __( 'Privacy policy', 'fixthisplz' ),
			'sentTitle'          => __( 'Report sent', 'fixthisplz' ),
			/* translators: %s: report number, for example #42. */
			'sentNumber'         => __( 'Report %s sent.', 'fixthisplz' ),
			'download'           => __( 'Download', 'fixthisplz' ),
			'downloadScreenshot' => __( 'Download the screenshot', 'fixthisplz' ),
			'done'               => __( 'Done', 'fixthisplz' ),
			'errorGeneric'       => __( 'The report could not be sent. Your text was kept; you can try again or download it.', 'fixthisplz' ),
			'errorRateLimit'     => __( 'Too many reports in a short time. Please try again later.', 'fixthisplz' ),
			'errorNotAllowed'    => __( 'You are not allowed to send bug reports.', 'fixthisplz' ),
			'errorCaptcha'       => __( 'The anti-spam check failed. Please try again.', 'fixthisplz' ),
			'errorLoad'          => __( 'The bug reporter could not be loaded.', 'fixthisplz' ),
			/* translators: %s: short description of the highlighted element, for example "button – Send". */
			'inspectHighlighted' => __( 'Highlighted: %s. Press Enter to select it, or the Up arrow for its parent.', 'fixthisplz' ),
			'reportTitle'        => __( 'Bug report', 'fixthisplz' ),
			'contactEmail'       => __( 'Contact e-mail', 'fixthisplz' ),
			'console'            => __( 'Console', 'fixthisplz' ),
			'network'            => __( 'Failed requests', 'fixthisplz' ),
			'generatedAt'        => __( 'Generated at', 'fixthisplz' ),
			'capturing'          => __( 'Taking the screenshot…', 'fixthisplz' ),
			'emailError'         => __( 'Please enter a valid e-mail address, or leave the field empty.', 'fixthisplz' ),
			'captureTab'         => __( 'Real capture', 'fixthisplz' ),
			'captureDom'         => __( 'Page reconstruction', 'fixthisplz' ),
			'captureMasked'      => __( 'masked', 'fixthisplz' ),
			'screenshot'         => __( 'Screenshot', 'fixthisplz' ),
			'errorCapture'       => __( 'The screenshot could not be taken. You can send the report without it.', 'fixthisplz' ),
			'errorCaptureDenied' => __( 'Screenshot cancelled: you can send the report without a screenshot.', 'fixthisplz' ),
			'errorCaptureTooLarge' => sprintf(
				/* translators: %s: maximum screenshot size in megabytes, for example "5". */
				__( 'The screenshot is too large (%s MB maximum). You can send the report without it.', 'fixthisplz' ),
				number_format_i18n( Screenshot_Store::MAX_BYTES / MB_IN_BYTES )
			),
			'errorTooLarge'      => __( 'The screenshot is too large to be sent. Remove it or retake it, then try again.', 'fixthisplz' ),
			'errorInvalid'       => __( 'The report was refused. Please check the form and try again.', 'fixthisplz' ),
			'errorDisabled'      => __( 'Bug reporting is currently disabled on this site.', 'fixthisplz' ),
			'errorCaptchaUnavailable' => __( 'The anti-spam check is unavailable right now. Please try again later.', 'fixthisplz' ),
			'errorCaptchaRequired' => __( 'Please complete the anti-spam check.', 'fixthisplz' ),
			'errorPageUrl'       => __( 'This page cannot be reported: its address is not a web address.', 'fixthisplz' ),
		);
	}
}
