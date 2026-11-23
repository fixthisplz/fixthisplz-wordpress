<?php

namespace FixThisPlz\Frontend;

defined( 'ABSPATH' ) || exit;

/** Decides, server side, whether the loader is enqueued at all (spec 6.1). */
final class Visibility {

	/**
	 * @param array<string,mixed> $settings Full plugin settings.
	 * @param string              $request_path Path of the request (no domain, no query).
	 */
	public static function should_load( array $settings, ?\WP_User $user, string $request_path, int $queried_id, bool $is_admin ): bool {
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$v = is_array( $settings['visibility'] ?? null ) ? $settings['visibility'] : array();

		if ( $is_admin && empty( $v['show_in_admin'] ) ) {
			return false;
		}

		if ( $queried_id > 0 && in_array( $queried_id, array_map( 'intval', (array) ( $v['excluded_pages'] ?? array() ) ), true ) ) {
			return false;
		}

		$path = '/' . ltrim( $request_path, '/' );
		foreach ( (array) ( $v['excluded_url_prefixes'] ?? array() ) as $prefix ) {
			if ( ! is_string( $prefix ) || '' === trim( $prefix ) ) {
				continue;
			}
			$prefix = '/' . ltrim( trim( $prefix ), '/' );
			if ( 0 === strpos( $path, $prefix ) ) {
				return false;
			}
		}

		return self::user_allowed( $settings, $user );
	}

	/**
	 * Role part of the visibility rule (guests / checked roles), shared with the REST submit route.
	 *
	 * @param array<string,mixed> $settings Full plugin settings.
	 */
	public static function user_allowed( array $settings, ?\WP_User $user ): bool {
		$v = is_array( $settings['visibility'] ?? null ) ? $settings['visibility'] : array();
		if ( null === $user || 0 === (int) $user->ID ) {
			return ! empty( $v['guests'] );
		}
		return array() !== array_intersect( (array) $user->roles, (array) ( $v['roles'] ?? array() ) );
	}
}
