<?php
/**
 * Code Archive uninstall: refuse while plugins are archived, otherwise remove the plugin's cached data.
 *
 * Once Code Archive is deleted nothing lists the archived plugins, so deleting it
 * is refused (and its files kept) until the archive is empty. Core runs this file
 * before it removes the plugin's files, so stopping here stops the deletion.
 *
 * The archive folder is kept, as is the folder name option, so that reinstalling
 * Code Archive finds it.
 *
 * @package code-archive
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-code-archive-store.php';

$code_archive_check = ( new Code_Archive_Store() )->uninstall_check();
if ( is_wp_error( $code_archive_check ) ) {
	if ( wp_doing_ajax() ) {
		// The Plugins screen deletes through Ajax: answer as core's wp_ajax_delete_plugin() does, so the message is shown.
		// The screen inserts errorMessage as HTML, so it is escaped here (plugin names come from the zips).
		wp_send_json_error(
			array(
				'delete'       => 'plugin',
				'slug'         => dirname( WP_UNINSTALL_PLUGIN ),
				'plugin'       => WP_UNINSTALL_PLUGIN,
				'errorCode'    => $code_archive_check->get_error_code(),
				'errorMessage' => esc_html( $code_archive_check->get_error_message() ),
			)
		);
	}
	wp_die(
		esc_html( $code_archive_check->get_error_message() ),
		esc_html__( 'Code Archive not deleted', 'code-archive' ),
		array(
			'response'  => 409,
			'back_link' => true,
		)
	);
}

foreach ( array( 'code_archive_plugins', 'code_archive_updates' ) as $code_archive_option ) {
	delete_option( $code_archive_option );
	if ( is_multisite() ) {
		delete_site_option( $code_archive_option );
	}
}
