<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class None implements Provider {

	public function id(): string {
		return 'none';
	}

	public function script_url(): string {
		return '';
	}

	public function verify( string $token, string $ip ): bool|\WP_Error {
		return true;
	}
}
