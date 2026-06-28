<?php
/**
 * Fixture for the dataset-generator regression test (see tests/smoke.php).
 *
 * Stands in for a slice of WordPress core `src`: it declares a genuinely
 * deprecated function (which MUST land in the dataset) alongside a
 * `_deprecated_argument()` call (which MUST NOT produce an `argument` bucket —
 * see issue #1: that data was generated but never consumed by the sniff).
 *
 * @package Pressready
 */

/**
 * A core function deprecated outright — expected in the dataset `function` bucket.
 */
function pressready_fixture_old_function() {
	_deprecated_function( __FUNCTION__, '6.0.0', 'pressready_fixture_new_function' );
}

/**
 * A still-supported function whose legacy parameter is deprecated. The function
 * itself is NOT deprecated, so it must not be flagged — and no `argument`
 * bucket should be emitted for it.
 */
function pressready_fixture_takes_legacy_arg( $value, $deprecated = '' ) {
	if ( '' !== $deprecated ) {
		_deprecated_argument( __FUNCTION__, '6.0.0', 'The $deprecated parameter is no longer used.' );
	}
	return $value;
}
