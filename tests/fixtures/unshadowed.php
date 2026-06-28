<?php
/**
 * Plugin Name: Pressready No-Shadow Fixture
 *
 * Uses the same deprecated core symbols as shadowed.php but does NOT define its
 * own versions, so both usages must still be flagged. This is the control that
 * proves the shadow guard doesn't suppress genuine core-API usage.
 *
 * @package Pressready
 */

get_settings( 'foo' );

$search = new WP_User_Search();
