<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;
use Bug_Spotter\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook blocks of the "Alerts" tab (Settings_Alerts_Tab, spec 7.12): Webhooks::MAX fixed blocks, the saved ones first, then empty ones.
 * A block with an empty address is not saved; the secret goes back encrypted in a hidden field and is kept.
 */
final class Settings_Webhook_Fields {

	public static function render(): void {
		F::description( __( 'Each new report, status change or assignment can be posted to a chat channel or to your own service. Only the addresses below are called. They receive the report number, an excerpt of the description, the page, the status, the priority, the name of the assignee and a link to the report, never the reporter’s e-mail address, IP address or screenshot.', 'bug-spotter' ) );
		$saved = Webhooks::all();
		for ( $i = 0; $i < Webhooks::MAX; $i++ ) {
			self::block( $i, $saved[ $i ] ?? null );
		}
	}

	/** @param array<string,mixed>|null $w The saved webhook, null for an empty block. */
	private static function block( int $i, ?array $w ): void {
		$path = static fn( string $key ): array => array( 'webhooks', (string) $i, $key );

		echo '<fieldset class="bugspot-webhook">';
		/* translators: %d: webhook number, 1 to 5. */
		echo '<legend>' . esc_html( sprintf( __( 'Webhook %d', 'bug-spotter' ), $i + 1 ) ) . '</legend>';
		F::table_open();

		$id = F::id( $path( 'url' ) );
		F::row_open( __( 'Address (URL)', 'bug-spotter' ), $id );
		F::input( 'url', $path( 'url' ), (string) ( $w['url'] ?? '' ), array( 'class' => 'large-text code', 'placeholder' => 'https://' ), true );
		F::description(
			Webhooks::dev_hosts_allowed()
				? __( 'Must start with https:// (in this development environment, http://localhost and *.test addresses are also accepted). Leave empty to remove the webhook.', 'bug-spotter' )
				: __( 'Must start with https://. Leave empty to remove the webhook.', 'bug-spotter' ),
			$id . '-desc'
		);
		F::row_close();

		F::select_row( __( 'Format', 'bug-spotter' ), $path( 'format' ), (string) ( $w['format'] ?? 'generic' ), Webhooks::format_labels() );

		F::row_open( __( 'Events', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Events', 'bug-spotter' ) . '</legend>';
		F::list_marker( $path( 'events' ) );
		foreach ( Webhooks::event_labels() as $event => $label ) {
			F::checkbox_item( $path( 'events' ), $event, null === $w || in_array( $event, (array) $w['events'], true ), $label );
		}
		echo '</fieldset>';
		F::row_close();

		F::row_open( __( 'Active', 'bug-spotter' ) );
		F::checkbox( $path( 'enabled' ), null === $w || ! empty( $w['enabled'] ), __( 'Send the events ticked above', 'bug-spotter' ) );
		F::row_close();

		if ( null !== $w ) {
			self::secret_row( $path, $w );
			F::row_open( __( 'Test', 'bug-spotter' ) );
			echo '<p><button type="button" class="button bugspot-webhook-test" data-index="' . esc_attr( (string) $i ) . '" hidden>' . esc_html__( 'Send a test', 'bug-spotter' ) . '</button></p>';
			echo '<div class="bugspot-webhook-result" role="status" aria-live="polite"></div>';
			F::description( __( 'Sends a test message to the saved address, even when the webhook is inactive. Save your changes first. Requires JavaScript.', 'bug-spotter' ) );
			F::row_close();
		}

		F::table_close();
		echo '</fieldset>';
	}

	/** The signing secret of a saved generic JSON webhook, readable by administrators only (this screen). @param callable $path @param array<string,mixed> $w */
	private static function secret_row( callable $path, array $w ): void {
		$id     = F::id( $path( 'secret' ) );
		$secret = Webhooks::secret( $w );
		F::row_open( __( 'Signing secret', 'bug-spotter' ), $id, false, F::show_if( $path( 'format' ), '=', 'generic' ) );
		echo '<input type="hidden" name="' . esc_attr( F::name( $path( 'secret' ) ) ) . '" value="' . esc_attr( (string) $w['secret'] ) . '">';
		if ( null !== $secret ) {
			echo '<input type="text" readonly class="large-text code" id="' . esc_attr( $id ) . '" value="' . esc_attr( $secret ) . '" aria-describedby="' . esc_attr( $id ) . '-desc">';
		}
		echo '<label><input type="checkbox" name="' . esc_attr( F::name( $path( 'regenerate' ) ) ) . '" value="1"> ' . esc_html__( 'Make a new secret when saving', 'bug-spotter' ) . '</label>';
		F::description(
			null !== $secret
				? __( 'Each request carries the header X-Bug-Spotter-Signature: sha256= followed by the HMAC-SHA256 of the raw body with this secret. Your service computes the same value to check that the request comes from this site.', 'bug-spotter' )
				: __( 'The secret can no longer be read (the BUGSPOT_SECRET key changed): a new one is made when saving.', 'bug-spotter' ),
			$id . '-desc'
		);
		F::row_close();
	}
}
