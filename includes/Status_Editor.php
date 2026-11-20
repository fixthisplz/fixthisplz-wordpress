<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * What the "Statuses" settings tab posts (spec 7.10): rows in display order, as `status_rows`, and the status each
 * deleted status hands its reports to, as `status_moves`. Keys are created here and never change afterwards;
 * the reports of deleted statuses are moved once the settings are saved.
 */
final class Status_Editor {

	/** Keys saved before this post and the posted move targets, until the option is written. @var array{known:string[],targets:array}|null */
	private static ?array $pending = null;

	/** Settings_Page::register_hooks(): the moves run after the option is written (first save included). */
	public static function register_hooks(): void {
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'apply_moves' ), 10, 0 );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'apply_moves' ), 10, 0 );
	}

	/**
	 * Posted rows => "statuses" list for Statuses::sanitize(). A row keeps its key only when it is a saved one;
	 * any other row is a new status whose key is made from its name (rows without a name are dropped). New rows
	 * only fill the room the kept saved statuses leave under Statuses::MAX, so the cap never drops a saved one
	 * (nor moves its reports). `new` and `seen` cannot be deleted, even by a forged post: missing ones are put
	 * back as saved, first.
	 *
	 * @param mixed $rows    Untrusted.
	 * @param mixed $targets Untrusted: deleted key => target key.
	 * @return list<array<string,mixed>>
	 */
	public static function from_post( $rows, $targets ): array {
		$saved = array_column( Statuses::sanitize( Settings::get()['statuses'] ), null, 'key' );
		$rows  = array_filter( is_array( $rows ) ? $rows : array(), 'is_array' );
		$kept  = array_intersect_key( $saved, array_flip( array_merge( Statuses::REQUIRED, array_filter( array_column( $rows, 'key' ), 'is_string' ) ) ) );
		$room  = Statuses::MAX - count( $kept );
		$taken = $saved;
		$out   = array();
		foreach ( $rows as $row ) {
			$key = is_string( $row['key'] ?? null ) ? $row['key'] : '';
			if ( ! isset( $saved[ $key ] ) ) {
				$label = is_scalar( $row['label'] ?? null ) ? sanitize_text_field( (string) $row['label'] ) : '';
				if ( '' === $label || $room < 1 ) {
					continue;
				}
				--$room;
				$key = self::new_key( $label, $taken );
			}
			$taken[ $key ] = true;
			$out[]         = array( 'key' => $key ) + $row;
		}

		$posted = array_column( $out, 'key' );
		foreach ( array_reverse( Statuses::REQUIRED ) as $required ) {
			if ( ! in_array( $required, $posted, true ) ) {
				array_unshift( $out, $saved[ $required ] );
			}
		}

		self::$pending = array(
			'known'   => array_column( $saved, 'key' ),
			'targets' => is_array( $targets ) ? $targets : array(),
		);
		return $out;
	}

	/** `[a-z0-9_]` key of at most 20 characters made from a name, suffixed _2, _3… while already taken. @param array<string,mixed> $taken */
	public static function new_key( string $label, array $taken ): string {
		$base = str_replace( '-', '_', sanitize_key( str_replace( ' ', '_', remove_accents( $label ) ) ) );
		$base = '' !== $base ? substr( $base, 0, Statuses::KEY_MAX ) : 'status';
		$key  = $base;
		for ( $n = 2; isset( $taken[ $key ] ); $n++ ) {
			$suffix = '_' . $n;
			$key    = substr( $base, 0, Statuses::KEY_MAX - strlen( $suffix ) ) . $suffix;
		}
		return $key;
	}

	/**
	 * Reports of each status saved before the post but absent from the settings now written go to the posted
	 * target, when it is one of the remaining statuses, else to `new`. Only after a post of the Statuses tab.
	 */
	public static function apply_moves(): void {
		if ( null === self::$pending ) {
			return;
		}
		$pending       = self::$pending;
		self::$pending = null;

		$kept = array_column( Statuses::sanitize( Settings::get()['statuses'] ), 'key' );
		$repo = new Reports_Repository();
		foreach ( array_diff( $pending['known'], $kept ) as $deleted ) {
			$target = $pending['targets'][ $deleted ] ?? '';
			$repo->reassign_status( $deleted, is_string( $target ) && in_array( $target, $kept, true ) ? $target : 'new' );
		}
	}
}
