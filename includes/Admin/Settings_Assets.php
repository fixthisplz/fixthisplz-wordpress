<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Frontend\Assets;
use Bug_Spotter\Rest\Reports_Controller;
use Bug_Spotter\Settings;

defined( 'ABSPATH' ) || exit;

/** Scripts, styles and localized data of the settings screen (loaded only there, see Menu::enqueue_assets). */
final class Settings_Assets {

	private const SCRIPT = 'bug-spotter-settings';

	public static function enqueue(): void {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();
		// captcha.js (loaded by the captcha test) imports ./async.js: see Assets::lib_module_ids() for why.
		wp_enqueue_script(
			self::SCRIPT,
			BUGSPOT_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-color-picker', 'wp-api-fetch' ),
			BUGSPOT_VERSION,
			array(
				'in_footer'           => true,
				'module_dependencies' => Assets::lib_module_ids(),
			)
		);
		if ( in_array( Settings_Page::current_tab(), array( 'statuses', 'priorities' ), true ) ) {
			wp_enqueue_script( 'bug-spotter-lists', BUGSPOT_URL . 'assets/js/admin-lists.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), BUGSPOT_VERSION, true );
		}

		if ( 'integrations' === Settings_Page::current_tab() ) {
			wp_enqueue_script( 'bug-spotter-webhooks', BUGSPOT_URL . 'assets/js/admin-webhooks.js', array( 'jquery', 'wp-api-fetch', self::SCRIPT ), BUGSPOT_VERSION, true );
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
			'bugspotSettings',
			array(
				'option'       => Settings::OPTION,
				'previewPath'  => '/' . Reports_Controller::NAMESPACE . '/button-preview',
				'testPath'     => '/' . Reports_Controller::NAMESPACE . '/captcha-test',
				'webhookPath'  => '/' . Reports_Controller::NAMESPACE . '/webhook-test',
				'captchaUrl'   => BUGSPOT_URL . 'assets/js/lib/captcha.js?ver=' . rawurlencode( BUGSPOT_VERSION ),
				'scriptUrls'   => $script_urls,
				'frontCss'     => BUGSPOT_URL . 'assets/css/front.css?ver=' . rawurlencode( BUGSPOT_VERSION ),
				'dashiconsCss' => includes_url( 'css/dashicons.min.css' ),
				'i18n'         => array(
					'defaultLabel' => __( 'Report a bug', 'bug-spotter' ),
					'mediaTitle'   => __( 'Choose an SVG icon', 'bug-spotter' ),
					'mediaButton'  => __( 'Use this icon', 'bug-spotter' ),
					'notSvg'       => __( 'Please choose an SVG file.', 'bug-spotter' ),
					'previewTitle' => __( 'Button preview', 'bug-spotter' ),
					'previewError' => __( 'The preview could not be updated.', 'bug-spotter' ),
					'testLoading'  => __( 'Loading the provider widget…', 'bug-spotter' ),
					'testSolve'    => __( 'Complete the check below to finish the test.', 'bug-spotter' ),
					'testChecking' => __( 'Checking the keys…', 'bug-spotter' ),
					'testValid'    => __( 'Keys are valid.', 'bug-spotter' ),
					'testFailed'   => __( 'The test failed.', 'bug-spotter' ),
					'testNoWidget' => __( 'The provider widget could not be loaded. Check the site key, the provider choice and your connection; reload the page before testing another Google key.', 'bug-spotter' ),
					'testTimeout'  => __( 'No answer from the check. Try again.', 'bug-spotter' ),
					'testNeedKey'  => __( 'Enter the site key first.', 'bug-spotter' ),
					'hookSending'  => __( 'Sending the test…', 'bug-spotter' ),
					'hookFailed'   => __( 'The test could not be sent.', 'bug-spotter' ),
				),
			)
		);
	}
}
