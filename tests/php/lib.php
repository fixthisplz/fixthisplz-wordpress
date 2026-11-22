<?php
// Minimal assertion helpers for the home-grown test runner.

function bugspot_test( string $name, callable $fn ): void {
	global $bugspot_tests;
	$bugspot_tests[] = array( $name, $fn );
}

function assert_same( mixed $expected, mixed $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $msg . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
	}
}

function assert_true( bool $v, string $msg = '' ): void {
	if ( true !== $v ) {
		throw new RuntimeException( '' !== $msg ? $msg : 'expected true' );
	}
}

function assert_wp_error( mixed $v, string $code ): void {
	if ( ! is_wp_error( $v ) ) {
		throw new RuntimeException( 'expected WP_Error "' . $code . '", got ' . gettype( $v ) );
	}
	if ( $v->get_error_code() !== $code ) {
		throw new RuntimeException( 'expected WP_Error code "' . $code . '", got "' . $v->get_error_code() . '"' );
	}
}

/**
 * Run $fn with the bugspot_settings option isolated: the original value (or its absence)
 * is restored afterwards, so tests never wipe real settings on the test site.
 */
function with_settings_option( callable $fn ): void {
	$missing  = new stdClass();
	$original = get_option( 'bugspot_settings', $missing );
	delete_option( 'bugspot_settings' );
	try {
		$fn();
	} finally {
		if ( $missing === $original ) {
			delete_option( 'bugspot_settings' );
		} else {
			update_option( 'bugspot_settings', $original );
		}
	}
}

// Tests never reach the network: a request that no test stubs with pre_http_request fails here.
add_filter( 'pre_http_request', static fn( $pre ) => false === $pre ? new WP_Error( 'bugspot_test_offline', 'Network blocked in tests.' ) : $pre, PHP_INT_MAX );
