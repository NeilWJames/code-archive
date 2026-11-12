# Plugin Archive test suite

## Running

PHPUnit 9.6 with the WordPress test library and `yoast/phpunit-polyfills`.

```bash
# One-off: WordPress, the test library and a test database.
bash script/install-wp-tests wordpress_test <db-user> <db-pass> localhost latest
composer install

composer test                       # single site
WP_MULTISITE=1 composer test        # multisite
```

CI (`.github/workflows/ci.yml`) runs PHP 8.2, 8.3 and 8.4 against the latest WordPress, PHP 8.2 against WordPress 6.5 (the oldest supported), and PHP 8.4 multisite.

The tests need the PHP zip extension, and write to the test WordPress's `wp-content`.

## Bootstrap and fixtures

- `tests/bootstrap.php` loads the plugin on `muplugins_loaded` and, since its classes normally load only in the admin, requires them directly.
- `Plugin_Archive_TestCase` (`tests/class-plugin-archive-testcase.php`) is the base class; it uses the `Plugin_Archive_Fixtures` trait (`tests/trait-plugin-archive-fixtures.php`):
  - archive folder `wp-content/pa-test-archive` (via the `plugin_archive_folder` filter), emptied before and after each test;
  - `make_plugin()` creates dummy plugins named `pa-test-*` (folder plugins with a sub-folder, an empty folder and a readme, or single-file plugins); all `pa-test-*` plugins are removed after each test. Real plugins are never touched;
  - `make_package()` builds a plugin zip outside the plugins folder (an update package);
  - `fake_api()` answers the WordPress.org update API through `pre_http_request` and counts requests;
  - `write_file()`, `read_file()`, `remove_path()` go through `$wp_filesystem`;
  - `login_admin()` (super admin on multisite), `expect_redirect()` (redirects throw `Plugin_Archive_Redirect_Exception`), `queued_notices()`;
  - core's own checks after an upgrade (`wp_version_check`, `wp_update_plugins`, `wp_update_themes`, language packs) are unhooked, as the tests have no network.

## Test classes

PHPUnit picks up `tests/class-plugin-archive-test-*.php` (`phpunit9.xml`). The tests pass the full `phpcs.ruleset.xml` (no exclusions for `tests/`), so Plugin Check on the development folder reports nothing for them either.

| Class | File | Tests | Covers |
|---|---|---|---|
| `Plugin_Archive_Test_Store` | `class-plugin-archive-test-store.php` | 13 | Archive folder and guards, folder-name checks, archiving folder and single-file plugins, refusals (active, self, unknown, existing zip), unarchive round trip (folder plugin; single-file plugin back in its own folder), no overwrite, cache following the folder, bad zips, headers as `get_plugin_data()` |
| `Plugin_Archive_Test_Updates` | `class-plugin-archive-test-updates.php` | 9 | Check hooked to core's update-check events at core's intervals, re-checked when the archive changes; core's update data cleared on archive/unarchive; version comparison; `update_plugins_{hostname}`; Updates counts; update round trip (install, archive); failed update keeps the zip; no update; no install over an existing folder |
| `Plugin_Archive_Test_Admin` | `class-plugin-archive-test-admin.php` | 19 | `plugins_list`, sub-site (multisite only), view label, row actions, capabilities, row meta and auto-update toggle, bulk actions and core bulk actions skipping archived plugins, admin actions with nonces (bad nonce stops Archive, Unarchive, Update now and bulk actions; a nonce only works for its own plugin), notices, update row, core list table rendering archived rows |

Total: 41 tests (one skipped on single site).

## Planned

- `uninstall.php`.
- Archive folder not writable / no zip extension (notices, no actions).
- Update with a package whose folder name differs from the installed one.
- End-to-end (Playwright) tests of the Plugins screen.

## Change log

- 2026-11-09: Nonce tests: `test_handle_archive_bad_nonce`, `test_nonce_is_per_plugin`, `test_handle_update_bad_nonce`, `test_handle_bulk_bad_nonce`; the bulk handler now checks the `bulk-plugins` nonce itself, so the bulk tests set one. 41 tests.
- 2026-11-09: Unarchive goes through core's plugin installer (Plugin Check reports `unzip_file()` into the plugins folder): `test_unarchive_single_file_plugin_into_folder` added; unarchive returns the new basename. 37 tests.
- 2026-11-09: Updates no longer use core's update transient (Plugin Check flags it): `test_check_follows_core_schedule` and `test_archive_and_unarchive_refresh_core_updates` replace the transient-based tests; `test_upgrade_refuses_when_target_exists` added. 36 tests.
- 2026-11-09: Tests made phpcs-clean under the plugin's full ruleset: classes renamed `Plugin_Archive_Test_*` (files `class-plugin-archive-test-*.php`), base class `Plugin_Archive_TestCase`, file operations through `$wp_filesystem`, request data read back with nonce check, unslash and sanitizing. Still 35 tests.
- 2026-11-05: First suite, 35 tests. Passing on WordPress 7.1.3 and 6.5.5, single site and multisite, PHP 8.3.
