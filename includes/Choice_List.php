<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/**
 * An ordered list of choices kept in the settings (spec 7.10, 7.11): each has a technical key, a name and a color.
 * One subclass per list (Statuses, Priorities), read through static calls. A key is made once, from the name,
 * and never changes; the REQUIRED keys can be renamed and recolored but never deleted.
 */
abstract class Choice_List {

	/** Key of the list in the settings. */
	public const SETTING = '';

	/** Report column holding the choice, also the prefix of the settings-tab fields (`{FIELD}_rows`, `{FIELD}_moves`). */
	public const FIELD = '';

	/** Always present, put back first when missing. The first one takes the reports of a deleted choice. */
	public const REQUIRED = array();

	public const MAX = 20;

	public const KEY_MAX = 20;

	/** Color of a choice without a valid one, or unknown to the settings. */
	public const FALLBACK_COLOR = '#8c8f94';

	private const LABEL_MAX = 40;

	/** Per list: last entries() result and the raw setting it was built from. @var array<string,array{raw:mixed,list:list<array>}> */
	private static array $memo = array();

	/** @return array<string,string> Translated name of the original choices, used while their name is left empty. */
	abstract protected static function default_labels(): array;

	/** Fields of an entry beyond key, label and color (none by default). @param array<string,mixed> $entry Untrusted. */
	protected static function extra_fields( array $entry, bool $required ): array {
		return array();
	}

	/** @return array<string,string> key => name, in the order of the settings. */
	public static function all(): array {
		$labels = array();
		foreach ( static::entries() as $entry ) {
			$labels[ $entry['key'] ] = '' !== $entry['label'] ? $entry['label'] : static::default_label( $entry['key'] );
		}
		return $labels;
	}

	/** Name of any stored key; a key unknown to the settings shows as itself. */
	public static function label( string $key ): string {
		return static::all()[ $key ] ?? $key;
	}

	/** Validated #rgb or #rrggbb color (the fallback color for a key unknown to the settings). */
	public static function color( string $key ): string {
		foreach ( static::entries() as $entry ) {
			if ( $entry['key'] === $key ) {
				return $entry['color'];
			}
		}
		return self::FALLBACK_COLOR;
	}

	public static function is_valid( string $key ): bool {
		return in_array( $key, array_column( static::entries(), 'key' ), true );
	}

	/** Translated name of an original choice; any other key: itself. */
	public static function default_label( string $key ): string {
		return static::default_labels()[ $key ] ?? $key;
	}

	/**
	 * The list setting, validated: keys `[a-z0-9_]{1,20}` and unique, name text of at most 40 characters,
	 * #rgb or #rrggbb color (else the default), the REQUIRED keys always present (put back first), at most MAX
	 * entries. Invalid entries are dropped; a non-list gives the defaults.
	 *
	 * @param mixed $input Untrusted.
	 * @return list<array<string,mixed>>
	 */
	public static function sanitize( $input ): array {
		$defaults = array_column( Settings::defaults()[ static::SETTING ], null, 'key' );
		$input    = is_array( $input ) && array_is_list( $input ) ? $input : array_values( $defaults );

		$out      = array();
		$optional = 0;
		foreach ( $input as $entry ) {
			$key = is_array( $entry ) && is_string( $entry['key'] ?? null ) ? $entry['key'] : '';
			if ( 1 !== preg_match( '/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/', $key ) || isset( $out[ $key ] ) ) {
				continue;
			}
			$required = in_array( $key, static::REQUIRED, true );
			if ( ! $required && $optional >= static::MAX - count( static::REQUIRED ) ) {
				continue;
			}
			$optional += $required ? 0 : 1;
			$label     = is_scalar( $entry['label'] ?? null ) ? sanitize_text_field( (string) $entry['label'] ) : '';
			$color     = Settings_Sanitizer::hex_color( $entry['color'] ?? null );

			$out[ $key ] = array(
				'key'   => $key,
				'label' => mb_substr( $label, 0, self::LABEL_MAX ),
				'color' => $color ?? ( $defaults[ $key ]['color'] ?? self::FALLBACK_COLOR ),
			) + static::extra_fields( $entry, $required );
		}

		$missing = array_diff_key( array_flip( static::REQUIRED ), $out );
		return array_values( array_intersect_key( $defaults, $missing ) + $out );
	}

	/** @return list<array<string,mixed>> Rebuilt only when the setting changes. */
	protected static function entries(): array {
		$raw = Settings::get()[ static::SETTING ];
		if ( ! isset( self::$memo[ static::class ] ) || self::$memo[ static::class ]['raw'] !== $raw ) {
			self::$memo[ static::class ] = array(
				'raw'  => $raw,
				'list' => static::sanitize( $raw ),
			);
		}
		return self::$memo[ static::class ]['list'];
	}
}
