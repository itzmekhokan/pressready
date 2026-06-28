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

// --- issue #4: compatibility matrix (highest safe version / first break) ---
// upgrade.php uses create_function() — removed in PHP 8.0.

// 24. A span straddling the removal grades each version and names the ceiling +
// first break: safe through 7.4, first break 8.0.
$m = json_decode( (string) shell_exec( "$bin --php=7.4-8.4 --matrix --path=tests/fixtures/upgrade.php --no-default-ignore --format=json 2>/dev/null" ), true )['matrix']['php'] ?? array();
check(
	'7.4' === ( $m['safe_through'] ?? null ) && '8.0' === ( $m['first_break'] ?? null ),
	'matrix reports safe-through 7.4 and first-break 8.0 (got ' . json_encode( array( $m['safe_through'] ?? null, $m['first_break'] ?? null ) ) . ')'
);

// 25. A span entirely below the removal has no break (first_break null, top is safe).
$m = json_decode( (string) shell_exec( "$bin --php=7.0-7.4 --matrix --path=tests/fixtures/upgrade.php --no-default-ignore --format=json 2>/dev/null" ), true )['matrix']['php'] ?? array();
check(
	'7.4' === ( $m['safe_through'] ?? null ) && array_key_exists( 'first_break', $m ) && null === $m['first_break'],
	'matrix over an all-clear span has no first break (got ' . json_encode( $m['first_break'] ?? 'absent' ) . ')'
);

// 26. --matrix honours --fail-on for CI: a span containing a fatal exits non-zero.
exec( "$bin --php=7.4-8.4 --matrix --path=tests/fixtures/upgrade.php --no-default-ignore --fail-on=fatal > /dev/null 2>&1", $_m1, $mrc );
check( 1 === $mrc, '--matrix --fail-on=fatal exits 1 when the span contains a fatal' );

// 27. The distributable .phar builds and runs self-contained: it extracts its
// bundled toolchain and produces the same findings as the source CLI. Skipped
// only where Phar building is impossible (no ext-phar / locked-down env).
if ( extension_loaded( 'Phar' ) ) {
	$phar_php = escapeshellarg( PHP_BINARY );
	$phar_out = sys_get_temp_dir() . '/pressready-smoke-' . getmypid() . '.phar';
	exec( "$phar_php -d phar.readonly=0 " . escapeshellarg( 'bin/build-phar.php' ) . ' ' . escapeshellarg( $phar_out ) . ' > /dev/null 2>&1', $_b, $brc );
	if ( 0 === $brc && is_file( $phar_out ) ) {
		// Run from a temp cwd to prove the phar has no dependence on the repo.
		$abs  = getcwd() . '/tests/fixtures/upgrade.php';
		$json = shell_exec( $phar_php . ' ' . escapeshellarg( $phar_out ) . ' --php=8.4 --wp=6.9 --path=' . escapeshellarg( $abs ) . ' --no-default-ignore --format=json 2>/dev/null' );
		$t    = json_decode( (string) $json, true )['tally'] ?? array();
		check(
			1 === ( $t['fatal'] ?? null ) && 2 === ( $t['wp'] ?? null ),
			'pressready.phar builds and scans self-contained (got ' . json_encode( $t ) . ')'
		);
		@unlink( $phar_out );
	} else {
		check( false, 'pressready.phar build failed (exit ' . $brc . ')' );
	}
} else {
	echo "  – phar build test skipped (ext-phar not loaded)\n";
}

// 28. The dual-mode Docker entrypoint: under GitHub Actions the 7 positional
// inputs build the github-mode flags; everywhere else (pre-commit / docker run)
// args pass straight through to the CLI. A stub `pressready` on PATH captures
// the resolved invocation so a future edit can't silently break action mode.
$stub = sys_get_temp_dir() . '/pressready-entry-' . getmypid();
@mkdir( $stub );
file_put_contents( $stub . '/pressready', "#!/bin/sh\necho \"called: \$*\"\n" );
chmod( $stub . '/pressready', 0755 );
$entry = escapeshellarg( getcwd() . '/entrypoint.sh' );
// Force CLI mode by clearing GITHUB_ACTIONS — CI itself sets it to "true" for
// every step, which would otherwise push this invocation into action mode.
$cli = (string) shell_exec( 'GITHUB_ACTIONS= PATH=' . escapeshellarg( $stub ) . ':$PATH sh ' . $entry . ' --php=8.4 --wp=6.9 --path=wp-content 2>&1' );
check(
	false !== strpos( $cli, 'called: --php=8.4 --wp=6.9 --path=wp-content' ),
	'entrypoint CLI mode passes args straight through (got ' . trim( $cli ) . ')'
);
$act = (string) shell_exec( 'PATH=' . escapeshellarg( $stub ) . ':$PATH GITHUB_ACTIONS=true GITHUB_WORKSPACE=' . escapeshellarg( $stub ) . ' sh ' . $entry . " '' '' fatal github 8.4 6.9 . 2>&1" );
check(
	false !== strpos( $act, 'called: --format=github --fail-on=fatal --php=8.4 --wp=6.9' ),
	'entrypoint action mode still builds the github-format flags (got ' . trim( $act ) . ')'
);
@unlink( $stub . '/pressready' );
@rmdir( $stub );

// 29. --format=gitlab emits a valid GitLab Code Quality report: an array of
// issues each carrying the required description / fingerprint / severity /
// location fields, with fatals mapped to GitLab's "blocker" severity.
$gl = json_decode(
	(string) shell_exec( "$bin --php=8.4 --wp=6.9 --path=tests/fixtures/upgrade.php --no-default-ignore --format=gitlab 2>/dev/null" ),
	true
);
$gl_ok = is_array( $gl ) && ! empty( $gl );
$fps   = array();
foreach ( (array) $gl as $iss ) {
	$gl_ok = $gl_ok
		&& isset( $iss['description'], $iss['fingerprint'], $iss['severity'], $iss['location']['path'], $iss['location']['lines']['begin'] );
	$fps[] = $iss['fingerprint'] ?? '';
}
$has_blocker = ! empty( $gl ) && in_array( 'blocker', array_column( (array) $gl, 'severity' ), true );
check(
	$gl_ok && count( $fps ) === count( array_unique( $fps ) ) && $has_blocker,
	'--format=gitlab emits valid Code Quality issues (fatal → blocker, unique fingerprints)'
);

// 30. --version / -V print a non-empty version and exit 0, handled early like
// --help (before any target validation). In a git checkout this resolves via
// `git describe`; the SARIF driver carries the same string.
exec( "$bin --version 2>/dev/null", $_v, $vrc );
$ver = trim( implode( "\n", $_v ) );
check(
	0 === $vrc && 1 === preg_match( '/^pressready \S+/', $ver ) && false === strpos( $ver, 'unknown' ),
	'--version prints a resolved version and exits 0 (got ' . $ver . ')'
);
$short = trim( (string) shell_exec( "$bin -V 2>/dev/null" ) );
check( $short === $ver, '-V matches --version (got ' . $short . ')' );
$sarif_ver = json_decode(
	(string) shell_exec( "$bin --php=8.4 --wp=6.9 --path=tests/fixtures/upgrade.php --no-default-ignore --format=sarif 2>/dev/null" ),
	true
)['runs'][0]['tool']['driver']['version'] ?? '';
check(
	'' !== $sarif_ver && ( 'pressready ' . $sarif_ver ) === $ver,
	'SARIF tool.driver.version matches --version (got ' . $sarif_ver . ')'
);

// 31. The WP-CLI command forwards --matrix to the engine and renders the graded
// matrix (instead of silently ignoring it), honouring --fail-on. Skipped when
// no `wp` binary is on PATH (the engine path is already covered by 24–26).
$wp_bin = trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) );
if ( '' !== $wp_bin ) {
	// Copy the fixture outside tests/ so the default ignore doesn't drop it
	// (WP-CLI intercepts --no-* flags, so --no-default-ignore isn't usable here).
	$wpdir = sys_get_temp_dir() . '/pressready-wpcli-' . getmypid();
	@mkdir( $wpdir );
	copy( getcwd() . '/tests/fixtures/upgrade.php', $wpdir . '/u.php' );
	$wpcmd = escapeshellarg( $wp_bin ) . ' --require=' . escapeshellarg( getcwd() . '/wp-cli.php' )
		. ' pressready scan --php=7.4-8.4 --matrix ' . escapeshellarg( $wpdir ) . ' --allow-root';

	$mjson = json_decode( (string) shell_exec( $wpcmd . ' --format=json 2>/dev/null' ), true )['matrix']['php'] ?? array();
	check(
		'7.4' === ( $mjson['safe_through'] ?? null ) && '8.0' === ( $mjson['first_break'] ?? null ),
		'wp pressready scan --matrix --format=json passes the matrix through (got ' . json_encode( array( $mjson['safe_through'] ?? null, $mjson['first_break'] ?? null ) ) . ')'
	);

	$mtable = (string) shell_exec( $wpcmd . ' 2>&1' );
	check(
		false !== strpos( $mtable, 'Safe through PHP 7.4' ) && false !== strpos( $mtable, 'First break: PHP 8.0' ),
		'wp pressready scan --matrix renders the graded verdict'
	);

	exec( $wpcmd . ' --fail-on=fatal > /dev/null 2>&1', $_wm, $wmrc );
	check( 1 === $wmrc, 'wp pressready scan --matrix --fail-on=fatal exits 1 on a fatal span' );

	@unlink( $wpdir . '/u.php' );
	@rmdir( $wpdir );
} else {
	echo "  – wp pressready --matrix test skipped (no wp binary)\n";
}

echo $failures ? "\nSMOKE FAILED ($failures)\n" : "\nALL SMOKE TESTS PASSED\n";
exit( $failures ? 1 : 0 );
