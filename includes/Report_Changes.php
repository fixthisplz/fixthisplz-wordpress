<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** What an edit of a report really changes, validated (Reports_Repository::update()). No database write. */
final class Report_Changes {

	/** The fields an edit may change. */
	public const FIELDS = array( 'status', 'priority', 'assigned_to' );

	/**
	 * A value unknown to the settings (or an assignee who left the team) may be kept, never newly set; any invalid
	 * new value rejects the whole edit. `assigned_to` is a user ID, 0 or null for nobody.
	 *
	 * @param array $current The stored report.
	 * @param array $fields  Requested values; other keys are ignored.
	 * @return array<string,array{0:?string,1:?string}>|null field => [old, new] of the values that differ (empty when
	 *                                                       nothing changes); null when invalid or nothing editable.
	 */
	public static function from( array $current, array $fields ): ?array {
		$wanted = array_intersect_key( $fields, array_flip( self::FIELDS ) );
		if ( ! $wanted ) {
			return null;
		}
		$changes = array();
		foreach ( $wanted as $field => $value ) {
			$old = isset( $current[ $field ] ) ? (string) $current[ $field ] : null;
			$new = 'assigned_to' === $field ? ( (int) $value > 0 ? (string) (int) $value : null ) : (string) $value;
			if ( $old === $new ) {
				continue;
			}
			if ( ! self::is_allowed( $field, $new ) ) {
				return null;
			}
			$changes[ $field ] = array( $old, $new );
		}
		return $changes;
	}

	private static function is_allowed( string $field, ?string $value ): bool {
		return match ( $field ) {
			'status'   => Statuses::is_valid( (string) $value ),
			'priority' => Priorities::is_valid( (string) $value ),
			default    => null === $value || Team::is_member( (int) $value ),
		};
	}
}
