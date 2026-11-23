<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "Privacy" tab of the settings screen: technical data attached to the reports, areas to mask, retention, uninstall. */
final class Settings_Privacy_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$x = $s['context'];
		$t = $s['data'];

		F::section( __( 'Technical data attached', 'bug-spotter' ), __( 'These details help to reproduce the problem. The addresses (URLs) they contain may include personal data.', 'bug-spotter' ) );
		F::row_open( __( 'Console errors', 'bug-spotter' ) );
		F::checkbox( array( 'context', 'console' ), (bool) $x['console'], __( 'Attach recent browser console errors and warnings', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Failed requests', 'bug-spotter' ) );
		F::checkbox( array( 'context', 'network' ), (bool) $x['network'], __( 'Attach recent failed network requests', 'bug-spotter' ) );
		F::row_close();
		F::table_close();

		F::section( __( 'Areas to mask', 'bug-spotter' ), __( 'Parts of your pages whose text is never written into the reports.', 'bug-spotter' ) );
		$id = F::id( array( 'context', 'sensitive_selectors' ) );
		F::row_open( __( 'Extra sensitive elements', 'bug-spotter' ), $id );
		F::textarea( array( 'context', 'sensitive_selectors' ), implode( "\n", $x['sensitive_selectors'] ), 5, '.account-balance', true );
		F::description( __( 'One CSS selector per line. The text of these areas is never collected. When masking is on (Form › Screenshot), they are also masked on screenshots, like form fields.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::section( __( 'Retention', 'bug-spotter' ), __( 'How long the handled reports are kept.', 'bug-spotter' ), Settings_Summary::RETENTION );
		$id = F::id( array( 'data', 'retention_months' ) );
		F::row_open( __( 'Automatic deletion', 'bug-spotter' ), $id );
		F::input( 'number', array( 'data', 'retention_months' ), (string) $t['retention_months'], array( 'min' => '0', 'max' => '120', 'class' => 'small-text' ), true );
		echo ' ' . esc_html__( 'months', 'bug-spotter' );
		F::description( __( 'Reports whose status is marked Done are deleted, with their screenshot, this many months after their last status change. 0 = keep without limit. Choose these statuses in Tracking › Statuses.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::section( __( 'Uninstall', 'bug-spotter' ), __( 'What happens to the data when the plugin is deleted.', 'bug-spotter' ) );
		F::row_open( __( 'Data', 'bug-spotter' ) );
		F::checkbox( array( 'data', 'delete_on_uninstall' ), (bool) $t['delete_on_uninstall'], __( 'Delete all reports, screenshots and settings when the plugin is deleted', 'bug-spotter' ) );
		F::row_close();
		F::table_close();
	}
}
