<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;
use FixThisPlz\Settings;

defined( 'ABSPATH' ) || exit;

/** "Spam protection" tab of the settings screen: the spam check service, the sending limits and, folded, the source of the visitors' IP address. */
final class Settings_Spam_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		self::captcha( $s['captcha'] );
		$p = $s['protection'];

		F::section( __( 'Sending limits', 'fixthisplz' ), __( 'Cap the number of reports one IP address can send.', 'fixthisplz' ) );
		$id = F::id( array( 'protection', 'per_hour' ) );
		F::row_open( __( 'Reports per hour', 'fixthisplz' ), $id );
		F::input( 'number', array( 'protection', 'per_hour' ), (string) $p['per_hour'], array( 'min' => '1', 'max' => '1000', 'class' => 'small-text' ), true );
		F::description( __( 'Maximum per IP address.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'protection', 'per_day' ) );
		F::row_open( __( 'Reports per day', 'fixthisplz' ), $id );
		F::input( 'number', array( 'protection', 'per_day' ), (string) $p['per_day'], array( 'min' => '1', 'max' => '10000', 'class' => 'small-text' ), true );
		F::description( __( 'Maximum per IP address. A hidden honeypot field also stops basic bots; it is always active.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::advanced_open( __( 'Change this only if your site is behind a proxy or Cloudflare.', 'fixthisplz' ), $p, Settings::defaults()['protection'], array( 'proxy_header' ) );
		$id = F::id( array( 'protection', 'proxy_header' ) );
		F::row_open( __( 'Visitors’ IP address', 'fixthisplz' ), $id );
		F::select(
			array( 'protection', 'proxy_header' ),
			$p['proxy_header'],
			array(
				''                 => __( 'Connection address (recommended)', 'fixthisplz' ),
				'x-forwarded-for'  => __( 'X-Forwarded-For header (proxy)', 'fixthisplz' ),
				'cf-connecting-ip' => __( 'CF-Connecting-IP header (Cloudflare)', 'fixthisplz' ),
			),
			true
		);
		echo '<div class="notice notice-warning inline" id="' . esc_attr( $id ) . '-desc"><p>' . esc_html__( 'Only choose a header if every request reaches this site through a proxy or CDN that sets it. Otherwise anyone can forge it and get around the per-IP limits.', 'fixthisplz' ) . '</p></div>';
		F::row_close();
		F::advanced_close();
	}

	/** @return array<string,string> Spam check service => label ("none" first), also shown in the summary of the General tab. */
	public static function provider_labels(): array {
		return array(
			'none'         => __( 'None', 'fixthisplz' ),
			'turnstile'    => __( 'Cloudflare Turnstile', 'fixthisplz' ),
			'recaptcha_v2' => __( 'Google reCAPTCHA v2 (checkbox)', 'fixthisplz' ),
			'recaptcha_v3' => __( 'Google reCAPTCHA v3 (invisible, score-based)', 'fixthisplz' ),
			'hcaptcha'     => __( 'hCaptcha', 'fixthisplz' ),
		);
	}

	/** The provider, its keys and the "Test" button (its widget is printed after the form, see Settings_Page). @param array<string,mixed> $c */
	private static function captcha( array $c ): void {
		$provider = array( 'captcha', 'provider' );
		F::section( __( 'Spam check', 'fixthisplz' ), __( 'Ask visitors to pass a check before sending, with the service of your choice.', 'fixthisplz' ), Settings_Summary::SPAM );

		F::select_row(
			__( 'Provider', 'fixthisplz' ),
			$provider,
			$c['provider'],
			self::provider_labels()
		);

		$keys = F::show_if( $provider, '!=', 'none' );

		$id = F::id( array( 'captcha', 'site_key' ) );
		F::row_open( __( 'Site key', 'fixthisplz' ), $id, false, $keys );
		F::input( 'text', array( 'captcha', 'site_key' ), $c['site_key'], array( 'class' => 'regular-text code', 'autocomplete' => 'off', 'spellcheck' => 'false' ) );
		F::row_close();

		$id = F::id( array( 'captcha', 'secret_key' ) );
		F::row_open( __( 'Secret key', 'fixthisplz' ), $id, false, $keys );
		// The secret is never printed back into the page: blank keeps the saved value.
		F::input(
			'password',
			array( 'captcha', 'secret_key' ),
			'',
			array(
				'class'        => 'regular-text code',
				'autocomplete' => 'new-password',
				'spellcheck'   => 'false',
				'placeholder'  => '' !== $c['secret_key'] ? __( 'Saved — leave blank to keep it', 'fixthisplz' ) : '',
			),
			true
		);
		F::description( __( 'Kept on the server only, never sent to visitors.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'captcha', 'v3_threshold' ) );
		F::row_open( __( 'Minimum score', 'fixthisplz' ), $id, false, F::show_if( $provider, '=', 'recaptcha_v3' ) );
		F::input( 'number', array( 'captcha', 'v3_threshold' ), (string) $c['v3_threshold'], array( 'min' => '0', 'max' => '1', 'step' => '0.1', 'class' => 'small-text' ), true );
		F::description( __( 'Between 0 (accept everything) and 1 (strictest). Default: 0.5.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Test the keys', 'fixthisplz' ), '', false, $keys );
		echo '<p><button type="button" class="button fixthisplz-captcha-test" hidden>' . esc_html__( 'Test', 'fixthisplz' ) . '</button></p>';
		echo '<div class="fixthisplz-captcha-result" role="status" aria-live="polite"></div>';
		F::description( __( 'Uses the provider and keys typed above, even before saving. A blank secret key tests the saved one. Requires JavaScript.', 'fixthisplz' ) );
		F::row_close();

		F::table_close();
	}
}
