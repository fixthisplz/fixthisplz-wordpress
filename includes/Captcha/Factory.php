<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class Factory {

	/** @param array<string,mixed> $captcha The "captcha" block of the settings. Unknown provider => None. */
	public static function from_settings( array $captcha ): Provider {
		$site   = is_string( $captcha['site_key'] ?? null ) ? $captcha['site_key'] : '';
		$secret = is_string( $captcha['secret_key'] ?? null ) ? $captcha['secret_key'] : '';

		switch ( $captcha['provider'] ?? 'none' ) {
			case 'turnstile':
				return new Turnstile( $site, $secret );
			case 'recaptcha_v2':
				return new Recaptcha_V2( $site, $secret );
			case 'recaptcha_v3':
				$threshold = is_numeric( $captcha['v3_threshold'] ?? null ) ? (float) $captcha['v3_threshold'] : 0.5;
				return new Recaptcha_V3( $site, $secret, $threshold );
			case 'hcaptcha':
				return new Hcaptcha( $site, $secret );
			default:
				return new None();
		}
	}
}
