<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * What the "Statuses" and "Priorities" settings tabs post (spec 7.10, 7.11): rows in display order, as
 * `{FIELD}_rows`, and the choice each deleted choice hands its reports to, as `{FIELD}_moves`. Keys are created
 * here and never change afterwards; the reports of deleted choices are moved once the settings are saved.
 */
final class List_Editor {

	/** The lists edited this way. @var list<class-string<Choice_List>> */
	public const LISTS = array( Statuses::class, Priorities::class );

	/** Per posted list: keys saved before this post and the posted move targets, until the option is written. @var array<string,array{known:string[],targets:array}> */
	private static array $pending = array();

	/** Settings_Page::register_hooks(): the moves run after the option is written (first save included). */
	public static function register_hooks(): void {
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'apply_moves' ), 10, 0 );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'apply_moves' ), 10, 0 );
	}

	/**
	 * Settings_Sanitizer: each posted editor is turned into the list to store. A post of a list tab starts from no
	 * pending move, so an earlier post that never reached the option leaves nothing behind. Any other input keeps
	 * them: on a first save, WordPress sanitizes the value again (add_option) before the moves run.
	 *
	 * @param array<string,mixed> $input
	 */
	public static function apply_post( array $input ): array {
		$posted = array_filter( self::LISTS, static fn( string $list ): bool => array_key_exists( $list::FIELD . '_rows', $input ) );
		if ( $posted ) {
			self::$pending = array();
		}
		foreach ( $posted as $list ) {
			$input[ $list::SETTING ] = self::from_post( $list, $input[ $list::FIELD . '_rows' ], $input[ $list::FIELD . '_moves' ] ?? array() );
		}
		return $input;
	}

	/**
	 * Posted rows => list for $list::sanitize(). A row keeps its key only when it is a saved one; any other row
	 * is a new choice whose key is made from its name (rows without a name are dropped). New rows only fill the
	 * room the kept saved choices leave under MAX, so the cap never drops a saved one (nor moves its reports).
	 * The REQUIRED choices cannot be deleted, even by a forged post: missing ones are put back as saved, first.
	 *
	 * @param class-string<Choice_List> $list
	 * @param mixed                     $rows    Untrusted.
	 * @param mixed                     $targets Untrusted: deleted key => target key.
	 * @return list<array<string,mixed>>
	 */
	private static function from_post( string $list, $rows, $targets ): array {
		$saved = array_column( $list::sanitize( Settings::get()[ $list::SETTING ] ), null, 'key' );
		$rows  = array_filter( is_array( $rows ) ? $rows : array(), 'is_array' );
		$kept  = array_intersect_key( $saved, array_flip( array_merge( $list::REQUIRED, array_filter( array_column( $rows, 'key' ), 'is_string' ) ) ) );
		$room  = $list::MAX - count( $kept );
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
				$key = self::new_key( $list, $label, $taken );
			}
			$taken[ $key ] = true;
			$out[]         = array( 'key' => $key ) + $row;
		}

		$posted = array_column( $out, 'key' );
		foreach ( array_reverse( $list::REQUIRED ) as $required ) {
			if ( ! in_array( $required, $posted, true ) ) {
				array_unshift( $out, $saved[ $required ] );
			}
		}

		self::$pending[ $list ] = array(
			'known'   => array_column( $saved, 'key' ),
			'targets' => is_array( $targets ) ? $targets : array(),
		);
		return $out;
	}

	/**
	 * `[a-z0-9_]` key of at most 20 characters made from a name (or from the list field when the name has no
	 * usable letter), suffixed _2, _3… while already taken.
	 *
	 * @param class-string<Choice_List> $list
	 * @param array<string,mixed>       $taken
	 */
	public static function new_key( string $list, string $label, array $taken ): string {
		$base = str_replace( '-', '_', sanitize_key( str_replace( ' ', '_', remove_accents( $label ) ) ) );
		$base = '' !== $base ? substr( $base, 0, Choice_List::KEY_MAX ) : $list::FIELD;
		$key  = $base;
		for ( $n = 2; isset( $taken[ $key ] ); $n++ ) {
			$suffix = '_' . $n;
			$key    = substr( $base, 0, Choice_List::KEY_MAX - strlen( $suffix ) ) . $suffix;
		}
		return $key;
	}

	/**
	 * Reports of each choice saved before the post but absent from the settings now written go to the posted
	 * target, when it is one of the remaining choices, else to the first REQUIRED one. Only after a post of a tab.
	 */
	public static function apply_moves(): void {
		$pending       = self::$pending;
		self::$pending = array();

		$repo = new Reports_Repository();
		foreach ( $pending as $list => $post ) {
			$kept = array_keys( $list::all() );
			foreach ( array_diff( $post['known'], $kept ) as $deleted ) {
				$target = $post['targets'][ $deleted ] ?? '';
				$repo->reassign( $list, $deleted, is_string( $target ) && in_array( $target, $kept, true ) ? $target : $list::REQUIRED[0] );
			}
		}
	}
}
