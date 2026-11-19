<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class Hcaptcha extends Siteverify_Provider {

	public function id(): string {
		return 'hcaptcha';
	}

	public function script_url(): string {
		return 'https://js.hcaptcha.com/1/api.js?render=explicit';
	}

	protected function endpoint(): string {
		return 'https://api.hcaptcha.com/siteverify';
	}
}
