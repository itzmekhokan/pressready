<?php
/**
 * Plugin Name: Pressready Shadow Fixture
 *
 * Defines its OWN `get_settings()` and `WP_User_Search` — both names collide
 * with deprecated WordPress core symbols. Because this component declares them
 * itself, its usage resolves to its own definitions and must NOT be flagged as
 * deprecated-core-API usage (issue #2).
 *
 * @package Pressready
 */

if ( ! function_exists( 'get_settings' ) ) {
	/**
	 * The component's own helper, shadowing the deprecated core get_settings().
	 */
	function get_settings( $key ) {
		return $key;
	}
}

/**
 * The component's own class, shadowing the deprecated core WP_User_Search.
 */
class WP_User_Search {
	public function query() {
		return array();
	}
}

get_settings( 'foo' );

$search = new WP_User_Search();
$search->query();

WP_User_Search::query();
