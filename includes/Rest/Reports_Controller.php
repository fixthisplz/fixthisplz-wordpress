<?php

namespace Bug_Spotter\Rest;

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Client_Ip;
use Bug_Spotter\Context_Validator;
use Bug_Spotter\Frontend\Button_Style;
use Bug_Spotter\Frontend\Visibility;
use Bug_Spotter\Mailer;
use Bug_Spotter\Rate_Limiter;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Screenshot_Store;
use Bug_Spotter\Secret;
use Bug_Spotter\Settings;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** REST API bug-spotter/v1: public report creation, and capability-gated status/priority + screenshot access. */
final class Reports_Controller {

	public const NAMESPACE = 'bug-spotter/v1';

	private const MAX_DESCRIPTION = 5000;
	private const MAX_EMAIL       = 254;
	/** Raw JSON bound, applied before decoding: the validator walks whole arrays before it truncates them. */
	private const MAX_CONTEXT_BYTES = 65536;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// After core's cookie check (priority 100).
		add_filter( 'rest_authentication_errors', array( self::class, 'tolerate_stale_nonce' ), 101 );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reports',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/reports/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/reports/(?P<id>\d+)/screenshot',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'screenshot' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
		// Settings screen helpers: same capability as the screen itself (spec 6.4).
		register_rest_route(
			self::NAMESPACE,
			'/captcha-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'captcha_test' ),
				'permission_callback' => array( self::class, 'can_manage_options' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/button-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'button_preview' ),
				'permission_callback' => array( self::class, 'can_manage_options' ),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'bugspot_manage' );
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
		if ( ! in_array( $provider, array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha' ), true ) ) {
			return self::invalid( __( 'Choose an anti-bot provider to test.', 'bug-spotter' ) );
		}
		$site_key = trim( self::string_param( $request, 'site_key' ) );
		$secret   = trim( self::string_param( $request, 'secret_key' ) );
		// The saved secret belongs to the saved provider: it is reused only when testing that same provider.
		if ( '' === $secret && $provider === $settings['captcha']['provider'] ) {
			$secret = (string) $settings['captcha']['secret_key'];
		}
		if ( '' === $secret ) {
			return self::invalid( __( 'The secret key is required.', 'bug-spotter' ) );
		}
		if ( '' === $site_key ) {
			return self::invalid( __( 'Enter the site key first.', 'bug-spotter' ) );
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
	 * validated by the same sanitizer as a save. Nothing is written.
	 */
	public static function button_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$button = $request->get_param( 'button' );
		$clean  = Settings::sanitize( array( 'button' => is_array( $button ) ? $button : array() ) )['button'];
		return new \WP_REST_Response( array( 'css' => Button_Style::css( $clean ) ), 200 );
	}

	/**
	 * Core answers 403 rest_cookie_invalid_nonce to a logged-in visitor whose nonce is stale (page cached for
	 * over 24 h). For the public submit route only, the request carries on as anonymous instead: the report is
	 * saved without a user. Other routes keep strict nonce enforcement.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function tolerate_stale_nonce( $result ) {
		if ( is_wp_error( $result ) && 'rest_cookie_invalid_nonce' === $result->get_error_code() && self::is_create_request() ) {
			wp_set_current_user( 0 );
			return null;
		}
		return $result;
	}

	private static function is_create_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
		return '/' . self::NAMESPACE . '/reports' === untrailingslashit( '/' . ltrim( $route, '/' ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create( \WP_REST_Request $request ) {
		$settings = Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return new \WP_Error( 'bugspot_disabled', __( 'Bug reporting is disabled.', 'bug-spotter' ), array( 'status' => 404 ) );
		}

		// A body over post_max_size is dropped whole by PHP (no params, no files): say so instead of a misleading
		// "description required". Checked before the limiter so it is not counted.
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) && is_scalar( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$limit  = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $limit > 0 && $length > $limit && ! $request->get_body_params() && ! $request->get_file_params() ) {
			return new \WP_Error( 'bugspot_too_large', __( 'The screenshot is too large (5 MB maximum).', 'bug-spotter' ), array( 'status' => 413 ) );
		}

		// Honeypot: same status and shape as a real success; nothing written, counted or mailed.
		// Any non-empty value counts, including arrays (website[]=x).
		$trap = $request->get_param( 'website' );
		if ( null !== $trap && '' !== $trap && array() !== $trap && ! ( is_string( $trap ) && '' === trim( $trap ) ) ) {
			return new \WP_REST_Response(
				array(
					'id'     => 0,
					'number' => '',
				),
				201
			);
		}

		// Server-side half of the visibility rule (spec 6.1): roles/guests only. Page exclusions and mobile
		// are front-end display choices and are not enforced here. Stale-nonce users count as anonymous.
		$current = wp_get_current_user();
		if ( ! Visibility::user_allowed( $settings, $current instanceof \WP_User ? $current : null ) ) {
			return new \WP_Error( 'bugspot_not_allowed', __( 'You are not allowed to send bug reports.', 'bug-spotter' ), array( 'status' => 403 ) );
		}

		$protection = $settings['protection'];
		$ip         = Client_Ip::get( (string) $protection['proxy_header'] );
		$ip_hash    = Secret::hmac( $ip );
		if ( ! ( new Rate_Limiter( (int) $protection['per_hour'], (int) $protection['per_day'] ) )->attempt( $ip_hash ) ) {
			return new \WP_Error( 'bugspot_rate_limited', __( 'Too many reports. Please try again later.', 'bug-spotter' ), array( 'status' => 429 ) );
		}

		$captcha = Factory::from_settings( $settings['captcha'] )->verify( self::string_param( $request, 'captcha_token' ), $ip );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}
		if ( true !== $captcha ) {
			return new \WP_Error( 'bugspot_captcha_failed', __( 'The anti-bot check failed.', 'bug-spotter' ), array( 'status' => 403 ) );
		}

		$description = trim( self::string_param( $request, 'description' ) );
		if ( '' === $description || mb_strlen( $description ) > self::MAX_DESCRIPTION ) {
			return self::invalid( __( 'The description is required (5000 characters maximum).', 'bug-spotter' ) );
		}
		$description = self::clean_description( $description );
		if ( '' === $description ) {
			return self::invalid( __( 'The description is required (5000 characters maximum).', 'bug-spotter' ) );
		}

		$user_id = get_current_user_id();
		$email   = '';
		if ( 0 === $user_id ) {
			$email = trim( self::string_param( $request, 'reporter_email' ) );
			if ( '' !== $email && ( mb_strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) ) {
				return self::invalid( __( 'The contact e-mail address is not valid.', 'bug-spotter' ) );
			}
		}

		$raw_context = $request->get_param( 'context' );
		$context_size = is_string( $raw_context ) ? strlen( $raw_context ) : strlen( (string) wp_json_encode( $raw_context ) );
		if ( $context_size > self::MAX_CONTEXT_BYTES ) {
			return self::invalid( __( 'The report context is too large.', 'bug-spotter' ) );
		}
		$context = Context_Validator::validate( $raw_context );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$screenshot = '';
		$file       = $request->get_file_params()['screenshot'] ?? null;
		if ( ! empty( $settings['dialog']['allow_screenshot'] ) && is_array( $file ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) {
			$stored = self::store_upload( $file );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$screenshot = $stored;
		}

		$id = ( new Reports_Repository() )->insert(
			array(
				'description'    => $description,
				'page_url'       => $context['url'],
				'context'        => $context,
				'user_id'        => $user_id,
				'reporter_email' => $email,
				'screenshot'     => $screenshot,
				'ip_hash'        => $ip_hash,
			)
		);
		if ( $id < 1 ) {
			if ( '' !== $screenshot ) {
				Screenshot_Store::delete( $screenshot );
			}
			return new \WP_Error( 'bugspot_save_failed', __( 'The report could not be saved.', 'bug-spotter' ), array( 'status' => 500 ) );
		}

		try {
			Mailer::notify( $id );
		} catch ( \Throwable $e ) {
			// The report is stored; a failing mail hook must not turn that into an error.
			error_log( 'Bug Spotter: notification failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'number' => '#' . $id,
			),
			201
		);
	}

	/** Keeps the reporter's text as typed (outputs escape): valid UTF-8, no control chars, LF newlines. */
	private static function clean_description( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update( \WP_REST_Request $request ) {
		$repo = new Reports_Repository();
		$id   = (int) $request['id'];
		if ( null === $repo->find( $id ) ) {
			return new \WP_Error( 'bugspot_not_found', __( 'Report not found.', 'bug-spotter' ), array( 'status' => 404 ) );
		}

		$fields = array();
		foreach ( array( 'status', 'priority' ) as $key ) {
			if ( null === $request->get_param( $key ) ) {
				continue;
			}
			$value = $request->get_param( $key );
			$valid = is_string( $value ) && ( 'status' === $key ? Statuses::is_valid( $value ) : Statuses::is_valid_priority( $value ) );
			if ( ! $valid ) {
				/* translators: %s: field name (status or priority) */
				return self::invalid( sprintf( __( 'Invalid value for "%s".', 'bug-spotter' ), $key ) );
			}
			$fields[ $key ] = $value;
		}
		if ( ! $fields ) {
			return self::invalid( __( 'Nothing to update: send a status and/or a priority.', 'bug-spotter' ) );
		}
		if ( ! $repo->update( $id, $fields ) ) {
			return new \WP_Error( 'bugspot_save_failed', __( 'The report could not be saved.', 'bug-spotter' ), array( 'status' => 500 ) );
		}

		$report = $repo->find( $id );
		return new \WP_REST_Response(
			array(
				'id'       => $id,
				'status'   => $report['status'] ?? $fields['status'] ?? '',
				'priority' => $report['priority'] ?? $fields['priority'] ?? '',
			),
			200
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function screenshot( \WP_REST_Request $request ) {
		$report = ( new Reports_Repository() )->find( (int) $request['id'] );
		$name   = is_array( $report ) && is_string( $report['screenshot'] ?? null ) ? $report['screenshot'] : '';
		$path   = '' !== $name ? Screenshot_Store::path( $name ) : null;
		if ( null === $path || ! is_file( $path ) ) {
			return new \WP_Error( 'bugspot_not_found', __( 'Screenshot not found.', 'bug-spotter' ), array( 'status' => 404 ) );
		}

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'image/jpeg' );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		// Binary body: stream it from rest_pre_serve_request instead of JSON-encoding the response.
		$stream = static function ( $served, $result ) use ( &$stream, $response, $path ) {
			if ( $result !== $response ) {
				return $served;
			}
			remove_filter( 'rest_pre_serve_request', $stream, 10 );
			// Core's no-cache headers were merged into Cache-Control already; restate the exact policy.
			header( 'Cache-Control: private, no-store' );
			header( 'Content-Length: ' . filesize( $path ) );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return true;
		};
		add_filter( 'rest_pre_serve_request', $stream, 10, 2 );
		return $response;
	}

	/** @return string|\WP_Error */
	private static function store_upload( array $file ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_OK );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new \WP_Error( 'bugspot_too_large', __( 'The screenshot is too large (5 MB maximum).', 'bug-spotter' ), array( 'status' => 413 ) );
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'bugspot_upload_failed', __( 'The screenshot could not be uploaded.', 'bug-spotter' ), array( 'status' => 422 ) );
		}
		return Screenshot_Store::store( is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '' );
	}

	private static function string_param( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_string( $value ) ? $value : '';
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'bugspot_invalid', $message, array( 'status' => 422 ) );
	}
}
