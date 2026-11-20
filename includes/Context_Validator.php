<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and redacts the client-supplied context (spec 5.1). Closed key list: anything else is dropped.
 * Every text goes through Redactor and is bounded in characters; non-strings are cast (arrays become '').
 */
final class Context_Validator {

	private const MAX_ENTRIES = 20;
	private const COORD_LIMIT = 1000000;

	/** @return array<string,mixed>|\WP_Error */
	public static function validate( mixed $raw ): array|\WP_Error {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( __( 'The report context is missing or malformed.', 'bug-spotter' ) );
		}

		$url = Redactor::url( self::scalar( $raw['url'] ?? null ), 2048 );
		if ( 1 !== preg_match( '#^https?://#', $url ) ) {
			return self::invalid( __( 'The report context needs a valid http(s) URL.', 'bug-spotter' ) );
		}

		$context = array(
			'url'            => $url,
			'title'          => self::text( $raw['title'] ?? null, 255 ),
			'locale'         => self::text( $raw['locale'] ?? null, 16 ),
			'timezone'       => self::text( $raw['timezone'] ?? null, 64 ),
			'user_agent'     => self::text( $raw['user_agent'] ?? null, 512 ),
			'viewport'       => self::text( $raw['viewport'] ?? null, 32 ),
			'plugin_version' => self::text( $raw['plugin_version'] ?? null, 32 ),
			'wp_page'        => self::wp_page( $raw['wp_page'] ?? null ),
			'element'        => self::element( $raw['element'] ?? null ),
			'console'        => self::console( $raw['console'] ?? null ),
			'network'        => self::network( $raw['network'] ?? null ),
		);

		$capture = self::capture( $raw['capture'] ?? null );
		if ( null !== $capture ) {
			$context['capture'] = $capture;
		}
		return $context;
	}

	/** How the attached screenshot was made; anything outside the closed values drops the key (never a 422). @return array{method:string,masked:bool}|null */
	private static function capture( mixed $v ): ?array {
		if ( ! is_array( $v ) || ! in_array( $v['method'] ?? null, Screenshot_Store::METHODS, true ) || ! is_bool( $v['masked'] ?? null ) ) {
			return null;
		}
		return array( 'method' => $v['method'], 'masked' => $v['masked'] );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'bugspot_invalid_context', $message, array( 'status' => 422 ) );
	}

	/** Scalars are cast to string; null, bools and arrays become ''. */
	private static function scalar( mixed $v ): string {
		if ( is_string( $v ) ) {
			return $v;
		}
		return ( is_int( $v ) || is_float( $v ) ) ? (string) $v : '';
	}

	private static function text( mixed $v, int $max ): string {
		return trim( Redactor::text( self::scalar( $v ), $max ) );
	}

	private static function wp_page( mixed $v ): string {
		if ( is_array( $v ) || is_object( $v ) ) {
			$v = wp_json_encode( $v );
		}
		return self::text( $v, 64 );
	}

	/** @return array<string,mixed>|null */
	private static function element( mixed $v ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		return array(
			'selector' => self::text( $v['selector'] ?? null, 512 ),
			'tag'      => self::text( $v['tag'] ?? null, 32 ),
			'role'     => self::text( $v['role'] ?? null, 64 ),
			'name'     => self::text( $v['name'] ?? null, 200 ),
			'text'     => self::text( $v['text'] ?? null, 300 ),
			'rect'     => self::rect( $v['rect'] ?? null ),
		);
	}

	/** @return array{x:int,y:int,width:int,height:int} */
	private static function rect( mixed $v ): array {
		$v   = is_array( $v ) ? $v : array();
		$out = array();
		foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
			$n = $v[ $key ] ?? 0;
			if ( is_string( $n ) && is_numeric( $n ) ) {
				$n = (float) $n;
			}
			$out[ $key ] = ( is_int( $n ) || is_float( $n ) ) && is_finite( $n )
				? (int) max( -self::COORD_LIMIT, min( self::COORD_LIMIT, $n ) )
				: 0;
		}
		return $out;
	}

	/** @return list<array{level:string,message:string,at:string}> */
	private static function console( mixed $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$level = strtolower( self::scalar( $entry['level'] ?? null ) );
			if ( 'error' !== $level && 'warn' !== $level ) {
				continue;
			}
			$out[] = array(
				'level'   => $level,
				'message' => self::text( $entry['message'] ?? null, 500 ),
				'at'      => self::text( $entry['at'] ?? null, 32 ),
			);
		}
		return array_slice( $out, -self::MAX_ENTRIES );
	}

	/** @return list<array{method:string,url:string,status:int,at:string}> */
	private static function network( mixed $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$status = self::status( $entry['status'] ?? null );
			if ( null === $status ) {
				continue;
			}
			$method = substr( strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', self::scalar( $entry['method'] ?? null ) ) ), 0, 10 );
			$out[]  = array(
				'method' => $method,
				'url'    => Redactor::url( self::scalar( $entry['url'] ?? null ), 512 ),
				'status' => $status,
				'at'     => self::text( $entry['at'] ?? null, 32 ),
			);
		}
		return array_slice( $out, -self::MAX_ENTRIES );
	}

	/** Integer 0-599 (int, integral float or digit string), else null. */
	private static function status( mixed $v ): ?int {
		if ( is_string( $v ) && 1 === preg_match( '/^\d{1,3}$/', $v ) ) {
			$v = (int) $v;
		} elseif ( is_float( $v ) && is_finite( $v ) && floor( $v ) === $v && abs( $v ) < 1000 ) {
			$v = (int) $v;
		}
		return ( is_int( $v ) && $v >= 0 && $v <= 599 ) ? $v : null;
	}
}
