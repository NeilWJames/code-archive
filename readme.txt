=== Plugin Archive ===

Contributors: nwjames
Tags: plugins, archive, inactive plugins, security, site health
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Keep inactive plugins out of the plugins folder: archive them as zip files, put them back when they are needed, and keep them updated meanwhile.

== Description ==

Every plugin in the plugins folder is code a web request can reach, active or not, and Site Health recommends removing inactive plugins for that reason. But some inactive plugins are worth keeping: one used a few times a year, a tool kept for emergencies, or one waiting to be tried.

Plugin Archive moves those plugins out of the plugins folder into an archive folder (`wp-content/plugins-archive`), each stored as a zip file. There is no PHP from them on disk to run, but they are still listed and still updated.

It does just two things, both on your own server:

* **Archive** compresses the plugin's folder into a zip and moves it into the archive folder; the plugin is then removed from the plugins folder.
* **Unarchive** uncompresses the zip and moves the plugin back into the plugins folder; the zip is then removed.

Everything happens on the web server, inside `wp-content`. Nothing is downloaded to your browser or uploaded from it, archived zips never leave the server, and archiving and unarchiving send nothing anywhere. The only connections Plugin Archive makes are for updates to archived plugins, described under External services.

* The Plugins screen lists archived plugins alongside installed ones, marked **Archived**, and in their own **Archived** view.
* An inactive plugin gets an **Archive** action. Active plugins must be deactivated first.
* An archived plugin's only action is **Unarchive**, which puts it back in the plugins folder, inactive. From there it can be activated, or deleted, as usual.
* Archive and Unarchive are also bulk actions.
* Updates for archived plugins are checked at the same times WordPress checks its own, and are counted with them. **Update now** installs the new version and archives it again in place of the old one; it is never activated on the way.

Plugin Archive never deletes a plugin, and never overwrites one: a plugin is only removed from the plugins folder once its zip is complete and checked, and Unarchive refuses if a plugin of the same name is already there.

On multisite, plugins belong to the network, so archived plugins are managed in Network Admin.

= External services =

To find updates for archived plugins, Plugin Archive uses the WordPress.org plugin update service, exactly as WordPress itself does for installed plugins:

* **When:** at the same times WordPress checks for plugin updates (twice a day, and when the Plugins or Updates screen is opened); a change in the archived plugins makes the next of these checks run straight away.
* **What is sent:** to `https://api.wordpress.org/plugins/update-check/1.1/`, the archived plugins' header details (name, version, folder and file name, and the other plugin header fields), the language of the admin screen, and a user agent with the WordPress version and site address, as in WordPress's own check.
* **Update now** downloads the new version's zip with WordPress's own installer: from WordPress.org, or, for a plugin updated through another host (below), from the address that host's updater gave.

WordPress.org's [privacy policy](https://wordpress.org/about/privacy/) applies to these requests. Plugins whose `Update URI` header names another host are not sent to WordPress.org; they are passed to the same filter WordPress uses, so only an updater plugin you have installed and activated for that host is asked.

For more information see the [Plugin Archive documentation](https://github.com/NeilWJames/plugin-archive/blob/main/docs/usage.md).

== Installation ==

1. Install and activate Plugin Archive as usual.
2. Go to **Plugins**. Inactive plugins now have an **Archive** action.

Requirements:

* The PHP zip extension.
* WordPress must be able to write to the `wp-content` folder directly (no FTP credentials needed). If it can't, the Plugins screen says so and the actions are not offered.

= nginx =

The archive folder gets an `.htaccess` file that stops its zips being downloaded. nginx ignores `.htaccess`, so on nginx add this to the site's server block and reload nginx:

`location ^~ /wp-content/plugins-archive/ { deny all; }`

Without it, anyone who guesses a plugin's folder name can download its archived zip, including its PHP source.

== Frequently Asked Questions ==

= Does anything leave my server? =

No. Archiving compresses a plugin and moves it into the archive folder; unarchiving uncompresses it and moves it back. Both are file operations on the server itself. Nothing is downloaded to or uploaded from your computer. The only network use is the update check and update download from WordPress.org (see External services).

= Where are archived plugins kept? =

In `wp-content/plugins-archive`, one zip per plugin, named after the plugin's folder (for example `hello-dolly.zip`). The folder name can be changed with the `plugin_archive_folder` option or filter.

= What happens to a single-file plugin such as Hello Dolly? =

It can be archived like any other. When it is unarchived, WordPress's installer puts it in a folder of its own (`hello.php` comes back as `hello/hello.php`), as it does when such a plugin is uploaded. It works the same.

= Can an archived plugin be activated or deleted? =

Not while it is archived. Unarchive it first; it is then an ordinary inactive plugin.

= Which archived plugins get updates? =

Plugins from WordPress.org. A plugin with its own updater usually only works while it is active, so it will not report updates while archived; plugins whose updates come through another active plugin (using the `Update URI` header) are checked as WordPress does for installed ones.

= What happens if I uninstall Plugin Archive? =

Its cached data is removed, but the archive folder and its zips are kept: they are plugins you still own. Reinstall Plugin Archive to manage them again, or unzip them into the plugins folder by hand.

== Changelog ==

= 0.1.0 (in development) =

* NEW: Archive inactive plugins (compress into a zip and move to `wp-content/plugins-archive`), and unarchive them (uncompress and move back). All on the server.
* NEW: Archived plugins listed on the Plugins screen (Archived view, and in All), with Unarchive as their only action.
* NEW: Bulk Archive and Unarchive.
* NEW: Updates for archived plugins: checked with WordPress's own plugin update check, counted in the Updates totals, and applied by installing the new version and archiving it again.

== Upgrade Notice ==

= 0.1.0 =

First release.
