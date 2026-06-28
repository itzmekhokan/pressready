<?php
/**
 * Pressready — WordPress upgrade-readiness scanner.
 *
 * @copyright 2026 Khokan Sardar
 * @license   GPL-2.0-or-later
 * @link      https://github.com/itzmekhokan/pressready
 *
 * Build pressready.phar — the single-file, dependency-free distributable.
 *
 * Bundles the driver, the Pressready standard, the dataset, and the full PHPCS
 * toolchain (PHPCompatibilityWP) so the phar runs anywhere with only PHP — no
 * `composer install`, and no chance of clashing with a consumer's pinned phpcs.
 * At runtime the driver extracts the toolchain to a cache dir and runs phpcs as
 * a subprocess over those real files (see phar_runtime_dir() in bin/pressready).
 *
 * Usage:
 *   php -d phar.readonly=0 bin/build-phar.php [output.phar]
 *
 * @package Pressready
 */

if ( ini_get( 'phar.readonly' ) ) {
	fwrite( STDERR, "error: phar.readonly is On — run with: php -d phar.readonly=0 bin/build-phar.php\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$out  = $argv[1] ?? ( $root . '/build/pressready.phar' );
@mkdir( dirname( $out ), 0777, true );
@unlink( $out );

$phar = new Phar( $out );
$phar->startBuffering();

// Directories whose real files the extracted runtime needs, plus the root files
// pulled in by Composer's autoloader (the `files` autoload references wp-cli.php).
$dirs  = array( 'bin', 'Pressready', 'data', 'vendor' );
$files = array( 'wp-cli.php', 'composer.json' );

$added = 0;
foreach ( $dirs as $dir ) {
	$base = $root . '/' . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			static function ( $cur ): bool {
				if ( $cur->isDir() ) {
					// VCS metadata and JS deps are never needed at runtime.
					return ! in_array( $cur->getFilename(), array( '.git', '.github', 'node_modules' ), true );
				}
				return true;
			}
		)
	);
	foreach ( $it as $file ) {
		$local = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( 'bin/pressready' === $local ) {
			// The driver is the phar entry point; strip its shebang so the stub's
			// include doesn't echo it onto stdout and corrupt the report.
			$phar->addFromString( $local, preg_replace( '/^#![^\n]*\n/', '', (string) file_get_contents( $file->getPathname() ) ) );
		} else {
			$phar->addFile( $file->getPathname(), $local );
		}
		++$added;
	}
}
foreach ( $files as $f ) {
	if ( is_file( $root . '/' . $f ) ) {
		$phar->addFile( $root . '/' . $f, $f );
		++$added;
	}
}

// CLI stub: alias the running phar and run the (shebang-stripped) driver.
$phar->setStub(
	"#!/usr/bin/env php\n"
	. "<?php Phar::mapPhar('pressready.phar'); require 'phar://pressready.phar/bin/pressready'; __HALT_COMPILER();\n"
);

$phar->stopBuffering();
@chmod( $out, 0755 );

/**
 * Format a byte count for the build summary.
 *
 * @param int $bytes Size in bytes.
 * @return string
 */
function human_size( int $bytes ): string {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i     = 0;
	$n     = (float) $bytes;
	while ( $n >= 1024 && $i < count( $units ) - 1 ) {
		$n /= 1024;
		++$i;
	}
	return sprintf( '%.1f%s', $n, $units[ $i ] );
}

printf( "Built %s (%d files, %s)\n", $out, $added, human_size( (int) filesize( $out ) ) );
