<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

final class Statuses {

	/** Statuses that mean the report is resolved (eligible for retention purge). */
	public const CLOSED = array( 'fixed', 'wont_fix', 'duplicate' );

	/**
	 * @return array<string,string> status key => translated label, in workflow order.
	 */
	public static function all(): array {
		return array(
			'new'         => __( 'New', 'bug-spotter' ),
			'seen'        => __( 'Seen', 'bug-spotter' ),
			'confirmed'   => __( 'Confirmed', 'bug-spotter' ),
			'in_progress' => __( 'In progress', 'bug-spotter' ),
			'fixed'       => __( 'Fixed', 'bug-spotter' ),
			'wont_fix'    => __( 'Won\'t fix', 'bug-spotter' ),
			'duplicate'   => __( 'Duplicate', 'bug-spotter' ),
		);
	}

	/**
	 * @return array<string,string> priority key => translated label, lowest first.
	 */
	public static function priorities(): array {
		return array(
			'low'      => __( 'Low', 'bug-spotter' ),
			'normal'   => __( 'Normal', 'bug-spotter' ),
			'high'     => __( 'High', 'bug-spotter' ),
			'critical' => __( 'Critical', 'bug-spotter' ),
		);
	}

	/**
	 * @return array<string,string> priority key => hex colour (white text stays readable on all of them).
	 */
	public static function priority_colors(): array {
		return array(
			'low'      => '#787c82',
			'normal'   => '#2271b1',
			'high'     => '#b26200',
			'critical' => '#d63638',
		);
	}

	public static function is_valid( string $status ): bool {
		return array_key_exists( $status, self::all() );
	}

	public static function is_valid_priority( string $priority ): bool {
		return array_key_exists( $priority, self::priorities() );
	}
}
