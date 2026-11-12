# Using Plugin Archive

## What it does

Plugin Archive moves inactive plugins out of `wp-content/plugins` into an archive folder, `wp-content/plugins-archive`, as one zip file per plugin. It does just two things:

- **Archive** = compress and move. The plugin's folder (or single file) is compressed into a zip in the archive folder, the zip is checked, and only then is the plugin removed from `wp-content/plugins`.
- **Unarchive** = uncompress and move. The zip is uncompressed by WordPress's own plugin installer, the plugin moved back into `wp-content/plugins` (inactive), and only then is the zip removed.

**Everything happens on the server.** Both operations work on the web server's own files, inside `wp-content`. Nothing is downloaded to your browser or uploaded from it, archived zips never leave the server, and archiving and unarchiving make no network connections. The only network use is for updates (see [Updates](#updates)).

While archived, a plugin:

- is not loaded, scanned or listed by WordPress as installed, and has no PHP files on disk that a web request could run;
- is still shown on the Plugins screen, marked **Archived**;
- still gets updates.

An archived plugin is treated as installed and inactive; only where (and how) it is stored differs.

## The Plugins screen

- **Archived** view: just the archived plugins.
- **All** (and search results): installed and archived plugins together. Archived rows are marked **Archived** and have no auto-update toggle.
- Inactive plugins have an **Archive** action. Active plugins, Must-Use plugins, drop-ins, Plugin Archive itself and plugins that are symbolic links are not offered it.
- An archived plugin's only action is **Unarchive**: it is put back in the plugins folder, inactive, by WordPress's own plugin installer (as if its zip had been uploaded on **Plugins → Add New**). Activate or delete it from there as usual. A single-file plugin (such as `hello.php`) comes back in a folder of its own (`hello/hello.php`), as WordPress's installer always does with a single file; it works the same, but settings WordPress keeps by the plugin's file name, such as whether it auto-updates, don't carry over.
- Bulk actions **Archive** and **Unarchive**. In the Archived view, Unarchive is the only bulk action. WordPress's own bulk actions (Activate, Delete, ...) skip archived plugins.

On multisite all of this is in **Network Admin → Plugins**; sub-site Plugins screens don't show archived plugins.

## Safety

- A plugin is zipped to a temporary file, the zip is checked (it opens, holds every file, and its main file is the plugin), and only then is the plugin removed from the plugins folder.
- Unarchive never overwrites: if the plugins folder already holds a folder (or file) of the same name, it stops and the zip is kept.
- Zips copied into, or removed from, the archive folder by hand are picked up. A zip holding files outside its plugin folder, or no plugin, is ignored.
- Plugin Archive never deletes a plugin.

## Updates

Plugin Archive checks the archived plugins at the same times WordPress checks installed ones: twice a day (WordPress's scheduled check), when the Plugins screen is opened and the last check is over 12 hours old, and when **Dashboard → Updates** is opened and the last check is over a minute old. Archiving a plugin, or a version change in the archive, makes the next of these check at once. WordPress's own update data is never changed.

- plugins from WordPress.org are checked with WordPress.org's update service (`api.wordpress.org/plugins/update-check/1.1/`), sending the archived plugins' header details, the language of the admin screen and a user agent with the WordPress version and site address, as WordPress's own check does;
- a plugin whose `Update URI` header names another host is passed to the same `update_plugins_{hostname}` filter WordPress uses, so an active updater plugin can answer for it;
- a plugin that updates itself (its updater is in its own code) can't report updates while archived.

Updates found are counted in the admin menu's Updates totals, and shown under the archived plugin's row. **Update now** is unarchive, update, archive in one step:

1. the new version's zip is downloaded from WordPress.org and installed into the plugins folder with WordPress's plugin installer (it is never activated; the old version isn't unzipped first, as the update would replace it anyway),
2. it is archived straight away, replacing the old zip.

If the update fails, the old zip is kept and nothing is left in the plugins folder. If the plugins folder already holds a folder of the same name, nothing is installed. If archiving the new version fails, it is left, inactive, in the plugins folder and the message says so.

Archiving or unarchiving a plugin clears WordPress's own plugin update data (as installing a plugin does), so WordPress checks again: an archived plugin drops out of its updates, and an unarchived one is checked as usual.

## Requirements

- PHP zip extension.
- Direct write access to `wp-content` (WordPress doesn't ask for FTP credentials). Otherwise the Plugins screen shows why archiving is unavailable.

## The archive folder

The folder is created on first use, with an empty `index.php` and an `.htaccess` that refuses all requests (Apache and LiteSpeed).

### nginx

nginx ignores `.htaccess`, so the folder must be denied in the server configuration, or its zips (with the plugins' PHP source) can be downloaded by anyone who guesses a plugin's folder name:

```nginx
location ^~ /wp-content/plugins-archive/ {
    deny all;
}
```

Change the path if the folder name is changed.

### Changing the folder name

Set the network option (site option on a single site) `plugin_archive_folder`, or use the `plugin_archive_folder` filter. The name is a single folder inside `wp-content`; anything else falls back to `plugins-archive`. Move existing zips to the new folder yourself.

## Uninstalling

Uninstalling Plugin Archive removes its cached data (`plugin_archive_plugins`, `plugin_archive_updates`). The archive folder, its zips and the `plugin_archive_folder` option are kept.
