<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** Daily purge of closed reports older than the configured retention. */
final class Retention {

	public static function register_hooks(): void {
		add_action( Installer::CRON_HOOK, array( self::class, 'run' ) );
	}

	/** @return int Number of reports deleted; 0 when retention is disabled (0 months). */
	public static function run(): int {
		return self::purge_closed_older_than( (int) ( Settings::get()['data']['retention_months'] ?? 0 ) );
	}

	/** Deletes the closed reports whose last status change is older than $months months (screenshots and activity too). */
	public static function purge_closed_older_than( int $months ): int {
		$closed = Statuses::closed();
		if ( $months <= 0 || ! $closed ) {
			return 0;
		}
		$cutoff = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );

		$repo    = new Reports_Repository();
		$deleted = 0;
		foreach ( $repo->ids_closed_before( $closed, $cutoff ) as $id ) {
			$deleted += $repo->delete( $id ) ? 1 : 0;
		}
		return $deleted;
	}
}
