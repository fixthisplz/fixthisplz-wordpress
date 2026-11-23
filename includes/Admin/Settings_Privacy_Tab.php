<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** "Privacy" tab of the settings screen: technical data attached to the reports, areas to mask, retention, uninstall. */
final class Settings_Privacy_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$x = $s['context'];
		$t = $s['data'];

		F::section( __( 'Technical data attached', 'fixthisplz' ), __( 'These details help to reproduce the problem. The addresses (URLs) they contain may include personal data.', 'fixthisplz' ) );
		F::row_open( __( 'Console errors', 'fixthisplz' ) );
		F::checkbox( array( 'context', 'console' ), (bool) $x['console'], __( 'Attach recent browser console errors and warnings', 'fixthisplz' ) );
		F::row_close();

		F::row_open( __( 'Failed requests', 'fixthisplz' ) );
		F::checkbox( array( 'context', 'network' ), (bool) $x['network'], __( 'Attach recent failed network requests', 'fixthisplz' ) );
		F::row_close();
		F::table_close();

		F::section( __( 'Areas to mask', 'fixthisplz' ), __( 'Parts of your pages whose text is never written into the reports.', 'fixthisplz' ) );
		$id = F::id( array( 'context', 'sensitive_selectors' ) );
		F::row_open( __( 'Extra sensitive elements', 'fixthisplz' ), $id );
		F::textarea( array( 'context', 'sensitive_selectors' ), implode( "\n", $x['sensitive_selectors'] ), 5, '.account-balance', true );
		F::description( __( 'One CSS selector per line. The text of these areas is never collected. When masking is on (Form › Screenshot), they are also masked on screenshots, like form fields.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::section( __( 'Retention', 'fixthisplz' ), __( 'How long the handled reports are kept.', 'fixthisplz' ), Settings_Summary::RETENTION );
		$id = F::id( array( 'data', 'retention_months' ) );
		F::row_open( __( 'Automatic deletion', 'fixthisplz' ), $id );
		F::input( 'number', array( 'data', 'retention_months' ), (string) $t['retention_months'], array( 'min' => '0', 'max' => '120', 'class' => 'small-text' ), true );
		echo ' ' . esc_html__( 'months', 'fixthisplz' );
		F::description( __( 'Reports whose status is marked Done are deleted, with their screenshot, this many months after their last status change. 0 = keep without limit. Choose these statuses in Tracking › Statuses.', 'fixthisplz' ), $id . '-desc' );
		F::row_close();
		F::table_close();

		F::section( __( 'Uninstall', 'fixthisplz' ), __( 'What happens to the data when the plugin is deleted.', 'fixthisplz' ) );
		F::row_open( __( 'Data', 'fixthisplz' ) );
		F::checkbox( array( 'data', 'delete_on_uninstall' ), (bool) $t['delete_on_uninstall'], __( 'Delete all reports, screenshots and settings when the plugin is deleted', 'fixthisplz' ) );
		F::row_close();
		F::table_close();
	}
}
