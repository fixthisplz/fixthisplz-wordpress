<?php
// Usage: wp eval-file wp-content/plugins/bug-spotter/tests/php/run.php [file-name-filter]
// Each test runs inside a SQL transaction that is always rolled back.

global $wpdb, $bugspot_tests; // $args is local to wp eval-file, not global.
$bugspot_tests = array();
$filter        = isset( $args[0] ) ? (string) $args[0] : '';

require_once __DIR__ . '/lib.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	if ( '' !== $filter && false === strpos( basename( $file ), $filter ) ) {
		continue;
	}
	require_once $file;
}

$failures = array();
foreach ( $bugspot_tests as list( $name, $fn ) ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		$fn();
	} catch ( Throwable $e ) {
		$failures[] = 'FAIL ' . $name . ': ' . $e->getMessage();
	}
	$wpdb->query( 'ROLLBACK' );
}

foreach ( $failures as $line ) {
	echo $line . "\n";
}
if ( $failures ) {
	exit( 1 );
}
echo 'OK (' . count( $bugspot_tests ) . " tests)\n";
