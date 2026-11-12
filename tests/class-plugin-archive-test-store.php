<?php
/**
 * Tests for Plugin_Archive_Store: archiving, unarchiving, the zips and the header cache.
 *
 * @package plugin-archive
 */

/**
 * Store tests.
 */
class Plugin_Archive_Test_Store extends Plugin_Archive_TestCase {

	/**
	 * The archive folder is created with an empty index.php and a deny-all .htaccess.
	 *
	 * @return void
	 */
	public function test_prepare_creates_folder_and_guards() {
		$this->assertTrue( $this->pa->store->prepare() );
		$this->assertDirectoryExists( $this->archive_dir );
		$this->assertFileExists( $this->archive_dir . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', $this->read_file( $this->archive_dir . '/.htaccess' ) );
	}

	/**
	 * A bad folder name falls back to the default, so the archive never leaves wp-content.
	 *
	 * @return void
	 */
	public function test_folder_name_is_checked() {
		remove_all_filters( 'plugin_archive_folder' );
		foreach ( array( '../outside', 'a/b', 'plugins', '' ) as $bad ) {
			update_site_option( Plugin_Archive_Store::FOLDER_OPTION, $bad );
			$this->assertSame( Plugin_Archive_Store::DEFAULT_FOLDER, $this->pa->store->get_folder(), $bad );
		}
		update_site_option( Plugin_Archive_Store::FOLDER_OPTION, 'my-archive' );
		$this->assertSame( 'my-archive', $this->pa->store->get_folder() );
		delete_site_option( Plugin_Archive_Store::FOLDER_OPTION );
	}

	/**
	 * Archiving a folder plugin: zip made, folder removed, headers cached, action fired.
	 *
	 * @return void
	 */
	public function test_archive_folder_plugin() {
		$file  = $this->make_plugin( 'pa-test-one', '1.2' );
		$fired = array();
		add_action(
			'plugin_archive_archived',
			function ( $archived ) use ( &$fired ) {
				$fired[] = $archived;
			}
		);

		$this->assertTrue( $this->pa->store->archive( $file ) );

		$this->assertFileExists( $this->archive_dir . '/pa-test-one.zip' );
		$this->assertFileDoesNotExist( $this->archive_dir . '/pa-test-one.zip.tmp' );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/pa-test-one' );
		$this->assertArrayNotHasKey( $file, get_plugins() );
		$plugins = $this->pa->store->get_plugins();
		$this->assertArrayHasKey( $file, $plugins );
		$this->assertSame( 'Test pa-test-one', $plugins[ $file ]['data']['Name'] );
		$this->assertSame( '1.2', $plugins[ $file ]['data']['Version'] );
		$this->assertSame( array( $file ), $fired );
		$this->assertArrayHasKey( $file, get_site_option( Plugin_Archive_Store::CACHE_OPTION ) );
	}

	/**
	 * A single-file plugin is archived as <name>.zip holding just that file.
	 *
	 * @return void
	 */
	public function test_archive_single_file_plugin() {
		$file = $this->make_plugin( 'pa-test-single', '2.0', true );
		$this->assertTrue( $this->pa->store->archive( $file ) );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/pa-test-single.php' );

		$zip = new ZipArchive();
		$zip->open( $this->archive_dir . '/pa-test-single.zip' );
		$this->assertSame( 1, count( $zip ) );
		$this->assertSame( 'pa-test-single.php', $zip->getNameIndex( 0 ) );
		$zip->close();
		$this->assertTrue( $this->pa->store->is_archived( 'pa-test-single.php' ) );
	}

	/**
	 * Active plugins are not archived.
	 *
	 * @return void
	 */
	public function test_archive_refuses_active_plugin() {
		$file = $this->make_plugin( 'pa-test-active' );
		update_option( 'active_plugins', array( $file ) );
		$result = $this->pa->store->archive( $file );
		update_option( 'active_plugins', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'plugin_archive_active', $result->get_error_code() );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . $file );
		$this->assertFileDoesNotExist( $this->archive_dir . '/pa-test-active.zip' );
	}

	/**
	 * Plugin Archive never archives itself; unknown plugins are refused.
	 *
	 * @return void
	 */
	public function test_archive_refuses_self_and_unknown() {
		$this->assertSame( 'plugin_archive_not_found', $this->pa->store->archive( 'pa-test-none/pa-test-none.php' )->get_error_code() );

		// Make Plugin Archive's own basename look installed.
		$self = plugin_basename( PLUGIN_ARCHIVE_FILE );
		$this->assertSame( 'plugin_archive_self', $this->check_with_installed( $self ) );
	}

	/**
	 * The check result for a basename, with that basename forced into get_plugins().
	 *
	 * @param string $file Plugin basename.
	 * @return string Error code, or 'ok'.
	 */
	private function check_with_installed( $file ) {
		wp_cache_set( 'plugins', array( '' => array( $file => array( 'Name' => 'Plugin Archive' ) ) ), 'plugins' );
		$result = $this->pa->store->check_archivable( $file );
		wp_cache_delete( 'plugins', 'plugins' );
		return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	}

	/**
	 * An existing zip of the same name is never overwritten.
	 *
	 * @return void
	 */
	public function test_archive_refuses_existing_zip() {
		$file = $this->make_plugin( 'pa-test-dup' );
		$this->pa->store->prepare();
		$this->write_file( $this->archive_dir . '/pa-test-dup.zip', 'not a zip' );

		$result = $this->pa->store->archive( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'plugin_archive_exists', $result->get_error_code() );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . $file );
		$this->assertSame( 'not a zip', $this->read_file( $this->archive_dir . '/pa-test-dup.zip' ) );
	}

	/**
	 * Unarchiving restores every file (and empty folders), inactive, and deletes the zip.
	 *
	 * @return void
	 */
	public function test_unarchive_restores_plugin() {
		$file     = $this->make_plugin( 'pa-test-round' );
		$original = $this->read_file( WP_PLUGIN_DIR . '/' . $file );
		$this->pa->store->archive( $file );

		$fired = array();
		add_action(
			'plugin_archive_unarchived',
			function ( $archived, $restored ) use ( &$fired ) {
				$fired[] = array( $archived, $restored );
			},
			10,
			2
		);
		$this->assertSame( $file, $this->pa->store->unarchive( $file ) );

		$this->assertSame( $original, $this->read_file( WP_PLUGIN_DIR . '/' . $file ) );
		$this->assertFileExists( WP_PLUGIN_DIR . '/pa-test-round/includes/helper.php' );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/pa-test-round/includes/empty' );
		$this->assertFileDoesNotExist( $this->archive_dir . '/pa-test-round.zip' );
		$this->assertFalse( $this->pa->store->is_archived( $file ) );
		$this->assertArrayHasKey( $file, get_plugins() );
		$this->assertFalse( is_plugin_active( $file ) );
		$this->assertSame( array( array( $file, $file ) ), $fired );
	}

	/**
	 * A single-file plugin comes back through core's installer in a folder of its own.
	 *
	 * @return void
	 */
	public function test_unarchive_single_file_plugin_into_folder() {
		$file     = $this->make_plugin( 'pa-test-lone', '1.0', true );
		$original = $this->read_file( WP_PLUGIN_DIR . '/' . $file );
		$this->pa->store->archive( $file );

		$this->assertSame( 'pa-test-lone/pa-test-lone.php', $this->pa->store->unarchive( $file ) );
		$this->assertSame( $original, $this->read_file( WP_PLUGIN_DIR . '/pa-test-lone/pa-test-lone.php' ) );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/pa-test-lone.php' );
		$this->assertFileDoesNotExist( $this->archive_dir . '/pa-test-lone.zip' );
		$this->assertArrayHasKey( 'pa-test-lone/pa-test-lone.php', get_plugins() );
	}

	/**
	 * Unarchive never overwrites a plugin of the same name; the zip is kept.
	 *
	 * @return void
	 */
	public function test_unarchive_refuses_when_target_exists() {
		$file = $this->make_plugin( 'pa-test-clash', '1.0' );
		$this->pa->store->archive( $file );
		$this->make_plugin( 'pa-test-clash', '9.0' );

		$result = $this->pa->store->unarchive( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'plugin_archive_target_exists', $result->get_error_code() );
		$this->assertFileExists( $this->archive_dir . '/pa-test-clash.zip' );
		$this->assertStringContainsString( 'Version: 9.0', $this->read_file( WP_PLUGIN_DIR . '/' . $file ) );
	}

	/**
	 * Zips copied in or deleted by hand are picked up; the cache follows the folder.
	 *
	 * @return void
	 */
	public function test_sync_follows_the_folder() {
		$this->pa->store->prepare();
		$this->make_package( 'pa-test-manual', '3.1', $this->archive_dir . '/pa-test-manual.zip' );
		$this->pa->store->reset();

		$plugins = $this->pa->store->get_plugins();
		$this->assertArrayHasKey( 'pa-test-manual/pa-test-manual.php', $plugins );
		$this->assertSame( '3.1', $plugins['pa-test-manual/pa-test-manual.php']['data']['Version'] );

		$this->remove_path( $this->archive_dir . '/pa-test-manual.zip' );
		$this->pa->store->reset();
		$this->assertSame( array(), $this->pa->store->get_plugins() );
		$this->assertSame( array(), get_site_option( Plugin_Archive_Store::CACHE_OPTION ) );
	}

	/**
	 * A zip with files outside its plugin folder, or without a plugin, is ignored.
	 *
	 * @return void
	 */
	public function test_bad_zips_are_ignored() {
		$this->pa->store->prepare();

		$zip = new ZipArchive();
		$zip->open( $this->archive_dir . '/pa-test-evil.zip', ZipArchive::CREATE );
		$zip->addFromString( 'pa-test-evil/pa-test-evil.php', $this->plugin_code( 'pa-test-evil', '1.0' ) );
		$zip->addFromString( 'other/shell.php', '<?php // Outside the folder.' );
		$zip->close();

		$zip = new ZipArchive();
		$zip->open( $this->archive_dir . '/pa-test-empty.zip', ZipArchive::CREATE );
		$zip->addFromString( 'pa-test-empty/readme.txt', 'No plugin here.' );
		$zip->close();

		$this->assertWPError( $this->pa->store->read_zip( $this->archive_dir . '/pa-test-evil.zip' ) );
		$this->assertWPError( $this->pa->store->read_zip( $this->archive_dir . '/pa-test-empty.zip' ) );
		$this->pa->store->reset();
		$this->assertSame( array(), $this->pa->store->get_plugins() );
	}

	/**
	 * Header parsing matches core's get_plugin_data() for the same file.
	 *
	 * @return void
	 */
	public function test_headers_match_core() {
		$file = $this->make_plugin(
			'pa-test-headers',
			'4.5.6',
			false,
			array(
				'Update URI'        => 'https://example.com/pa-test-headers',
				'Requires PHP'      => '8.2',
				'Requires at least' => '6.5',
				'Network'           => 'true',
			)
		);
		$core = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		$this->pa->store->archive( $file );
		$parsed = $this->pa->store->get_plugins()[ $file ]['data'];
		foreach ( array_keys( Plugin_Archive_Store::HEADERS ) as $field ) {
			$this->assertSame( $core[ $field ], $parsed[ $field ], $field );
		}
	}
}
