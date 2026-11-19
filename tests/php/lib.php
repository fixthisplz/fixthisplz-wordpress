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
