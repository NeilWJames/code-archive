<?php
/**
 * Bootstrap the PHPUnit test environment.
 *
 * @package plugin-archive
 */

$plugin_archive_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $plugin_archive_tests_dir ) {
	$plugin_archive_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $plugin_archive_tests_dir . '/includes/functions.php';

/**
 * Load Plugin Archive. Its classes normally load only in the admin, so load them here as well.
 *
 * @return void
 */
function plugin_archive_tests_load_plugin() {
	require dirname( __DIR__ ) . '/plugin-archive.php';
	require_once dirname( __DIR__ ) . '/includes/class-plugin-archive-store.php';
	require_once dirname( __DIR__ ) . '/includes/class-plugin-archive-updates.php';
	require_once dirname( __DIR__ ) . '/includes/class-plugin-archive.php';
}
tests_add_filter( 'muplugins_loaded', 'plugin_archive_tests_load_plugin' );

require $plugin_archive_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

// Shared fixtures and base class (need the WP test classes loaded above).
require_once __DIR__ . '/class-plugin-archive-redirect-exception.php';
require_once __DIR__ . '/trait-plugin-archive-fixtures.php';
require_once __DIR__ . '/class-plugin-archive-testcase.php';
