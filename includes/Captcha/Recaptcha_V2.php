<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class Recaptcha_V2 extends Siteverify_Provider {

	public function id(): string {
		return 'recaptcha_v2';
	}

	public function script_url(): string {
		return 'https://www.google.com/recaptcha/api.js?render=explicit';
	}

	protected function endpoint(): string {
		return 'https://www.google.com/recaptcha/api/siteverify';
	}
}
