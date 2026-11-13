<?php
/**
 * Shared fixtures for Code Archive tests.
 *
 * @package code-archive
 */

/**
 * Dummy plugins, a test archive folder, an admin user and request helpers.
 *
 * Tests never touch real plugins: they create their own under a `ca-test-` prefix
 * and the archive folder is `ca-test-archive`.
 */
trait Code_Archive_Fixtures {

	/**
	 * Code Archive instance under test.
	 *
	 * @var Code_Archive
	 */
	protected $ca;

	/**
	 * Archive folder used by the tests.
	 *
	 * @var string
	 */
	protected $archive_dir;

	/**
	 * Paths created by the test, removed in tear down.
	 *
	 * @var string[]
	 */
	protected $created = array();

	/**
	 * Set up: test archive folder, filesystem, Plugins screen and a fresh instance.
	 *
	 * @return void
	 */
	protected function ca_set_up() {
		add_filter( 'code_archive_folder', fn() => 'ca-test-archive' );
		add_filter( 'filesystem_method', fn() => 'direct' );
		WP_Filesystem();

		$this->archive_dir = WP_CONTENT_DIR . '/ca-test-archive';
		$this->remove_path( $this->archive_dir );
		delete_site_option( Code_Archive_Store::CACHE_OPTION );
		delete_option( Code_Archive_Store::CACHE_OPTION );
		delete_site_option( Code_Archive_Updates::OPTION );
		delete_option( Code_Archive_Updates::OPTION );
		delete_site_transient( 'update_plugins' );

		// Core checks for its own updates after an upgrade; tests have no network.
		remove_action( 'upgrader_process_complete', 'wp_version_check', 10 );
		remove_action( 'upgrader_process_complete', 'wp_update_plugins', 10 );
		remove_action( 'upgrader_process_complete', 'wp_update_themes', 10 );
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );

		set_current_screen( 'plugins' );
		$this->ca = new Code_Archive();
		wp_clean_plugins_cache( false );
	}

	/**
	 * Tear down: remove dummy plugins, the archive folder and request data.
	 *
	 * @return void
	 */
	protected function ca_tear_down() {
		foreach ( $this->created as $path ) {
			$this->remove_path( $path );
		}
		foreach ( glob( WP_PLUGIN_DIR . '/ca-test-*' ) as $path ) {
			$this->remove_path( $path );
		}
		$this->remove_path( $this->archive_dir );
		$this->created = array();
		$_GET          = array();
		$_POST         = array();
		$_REQUEST      = array();
		wp_clean_plugins_cache( false );
		set_current_screen( 'front' );
	}

	/**
	 * Create a dummy plugin in the plugins folder.
	 *
	 * @param string $slug    Folder (or file, without .php) name; must start with ca-test-.
	 * @param string $version Version header.
	 * @param bool   $single  Single-file plugin.
	 * @param array  $headers Extra headers (header name => value).
	 * @return string Plugin basename.
	 */
	protected function make_plugin( $slug, $version = '1.0', $single = false, $headers = array() ) {
		$code = $this->plugin_code( $slug, $version, $headers );
		if ( $single ) {
			$this->write_file( WP_PLUGIN_DIR . '/' . $slug . '.php', $code );
			$file = $slug . '.php';
		} else {
			$dir = WP_PLUGIN_DIR . '/' . $slug;
			wp_mkdir_p( $dir . '/includes/empty' );
			$this->write_file( $dir . '/' . $slug . '.php', $code );
			$this->write_file( $dir . '/readme.txt', "=== $slug ===\n" );
			$this->write_file( $dir . '/includes/helper.php', "<?php\n// Helper.\n" );
			$file = $slug . '/' . $slug . '.php';
		}
		wp_clean_plugins_cache( false );
		$this->ca->store->reset();
		return $file;
	}

	/**
	 * Main file code of a dummy plugin.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version.
	 * @param array  $headers Extra headers.
	 * @return string
	 */
	protected function plugin_code( $slug, $version, $headers = array() ) {
		$lines = "<?php\n/**\n * Plugin Name: Test $slug\n * Version: $version\n * Description: Dummy plugin for Code Archive tests.\n";
		foreach ( $headers as $name => $value ) {
			$lines .= " * $name: $value\n";
		}
		return $lines . " */\n";
	}

	/**
	 * Build a zip of a plugin folder outside the plugins folder (e.g. an update package).
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version.
	 * @param string $path    Zip to create.
	 * @return string The zip path.
	 */
	protected function make_package( $slug, $version, $path ) {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addEmptyDir( $slug );
		$zip->addFromString( $slug . '/' . $slug . '.php', $this->plugin_code( $slug, $version ) );
		$zip->addFromString( $slug . '/readme.txt', "=== $slug $version ===\n" );
		$zip->close();
		$this->created[] = $path;
		return $path;
	}

	/**
	 * Log in as an administrator (a super admin on multisite).
	 *
	 * @return int User ID.
	 */
	protected function login_admin() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Turn redirects into Code_Archive_Redirect_Exception (whose message is the location).
	 *
	 * @return void
	 */
	protected function expect_redirect() {
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new Code_Archive_Redirect_Exception( esc_url_raw( (string) $location ) );
			}
		);
	}

	/**
	 * Notices queued for the current user.
	 *
	 * @return array[]
	 */
	protected function queued_notices() {
		$notices = get_transient( 'code_archive_notice_' . get_current_user_id() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Fake WordPress.org update API answers.
	 *
	 * @param array $plugins Update data keyed by plugin basename (as the API's 'plugins').
	 * @param int   $calls   Set to the number of API requests made.
	 * @return void
	 */
	protected function fake_api( $plugins, &$calls ) {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $plugins, &$calls ) {
				if ( Code_Archive_Updates::API_URL !== $url ) {
					return $pre;
				}
				++$calls;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'plugins'   => $plugins,
							'no_update' => array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Remove a file or folder.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	protected function remove_path( $path ) {
		global $wp_filesystem;
		if ( file_exists( $path ) || is_link( $path ) ) {
			$wp_filesystem->delete( $path, true );
		}
	}

	/**
	 * Write a file through the WordPress filesystem.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 * @return void
	 */
	protected function write_file( $path, $content ) {
		global $wp_filesystem;
		$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Read a file through the WordPress filesystem.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	protected function read_file( $path ) {
		global $wp_filesystem;
		return $wp_filesystem->get_contents( $path );
	}
}
