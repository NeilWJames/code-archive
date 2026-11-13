<?php
/**
 * Bootstrap the PHPUnit test environment.
 *
 * @package code-archive
 */

$code_archive_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $code_archive_tests_dir ) {
	$code_archive_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $code_archive_tests_dir . '/includes/functions.php';

/**
 * Load Code Archive. Its classes normally load only in the admin, so load them here as well.
 *
 * @return void
 */
function code_archive_tests_load_plugin() {
	require dirname( __DIR__ ) . '/code-archive.php';
	require_once dirname( __DIR__ ) . '/includes/class-code-archive-store.php';
	require_once dirname( __DIR__ ) . '/includes/class-code-archive-updates.php';
	require_once dirname( __DIR__ ) . '/includes/class-code-archive.php';
}
tests_add_filter( 'muplugins_loaded', 'code_archive_tests_load_plugin' );

require $code_archive_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

// Shared fixtures and base class (need the WP test classes loaded above).
require_once __DIR__ . '/class-code-archive-redirect-exception.php';
require_once __DIR__ . '/trait-code-archive-fixtures.php';
require_once __DIR__ . '/class-code-archive-testcase.php';
