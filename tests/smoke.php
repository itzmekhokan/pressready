<?php
/**
 * Smoke tests — run the `pressready` CLI against the committed fixtures and
 * assert the severity tally and CI exit codes. No test framework needed; this
 * is what CI runs to prove the scanner still behaves.
 *
 * Usage: php tests/smoke.php   (exit 0 = all passed, 1 = a failure)
 *
 * @package Pressready
 */

chdir( __DIR__ . '/..' );

$bin      = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( 'bin/pressready' );
$failures = 0;

/**
 * Assert a condition and print a line.
 */
function check( bool $cond, string $msg ): void {
	global $failures;
	echo ( $cond ? "  ✓ " : "  ✗ " ) . $msg . "\n";
	if ( ! $cond ) {
		++$failures;
	}
}

/**
 * Run the CLI and return the decoded tally (json format).
 */
function tally_of( string $bin, string $args ): array {
	$json = shell_exec( "$bin $args --format=json 2>/dev/null" );
	return json_decode( (string) $json, true )['tally'] ?? array();
}

// 1. Combined PHP + WP fixture: 1 fatal, 1 risky, 1 php, 2 wp.
$t = tally_of( $bin, '--php=8.4 --wp=6.9 --path=tests/fixtures/upgrade.php' );
check(
	1 === ( $t['fatal'] ?? null ) && 1 === ( $t['risky'] ?? null )
		&& 1 === ( $t['php'] ?? null ) && 2 === ( $t['wp'] ?? null ),
	'upgrade.php → 1 fatal, 1 risky, 1 php, 2 wp (got ' . json_encode( $t ) . ')'
);

// 2. WP-only fixture: 6 wp deprecations, no fatals.
$t = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/sample.php' );
check(
	6 === ( $t['wp'] ?? null ) && 0 === ( $t['fatal'] ?? null ),
	'sample.php → 6 wp, 0 fatal (got ' . json_encode( $t ) . ')'
);

// 3. --fail-on=fatal returns non-zero when a fatal is present.
exec( "$bin --php=8.4 --path=tests/fixtures/upgrade.php --fail-on=fatal --format=summary > /dev/null 2>&1", $_o1, $rc1 );
check( 1 === $rc1, '--fail-on=fatal exits 1 when a fatal is present' );

// 4. --fail-on=fatal returns zero when there is no fatal (WP-only fixture).
exec( "$bin --wp=6.9 --path=tests/fixtures/sample.php --fail-on=fatal --format=summary > /dev/null 2>&1", $_o2, $rc2 );
check( 0 === $rc2, '--fail-on=fatal exits 0 when no fatal' );

// 5. No target given is an error (exit 2).
exec( "$bin --path=tests/fixtures/sample.php > /dev/null 2>&1", $_o3, $rc3 );
check( 2 === $rc3, 'missing --php/--wp exits 2' );

// 6. The WP-CLI command class loads and exposes scan() (registerable by wp-cli.php).
require_once __DIR__ . '/../Pressready/CLI/Command.php';
check(
	class_exists( 'Pressready\\CLI\\Command' ) && method_exists( 'Pressready\\CLI\\Command', 'scan' ),
	'WP-CLI command Pressready\\CLI\\Command::scan() is defined'
);

echo $failures ? "\nSMOKE FAILED ($failures)\n" : "\nALL SMOKE TESTS PASSED\n";
exit( $failures ? 1 : 0 );
