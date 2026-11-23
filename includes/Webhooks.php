<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/**
 * The webhooks of Settings › Alerts › Webhooks (spec 7.12): validation of the saved rows and their signing secret.
 * Each saved row: url, format, events, enabled, secret (a random hex key, stored encrypted with Secret).
 * Sending: Webhook_Sender; what is sent: Webhook_Payload.
 */
final class Webhooks {

	public const MAX = 5;

	public const FORMATS = array( 'generic', 'slack', 'discord', 'teams' );

	public const EVENTS = array( 'created', 'status_changed', 'assigned' );

	/** @return array<string,string> Format => label. */
	public static function format_labels(): array {
		return array(
			'generic' => __( 'Generic JSON (signed)', 'fixthisplz' ),
			'slack'   => __( 'Slack', 'fixthisplz' ),
			'discord' => __( 'Discord', 'fixthisplz' ),
			'teams'   => __( 'Microsoft Teams', 'fixthisplz' ),
		);
	}

	/** @return array<string,string> Event => label. */
	public static function event_labels(): array {
		return array(
			'created'        => __( 'New report', 'fixthisplz' ),
			'status_changed' => __( 'Status changed', 'fixthisplz' ),
			'assigned'       => __( 'Report assigned', 'fixthisplz' ),
		);
	}

	/** The saved webhooks, in the order of the settings tab. @return list<array<string,mixed>> */
	public static function all(): array {
		$rows = Settings::get()['webhooks'];
		return array_values( array_filter( is_array( $rows ) ? $rows : array(), static fn( $row ): bool => is_array( $row ) && is_string( $row['url'] ?? null ) && '' !== $row['url'] ) );
	}

	/** The saved webhooks that are switched on. @return list<array<string,mixed>> */
	public static function active(): array {
		return array_values( array_filter( self::all(), static fn( array $w ): bool => ! empty( $w['enabled'] ) ) );
	}

	/**
	 * Settings_Sanitizer: rows without an address are dropped, an invalid address is dropped with a notice.
	 * The secret is kept only when it decrypts (the form posts the stored ciphertext back), otherwise a new
	 * one is made, as it is when "regenerate" is ticked.
	 *
	 * @param mixed $rows Untrusted.
	 * @return list<array<string,mixed>>
	 */
	public static function sanitize( $rows ): array {
		$saved_urls = array_column( self::all(), 'url' );
		$out        = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$url = is_array( $row ) && is_string( $row['url'] ?? null ) ? trim( $row['url'] ) : '';
			if ( '' === $url || count( $out ) >= self::MAX ) {
				continue;
			}
			// A saved address was checked when it was saved: no new DNS lookup when another tab is saved.
			$clean = in_array( $url, $saved_urls, true ) ? $url : self::clean_url( $url );
			if ( null === $clean ) {
				self::url_error( $url );
				continue;
			}
			$events = array_filter( (array) ( $row['events'] ?? array() ), 'is_string' );
			$out[]  = array(
				'url'     => $clean,
				'format'  => in_array( $row['format'] ?? null, self::FORMATS, true ) ? $row['format'] : 'generic',
				'events'  => array_values( array_intersect( self::EVENTS, $events ) ),
				'enabled' => Settings_Sanitizer::bool( $row['enabled'] ?? false ),
				'secret'  => ! Settings_Sanitizer::bool( $row['regenerate'] ?? false ) && null !== self::secret( $row ) ? $row['secret'] : Secret::encrypt( bin2hex( random_bytes( 32 ) ) ),
			);
		}
		return $out;
	}

	/** The plain signing secret of a saved row (64 hex characters); null when missing or unreadable. @param array<string,mixed> $row */
	public static function secret( array $row ): ?string {
		$plain = is_string( $row['secret'] ?? null ) ? Secret::decrypt( $row['secret'] ) : null;
		return null !== $plain && 1 === preg_match( '/^[0-9a-f]{64}$/', $plain ) ? $plain : null;
	}

	/**
	 * Whether development hosts are accepted: by default only when the WordPress environment type is "local" or
	 * "development"; the `fixthisplz_allow_dev_webhooks` filter decides otherwise.
	 */
	public static function dev_hosts_allowed(): bool {
		return (bool) apply_filters( 'fixthisplz_allow_dev_webhooks', in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );
	}

	/** A development host (localhost, 127.0.0.1, *.localhost, *.test) while they are allowed: http and no public-address check. */
	public static function is_dev_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$dev  = in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
		return $dev && self::dev_hosts_allowed();
	}

	/** An https address that wp_http_validate_url() accepts (public host, safe port), or a development host. */
	private static function clean_url( string $url ): ?string {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return null;
		}
		if ( self::is_dev_url( $url ) ) {
			return $url;
		}
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && false !== wp_http_validate_url( $url ) ? $url : null;
	}

	private static function url_error( string $url ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		$message = self::dev_hosts_allowed()
			/* translators: %s: the address typed for a webhook. The notice is printed as HTML: the address is escaped. */
			? __( 'The webhook %s was not saved: its address must start with https:// and point to a public server (http://localhost and *.test addresses are accepted for development).', 'fixthisplz' )
			/* translators: %s: the address typed for a webhook. The notice is printed as HTML: the address is escaped. */
			: __( 'The webhook %s was not saved: its address must start with https:// and point to a public server.', 'fixthisplz' );
		$message = sprintf( $message, esc_html( $url ) );
		// WordPress sanitizes a new option twice (update_option() then add_option()): one notice is enough.
		if ( in_array( $message, array_column( get_settings_errors( Settings::OPTION ), 'message' ), true ) ) {
			return;
		}
		add_settings_error( Settings::OPTION, 'fixthisplz_webhook_url', $message );
	}
}
