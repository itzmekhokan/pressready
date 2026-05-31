<?php
/**
 * WP-CLI bootstrap — registers the `wp pressready` command when running under
 * WP-CLI. Safe to autoload anywhere: it no-ops outside WP-CLI.
 *
 * Usage on a site that has this package installed:
 *   wp --require=vendor/itzmekhokan/pressready/wp-cli.php pressready scan --php=8.4 --wp=6.9
 * or add to the project's wp-cli.yml:
 *   require:
 *     - vendor/itzmekhokan/pressready/wp-cli.php
 *
 * @package Pressready
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'Pressready\\CLI\\Command' ) ) {
	require_once __DIR__ . '/Pressready/CLI/Command.php';
}

WP_CLI::add_command( 'pressready', 'Pressready\\CLI\\Command' );
