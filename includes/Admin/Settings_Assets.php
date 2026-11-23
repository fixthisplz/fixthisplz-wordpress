<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Captcha\Factory;
use FixThisPlz\Frontend\Assets;
use FixThisPlz\Rest\Reports_Controller;
use FixThisPlz\Settings;

defined( 'ABSPATH' ) || exit;

/** Scripts, styles and localized data of the settings screen (loaded only there, see Menu::enqueue_assets). */
final class Settings_Assets {

	private const SCRIPT = 'fixthisplz-settings';

	public static function enqueue(): void {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();
		wp_enqueue_script( self::SCRIPT, FIXTHISPLZ_URL . 'assets/js/admin-settings.js', array( 'jquery', 'wp-color-picker', 'wp-api-fetch' ), FIXTHISPLZ_VERSION, true );
		if ( in_array( Settings_Page::current_view(), array( 'statuses', 'priorities' ), true ) ) {
			wp_enqueue_script( 'fixthisplz-lists', FIXTHISPLZ_URL . 'assets/js/admin-lists.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), FIXTHISPLZ_VERSION, true );
		}

		if ( 'spam' === Settings_Page::current_tab() ) {
			// captcha.js (imported by the test) imports ./async.js: see Assets::lib_module_ids() for why.
			wp_enqueue_script(
				'fixthisplz-captcha-test',
				FIXTHISPLZ_URL . 'assets/js/admin-captcha-test.js',
				array( 'jquery', 'wp-api-fetch', self::SCRIPT ),
				FIXTHISPLZ_VERSION,
				array(
					'in_footer'           => true,
					'module_dependencies' => Assets::lib_module_ids(),
				)
			);
		}

		if ( 'alerts' === Settings_Page::current_tab() ) {
			wp_enqueue_script( 'fixthisplz-webhooks', FIXTHISPLZ_URL . 'assets/js/admin-webhooks.js', array( 'jquery', 'wp-api-fetch', self::SCRIPT ), FIXTHISPLZ_VERSION, true );
		}

		$script_urls = array();
		foreach ( Factory::PROVIDERS as $provider ) {
			// "{siteKey}" is replaced in the browser by the typed key (only reCAPTCHA v3 puts it in the URL).
			$script_urls[ $provider ] = Factory::from_settings(
				array(
					'provider' => $provider,
					'site_key' => '{siteKey}',
				)
			)->script_url();
		}

		wp_localize_script(
			self::SCRIPT,
			'fixThisPlzSettings',
			array(
				'option'       => Settings::OPTION,
				'previewPath'  => '/' . Reports_Controller::NAMESPACE . '/button-preview',
				'testPath'     => '/' . Reports_Controller::NAMESPACE . '/captcha-test',
				'webhookPath'  => '/' . Reports_Controller::NAMESPACE . '/webhook-test',
				'captchaUrl'   => FIXTHISPLZ_URL . 'assets/js/lib/captcha.js?ver=' . rawurlencode( FIXTHISPLZ_VERSION ),
				'scriptUrls'   => $script_urls,
				'frontCss'     => FIXTHISPLZ_URL . 'assets/css/front.css?ver=' . rawurlencode( FIXTHISPLZ_VERSION ),
				'dashiconsCss' => includes_url( 'css/dashicons.min.css' ),
				'i18n'         => array(
					'defaultLabel' => __( 'Report a bug', 'fixthisplz' ),
					'mediaTitle'   => __( 'Choose an SVG icon', 'fixthisplz' ),
					'mediaButton'  => __( 'Use this icon', 'fixthisplz' ),
					'notSvg'       => __( 'Please choose an SVG file.', 'fixthisplz' ),
					'previewError' => __( 'The preview could not be updated.', 'fixthisplz' ),
					'testLoading'  => __( 'Loading the provider widget…', 'fixthisplz' ),
					'testSolve'    => __( 'Complete the check below to finish the test.', 'fixthisplz' ),
					'testChecking' => __( 'Checking the keys…', 'fixthisplz' ),
					'testValid'    => __( 'Keys are valid.', 'fixthisplz' ),
					'testFailed'   => __( 'The test failed.', 'fixthisplz' ),
					'testNoWidget' => __( 'The provider widget could not be loaded. Check the site key, the provider choice and your connection; reload the page before testing another Google key.', 'fixthisplz' ),
					'testTimeout'  => __( 'No answer from the check. Try again.', 'fixthisplz' ),
					'testNeedKey'  => __( 'Enter the site key first.', 'fixthisplz' ),
					'hookSending'  => __( 'Sending the test…', 'fixthisplz' ),
					'hookFailed'   => __( 'The test could not be sent.', 'fixthisplz' ),
				),
			)
		);
	}
}
