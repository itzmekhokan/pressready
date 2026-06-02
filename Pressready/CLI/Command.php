<?php
/**
 * The `wp pressready` WP-CLI command.
 *
 * A thin adapter over the same engine as the standalone `bin/pressready` CLI
 * (so Phases 2–4 stay the single source of truth): it runs the scan, then
 * presents the results the WP-CLI way (tables, --format, proper exit via
 * WP_CLI::error). Registered `before_wp_load` — readiness scanning is static,
 * so it needs no database or booted WordPress.
 *
 * @package Pressready
 */

namespace Pressready\CLI;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Scan a WordPress site's stack for what breaks on a target PHP/WP version.
 */
class Command {

	/**
	 * Scan the installed stack for upgrade-readiness issues.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Directory to scan. Default: this site's wp-content. (Note: --path is reserved by WP-CLI for the install dir, so the scan target is positional.)
	 *
	 * [--php=<version>]
	 * : Target PHP version the site will run on (e.g. 8.4). Reports removed/deprecated PHP features.
	 *
	 * [--wp=<version>]
	 * : Target WordPress version (e.g. 6.9). Reports deprecated core APIs.
	 *
	 * [--since=<version>]
	 * : With --wp, only what NEWLY deprecates upgrading from this version.
	 *
	 * [--format=<format>]
	 * : Render as. One of: table, summary, json, csv, yaml, count.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - summary
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--fail-on=<level>]
	 * : Exit non-zero when findings at/above this level exist. One of: fatal, risky, deprecated.
	 *
	 * ## EXAMPLES
	 *
	 *     # Will PHP 8.4 and WP 6.9 break this site, and where?
	 *     $ wp pressready scan --php=8.4 --wp=6.9
	 *
	 *     # CI gate: fail only on real fatals.
	 *     $ wp pressready scan --php=8.4 --fail-on=fatal
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 * @when before_wp_load
	 */
	public function scan( $args, $assoc_args ) {
		$php   = (string) ( $assoc_args['php'] ?? '' );
		$wp    = (string) ( $assoc_args['wp'] ?? '' );
		$since = (string) ( $assoc_args['since'] ?? '' );
		$path  = (string) ( $args[0] ?? $this->default_path() );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$failon = (string) ( $assoc_args['fail-on'] ?? '' );

		if ( '' === $php && '' === $wp ) {
			WP_CLI::error( 'Specify at least one target: --php=<ver> and/or --wp=<ver>.' );
		}

		$result = $this->run_engine( $php, $wp, $since, $path, $failon );
		$data   = json_decode( $result['json'], true );
		if ( ! is_array( $data ) || ! isset( $data['tally'] ) ) {
			$detail = ( '' !== ( $result['stderr'] ?? '' ) )
				? "\n" . $result['stderr']
				: ' Is the package installed (composer install)?';
			WP_CLI::error( 'Scan produced no parseable output.' . $detail );
		}

		$tally = $data['tally'];
		$rows  = $this->flatten( $data['components'] ?? array() );

		// Machine-readable formats emit ONLY data (no human summary that would
		// corrupt the output stream).
		$machine = in_array( $format, array( 'json', 'csv', 'yaml', 'count' ), true );

		if ( 'json' === $format ) {
			// Raw JSON passthrough keeps the richer {tally, components} shape.
			WP_CLI::print_value( $data, array( 'format' => 'json' ) );
		} elseif ( 'summary' !== $format && $rows ) {
			Utils\format_items( $format, $rows, array( 'severity', 'component', 'location', 'message' ) );
		}

		if ( ! $machine ) {
			$this->report_summary( $tally, $rows );
		}

		// Exit code: the engine already applied --fail-on; mirror it as the WP-CLI result.
		if ( 1 === $result['code'] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Run the shared engine (bin/pressready) and capture its JSON + exit code.
	 *
	 * @param string $php    Target PHP version.
	 * @param string $wp     Target WP version.
	 * @param string $since  Optional since version.
	 * @param string $path   Scan path.
	 * @param string $failon Optional --fail-on level.
	 * @return array{json:string,code:int}
	 */
	private function run_engine( $php, $wp, $since, $path, $failon ) {
		$bin  = dirname( __DIR__, 2 ) . '/bin/pressready';
		$args = array( $bin, '--format=json' );
		if ( '' !== $php ) {
			$args[] = '--php=' . $php;
		}
		if ( '' !== $wp ) {
			$args[] = '--wp=' . $wp;
		}
		if ( '' !== $since ) {
			$args[] = '--since=' . $since;
		}
		if ( '' !== $failon ) {
			$args[] = '--fail-on=' . $failon;
		}
		$args[] = '--path=' . $path;

		$cmd = escapeshellarg( PHP_BINARY );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}

		// Capture stderr so an engine/phpcs crash is reportable, not swallowed.
		$out     = array();
		$code    = 0;
		$errfile = tempnam( sys_get_temp_dir(), 'pressready-cli-' );
		exec( $cmd . ' 2>' . escapeshellarg( $errfile ), $out, $code );
		$stderr  = is_file( $errfile ) ? trim( (string) file_get_contents( $errfile ) ) : '';
		@unlink( $errfile );
		return array( 'json' => implode( "\n", $out ), 'code' => (int) $code, 'stderr' => $stderr );
	}

	/**
	 * Flatten the grouped {components} structure into table rows.
	 *
	 * @param array $components Grouped components.
	 * @return array<int,array<string,string|int>>
	 */
	private function flatten( array $components ) {
		$rows = array();
		foreach ( $components as $c ) {
			foreach ( $c['files'] as $rel => $findings ) {
				foreach ( $findings as $f ) {
					$rows[] = array(
						'severity'  => $f['severity'],
						'component' => $c['label'],
						'location'  => $rel . ':' . $f['line'],
						'message'   => $f['message'],
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * Print the one-line severity summary + verdict.
	 *
	 * @param array $tally Severity tally.
	 * @param array $rows  Flattened rows (for the component count).
	 * @return void
	 */
	private function report_summary( array $tally, array $rows ) {
		if ( 0 === (int) ( $tally['total'] ?? 0 ) ) {
			WP_CLI::success( 'Nothing blocks this upgrade.' );
			return;
		}

		$bits = array();
		foreach ( array( 'fatal' => 'fatal', 'risky' => 'risky' ) as $k => $label ) {
			if ( ! empty( $tally[ $k ] ) ) {
				$bits[] = $tally[ $k ] . ' ' . $label;
			}
		}
		$deprecations = (int) ( $tally['php'] ?? 0 ) + (int) ( $tally['wp'] ?? 0 );
		if ( $deprecations > 0 ) {
			$bits[] = $deprecations . ' deprecation' . ( 1 === $deprecations ? '' : 's' );
		}
		$line = implode( ', ', $bits );
		if ( ! empty( $tally['suppressed'] ) ) {
			$line .= sprintf( ' (%d suppressed by baseline)', $tally['suppressed'] );
		}
		WP_CLI::log( $line . '.' );

		if ( ! empty( $tally['fatal'] ) ) {
			WP_CLI::warning( 'Upgrade will break — fix fatals first.' );
		} elseif ( ! empty( $tally['risky'] ) ) {
			WP_CLI::log( 'No fatals, but review the risky behaviour changes before upgrading.' );
		} else {
			WP_CLI::success( 'No fatals — safe to upgrade; clear deprecations at leisure.' );
		}
	}

	/**
	 * Default scan path: the site's wp-content if discoverable, else cwd.
	 *
	 * @return string
	 */
	private function default_path() {
		if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR ) ) {
			return WP_CONTENT_DIR;
		}
		$cwd = getcwd();
		if ( $cwd && is_dir( $cwd . '/wp-content' ) ) {
			return $cwd . '/wp-content';
		}
		return (string) $cwd;
	}
}
