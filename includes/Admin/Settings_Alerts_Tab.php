<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "Alerts" tab of the settings screen: the e-mail sent for each new report, then the webhooks. */
final class Settings_Alerts_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$n = $s['notifications'];

		F::section( __( 'E-mail', 'fixthisplz' ), __( 'Send an e-mail to your team for every new report.', 'fixthisplz' ), Settings_Summary::EMAIL );
		F::row_open( __( 'E-mail notification', 'fixthisplz' ) );
		F::checkbox( array( 'notifications', 'enabled' ), (bool) $n['enabled'], __( 'Send an e-mail for every new report', 'fixthisplz' ) );
		F::row_close();

		$id    = F::id( array( 'notifications', 'recipients' ) );
		$admin = (string) get_option( 'admin_email' );
		$if_enabled = array( array( 'notifications', 'enabled' ), '=', '1' );
		F::row_open( __( 'Recipients', 'fixthisplz' ), $id, false, $if_enabled );
		F::input( 'text', array( 'notifications', 'recipients' ), $n['recipients'], array( 'class' => 'large-text', 'placeholder' => $admin ), true );
		/* translators: %s: the site administration e-mail address. */
		F::description( sprintf( __( 'Several addresses separated by commas. Empty: %s (site administration address).', 'fixthisplz' ), $admin ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Attachment', 'fixthisplz' ), '', false, $if_enabled );
		F::checkbox( array( 'notifications', 'attach_screenshot' ), (bool) $n['attach_screenshot'], __( 'Attach the screenshot to the e-mail', 'fixthisplz' ) );
		F::row_close();
		F::table_close();

		F::heading( __( 'Webhooks', 'fixthisplz' ), __( 'Post each event to Slack, Microsoft Teams, Discord or your own service.', 'fixthisplz' ), Settings_Summary::WEBHOOKS );
		Settings_Webhook_Fields::render();
	}
}
