<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class Turnstile extends Siteverify_Provider {

	public function id(): string {
		return 'turnstile';
	}

	public function script_url(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
	}

	protected function endpoint(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	}
}
