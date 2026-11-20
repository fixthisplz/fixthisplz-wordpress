<?php

namespace Bug_Spotter;

use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Frontend\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Business flow of a new report, independent of HTTP: visibility, send limit, anti-bot check, field
 * validation, screenshot storage, insertion and notification. Steps run in that order and stop at the first
 * error, so a refused report is counted at most once by the send limit, and never stored or mailed.
 */
final class Report_Submission {

	private const MAX_DESCRIPTION = 5000;
	private const MAX_EMAIL      = 254;
	/** Raw JSON bound, applied before decoding: the validator walks whole arrays before it truncates them. */
	private const MAX_CONTEXT_BYTES = 65536;

	/**
	 * @param array<string,mixed> $settings Settings::get().
	 * @param array<string,mixed> $input    Untrusted: description, reporter_email, context (JSON string or array),
	 *                                      captcha_token, screenshot (an uploaded-file array from $_FILES, or null).
	 * @param \WP_User|null       $user     The sender; null or ID 0 for a visitor.
	 * @param string              $ip       Client IP, already resolved with the configured proxy header.
	 * @return int|\WP_Error The new report id.
	 */
	public static function submit( array $settings, array $input, ?\WP_User $user, string $ip ) {
		$ip_hash = self::check_sender( $settings, $input, $user, $ip );
		if ( is_wp_error( $ip_hash ) ) {
			return $ip_hash;
		}
		$user_id = null !== $user ? (int) $user->ID : 0;
		$fields  = self::read_fields( $input, $user_id );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$screenshot = '';
		$file       = $input['screenshot'] ?? null;
		if ( ! empty( $settings['dialog']['allow_screenshot'] ) && is_array( $file ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) {
			$stored = self::store_upload( $file );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$screenshot = $stored;
		}
		// The capture method describes the screenshot: without a stored one it means nothing.
		if ( '' === $screenshot ) {
			unset( $fields['context']['capture'] );
		}

		return self::save( $fields + array( 'user_id' => $user_id, 'screenshot' => $screenshot, 'ip_hash' => $ip_hash ) );
	}

	/**
	 * Server-side half of the visibility rule (spec 6.1: roles/guests only; page exclusions and mobile are
	 * front-end display choices), then the send limit, then the anti-bot check.
	 *
	 * @return string|\WP_Error HMAC of the sender's IP bucket.
	 */
	private static function check_sender( array $settings, array $input, ?\WP_User $user, string $ip ) {
		if ( ! Visibility::user_allowed( $settings, $user ) ) {
			return new \WP_Error( 'bugspot_not_allowed', __( 'You are not allowed to send bug reports.', 'bug-spotter' ), array( 'status' => 403 ) );
		}

		$protection = $settings['protection'];
		$ip_hash    = Secret::hmac( Rate_Limiter::bucket( $ip ) );
		if ( ! ( new Rate_Limiter( (int) $protection['per_hour'], (int) $protection['per_day'] ) )->attempt( $ip_hash ) ) {
			return new \WP_Error( 'bugspot_rate_limited', __( 'Too many reports. Please try again later.', 'bug-spotter' ), array( 'status' => 429 ) );
		}

		$captcha = Factory::from_settings( $settings['captcha'] )->verify( self::string( $input, 'captcha_token' ), $ip );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}
		if ( true !== $captcha ) {
			return new \WP_Error( 'bugspot_captcha_failed', __( 'The anti-bot check failed.', 'bug-spotter' ), array( 'status' => 403 ) );
		}
		return $ip_hash;
	}

	/** @return array{description:string,reporter_email:string,context:array<string,mixed>}|\WP_Error */
	private static function read_fields( array $input, int $user_id ) {
		$description = trim( self::string( $input, 'description' ) );
		$description = '' === $description || mb_strlen( $description ) > self::MAX_DESCRIPTION ? '' : self::clean_description( $description );
		if ( '' === $description ) {
			/* translators: %s: maximum number of characters, for example "5,000". */
			return self::invalid( sprintf( __( 'The description is required (%s characters maximum).', 'bug-spotter' ), number_format_i18n( self::MAX_DESCRIPTION ) ) );
		}

		// Only a visitor gives an e-mail address: a logged-in sender is known by their account.
		$email = 0 === $user_id ? trim( self::string( $input, 'reporter_email' ) ) : '';
		if ( '' !== $email && ( mb_strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) ) {
			return self::invalid( __( 'The contact e-mail address is not valid.', 'bug-spotter' ) );
		}

		$raw_context  = $input['context'] ?? null;
		$context_size = is_string( $raw_context ) ? strlen( $raw_context ) : strlen( (string) wp_json_encode( $raw_context ) );
		if ( $context_size > self::MAX_CONTEXT_BYTES ) {
			return self::invalid( __( 'The report context is too large.', 'bug-spotter' ) );
		}
		$context = Context_Validator::validate( $raw_context );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return array(
			'description'    => $description,
			'reporter_email' => $email,
			'context'        => $context,
		);
	}

	/** Keeps the reporter's text as typed (outputs escape): valid UTF-8, no control chars, LF newlines. */
	private static function clean_description( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	}

	/** @return string|\WP_Error Stored screenshot name. */
	private static function store_upload( array $file ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_OK );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return Screenshot_Store::too_large();
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'bugspot_upload_failed', __( 'The screenshot could not be uploaded.', 'bug-spotter' ), array( 'status' => 422 ) );
		}
		return Screenshot_Store::store( is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '' );
	}

	/** @param array<string,mixed> $row Validated fields. @return int|\WP_Error */
	private static function save( array $row ) {
		$id = ( new Reports_Repository() )->insert(
			array(
				'description'    => $row['description'],
				'page_url'       => $row['context']['url'],
				'context'        => $row['context'],
				'user_id'        => $row['user_id'],
				'reporter_email' => $row['reporter_email'],
				'screenshot'     => $row['screenshot'],
				'ip_hash'        => $row['ip_hash'],
			)
		);
		if ( $id < 1 ) {
			if ( '' !== $row['screenshot'] ) {
				Screenshot_Store::delete( $row['screenshot'] );
			}
			return new \WP_Error( 'bugspot_save_failed', __( 'The report could not be saved.', 'bug-spotter' ), array( 'status' => 500 ) );
		}

		try {
			Mailer::notify( $id );
		} catch ( \Throwable $e ) {
			// The report is stored; a failing mail hook must not turn that into an error.
			error_log( 'Bug Spotter: notification failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return $id;
	}

	private static function string( array $input, string $key ): string {
		return is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
	}

	/** The plugin's 422 "invalid input" error, shared with the REST controllers. */
	public static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'bugspot_invalid', $message, array( 'status' => 422 ) );
	}
}
