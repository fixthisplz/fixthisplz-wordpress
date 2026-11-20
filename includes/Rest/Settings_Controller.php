<?php

namespace Bug_Spotter\Rest;

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Client_Ip;
use Bug_Spotter\Frontend\Button_Style;
use Bug_Spotter\Report_Submission;
use Bug_Spotter\Settings;
use Bug_Spotter\Settings_Sanitizer;

defined( 'ABSPATH' ) || exit;

/** REST helpers of the settings screen (bug-spotter/v1): anti-bot key test and button preview. Nothing is saved. */
final class Settings_Controller {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// Same capability as the settings screen itself (spec 6.4).
		register_rest_route(
			Reports_Controller::NAMESPACE,
			'/captcha-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'captcha_test' ),
				'permission_callback' => array( self::class, 'can_manage_options' ),
			)
		);
		register_rest_route(
			Reports_Controller::NAMESPACE,
			'/button-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'button_preview' ),
				'permission_callback' => array( self::class, 'can_manage_options' ),
			)
		);
	}

	public static function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Checks typed (possibly unsaved) anti-bot keys with a token produced by the provider widget in the
	 * settings screen. A blank secret means "the saved one", like the settings form.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function captcha_test( \WP_REST_Request $request ) {
		$settings = Settings::get();
		$provider = self::string_param( $request, 'provider' );
		if ( ! in_array( $provider, Factory::PROVIDERS, true ) ) {
			return Report_Submission::invalid( __( 'Choose an anti-bot provider to test.', 'bug-spotter' ) );
		}
		$site_key = trim( self::string_param( $request, 'site_key' ) );
		$secret   = trim( self::string_param( $request, 'secret_key' ) );
		// The saved secret belongs to the saved provider: it is reused only when testing that same provider.
		if ( '' === $secret && $provider === $settings['captcha']['provider'] ) {
			$secret = (string) $settings['captcha']['secret_key'];
		}
		if ( '' === $secret ) {
			return Report_Submission::invalid( __( 'The secret key is required.', 'bug-spotter' ) );
		}
		if ( '' === $site_key ) {
			return Report_Submission::invalid( __( 'Enter the site key first.', 'bug-spotter' ) );
		}
		$threshold = $request->get_param( 'v3_threshold' );

		$result = Factory::from_settings(
			array(
				'provider'     => $provider,
				'site_key'     => $site_key,
				'secret_key'   => $secret,
				'v3_threshold' => is_numeric( $threshold ) ? max( 0.0, min( 1.0, (float) $threshold ) ) : $settings['captcha']['v3_threshold'],
			)
		)->verify( self::string_param( $request, 'token' ), Client_Ip::get( (string) $settings['protection']['proxy_header'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new \WP_Error( 'bugspot_captcha_failed', __( 'The anti-bot check failed.', 'bug-spotter' ), array( 'status' => 403 ) );
		}
		return new \WP_REST_Response(
			array(
				'valid'   => true,
				'message' => __( 'Keys are valid.', 'bug-spotter' ),
			),
			200
		);
	}

	/**
	 * Live preview of the floating button: the exact front-end CSS for the posted (unsaved) "button" block,
	 * validated by the same sanitizer as a save.
	 */
	public static function button_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$button = $request->get_param( 'button' );
		$clean  = Settings_Sanitizer::sanitize( array( 'button' => is_array( $button ) ? $button : array() ) )['button'];
		return new \WP_REST_Response( array( 'css' => Button_Style::css( $clean ) ), 200 );
	}

	private static function string_param( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_string( $value ) ? $value : '';
	}
}
