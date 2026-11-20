<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** Report statuses (ordered list from the "statuses" setting) and the fixed priorities. */
final class Statuses {

	/** Always present and never closed: every new report is `new`, opening it makes it `seen`. */
	public const REQUIRED = array( 'new', 'seen' );

	public const MAX = 20;

	public const KEY_MAX = 20;

	/** Color of a status without a valid one, or unknown to the settings. */
	public const FALLBACK_COLOR = '#8c8f94';

	private const LABEL_MAX = 40;

	/** Last list() result and the raw setting it was built from. @var array{raw:mixed,list:list<array>}|null */
	private static ?array $memo = null;

	/**
	 * @return array<string,string> status key => label, in workflow order.
	 */
	public static function all(): array {
		$labels = array();
		foreach ( self::list() as $status ) {
			$labels[ $status['key'] ] = '' !== $status['label'] ? $status['label'] : self::default_label( $status['key'] );
		}
		return $labels;
	}

	/** Label of any stored status; a key unknown to the settings shows as itself. */
	public static function label( string $key ): string {
		return self::all()[ $key ] ?? $key;
	}

	/** @return string[] Keys of the statuses that mean the report is resolved (eligible for retention purge). */
	public static function closed(): array {
		return array_column( array_filter( self::list(), static fn( array $s ): bool => $s['closed'] ), 'key' );
	}

	/** Validated #rgb or #rrggbb color of a status (fallback color for a key unknown to the settings). */
	public static function color( string $key ): string {
		foreach ( self::list() as $status ) {
			if ( $status['key'] === $key ) {
				return $status['color'];
			}
		}
		return self::FALLBACK_COLOR;
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, array_column( self::list(), 'key' ), true );
	}

	/**
	 * The "statuses" setting, validated: keys `[a-z0-9_]{1,20}` and unique, label text of at most 40 characters,
	 * #rgb or #rrggbb color (else the default), `new` and `seen` always present (put back first) and never closed,
	 * at most 20 entries. Invalid entries are dropped; a non-list gives the defaults.
	 *
	 * @param mixed $input Untrusted.
	 * @return list<array{key:string,label:string,color:string,closed:bool}>
	 */
	public static function sanitize( $input ): array {
		$defaults = array_column( Settings::defaults()['statuses'], null, 'key' );
		$input    = is_array( $input ) && array_is_list( $input ) ? $input : array_values( $defaults );

		$out      = array();
		$optional = 0;
		foreach ( $input as $entry ) {
			$key = is_array( $entry ) && is_string( $entry['key'] ?? null ) ? $entry['key'] : '';
			if ( 1 !== preg_match( '/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/', $key ) || isset( $out[ $key ] ) ) {
				continue;
			}
			$required = in_array( $key, self::REQUIRED, true );
			if ( ! $required && $optional >= self::MAX - count( self::REQUIRED ) ) {
				continue;
			}
			$optional += $required ? 0 : 1;
			$label     = is_scalar( $entry['label'] ?? null ) ? sanitize_text_field( (string) $entry['label'] ) : '';
			$color     = Settings_Sanitizer::hex_color( $entry['color'] ?? null );
			$closed    = $entry['closed'] ?? false;

			$out[ $key ] = array(
				'key'    => $key,
				'label'  => mb_substr( $label, 0, self::LABEL_MAX ),
				'color'  => $color ?? ( $defaults[ $key ]['color'] ?? self::FALLBACK_COLOR ),
				'closed' => ! $required && ( is_string( $closed ) ? filter_var( $closed, FILTER_VALIDATE_BOOLEAN ) : (bool) $closed ),
			);
		}

		$missing = array_diff_key( array_flip( self::REQUIRED ), $out );
		return array_values( array_intersect_key( $defaults, $missing ) + $out );
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

	public static function is_valid_priority( string $priority ): bool {
		return array_key_exists( $priority, self::priorities() );
	}

	/** @return list<array{key:string,label:string,color:string,closed:bool}> Rebuilt only when the setting changes. */
	private static function list(): array {
		$raw = Settings::get()['statuses'];
		if ( null === self::$memo || self::$memo['raw'] !== $raw ) {
			self::$memo = array(
				'raw'  => $raw,
				'list' => self::sanitize( $raw ),
			);
		}
		return self::$memo['list'];
	}

	/** Translated name of the built-in statuses, used while their label is left empty (any other key: itself). */
	public static function default_label( string $key ): string {
		switch ( $key ) {
			case 'new':
				return __( 'New', 'bug-spotter' );
			case 'seen':
				return __( 'Seen', 'bug-spotter' );
			case 'confirmed':
				return __( 'Confirmed', 'bug-spotter' );
			case 'in_progress':
				return __( 'In progress', 'bug-spotter' );
			case 'fixed':
				return __( 'Fixed', 'bug-spotter' );
			case 'wont_fix':
				return __( 'Won\'t fix', 'bug-spotter' );
			case 'duplicate':
				return __( 'Duplicate', 'bug-spotter' );
		}
		return $key;
	}
}
