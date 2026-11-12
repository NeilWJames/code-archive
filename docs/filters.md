# Filters

The following filters are available for this plugin:

* `plugin_archive_folder`

    Changes the archive folder name (relative to `wp-content`). Default: the `plugin_archive_folder` option, or `plugins-archive`. Must be a single folder level; anything else falls back to the default.

* `plugin_archive_updates`

    Changes the updates found for archived plugins after each check. Receives the updates (objects as in WordPress's `update_plugins` transient, keyed by plugin basename) and the archived plugins.

Plugin Archive also applies WordPress's own `update_plugins_{$hostname}` filter to archived plugins whose `Update URI` names a host other than WordPress.org, exactly as WordPress does for installed plugins.
