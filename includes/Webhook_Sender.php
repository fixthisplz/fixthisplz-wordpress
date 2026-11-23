<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/**
 * Posts the report events to the enabled webhooks that chose them (spec 7.12): right after the database change (after
 * the whole bulk action, see batch()), non-blocking, with no queue and no retry. Only the addresses saved by an
 * administrator are called.
 */
final class Webhook_Sender {

	private const TIMEOUT = 2;

	/** The settings "Send a test" button waits for the answer. */
	private const TEST_TIMEOUT = 5;

	/** Per event, the [report ID, change, new value] held while batch() runs; null outside it. @var array<string,list<array{0:int,1:?string,2:string}>>|null */
	private static ?array $batch = null;

	public static function register_hooks(): void {
		add_action( 'fixthisplz_report_created', array( self::class, 'on_created' ) );
		add_action( 'fixthisplz_report_changed', array( self::class, 'on_changed' ), 10, 2 );
	}

	public static function on_created( int $report_id ): void {
		self::dispatch( 'created', $report_id, null, '' );
	}

	/**
	 * One message per event: a status change (except New → Seen, which only means someone opened the report) and a
	 * new assignee (removing the assignee is not an event).
	 *
	 * @param array<string,array{0:?string,1:?string}> $changes
	 */
	public static function on_changed( int $report_id, array $changes ): void {
		$status = $changes['status'] ?? null;
		if ( null !== $status && array( 'new', 'seen' ) !== $status ) {
			self::dispatch( 'status_changed', $report_id, Report_Format::change( 'status', ...$status ), Statuses::label( (string) $status[1] ) );
		}
		$assignee = $changes['assigned_to'] ?? null;
		if ( null !== ( $assignee[1] ?? null ) ) {
			self::dispatch( 'assigned', $report_id, Report_Format::change( 'assigned_to', ...$assignee ), Team::name( (int) $assignee[1] ) );
		}
	}

	/**
	 * Runs $fn (a bulk action) and only then calls the webhooks, so a slow receiver can never interrupt the database
	 * writes: the generic JSON webhooks get one signed event per report; each chat webhook one message per event,
	 * the usual one when a single report changed, else a summary.
	 */
	public static function batch( callable $fn ): void {
		self::$batch = array();
		try {
			$fn();
			$batch = self::$batch;
		} finally {
			self::$batch = null;
		}
		foreach ( $batch as $event => $items ) {
			$webhooks = self::subscribed( $event );
			if ( 1 === count( $items ) ) {
				self::post( $webhooks, $event, $items[0][0], $items[0][1] );
				continue;
			}
			$generic = array_filter( $webhooks, static fn( array $w ): bool => 'generic' === $w['format'] );
			foreach ( $items as $item ) {
				self::post( $generic, $event, $item[0], $item[1] );
			}
			$data = Webhook_Payload::summary( $event, count( $items ), $items[0][2] );
			foreach ( array_diff_key( $webhooks, $generic ) as $webhook ) {
				self::send( $webhook, $data, false );
			}
		}
	}

	/** @param string $value New status name or assignee name, for a bulk summary. */
	private static function dispatch( string $event, int $report_id, ?string $change, string $value ): void {
		$webhooks = self::subscribed( $event );
		if ( $webhooks && null !== self::$batch ) {
			self::$batch[ $event ][] = array( $report_id, $change, $value );
			return;
		}
		self::post( $webhooks, $event, $report_id, $change );
	}

	/** @return array<int,array<string,mixed>> The enabled webhooks that chose $event. */
	private static function subscribed( string $event ): array {
		return array_filter( Webhooks::active(), static fn( array $w ): bool => in_array( $event, (array) $w['events'], true ) );
	}

	/** @param array<int,array<string,mixed>> $webhooks */
	private static function post( array $webhooks, string $event, int $report_id, ?string $change ): void {
		if ( ! $webhooks ) {
			return;
		}
		$report = ( new Reports_Repository() )->find( $report_id );
		if ( null === $report ) {
			return;
		}
		$data = Webhook_Payload::data( $event, $report, $change );
		foreach ( $webhooks as $webhook ) {
			self::send( $webhook, $data, false );
		}
	}

	/**
	 * Posts $data to $webhook in its format. The generic JSON carries X-FixThisPlz-Event and
	 * X-FixThisPlz-Signature: "sha256=" + HMAC-SHA256 (hex) of the raw body with the webhook's secret.
	 * Never throws: a failing webhook must not break the admin request nor the other webhooks.
	 *
	 * @param array<string,mixed> $webhook A row of Webhooks::all().
	 * @param array<string,mixed> $data    Webhook_Payload::data() or summary().
	 * @param bool                $blocking True only for the test button: wait for the answer.
	 * @return array|\WP_Error wp_remote_post() result.
	 */
	public static function send( array $webhook, array $data, bool $blocking ) {
		try {
			$format  = (string) $webhook['format'];
			$body    = Webhook_Payload::body( $format, $data );
			$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
			if ( 'generic' === $format ) {
				$headers['X-FixThisPlz-Event'] = $data['event'];
				$secret                        = Webhooks::secret( $webhook );
				if ( null !== $secret ) {
					$headers['X-FixThisPlz-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
				}
			}
			$url = (string) $webhook['url'];
			return wp_remote_post(
				$url,
				array(
					'body'               => $body,
					'headers'            => $headers,
					'timeout'            => $blocking ? self::TEST_TIMEOUT : self::TIMEOUT,
					'blocking'           => $blocking,
					'redirection'        => 0,
					// Checked again at send time (the address may now resolve to a private one), except development hosts.
					'reject_unsafe_urls' => ! Webhooks::is_dev_url( $url ),
				)
			);
		} catch ( \Throwable $e ) {
			Plugin::debug_log( 'webhook failed: ' . $e->getMessage() );
			return new \WP_Error( 'fixthisplz_webhook_failed', $e->getMessage() );
		}
	}
}
