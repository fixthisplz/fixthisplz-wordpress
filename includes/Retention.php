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
		$months = (int) ( Settings::get()['data']['retention_months'] ?? 0 );
		if ( $months <= 0 ) {
			return 0;
		}
		return ( new Reports_Repository() )->purge_closed_older_than( $months );
	}
}
