<?php
/**
 * Tests for Code_Archive_Updates: finding, counting and applying updates to archived plugins.
 *
 * @package code-archive
 */

/**
 * Updates tests.
 */
class Code_Archive_Test_Updates extends Code_Archive_TestCase {

	/**
	 * Archive a dummy plugin.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @param array  $headers Extra headers.
	 * @return string Plugin basename.
	 */
	private function archived( $slug, $version = '1.0', $headers = array() ) {
		$file = $this->make_plugin( $slug, $version, false, $headers );
		$this->assertTrue( $this->ca->store->archive( $file ) );
		return $file;
	}

	/**
	 * The check runs with core's update-check events, at core's intervals; a change in the archive re-checks.
	 *
	 * @return void
	 */
	public function test_check_follows_core_schedule() {
		$file = $this->archived( 'ca-test-upd' );
		$this->fake_api(
			array(
				$file => array(
					'slug'        => 'ca-test-upd',
					'new_version' => '2.0',
					'package'     => 'https://example.com/ca-test-upd.zip',
				),
			),
			$calls
		);
		$updates = $this->ca->updates;
		$this->assertSame( 20, has_action( 'wp_update_plugins', array( $updates, 'scheduled_check' ) ) );
		$this->assertSame( 20, has_action( 'load-plugins.php', array( $updates, 'scheduled_check' ) ) );
		$this->assertSame( 20, has_action( 'load-update-core.php', array( $updates, 'updates_screen_check' ) ) );

		$updates->scheduled_check();
		$this->assertSame( 1, $calls );
		$this->assertSame( '2.0', $updates->get_update( $file )->new_version );

		// Checked just now: neither the next scheduled check nor the Updates screen asks again.
		$updates->scheduled_check();
		$updates->updates_screen_check();
		$this->assertSame( 1, $calls );

		// The Updates screen asks again after a minute, as core does.
		$saved            = get_site_option( Code_Archive_Updates::OPTION );
		$saved['checked'] = time() - 2 * MINUTE_IN_SECONDS;
		update_site_option( Code_Archive_Updates::OPTION, $saved );
		$updates->updates_screen_check();
		$this->assertSame( 2, $calls );

		// Archiving another plugin changes what is archived: checked at once.
		$this->archived( 'ca-test-upd2' );
		$updates->scheduled_check();
		$this->assertSame( 3, $calls );
	}

	/**
	 * Archiving and unarchiving clear core's plugin update data, so core checks again.
	 *
	 * @return void
	 */
	public function test_archive_and_unarchive_refresh_core_updates() {
		$file = $this->make_plugin( 'ca-test-refresh' );
		$this->fake_api( array(), $calls );

		set_site_transient( 'update_plugins', (object) array( 'last_checked' => time() ) );
		$this->ca->store->archive( $file );
		$this->assertFalse( get_site_transient( 'update_plugins' ) );

		set_site_transient( 'update_plugins', (object) array( 'last_checked' => time() ) );
		$this->ca->store->unarchive( $file );
		$this->assertFalse( get_site_transient( 'update_plugins' ) );
	}

	/**
	 * An update not newer than the archived version is not offered.
	 *
	 * @return void
	 */
	public function test_older_or_same_version_is_ignored() {
		$file = $this->archived( 'ca-test-same', '2.0' );
		$this->fake_api(
			array(
				$file => array(
					'new_version' => '2.0',
					'package'     => 'https://example.com/x.zip',
				),
			),
			$calls
		);
		$this->ca->updates->check();
		$this->assertNull( $this->ca->updates->get_update( $file ) );
	}

	/**
	 * A plugin with its own Update URI host is asked through core's update_plugins_{hostname} filter.
	 *
	 * @return void
	 */
	public function test_third_party_updater_filter() {
		$file = $this->archived( 'ca-test-third', '1.0', array( 'Update URI' => 'https://updates.example.com/ca-test-third' ) );
		$this->fake_api( array(), $calls );
		add_filter(
			'update_plugins_updates.example.com',
			function ( $update, $data, $plugin_file ) {
				return array(
					'version' => '1.5',
					'package' => 'https://updates.example.com/ca-test-third-1.5.zip',
					'slug'    => dirname( $plugin_file ),
				);
			},
			10,
			3
		);

		$this->ca->updates->check();
		$this->assertSame( 0, $calls, 'Third-party plugins are not sent to WordPress.org.' );
		$update = $this->ca->updates->get_update( $file );
		$this->assertSame( '1.5', $update->new_version );
		$this->assertSame( $file, $update->plugin );
	}

	/**
	 * Archived updates are added to the admin Updates counts and title.
	 *
	 * @return void
	 */
	public function test_update_counts_include_archived() {
		$this->login_admin();
		$file = $this->archived( 'ca-test-count' );
		$this->fake_api(
			array(
				$file => array(
					'new_version' => '1.1',
					'package'     => 'https://example.com/x.zip',
				),
			),
			$calls
		);
		$this->ca->updates->check();

		$data = wp_get_update_data();
		$this->assertSame( 1, $data['counts']['plugins'] );
		$this->assertSame( 1, $data['counts']['total'] );
		$this->assertStringContainsString( '1 Plugin Update', $data['title'] );
	}

	/**
	 * Updating: the new version is installed from the package and archived in place of the old zip. Never activated.
	 *
	 * @return void
	 */
	public function test_upgrade_round_trip() {
		$file    = $this->archived( 'ca-test-up', '1.0' );
		$package = $this->make_package( 'ca-test-up', '2.0', get_temp_dir() . 'ca-test-up-2.0.zip' );
		$this->fake_api(
			array(
				$file => array(
					'slug'        => 'ca-test-up',
					'new_version' => '2.0',
					'package'     => $package,
				),
			),
			$calls
		);
		$this->ca->updates->check();

		$result = $this->ca->updates->upgrade( $file );

		$this->assertSame( $file, $result );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/ca-test-up' );
		$this->ca->store->reset();
		$this->assertSame( '2.0', $this->ca->store->get_plugins()[ $file ]['data']['Version'] );
		$this->assertNull( $this->ca->updates->get_update( $file ) );
		$this->assertFalse( is_plugin_active( $file ) );
		$this->assertSame( array( 'ca-test-up.zip' ), array_values( preg_grep( '/\.zip/', scandir( $this->archive_dir ) ) ) );
	}

	/**
	 * A failed update leaves the archived copy as it was and nothing in the plugins folder.
	 *
	 * @return void
	 */
	public function test_upgrade_failure_keeps_archive() {
		$file = $this->archived( 'ca-test-fail', '1.0' );
		$zip  = $this->archive_dir . '/ca-test-fail.zip';
		$md5  = md5_file( $zip );
		$this->fake_api(
			array(
				$file => array(
					'new_version' => '2.0',
					'package'     => get_temp_dir() . 'ca-test-missing.zip',
				),
			),
			$calls
		);
		$this->ca->updates->check();

		$result = $this->ca->updates->upgrade( $file );

		$this->assertWPError( $result );
		$this->assertSame( $md5, md5_file( $zip ) );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/ca-test-fail' );
		$this->assertNotNull( $this->ca->updates->get_update( $file ), 'The update is still offered.' );
	}

	/**
	 * Without an update there is nothing to do.
	 *
	 * @return void
	 */
	public function test_upgrade_without_update() {
		$file = $this->archived( 'ca-test-noup' );
		$this->assertSame( 'code_archive_no_update', $this->ca->updates->upgrade( $file )->get_error_code() );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/ca-test-noup' );
	}

	/**
	 * An update is not installed over a plugin of the same name in the plugins folder.
	 *
	 * @return void
	 */
	public function test_upgrade_refuses_when_target_exists() {
		$file = $this->archived( 'ca-test-busy', '1.0' );
		$this->fake_api(
			array(
				$file => array(
					'new_version' => '2.0',
					'package'     => 'https://example.com/x.zip',
				),
			),
			$calls
		);
		$this->ca->updates->check();
		$this->make_plugin( 'ca-test-busy', '5.0' );

		$this->assertSame( 'code_archive_target_exists', $this->ca->updates->upgrade( $file )->get_error_code() );
		$this->assertStringContainsString( 'Version: 5.0', $this->read_file( WP_PLUGIN_DIR . '/' . $file ) );
		$this->assertFileExists( $this->archive_dir . '/ca-test-busy.zip' );
	}
}
