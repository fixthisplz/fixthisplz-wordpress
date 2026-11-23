<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Shared "siteverify" flow (Turnstile, reCAPTCHA, hCaptcha all speak the same protocol).
 * Fail-closed: anything other than a readable 200 JSON answer is "unavailable", never "pass".
 */
abstract class Siteverify_Provider implements Provider {

	protected string $site_key;
	protected string $secret_key;

	public function __construct( string $site_key, string $secret_key ) {
		$this->site_key   = $site_key;
		$this->secret_key = $secret_key;
	}

	abstract protected function endpoint(): string;

	/** Without its secret key, every check answers "unavailable": no report can be sent (also told by the settings summary). */
	public function has_secret(): bool {
		return '' !== $this->secret_key;
	}

	public function verify( string $token, string $ip ): bool|\WP_Error {
		if ( ! $this->has_secret() ) {
			return $this->unavailable();
		}
		if ( '' === trim( $token ) ) {
			return $this->failed();
		}

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $this->secret_key,
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->unavailable();
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->unavailable();
		}

		return $this->evaluate( $data );
	}

	/**
	 * Decides from the decoded provider answer. Subclasses add checks (score, action) on top.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function evaluate( array $data ): bool|\WP_Error {
		return true === ( $data['success'] ?? false ) ? true : $this->failed();
	}

	protected function failed(): \WP_Error {
		return new \WP_Error( 'bugspot_captcha_failed', __( 'The anti-bot check failed. Please try again.', 'bug-spotter' ), array( 'status' => 403 ) );
	}

	protected function unavailable(): \WP_Error {
		return new \WP_Error( 'bugspot_captcha_unavailable', __( 'The anti-bot check is currently unavailable. Please try again later.', 'bug-spotter' ), array( 'status' => 503 ) );
	}
}
