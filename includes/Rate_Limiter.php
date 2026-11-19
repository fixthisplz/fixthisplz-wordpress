<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window limiter on top of transients (hour and day windows).
 * Each transient stores {count, reset_at}: the window starts at the first counted hit and is not
 * extended by later hits, and a blocked attempt is never counted.
 * Not atomic: two simultaneous requests may both read the same count (acceptable for anti-spam).
 */
final class Rate_Limiter {

	private int $per_hour;
	private int $per_day;

	public function __construct( int $per_hour, int $per_day ) {
		$this->per_hour = $per_hour;
		$this->per_day  = $per_day;
	}

	/** True when allowed (and counted); false when either window is exhausted (nothing counted). */
	public function attempt( string $ip_hash ): bool {
		$short = substr( $ip_hash, 0, 16 );
		$hour  = array( 'bugspot_rl_h_' . $short, HOUR_IN_SECONDS, $this->per_hour );
		$day   = array( 'bugspot_rl_d_' . $short, DAY_IN_SECONDS, $this->per_day );

		$states = array();
		foreach ( array( $hour, $day ) as list( $key, $window, $limit ) ) {
			$state = $this->read( $key );
			if ( $state['count'] >= $limit ) {
				return false;
			}
			$states[] = array( $key, $window, $state );
		}

		foreach ( $states as list( $key, $window, $state ) ) {
			$reset_at = $state['reset_at'] ?? time() + $window;
			set_transient(
				$key,
				array(
					'count'    => $state['count'] + 1,
					'reset_at' => $reset_at,
				),
				max( 1, $reset_at - time() )
			);
		}
		return true;
	}

	/** @return array{count:int,reset_at:?int} */
	private function read( string $key ): array {
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['count'], $value['reset_at'] ) || $value['reset_at'] <= time() ) {
			return array(
				'count'    => 0,
				'reset_at' => null,
			);
		}
		return array(
			'count'    => (int) $value['count'],
			'reset_at' => (int) $value['reset_at'],
		);
	}
}
