<?php

namespace FixThisPlz;

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
		return array( 'closed' => ! $required && Settings_Sanitizer::bool( $entry['closed'] ?? false ) );
	}

	protected static function default_labels(): array {
		return array(
			'new'         => __( 'New', 'fixthisplz' ),
			'seen'        => __( 'Seen', 'fixthisplz' ),
			'confirmed'   => __( 'Confirmed', 'fixthisplz' ),
			'in_progress' => __( 'In progress', 'fixthisplz' ),
			'fixed'       => __( 'Fixed', 'fixthisplz' ),
			'wont_fix'    => __( 'Won\'t fix', 'fixthisplz' ),
			'duplicate'   => __( 'Duplicate', 'fixthisplz' ),
		);
	}
}
