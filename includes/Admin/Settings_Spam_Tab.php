<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "Spam protection" tab of the settings screen: the spam check service, the sending limits and the IP source. */
final class Settings_Spam_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		self::captcha( $s['captcha'] );
		$p = $s['protection'];

		F::section( __( 'Sending limits', 'bug-spotter' ), __( 'Cap the number of reports one IP address can send.', 'bug-spotter' ) );
		$id = F::id( array( 'protection', 'per_hour' ) );
		F::row_open( __( 'Reports per hour', 'bug-spotter' ), $id );
		F::input( 'number', array( 'protection', 'per_hour' ), (string) $p['per_hour'], array( 'min' => '1', 'max' => '1000', 'class' => 'small-text' ), true );
		F::description( __( 'Maximum per IP address.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'protection', 'per_day' ) );
		F::row_open( __( 'Reports per day', 'bug-spotter' ), $id );
		F::input( 'number', array( 'protection', 'per_day' ), (string) $p['per_day'], array( 'min' => '1', 'max' => '10000', 'class' => 'small-text' ), true );
		F::description( __( 'Maximum per IP address. A hidden honeypot field also stops basic bots; it is always active.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::section( __( 'Advanced settings', 'bug-spotter' ), __( 'Change this only if your site is behind a proxy or Cloudflare.', 'bug-spotter' ) );
		$id = F::id( array( 'protection', 'proxy_header' ) );
		F::row_open( __( 'Trusted proxy header (advanced)', 'bug-spotter' ), $id );
		F::select(
			array( 'protection', 'proxy_header' ),
			$p['proxy_header'],
			array(
				''                 => __( 'None: use the connection address (recommended)', 'bug-spotter' ),
				'x-forwarded-for'  => 'X-Forwarded-For',
				'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)',
			),
			true
		);
		echo '<div class="notice notice-warning inline" id="' . esc_attr( $id ) . '-desc"><p>' . esc_html__( 'Only choose a header if every request reaches this site through a proxy or CDN that sets it. Otherwise anyone can forge it and get around the per-IP limits.', 'bug-spotter' ) . '</p></div>';
		F::row_close();
		F::table_close();
	}

	/** The provider, its keys and the "Test" button (its widget is printed after the form, see Settings_Page). @param array<string,mixed> $c */
	private static function captcha( array $c ): void {
		$provider = array( 'captcha', 'provider' );
		F::section( __( 'Spam check', 'bug-spotter' ), __( 'Ask visitors to pass a check before sending, with the service of your choice.', 'bug-spotter' ) );

		F::select_row(
			__( 'Provider', 'bug-spotter' ),
			$provider,
			$c['provider'],
			array(
				'none'         => __( 'None', 'bug-spotter' ),
				'turnstile'    => __( 'Cloudflare Turnstile', 'bug-spotter' ),
				'recaptcha_v2' => __( 'Google reCAPTCHA v2 (checkbox)', 'bug-spotter' ),
				'recaptcha_v3' => __( 'Google reCAPTCHA v3 (invisible, score-based)', 'bug-spotter' ),
				'hcaptcha'     => __( 'hCaptcha', 'bug-spotter' ),
			)
		);

		$keys = F::show_if( $provider, '!=', 'none' );

		$id = F::id( array( 'captcha', 'site_key' ) );
		F::row_open( __( 'Site key', 'bug-spotter' ), $id, false, $keys );
		F::input( 'text', array( 'captcha', 'site_key' ), $c['site_key'], array( 'class' => 'regular-text code', 'autocomplete' => 'off', 'spellcheck' => 'false' ) );
		F::row_close();

		$id = F::id( array( 'captcha', 'secret_key' ) );
		F::row_open( __( 'Secret key', 'bug-spotter' ), $id, false, $keys );
		// The secret is never printed back into the page: blank keeps the saved value.
		F::input(
			'password',
			array( 'captcha', 'secret_key' ),
			'',
			array(
				'class'        => 'regular-text code',
				'autocomplete' => 'new-password',
				'spellcheck'   => 'false',
				'placeholder'  => '' !== $c['secret_key'] ? __( 'Saved — leave blank to keep it', 'bug-spotter' ) : '',
			),
			true
		);
		F::description( __( 'Kept on the server only, never sent to visitors.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'captcha', 'v3_threshold' ) );
		F::row_open( __( 'Minimum score', 'bug-spotter' ), $id, false, F::show_if( $provider, '=', 'recaptcha_v3' ) );
		F::input( 'number', array( 'captcha', 'v3_threshold' ), (string) $c['v3_threshold'], array( 'min' => '0', 'max' => '1', 'step' => '0.1', 'class' => 'small-text' ), true );
		F::description( __( 'Between 0 (accept everything) and 1 (strictest). Default: 0.5.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Test the keys', 'bug-spotter' ), '', false, $keys );
		echo '<p><button type="button" class="button bugspot-captcha-test" hidden>' . esc_html__( 'Test', 'bug-spotter' ) . '</button></p>';
		echo '<div class="bugspot-captcha-result" role="status" aria-live="polite"></div>';
		F::description( __( 'Uses the provider and keys typed above, even before saving. A blank secret key tests the saved one. Requires JavaScript.', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}
}
