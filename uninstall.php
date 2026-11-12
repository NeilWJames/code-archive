<?php
/**
 * Plugin Archive uninstall: remove the plugin's cached data.
 *
 * The archive folder and its zips are kept: they are plugins the site still owns.
 * The folder name option is kept too, so that reinstalling Plugin Archive finds them.
 *
 * @package plugin-archive
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'plugin_archive_plugins', 'plugin_archive_updates' ) as $plugin_archive_option ) {
	delete_option( $plugin_archive_option );
	if ( is_multisite() ) {
		delete_site_option( $plugin_archive_option );
	}
}
