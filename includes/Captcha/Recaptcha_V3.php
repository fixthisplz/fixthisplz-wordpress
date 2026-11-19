<?php

namespace Bug_Spotter\Captcha;

defined( 'ABSPATH' ) || exit;

final class Recaptcha_V3 extends Siteverify_Provider {

	public const ACTION = 'bug_report';

	private float $threshold;

	public function __construct( string $site_key, string $secret_key, float $threshold ) {
		parent::__construct( $site_key, $secret_key );
		$this->threshold = $threshold;
	}

	public function id(): string {
		return 'recaptcha_v3';
	}

	public function script_url(): string {
		return 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->site_key );
	}

	protected function endpoint(): string {
		return 'https://www.google.com/recaptcha/api/siteverify';
	}

	protected function evaluate( array $data ): bool|\WP_Error {
		$passed = parent::evaluate( $data );
		if ( is_wp_error( $passed ) ) {
			return $passed;
		}
		$score = $data['score'] ?? null;
		if ( ! is_numeric( $score ) || (float) $score < $this->threshold || ( $data['action'] ?? null ) !== self::ACTION ) {
			return $this->failed();
		}
		return true;
	}
}
