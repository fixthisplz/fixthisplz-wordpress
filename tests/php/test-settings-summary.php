<?php

use FixThisPlz\Admin\Settings_Page;
use FixThisPlz\Admin\Settings_Summary;
use FixThisPlz\Settings;

/** Summary rows (label => [value, class]) for the saved $option. @param array<string,mixed> $option */
function settings_summary_rows( array $option ): array {
	update_option( Settings::OPTION, $option );
	$out = array();
	foreach ( Settings_Summary::rows( Settings::get() ) as $row ) {
		$out[ $row['label'] ] = array( $row['value'], $row['class'] );
	}
	return $out;
}

fixthisplz_test(
	'settings-summary: button_off_by_default_and_on_when_enabled',
	function () {
		with_settings_option(
			function () {
				$rows = settings_summary_rows( array() );
				assert_same( array( 'Off: nobody sees the button.', 'fixthisplz-summary-warning' ), $rows['Report button'] );
				assert_same( array( 'Administrator', '' ), $rows['Who can report'] );
				assert_same( array( 'None', '' ), $rows['Spam protection'], 'guests cannot report: no warning' );

				$rows = settings_summary_rows( array( 'enabled' => true ) );
				assert_same( array( 'On', '' ), $rows['Report button'] );

				$rows = settings_summary_rows( array( 'enabled' => true, 'visibility' => array( 'roles' => array( 'gone_role' ) ) ) );
				assert_same( 'Nobody', $rows['Who can report'][0], 'a role that no longer exists is not listed' );
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: guests_without_spam_check_warn_like_the_notice',
	function () {
		with_settings_option(
			function () {
				$option = array(
					'enabled'    => true,
					'visibility' => array( 'roles' => array( 'administrator', 'editor' ), 'guests' => true ),
				);
				$rows   = settings_summary_rows( $option );
				assert_same( 'Administrator, Editor, Guests (not logged in)', $rows['Who can report'][0] );
				assert_same( array( 'None: guests can send reports without any check.', 'fixthisplz-summary-warning' ), $rows['Spam protection'] );
				assert_true( Settings_Page::guests_unprotected( Settings::get() ), 'same rule as the notice' );

				$option['captcha'] = array( 'provider' => 'turnstile', 'site_key' => 'site', 'secret_key' => 'secret' );
				$rows              = settings_summary_rows( $option );
				assert_same( array( 'Cloudflare Turnstile', '' ), $rows['Spam protection'] );
				assert_true( ! Settings_Page::guests_unprotected( Settings::get() ) );

				$option['enabled'] = false;
				$option['captcha'] = array( 'provider' => 'none' );
				$rows              = settings_summary_rows( $option );
				assert_same( array( 'None', '' ), $rows['Spam protection'], 'button off: guests cannot report' );
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: email_off_default_address_or_recipient_count',
	function () {
		with_settings_option(
			function () {
				$rows = settings_summary_rows( array( 'notifications' => array( 'enabled' => false, 'recipients' => 'a@example.com' ) ) );
				assert_same( 'Off', $rows['E-mail'][0] );

				$rows = settings_summary_rows( array( 'notifications' => array( 'enabled' => true, 'recipients' => '' ) ) );
				assert_same( 'Site administration address (' . get_option( 'admin_email' ) . ')', $rows['E-mail'][0] );

				$rows = settings_summary_rows( array( 'notifications' => array( 'enabled' => true, 'recipients' => 'not-an-address' ) ) );
				assert_same( 'Site administration address (' . get_option( 'admin_email' ) . ')', $rows['E-mail'][0], 'only invalid addresses: the mailer falls back' );

				$rows = settings_summary_rows( array( 'notifications' => array( 'enabled' => true, 'recipients' => 'a@example.com' ) ) );
				assert_same( '1 recipient', $rows['E-mail'][0] );

				$rows = settings_summary_rows( array( 'notifications' => array( 'enabled' => true, 'recipients' => 'a@example.com, b@example.com,bad' ) ) );
				assert_same( '2 recipients', $rows['E-mail'][0] );
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: counts_only_switched_on_webhooks',
	function () {
		with_settings_option(
			function () {
				$rows = settings_summary_rows( array() );
				assert_same( '0 active', $rows['Webhooks'][0] );

				$hook = static fn( bool $on ): array => array( 'url' => 'https://example.com/' . wp_rand(), 'format' => 'slack', 'events' => array( 'created' ), 'enabled' => $on, 'secret' => '' );
				$rows = settings_summary_rows( array( 'webhooks' => array( $hook( true ), $hook( false ), $hook( true ), array( 'url' => '' ) ) ) );
				assert_same( '2 active', $rows['Webhooks'][0] );
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: retention_without_limit_or_months',
	function () {
		with_settings_option(
			function () {
				$rows = settings_summary_rows( array( 'data' => array( 'retention_months' => 0 ) ) );
				assert_same( array( 'Without limit', '' ), $rows['Retention'] );

				$rows = settings_summary_rows( array( 'data' => array( 'retention_months' => 1 ) ) );
				assert_same( array( '1 month', '' ), $rows['Retention'] );

				$rows = settings_summary_rows( array( 'data' => array( 'retention_months' => 6 ) ) );
				assert_same( array( '6 months', '' ), $rows['Retention'] );

				$open     = array_map( static fn( array $st ): array => array_merge( $st, array( 'closed' => false ) ), Settings::defaults()['statuses'] );
				$rows     = settings_summary_rows( array( 'data' => array( 'retention_months' => 6 ), 'statuses' => $open ) );
				assert_same( array( '6 months, but no status is marked Done: nothing is deleted.', 'fixthisplz-summary-warning' ), $rows['Retention'] );
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: rendered_at_the_top_of_general_with_escaped_links_to_sections',
	function () {
		with_settings_option(
			function () {
				update_option( Settings::OPTION, array( 'notifications' => array( 'enabled' => true, 'recipients' => '' ) ) );
				ob_start();
				Settings_Page::render_tab_form( 'general', Settings::get() );
				$html = (string) ob_get_clean();
				$dl   = strpos( $html, '<dl class="fixthisplz-summary">' );
				assert_true( false !== $dl && $dl < strpos( $html, 'id="' . Settings_Summary::ACTIVATION . '"' ), 'summary before the first section' );
				assert_same( 6, substr_count( $html, '<dt><a href=' ), 'six lines' );
				assert_true( str_contains( $html, 'tab=spam#' . Settings_Summary::SPAM ), 'link to the spam check section' );
				assert_true( str_contains( $html, '<dt><a href="#' . Settings_Summary::ACTIVATION . '">' ), 'a line of the General tab is a plain anchor' );
				assert_true( str_contains( $html, '<dt><a href="#' . Settings_Summary::WHO . '">' ) );
				assert_true( str_contains( $html, 'tab=privacy#' . Settings_Summary::RETENTION ), 'link to the retention section' );
				assert_true( str_contains( $html, '<dd class="fixthisplz-summary-warning">Off: nobody sees the button.</dd>' ) );

				// Every anchor exists on its tab.
				foreach ( array( 'general' => array( 'ACTIVATION', 'WHO' ), 'spam' => array( 'SPAM' ), 'alerts' => array( 'EMAIL', 'WEBHOOKS' ), 'privacy' => array( 'RETENTION' ) ) as $tab => $anchors ) {
					ob_start();
					Settings_Page::render_tab_form( $tab, Settings::get() );
					$tab_html = (string) ob_get_clean();
					foreach ( $anchors as $name ) {
						assert_true( str_contains( $tab_html, '<h2 id="' . constant( Settings_Summary::class . '::' . $name ) . '">' ), "$tab: $name" );
					}
				}
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: a_service_without_secret_key_warns_that_reports_fail',
	function () {
		with_settings_option(
			function () {
				foreach ( array_keys( FixThisPlz\Admin\Settings_Spam_Tab::provider_labels() ) as $provider ) {
					if ( 'none' === $provider ) {
						continue;
					}
					$rows  = settings_summary_rows( array( 'captcha' => array( 'provider' => $provider, 'site_key' => 'site', 'secret_key' => '' ) ) );
					$label = FixThisPlz\Admin\Settings_Spam_Tab::provider_labels()[ $provider ];
					assert_same( array( $label . ': secret key missing, every report will fail.', 'fixthisplz-summary-warning' ), $rows['Spam protection'], $provider );
					// The real behaviour: every check is refused while the secret is missing.
					assert_wp_error( FixThisPlz\Captcha\Factory::from_settings( Settings::get()['captcha'] )->verify( 'token', '127.0.0.1' ), 'fixthisplz_captcha_unavailable' );

					$rows = settings_summary_rows( array( 'captcha' => array( 'provider' => $provider, 'site_key' => 'site', 'secret_key' => 'secret' ) ) );
					assert_same( array( $label, '' ), $rows['Spam protection'], "$provider with its secret" );
				}
			}
		);
	}
);

fixthisplz_test(
	'settings-summary: guests_box_warns_only_without_a_spam_check_and_follows_the_box',
	function () {
		with_settings_option(
			function () {
				$general = static function ( array $option ): string {
					update_option( Settings::OPTION, $option );
					ob_start();
					Settings_Page::render_tab_form( 'general', Settings::get() );
					return (string) ob_get_clean();
				};
				$html = $general( array( 'visibility' => array( 'guests' => false ) ) );
				assert_true( 1 === preg_match( '#<p class="description fixthisplz-warning" data-fixthisplz-show-if="fixthisplz_settings\[visibility\]\[guests\]" data-fixthisplz-op="=" data-fixthisplz-value="1">No spam check is configured: expect unwanted reports\. <a href="[^"]*tab=spam">Set up spam protection</a></p>#', $html ), 'warning tied to the guests box, rendered even while unticked' );
				assert_true( strpos( $html, 'fixthisplz-warning' ) > strpos( $html, 'name="fixthisplz_settings[visibility][guests]"' ), 'right under the box' );

				$html = $general( array( 'visibility' => array( 'guests' => true ), 'captcha' => array( 'provider' => 'hcaptcha', 'site_key' => 'a', 'secret_key' => 'b' ) ) );
				assert_true( ! str_contains( $html, 'No spam check is configured' ), 'a service is chosen' );
			}
		);
	}
);
