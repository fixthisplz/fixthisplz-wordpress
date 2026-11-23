<?php

namespace FixThisPlz\Captcha;

defined( 'ABSPATH' ) || exit;

final class Turnstile extends Siteverify_Provider {

	public function id(): string {
		return 'turnstile';
	}

	public function script_url(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the provider's own widget script (remote service, see readme "External services").
	}

	protected function endpoint(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the provider's verification API (remote service, see readme "External services").
	}
}
