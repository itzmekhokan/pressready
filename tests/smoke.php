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
	// Fixtures live under tests/, which the default --ignore excludes; opt out.
	$json = shell_exec( "$bin $args --no-default-ignore --format=json 2>/dev/null" );
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
exec( "$bin --php=8.4 --path=tests/fixtures/upgrade.php --fail-on=fatal --no-default-ignore --format=summary > /dev/null 2>&1", $_o1, $rc1 );
check( 1 === $rc1, '--fail-on=fatal exits 1 when a fatal is present' );

// 4. --fail-on=fatal returns zero when there is no fatal (WP-only fixture).
exec( "$bin --wp=6.9 --path=tests/fixtures/sample.php --fail-on=fatal --no-default-ignore --format=summary > /dev/null 2>&1", $_o2, $rc2 );
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

// 7. --ignore-on=deprecated drops the WP deprecations (sample.php has only those).
$t = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/sample.php --ignore-on=deprecated' );
check( 0 === ( $t['total'] ?? null ), '--ignore-on=deprecated hides WP deprecations (got ' . json_encode( $t ) . ')' );

// 8. --only=nomatch filters everything out (no components match).
$t = tally_of( $bin, '--php=8.4 --wp=6.9 --path=tests/fixtures/upgrade.php --only=zzznomatch' );
check( 0 === ( $t['total'] ?? null ), '--only with no match yields 0 findings (got ' . json_encode( $t ) . ')' );

// 9. SARIF output is valid JSON with the expected 2.1.0 shape.
$sarif = shell_exec( "$bin --php=8.4 --wp=6.9 --path=tests/fixtures/upgrade.php --no-default-ignore --format=sarif 2>/dev/null" );
$s     = json_decode( (string) $sarif, true );
check(
	is_array( $s ) && '2.1.0' === ( $s['version'] ?? null ) && ! empty( $s['runs'][0]['results'] ),
	'SARIF output is valid 2.1.0 with results'
);

// 10. The default --ignore excludes a tests/ path; --no-default-ignore restores it.
// (A fully-ignored explicit file yields no report at all, hence total defaults to 0.)
$with    = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/sample.php --no-default-ignore' );
$without = shell_exec( "$bin --wp=6.9 --path=tests/fixtures/sample.php --format=json 2>/dev/null" );
$wt      = json_decode( (string) $without, true )['tally'] ?? array();
check(
	( $with['total'] ?? 0 ) > 0 && 0 === ( $wt['total'] ?? 0 ),
	'default ignore excludes tests/ fixtures; --no-default-ignore restores them'
);

// 11. .pressready.json config supplies defaults (CLI still wins).
$cfg = sys_get_temp_dir() . '/pressready-smoke-config.json';
file_put_contents( $cfg, json_encode( array( 'wp' => '6.9', 'no-default-ignore' => true ) ) );
$t = tally_of( $bin, '--config=' . escapeshellarg( $cfg ) . ' --path=tests/fixtures/sample.php' );
@unlink( $cfg );
check( 6 === ( $t['wp'] ?? null ), 'config file supplies --wp and --no-default-ignore (got ' . json_encode( $t ) . ')' );

// 12. Scanning zero files exits 2 (a CI gate must fail loudly, not pass vacuously).
$empty = sys_get_temp_dir() . '/pressready-smoke-empty-' . getmypid();
@mkdir( $empty );
file_put_contents( $empty . '/notes.txt', "no php here\n" );
exec( "$bin --php=8.4 --path=" . escapeshellarg( $empty ) . " --format=summary > /dev/null 2>&1", $_o4, $rc4 );
check( 2 === $rc4, 'zero files scanned exits 2 (no vacuous pass)' );
@unlink( $empty . '/notes.txt' );
@rmdir( $empty );

// 13. Internal.NoCodeFound (a Blade template) is not counted as a deprecation.
// (--no-default-ignore forces the .blade.php to be scanned; the artefact is stripped.)
$t = tally_of( $bin, '--php=8.4 --wp=6.9 --path=tests/fixtures/template.blade.php' );
check( 0 === ( $t['total'] ?? null ), 'Blade NoCodeFound is stripped, not counted (got ' . json_encode( $t ) . ')' );

// 14. --help and -h print usage and exit 0.
exec( "$bin --help 2>&1", $help_out, $rc5 );
check(
	0 === $rc5 && false !== strpos( implode( "\n", $help_out ), 'Usage:' ),
	'--help prints usage and exits 0'
);
exec( "$bin -h > /dev/null 2>&1", $_o6, $rc6 );
check( 0 === $rc6, '-h exits 0' );

// 15. Repeated --path merges all targets (rather than keeping only the last).
$t = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/upgrade.php --path=tests/fixtures/sample.php' );
$single = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/sample.php' );
check(
	( $t['total'] ?? 0 ) > ( $single['total'] ?? 0 ),
	'repeated --path merges targets, not last-wins (got ' . json_encode( $t ) . ')'
);

// 16. An unknown config key warns on stderr (typo'd keys must not be silent).
$badcfg = sys_get_temp_dir() . '/pressready-smoke-badkey.json';
file_put_contents( $badcfg, json_encode( array( 'wp' => '6.9', 'exclud' => array( 'x' ) ) ) );
$warn = shell_exec( "$bin --config=" . escapeshellarg( $badcfg ) . " --path=tests/fixtures/sample.php --no-default-ignore --format=summary 2>&1 1>/dev/null" );
@unlink( $badcfg );
check( false !== strpos( (string) $warn, "unknown key 'exclud'" ), 'unknown config key warns on stderr' );

// 17. The dataset generator no longer emits an `argument` bucket (issue #1):
// `_deprecated_argument()` data was generated but never consumed by the sniff,
// so it must not appear, while a real `_deprecated_function()` still does.
$gen = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( 'bin/gen-wp-deprecations.php' );
$out = sys_get_temp_dir() . '/pressready-smoke-gen-' . getmypid() . '.json';
exec( "$gen --src=" . escapeshellarg( 'tests/fixtures/gen/src' ) . ' --out=' . escapeshellarg( $out ) . ' 2>/dev/null' );
$ds = json_decode( (string) @file_get_contents( $out ), true );
@unlink( $out );
check(
	is_array( $ds )
		&& ! isset( $ds['counts']['argument'] )
		&& ! isset( $ds['deprecations']['argument'] )
		&& isset( $ds['deprecations']['function']['pressready_fixture_old_function'] ),
	'generator emits no `argument` bucket but still records deprecated functions'
);

// 18. A component that defines its own symbol shadowing a deprecated core name
// is not falsely flagged (issue #2): shadowed.php declares its own get_settings()
// and WP_User_Search, so neither usage is a finding.
$t = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/shadowed.php' );
check( 0 === ( $t['total'] ?? null ), 'self-declared symbols are not flagged as deprecated core (got ' . json_encode( $t ) . ')' );

// 19. Control: the same usages WITHOUT a local declaration are still flagged,
// proving the shadow guard does not suppress genuine core-API usage.
$t = tally_of( $bin, '--wp=6.9 --path=tests/fixtures/unshadowed.php' );
check( 2 === ( $t['wp'] ?? null ), 'undeclared core deprecations are still flagged (got ' . json_encode( $t ) . ')' );

// --- issue #3: removed-by-target WP findings are fatal; dataset-version aware ---
// Drive a fixture dataset that marks pressready_gone_function() removed in 6.4.
$ds  = escapeshellarg( __DIR__ . '/fixtures/removed/dataset.json' );
$rm  = '--path=tests/fixtures/removed/usage.php --no-default-ignore --format=json';

// 20. With --wp at/after the removal version, the removed symbol is a FATAL while
// the still-shimmed deprecation stays a warning.
$t = json_decode( (string) shell_exec( "PRESSREADY_DATASET=$ds $bin --wp=6.9 $rm 2>/dev/null" ), true )['tally'] ?? array();
check(
	1 === ( $t['fatal'] ?? null ) && 1 === ( $t['wp'] ?? null ),
	'removed-by-target WP symbol is fatal, deprecation stays a warning (got ' . json_encode( $t ) . ')'
);

// 21. Before the removal version, the same symbol is only a deprecation (no fatal).
$t = json_decode( (string) shell_exec( "PRESSREADY_DATASET=$ds $bin --wp=6.3 $rm 2>/dev/null" ), true )['tally'] ?? array();
check(
	0 === ( $t['fatal'] ?? null ) && 2 === ( $t['wp'] ?? null ),
	'pre-removal target reports a deprecation, not a fatal (got ' . json_encode( $t ) . ')'
);

// 22. Targeting a WP newer than the dataset's source version warns on stderr
// (the dataset here is generated from 6.5, so --wp=6.9 is newer).
$err = (string) shell_exec( "PRESSREADY_DATASET=$ds $bin --wp=6.9 $rm 2>&1 1>/dev/null" );
check( false !== strpos( $err, 'newer than this dataset' ), 'stale-dataset target warns on stderr' );

// 23. Delta scan: a removal landing in the (since, target] window is fatal even
// though the symbol's *deprecation* predates the since floor — it is the removal
// that newly breaks the upgrade. (removed 6.4 ∈ (6.0, 6.9]; deprecated 5.0 < 6.0.)
$t = json_decode( (string) shell_exec( "PRESSREADY_DATASET=$ds $bin --wp=6.9 --since=6.0 $rm 2>/dev/null" ), true )['tally'] ?? array();
check(
	1 === ( $t['fatal'] ?? null ) && 0 === ( $t['wp'] ?? null ),
	'delta scan flags a removal in-window even when its deprecation predates --since (got ' . json_encode( $t ) . ')'
);

echo $failures ? "\nSMOKE FAILED ($failures)\n" : "\nALL SMOKE TESTS PASSED\n";
exit( $failures ? 1 : 0 );
