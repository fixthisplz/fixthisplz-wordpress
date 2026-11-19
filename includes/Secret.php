<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned secret key: encrypts the reporter e-mail and signs HMACs.
 * Independent from the WordPress salts so rotating them never makes stored data unreadable.
 */
final class Secret {

	public const OPTION = 'bugspot_secret';

	/** Raw 32-byte key: BUGSPOT_SECRET (hashed) if defined, otherwise a generated option. */
	public static function key(): string {
		// The filter exists so tests can exercise the constant path (a real constant cannot be undefined).
		$constant = apply_filters( 'bugspot_secret_constant', defined( 'BUGSPOT_SECRET' ) ? BUGSPOT_SECRET : null );
		if ( is_string( $constant ) && '' !== $constant ) {
			return hash( 'sha256', $constant, true );
		}

		$stored = get_option( self::OPTION, '' );
		$raw    = is_string( $stored ) ? base64_decode( $stored, true ) : false;
		if ( is_string( $raw ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) {
			return $raw;
		}

		$raw = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		update_option( self::OPTION, base64_encode( $raw ), false );
		return $raw;
	}

	/** base64( nonce . secretbox ) with a fresh random nonce per call. */
	public static function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return base64_encode( $nonce . $cipher );
	}

	/** Plain text, or null when the input is not valid ciphertext for the current key. Never throws. */
	public static function decrypt( string $cipher ): ?string {
		$raw = base64_decode( $cipher, true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		return false === $plain ? null : $plain;
	}

	/** Hex HMAC-SHA256 (64 chars) of $value. */
	public static function hmac( string $value ): string {
		return hash_hmac( 'sha256', $value, self::key() );
	}
}
