<?php
/**
 * Plugin Name: Pressready Removed Fixture
 *
 * Exercises the removed-vs-deprecated severity split (issue #3) against the
 * sibling dataset.json (pointed at via PRESSREADY_DATASET in the smoke test):
 *
 *   pressready_gone_function()    deprecated 5.0, removed 6.4 → fatal once --wp >= 6.4
 *   pressready_soft_deprecated()  deprecated 5.0, never removed → always a warning
 *
 * @package Pressready
 */

pressready_gone_function();

pressready_soft_deprecated();
