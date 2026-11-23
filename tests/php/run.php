<?php
// Usage: wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php [file-name-filter]
// Each test runs inside a SQL transaction that is always rolled back.

global $wpdb, $fixthisplz_tests; // $args is local to wp eval-file, not global.
$fixthisplz_tests = array();
$filter           = isset( $args[0] ) ? (string) $args[0] : '';

require_once __DIR__ . '/lib.php';

// Assertions compare English source strings; the bench site runs in French and the plugin ships fr_FR.
switch_to_locale( 'en_US' );

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	if ( '' !== $filter && false === strpos( basename( $file ), $filter ) ) {
		continue;
	}
	require_once $file;
}

$failures = array();
foreach ( $fixthisplz_tests as list( $name, $fn ) ) {
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
echo 'OK (' . count( $fixthisplz_tests ) . " tests)\n";
