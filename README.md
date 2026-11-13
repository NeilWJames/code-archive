# Code Archive

[![CI](https://github.com/NeilWJames/code-archive/actions/workflows/ci.yml/badge.svg)](https://github.com/NeilWJames/code-archive/actions/workflows/ci.yml)

A WordPress plugin that keeps inactive plugins out of the plugins folder by archiving them as zip files, puts them back when they are needed, and keeps them updated meanwhile.

**[Documentation](docs/usage.md)** | **[Changelog](docs/changelog.md)** | **[Filters](docs/filters.md)** | **[Actions](docs/actions.md)**

## Why

Every plugin in `wp-content/plugins` is code a web request can reach, active or not; Site Health recommends removing inactive plugins for that reason. Code Archive lets you keep the plugins you use now and then without leaving them there.

## How it works

Code Archive runs entirely on the web server, and does just two file operations:

- **Archive** = compress and move: the plugin's folder is compressed into a zip, moved into `wp-content/plugins-archive`, and removed from `wp-content/plugins`.
- **Unarchive** = uncompress and move: the zip is uncompressed, the plugin moved back into `wp-content/plugins` (inactive), and the zip removed.

Nothing is downloaded to or uploaded from your browser, and archived zips never leave the server. The only network use is checking WordPress.org for updates to archived plugins, and downloading an update when you choose **Update now**, as WordPress does for installed plugins.

## Key features

- **Archive** an inactive plugin: it becomes `wp-content/plugins-archive/<folder>.zip`. No PHP from it is left on disk.
- Archived plugins stay on the Plugins screen (Archived view, and in All), with **Unarchive** as their only action.
- **Unarchive** puts the plugin back in the plugins folder, inactive.
- Bulk Archive and Unarchive.
- **Updates** for archived plugins are found with WordPress's own update check, counted in the Updates totals, and applied by installing the new version and archiving it again.
- Never deletes or overwrites a plugin.
- Multisite: managed in Network Admin.

## Requirements

- **WordPress:** 6.5 or higher
- **PHP:** 8.2 or higher, with the zip extension
- Direct write access to `wp-content`
- **nginx:** deny the archive folder in the server configuration (see [docs/usage.md](docs/usage.md#nginx))

## Development

- Lint: `composer lint` (WPCS 3 + PHPCompatibilityWP, `phpcs.ruleset.xml`)
- Tests: `bash script/install-wp-tests wordpress_test <db-user> <db-pass> localhost latest`, then `composer test` (add `WP_MULTISITE=1` for multisite). See [tests/test_suite.md](tests/test_suite.md).
