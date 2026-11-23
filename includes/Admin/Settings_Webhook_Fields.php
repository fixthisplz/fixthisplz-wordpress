<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Admin\Settings_Fields as F;
use FixThisPlz\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook blocks of the "Alerts" tab (Settings_Alerts_Tab, spec 7.12): one open block per saved webhook (index i of
 * Webhooks::all(), the index the "Send a test" button posts), then, while there is room, one empty block folded under
 * "Add a webhook". The sanitizer drops a block with an empty address (so an untouched "Add" block and an emptied
 * address save nothing) and keeps the secret posted back, encrypted, in each block's hidden field.
 */
final class Settings_Webhook_Fields {

	public static function render(): void {
		self::what_is_sent();
		$saved = Webhooks::all();
		foreach ( $saved as $i => $w ) {
			self::block( $i, $w );
		}
		if ( count( $saved ) < Webhooks::MAX ) {
			F::details_open( __( 'Add a webhook', 'fixthisplz' ), false, 'fixthisplz-advanced', 3 );
			self::block( count( $saved ), null );
			F::details_close();
		}
		// After the blocks (so it takes the next index): the list is always posted whole, even without any block.
		F::list_marker( array( 'webhooks' ) );
	}

	/** Folded list of what a webhook message holds and what it never holds (Webhook_Payload). */
	private static function what_is_sent(): void {
		F::details_open( __( 'What does a webhook receive?', 'fixthisplz' ), false, 'fixthisplz-disclosure' );
		F::description( __( 'Only the addresses below are called. Each message holds:', 'fixthisplz' ) );
		self::items(
			array(
				__( 'the event, the name and address of the site, the date and time', 'fixthisplz' ),
				__( 'the report number and the first 300 characters of its description', 'fixthisplz' ),
				__( 'the address of the page, the status, the priority and the name of the assignee', 'fixthisplz' ),
				__( 'the change made (old and new status, or old and new assignee) and a link to the report in the administration', 'fixthisplz' ),
			)
		);
		F::description( __( 'When a bulk action changes several reports at once, Slack, Microsoft Teams and Discord receive a single summary instead: the number of reports, the new status or assignee and a link to the list. The generic JSON format still receives one message per report.', 'fixthisplz' ) );
		F::description( __( 'It never holds:', 'fixthisplz' ) );
		self::items(
			array(
				__( 'the name, e-mail address or IP address of the reporter', 'fixthisplz' ),
				__( 'the screenshot', 'fixthisplz' ),
				__( 'the technical data (browser, console errors, network requests) and the internal notes', 'fixthisplz' ),
			)
		);
		F::details_close();
	}

	/** @param string[] $items */
	private static function items( array $items ): void {
		echo '<ul class="ul-disc">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
	}

	/** Legend of a block: "Webhook 2", enriched for a saved webhook with its format and whether it is enabled. @param array<string,mixed>|null $w */
	private static function title( int $i, ?array $w ): string {
		if ( null === $w ) {
			/* translators: %d: webhook number, 1 to 5. */
			return sprintf( __( 'Webhook %d', 'fixthisplz' ), $i + 1 );
		}
		return sprintf(
			/* translators: 1: webhook number, 1 to 5; 2: its format, such as "Slack"; 3: "enabled" or "paused". */
			__( 'Webhook %1$d: %2$s, %3$s', 'fixthisplz' ),
			$i + 1,
			Webhooks::format_labels()[ $w['format'] ] ?? $w['format'],
			empty( $w['enabled'] ) ? _x( 'paused', 'webhook state', 'fixthisplz' ) : _x( 'enabled', 'webhook state', 'fixthisplz' )
		);
	}

	/** @param array<string,mixed>|null $w The saved webhook, null for an empty block. */
	private static function block( int $i, ?array $w ): void {
		$path = static fn( string $key ): array => array( 'webhooks', (string) $i, $key );

		echo '<fieldset class="fixthisplz-webhook">';
		echo '<legend>' . esc_html( self::title( $i, $w ) ) . '</legend>';
		F::table_open();

		$id = F::id( $path( 'url' ) );
		F::row_open( __( 'Address (URL)', 'fixthisplz' ), $id );
		F::input( 'url', $path( 'url' ), (string) ( $w['url'] ?? '' ), array( 'class' => 'large-text code', 'placeholder' => 'https://' ), true );
		F::description(
			Webhooks::dev_hosts_allowed()
				? __( 'Must start with https:// (in this development environment, http://localhost and *.test addresses are also accepted). Leave empty to remove the webhook.', 'fixthisplz' )
				: __( 'Must start with https://. Leave empty to remove the webhook.', 'fixthisplz' ),
			$id . '-desc'
		);
		F::row_close();

		F::select_row( __( 'Format', 'fixthisplz' ), $path( 'format' ), (string) ( $w['format'] ?? 'generic' ), Webhooks::format_labels() );

		F::row_open( __( 'Events', 'fixthisplz' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Events', 'fixthisplz' ) . '</legend>';
		F::list_marker( $path( 'events' ) );
		foreach ( Webhooks::event_labels() as $event => $label ) {
			F::checkbox_item( $path( 'events' ), $event, null === $w || in_array( $event, (array) $w['events'], true ), $label );
		}
		echo '</fieldset>';
		F::row_close();

		F::row_open( __( 'Active', 'fixthisplz' ) );
		F::checkbox( $path( 'enabled' ), null === $w || ! empty( $w['enabled'] ), __( 'Send the events ticked above', 'fixthisplz' ) );
		F::row_close();

		if ( null !== $w ) {
			self::secret_row( $path, $w );
			F::row_open( __( 'Test', 'fixthisplz' ) );
			echo '<p><button type="button" class="button fixthisplz-webhook-test" data-index="' . esc_attr( (string) $i ) . '" hidden>' . esc_html__( 'Send a test', 'fixthisplz' ) . '</button></p>';
			echo '<div class="fixthisplz-webhook-result" role="status" aria-live="polite"></div>';
			F::description( __( 'Sends a test message to the saved address, even when the webhook is inactive. Save your changes first. Requires JavaScript.', 'fixthisplz' ) );
			F::row_close();
		}

		F::table_close();
		echo '</fieldset>';
	}

	/**
	 * The signing secret of a saved generic JSON webhook, readable by administrators only (this screen), folded for
	 * developers. The encrypted secret goes back in a hidden field, outside the fold: it is kept unless "regenerate" is ticked.
	 *
	 * @param callable            $path
	 * @param array<string,mixed> $w
	 */
	private static function secret_row( callable $path, array $w ): void {
		$id     = F::id( $path( 'secret' ) );
		$secret = Webhooks::secret( $w );
		F::row_open( __( 'Signing secret', 'fixthisplz' ), '', true, F::show_if( $path( 'format' ), '=', 'generic' ) );
		echo '<input type="hidden" name="' . esc_attr( F::name( $path( 'secret' ) ) ) . '" value="' . esc_attr( (string) $w['secret'] ) . '">';
		// Open when the secret is unreadable: the administrator must see that a new one is coming.
		F::details_open( __( 'Check the signature (developers)', 'fixthisplz' ), null === $secret, 'fixthisplz-disclosure' );
		if ( null !== $secret ) {
			echo '<p><input type="text" readonly class="large-text code" id="' . esc_attr( $id ) . '" value="' . esc_attr( $secret ) . '" aria-label="' . esc_attr__( 'Signing secret', 'fixthisplz' ) . '" aria-describedby="' . esc_attr( $id ) . '-desc"></p>';
		}
		echo '<label><input type="checkbox" name="' . esc_attr( F::name( $path( 'regenerate' ) ) ) . '" value="1"> ' . esc_html__( 'Make a new secret when saving', 'fixthisplz' ) . '</label>';
		F::description(
			null !== $secret
				? __( 'Each request carries the header X-FixThisPlz-Signature: sha256= followed by the HMAC-SHA256 of the raw body with this secret. Your service computes the same value to check that the request comes from this site.', 'fixthisplz' )
				: __( 'The secret can no longer be read (the FIXTHISPLZ_SECRET key changed): a new one is made when saving.', 'fixthisplz' ),
			$id . '-desc'
		);
		F::details_close();
		F::row_close();
	}
}
