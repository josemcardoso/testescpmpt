<?php
require __DIR__ . '/bootstrap.php';

$GLOBALS['wpm_tests'] = array();
function test( string $name, callable $fn ): void {
	$GLOBALS['wpm_tests'][ $name ] = $fn;
}
function ok( $condition, string $message = '' ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Assertion failed' . ( $message ? ": $message" : '' ) );
	}
}
function eq( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? "$message\n" : '' ) . 'Expected: ' . var_export( $expected, true ) . "\nActual:   " . var_export( $actual, true ) );
	}
}
function has( string $needle, string $haystack, string $message = '' ): void {
	ok( str_contains( $haystack, $needle ), ( $message ?: 'Missing substring' ) . ": $needle\nIn: " . substr( $haystack, 0, 2000 ) );
}
function lacks( string $needle, string $haystack, string $message = '' ): void {
	ok( ! str_contains( $haystack, $needle ), ( $message ?: 'Unexpected substring' ) . ": $needle" );
}

foreach ( glob( __DIR__ . '/unit/*.php' ) as $file ) {
	require $file;
}

$filter = $argv[1] ?? '';
$failed = 0;
$passed = 0;
foreach ( $GLOBALS['wpm_tests'] as $name => $fn ) {
	if ( $filter && ! str_contains( $name, $filter ) ) {
		continue;
	}
	try {
		$fn();
		++$passed;
		echo "  ✓ $name\n";
	} catch ( Throwable $e ) {
		++$failed;
		echo "  ✗ $name\n      " . str_replace( "\n", "\n      ", $e->getMessage() ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
