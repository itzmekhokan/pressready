<?php
/**
 * Plugin Name: Pressready Fixture
 * Version: 0.0.0
 *
 * Combined PHP-side + WP-side fixture for `bin/pressready --php= --wp=`.
 * Expected at --php=8.4 --wp=6.9: 1 fatal, 1 risky, 3 deprecations (2 wp, 1 php).
 */

// --- PHP side (PHPCompatibility) ---
$f = create_function( '$a', 'return $a;' ); // fatal: removed in PHP 8.0.
utf8_encode( $s );                          // php:   deprecated in PHP 8.2.
function legacy( $user_id ) {               // risky: func_get_args() semantics since 7.0.
	$user_id = 0;
	return func_get_args();
}

// --- WP side (Pressready) ---
$d = get_postdata( 42 );            // wp: deprecated WP 1.5.1.
add_filter( 'query_string', 'cb' ); // wp: deprecated filter hook WP 2.1.0.

// --- Clean (must NOT flag) ---
$ok   = get_post( 42 );
$anon = static fn( $a ) => $a;
add_filter( 'the_content', 'cb' );
