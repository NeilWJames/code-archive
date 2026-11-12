<?php
/**
 * Plugin Name:       Plugin Archive
 * Plugin URI:        https://github.com/NeilWJames/plugin-archive
 * Description:       Keeps inactive plugins out of the plugins folder by archiving them as zip files, and puts them back when they are needed. Archived plugins stay listed and can still be updated.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Neil James
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       plugin-archive
 *
 * @package           plugin-archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Silence is golden.' );
}

/*
Copyright 2026 Neil James (neil@familyjames.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, see <https://www.gnu.org/licenses/>.
*/

define( 'PLUGIN_ARCHIVE_VERSION', '0.1.0' );
define( 'PLUGIN_ARCHIVE_FILE', __FILE__ );

// Admin screens, admin-post actions and the WP-Cron update check only. Nothing runs on the front end.
if ( is_admin() || wp_doing_cron() ) {
	require_once __DIR__ . '/includes/class-plugin-archive-store.php';
	require_once __DIR__ . '/includes/class-plugin-archive-updates.php';
	require_once __DIR__ . '/includes/class-plugin-archive.php';

	global $plugin_archive;
	$plugin_archive = new Plugin_Archive();
}
