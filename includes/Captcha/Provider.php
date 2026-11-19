<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

interface Provider {

	/** Settings identifier (none, turnstile, recaptcha_v2, recaptcha_v3, hcaptcha). */
	public function id(): string;

	/** Provider script to load in the browser, or '' when none is needed. */
	public function script_url(): string;

	/**
	 * @return bool|\WP_Error true when the token is accepted; bugspot_captcha_failed (403) or
	 *                        bugspot_captcha_unavailable (503, fail-closed) otherwise.
	 */
	public function verify( string $token, string $ip ): bool|\WP_Error;
}
