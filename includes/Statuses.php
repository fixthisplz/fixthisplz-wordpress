<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** Report statuses: ordered list from the "statuses" setting (Kanban columns, selectors), some of them "closed". */
final class Statuses extends Choice_List {

	public const SETTING = 'statuses';

	public const FIELD = 'status';

	/** Never closed: every new report is `new`, opening it makes it `seen`. */
	public const REQUIRED = array( 'new', 'seen' );

	/** @return string[] Keys of the statuses that mean the report is resolved (eligible for retention purge). */
	public static function closed(): array {
		return array_column( array_filter( self::entries(), static fn( array $s ): bool => $s['closed'] ), 'key' );
	}

	/** The "closed" flag (spec 7.10), never set on a required status. @param array<string,mixed> $entry Untrusted. */
	protected static function extra_fields( array $entry, bool $required ): array {
		$closed = $entry['closed'] ?? false;
		return array( 'closed' => ! $required && ( is_string( $closed ) ? filter_var( $closed, FILTER_VALIDATE_BOOLEAN ) : (bool) $closed ) );
	}

	protected static function default_labels(): array {
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
}
