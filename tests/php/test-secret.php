<?php

use Bug_Spotter\Secret;

/** Drops cached copies of the key option: the runner's ROLLBACK does not roll back WP's object cache. */
function flush_secret_option_cache(): void {
	wp_cache_delete( Secret::OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/** Runs $fn with the real bugspot_secret option saved and restored (rotating it would orphan stored data). */
function with_secret_option( callable $fn ): void {
	flush_secret_option_cache();
	$missing  = new stdClass();
	$original = get_option( Secret::OPTION, $missing );
	try {
		$fn();
	} finally {
		flush_secret_option_cache();
		if ( $missing === $original ) {
			delete_option( Secret::OPTION );
		} else {
			update_option( Secret::OPTION, $original, false );
		}
	}
}

bugspot_test(
	'secret: encrypt_roundtrip',
	function () {
		$cipher = Secret::encrypt( 'jane@example.com' );
		assert_true( $cipher !== 'jane@example.com', 'cipher must differ from plain text' );
		assert_same( 'jane@example.com', Secret::decrypt( $cipher ) );
		assert_true( Secret::encrypt( 'x' ) !== Secret::encrypt( 'x' ), 'nonce must be random per call' );
	}
);

bugspot_test(
	'secret: decrypt_garbage_returns_null',
	function () {
		assert_same( null, Secret::decrypt( '' ) );
		assert_same( null, Secret::decrypt( '!!not base64!!' ) );
		assert_same( null, Secret::decrypt( base64_encode( 'short' ) ) );
		$tampered = base64_decode( Secret::encrypt( 'hello' ), true );
		$tampered[ strlen( $tampered ) - 1 ] = $tampered[ strlen( $tampered ) - 1 ] ^ "\x01";
		assert_same( null, Secret::decrypt( base64_encode( $tampered ) ) );
	}
);

bugspot_test(
	'secret: hmac_is_stable_and_64_hex',
	function () {
		$a = Secret::hmac( 'jane@example.com' );
		assert_same( $a, Secret::hmac( 'jane@example.com' ) );
		assert_true( 1 === preg_match( '/^[0-9a-f]{64}$/', $a ), 'expected 64 hex chars' );
		assert_true( $a !== Secret::hmac( 'john@example.com' ), 'different input, different hmac' );
	}
);

bugspot_test(
	'secret: key_is_32_bytes_and_persisted_without_autoload',
	function () {
		with_secret_option(
			function () {
				global $wpdb;
				delete_option( Secret::OPTION );
				flush_secret_option_cache();
				$key = Secret::key();
				assert_same( 32, strlen( $key ) );
				assert_same( $key, Secret::key() );
				assert_same( base64_encode( $key ), get_option( Secret::OPTION ) );
				$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secret::OPTION ) );
				assert_true( in_array( $autoload, array( 'no', 'off' ), true ), 'autoload must be off, got ' . var_export( $autoload, true ) );
			}
		);
	}
);

bugspot_test(
	'secret: constant_value_is_hashed_into_the_key',
	function () {
		assert_same( hash( 'sha256', 'wp-config-secret', true ), Secret::from_constant( 'wp-config-secret' ) );
		assert_same( null, Secret::from_constant( '' ), 'empty constant falls back to the option' );
		assert_same( null, Secret::from_constant( 123 ), 'non-string constant falls back to the option' );
		assert_same( null, Secret::from_constant( null ), 'undefined constant falls back to the option' );
	}
);

bugspot_test(
	'secret: key_uses_the_constant_when_defined_else_the_option',
	function () {
		$key           = Secret::key();
		$from_constant = defined( 'BUGSPOT_SECRET' ) ? Secret::from_constant( BUGSPOT_SECRET ) : null;
		assert_same( $from_constant ?? base64_decode( (string) get_option( Secret::OPTION ), true ), $key );
	}
);
