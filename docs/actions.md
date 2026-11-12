# Actions

The following actions are available for this plugin:

* `plugin_archive_archived`

    Fires after a plugin has been archived. Receives the plugin basename.

* `plugin_archive_unarchived`

    Fires after a plugin has been unarchived (put back in the plugins folder, inactive). Receives the plugin basename it had while archived, and its basename now (they differ for a single-file plugin, which comes back in a folder of its own).
