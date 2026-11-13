# Changelog

## Version 0.1.0  (in development)

### Changes

* NEW: Archive inactive plugins (compress into a zip and move to `wp-content/plugins-archive`, one zip per plugin), and unarchive them (uncompress and move back into the plugins folder, inactive). Everything happens on the server; nothing is downloaded or uploaded. Unarchive uses WordPress's own plugin installer (a single-file plugin comes back in a folder of its own). A plugin is only removed once its zip is complete and checked; unarchive never overwrites.
* NEW: Plugins screen: archived plugins in their own Archived view and in All (and search), marked Archived, with Unarchive as their only action and no auto-update toggle. Archive action for inactive plugins.
* NEW: Bulk Archive and Unarchive. WordPress's own bulk actions skip archived plugins.
* NEW: Updates for archived plugins: checked at the same times as WordPress's own check (WordPress.org, and `update_plugins_{$hostname}` for other Update URI hosts), counted in the Updates totals, shown under the archived row, and applied by installing the new version and archiving it in place of the old zip. WordPress's own update data is not changed; archiving and unarchiving clear it so WordPress checks again.
* NEW: Archive folder protected by `index.php` and `.htaccess`; nginx rule documented.
* NEW: Multisite: managed in Network Admin.
* NEW: Code Archive cannot be deleted while plugins are archived (`uninstall.php` refuses, so core keeps its files); the Plugins screen shows why. Unarchive them first.
* DEV: Filters `code_archive_folder`, `code_archive_updates`; actions `code_archive_archived`, `code_archive_unarchived`.
* DEV: PHPUnit tests (single site and multisite), phpcs, GitHub workflows (CI, Generate POT file).
