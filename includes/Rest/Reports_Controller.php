<?php

namespace Bug_Spotter\Rest;

use Bug_Spotter\Client_Ip;
use Bug_Spotter\Installer;
use Bug_Spotter\Priorities;
use Bug_Spotter\Report_Submission;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Screenshot_Store;
use Bug_Spotter\Settings;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/** REST API bug-spotter/v1, HTTP layer only: public report creation (flow in Report_Submission), and capability-gated status/priority + screenshot access. */
final class Reports_Controller {

	public const NAMESPACE = 'bug-spotter/v1';

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
	}

	public static function can_manage(): bool {
		return current_user_can( Installer::CAPABILITY );
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
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
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
			return Screenshot_Store::too_large();
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

		// Stale-nonce users count as anonymous (tolerate_stale_nonce()).
		$current = wp_get_current_user();
		$id      = Report_Submission::submit(
			$settings,
			self::submission_input( $request ),
			$current instanceof \WP_User ? $current : null,
			Client_Ip::get( (string) $settings['protection']['proxy_header'] )
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'number' => '#' . $id,
			),
			201
		);
	}

	/** The request fields Report_Submission reads (all untrusted). @return array<string,mixed> */
	private static function submission_input( \WP_REST_Request $request ): array {
		return array(
			'description'    => $request->get_param( 'description' ),
			'reporter_email' => $request->get_param( 'reporter_email' ),
			'context'        => $request->get_param( 'context' ),
			'captcha_token'  => $request->get_param( 'captcha_token' ),
			'screenshot'     => $request->get_file_params()['screenshot'] ?? null,
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update( \WP_REST_Request $request ) {
		$repo   = new Reports_Repository();
		$id     = (int) $request['id'];
		$report = $repo->find( $id );
		if ( null === $report ) {
			return new \WP_Error( 'bugspot_not_found', __( 'Report not found.', 'bug-spotter' ), array( 'status' => 404 ) );
		}

		$fields = array();
		foreach ( array( Statuses::class, Priorities::class ) as $list ) {
			$key   = $list::FIELD;
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || ! $list::is_valid( $value ) ) {
				/* translators: %s: field name (status or priority) */
				return Report_Submission::invalid( sprintf( __( 'Invalid value for "%s".', 'bug-spotter' ), $key ) );
			}
			$fields[ $key ] = $value;
		}
		if ( ! $fields ) {
			return Report_Submission::invalid( __( 'Nothing to update: send a status and/or a priority.', 'bug-spotter' ) );
		}
		if ( ! $repo->update( $id, $fields, $report ) ) {
			return new \WP_Error( 'bugspot_save_failed', __( 'The report could not be saved.', 'bug-spotter' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'id'       => $id,
				'status'   => $fields['status'] ?? $report['status'],
				'priority' => $fields['priority'] ?? $report['priority'],
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
}
