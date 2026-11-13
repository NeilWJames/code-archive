<?php
/**
 * Code Archive: the archive folder, its zips and the header cache.
 *
 * @package code-archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Silence is golden.' );
}

/**
 * Moves plugins between the plugins folder and zips in the archive folder.
 *
 * The zips are the source of truth. Their plugin headers are cached in a
 * (network) option so the Plugins screen never has to open them; the cache
 * is checked against the folder (names, sizes, times) and corrected.
 */
class Code_Archive_Store {

	/**
	 * Option holding the archive folder name, relative to WP_CONTENT_DIR.
	 */
	const FOLDER_OPTION = 'code_archive_folder';

	/**
	 * Default archive folder name.
	 */
	const DEFAULT_FOLDER = 'plugins-archive';

	/**
	 * Option holding the header cache.
	 */
	const CACHE_OPTION = 'code_archive_plugins';

	/**
	 * Plugin headers read from an archived plugin's main file (as get_plugin_data()).
	 *
	 * @var array<string, string>
	 */
	const HEADERS = array(
		'Name'            => 'Plugin Name',
		'PluginURI'       => 'Plugin URI',
		'Version'         => 'Version',
		'Description'     => 'Description',
		'Author'          => 'Author',
		'AuthorURI'       => 'Author URI',
		'TextDomain'      => 'Text Domain',
		'DomainPath'      => 'Domain Path',
		'Network'         => 'Network',
		'RequiresWP'      => 'Requires at least',
		'RequiresPHP'     => 'Requires PHP',
		'UpdateURI'       => 'Update URI',
		'RequiresPlugins' => 'Requires Plugins',
	);

	/**
	 * Archived plugins, once synchronised with the folder in this request.
	 *
	 * @var array<string, array>|null
	 */
	private $plugins = null;

	/**
	 * Archive folder name, relative to WP_CONTENT_DIR.
	 *
	 * @return string
	 */
	public function get_folder() {
		$folder = get_site_option( self::FOLDER_OPTION, self::DEFAULT_FOLDER );

		/**
		 * Filters the archive folder name (relative to WP_CONTENT_DIR).
		 *
		 * @param string $folder Folder name. Default 'plugins-archive'.
		 */
		$folder = (string) apply_filters( 'code_archive_folder', $folder );
		$folder = trim( str_replace( '\\', '/', $folder ), '/' );

		// A single folder level, never one that climbs out of wp-content or hides in plugins.
		if ( '' === $folder || 0 !== validate_file( $folder ) || str_contains( $folder, '/' ) || 'plugins' === $folder ) {
			$folder = self::DEFAULT_FOLDER;
		}
		return $folder;
	}

	/**
	 * Full path of the archive folder (no trailing slash).
	 *
	 * @return string
	 */
	public function get_dir() {
		return untrailingslashit( WP_CONTENT_DIR ) . '/' . $this->get_folder();
	}

	/**
	 * Whether this site can archive and unarchive.
	 *
	 * Zips are written with ZipArchive straight to disk, so both the zip extension
	 * and direct file access are needed.
	 *
	 * @return true|WP_Error
	 */
	public function can_write() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'code_archive_no_zip', __( 'The PHP zip extension is not available, so plugins cannot be archived.', 'code-archive' ) );
		}
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR ) ) {
			return new WP_Error( 'code_archive_not_direct', __( 'WordPress cannot write directly to the wp-content folder, so plugins cannot be archived.', 'code-archive' ) );
		}
		return true;
	}

	/**
	 * Archived plugins, keyed by plugin basename.
	 *
	 * Each entry: 'file' (basename), 'zip' (zip file name), 'size', 'mtime', 'data' (headers).
	 *
	 * @return array<string, array>
	 */
	public function get_plugins() {
		if ( null === $this->plugins ) {
			$this->sync();
		}
		return $this->plugins;
	}

	/**
	 * Whether a plugin basename is archived.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	public function is_archived( $plugin_file ) {
		return isset( $this->get_plugins()[ $plugin_file ] );
	}

	/**
	 * Whether Code Archive can be uninstalled: only when no plugins are archived.
	 *
	 * Once Code Archive is gone, nothing shows the archived plugins any more, so they
	 * must be unarchived first. Called from uninstall.php.
	 *
	 * @return true|WP_Error Error with a plain-text message (plugin names unescaped).
	 */
	public function uninstall_check() {
		$plugins = $this->get_plugins();
		if ( ! $plugins ) {
			return true;
		}
		$names = array_map( fn( $plugin ) => '' !== ( $plugin['data']['Name'] ?? '' ) ? $plugin['data']['Name'] : $plugin['file'], $plugins );
		return new WP_Error(
			'code_archive_not_empty',
			sprintf(
				/* translators: 1: Number of archived plugins, 2: Comma-separated plugin names. */
				_n(
					'Code Archive cannot be deleted while %1$s plugin is archived (%2$s). Unarchive it first, then delete Code Archive.',
					'Code Archive cannot be deleted while %1$s plugins are archived (%2$s). Unarchive them first, then delete Code Archive.',
					count( $plugins ),
					'code-archive'
				),
				number_format_i18n( count( $plugins ) ),
				implode( ', ', $names )
			)
		);
	}

	/**
	 * Forget what was read in this request, so the next call re-reads the folder.
	 *
	 * @return void
	 */
	public function reset() {
		$this->plugins = null;
	}

	/**
	 * Folder name (or file name without .php for a single-file plugin) of a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	public static function get_slug( $plugin_file ) {
		if ( str_contains( $plugin_file, '/' ) ) {
			return dirname( $plugin_file );
		}
		return basename( $plugin_file, '.php' );
	}

	/**
	 * Bring the header cache in line with the zips in the archive folder.
	 *
	 * New or changed zips are read; entries for zips that have gone are dropped.
	 *
	 * @return void
	 */
	public function sync() {
		$cache = get_site_option( self::CACHE_OPTION, array() );
		$cache = is_array( $cache ) ? $cache : array();

		// Index the cache by zip name.
		$by_zip = array();
		foreach ( $cache as $entry ) {
			if ( isset( $entry['zip'], $entry['file'] ) ) {
				$by_zip[ $entry['zip'] ] = $entry;
			}
		}

		$plugins = array();
		$dir     = $this->get_dir();
		$names   = is_dir( $dir ) ? scandir( $dir ) : array();
		foreach ( (array) $names as $name ) {
			if ( ! str_ends_with( strtolower( $name ), '.zip' ) ) {
				continue;
			}
			$path  = $dir . '/' . $name;
			$size  = (int) filesize( $path );
			$mtime = (int) filemtime( $path );
			if ( isset( $by_zip[ $name ] ) && $size === $by_zip[ $name ]['size'] && $mtime === $by_zip[ $name ]['mtime'] ) {
				$entry = $by_zip[ $name ];
			} else {
				$read = $this->read_zip( $path );
				if ( is_wp_error( $read ) ) {
					// Without the zip extension nothing can be read: keep what was cached.
					if ( 'code_archive_no_zip' === $read->get_error_code() && isset( $by_zip[ $name ] ) ) {
						$plugins[ $by_zip[ $name ]['file'] ] = $by_zip[ $name ];
					}
					continue;
				}
				$entry = array(
					'file'  => $read['file'],
					'zip'   => $name,
					'size'  => $size,
					'mtime' => $mtime,
					'data'  => $read['data'],
				);
			}
			$plugins[ $entry['file'] ] = $entry;
		}
		ksort( $plugins );

		if ( $plugins !== $cache ) {
			$this->save_cache( $plugins );
		}
		$this->plugins = $plugins;
	}

	/**
	 * Save the header cache (not autoloaded on a single site).
	 *
	 * @param array<string, array> $plugins Cache entries.
	 * @return void
	 */
	private function save_cache( $plugins ) {
		if ( is_multisite() ) {
			update_site_option( self::CACHE_OPTION, $plugins );
		} else {
			update_option( self::CACHE_OPTION, $plugins, false );
		}
	}

	/**
	 * Read the plugin basename and headers from an archive zip, checking its layout.
	 *
	 * A folder plugin's zip holds only `<slug>/...`; a single-file plugin's zip holds only `<slug>.php`.
	 *
	 * @param string      $path Full path of the zip.
	 * @param string|null $slug Plugin folder name; default from the zip name (`<slug>.zip`).
	 * @return array{file: string, data: array<string, string>}|WP_Error
	 */
	public function read_zip( $path, $slug = null ) {
		if ( null === $slug ) {
			$slug = substr( basename( $path ), 0, -4 );
		}
		if ( '' === $slug || 0 !== validate_file( $slug ) ) {
			return new WP_Error( 'code_archive_bad_zip', __( 'The archive file name is not valid.', 'code-archive' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'code_archive_no_zip', __( 'The PHP zip extension is not available, so plugins cannot be archived.', 'code-archive' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'code_archive_bad_zip', __( 'The archive file cannot be opened.', 'code-archive' ) );
		}

		$candidates = array();
		$single     = false;
		$entries    = count( $zip );
		$valid      = $entries > 0;
		for ( $i = 0; $valid && $i < $entries; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( 0 !== validate_file( $name ) ) {
				$valid = false;
			} elseif ( $name === $slug . '.php' ) {
				$single       = true;
				$candidates[] = $name;
			} elseif ( ! str_starts_with( $name, $slug . '/' ) ) {
				$valid = false;
			} elseif ( 1 === substr_count( $name, '/' ) && str_ends_with( strtolower( $name ), '.php' ) ) {
				$candidates[] = $name;
			}
		}
		// A single-file plugin's zip holds that one file and nothing else.
		if ( $single && 1 !== $entries ) {
			$valid = false;
		}
		if ( ! $valid ) {
			$zip->close();
			return new WP_Error( 'code_archive_bad_zip', __( 'The archive holds files outside the plugin folder.', 'code-archive' ) );
		}

		// As get_plugins(): the first PHP file at the top of the folder with a Plugin Name.
		// Prefer <slug>/<slug>.php, then alphabetical order.
		usort(
			$candidates,
			function ( $a, $b ) use ( $slug ) {
				$main = $slug . '/' . $slug . '.php';
				return array( $a !== $main, $a ) <=> array( $b !== $main, $b );
			}
		);
		foreach ( $candidates as $name ) {
			$contents = $zip->getFromName( $name, 8 * KB_IN_BYTES );
			$data     = is_string( $contents ) ? self::parse_headers( $contents ) : array();
			if ( ! empty( $data['Name'] ) ) {
				// As get_plugin_data(): a folder plugin's text domain defaults to its folder name.
				if ( '' === $data['TextDomain'] && str_contains( $name, '/' ) ) {
					$data['TextDomain'] = $slug;
				}
				$zip->close();
				return array(
					'file' => $name,
					'data' => $data,
				);
			}
		}
		$zip->close();
		return new WP_Error( 'code_archive_bad_zip', __( 'The archive does not hold a plugin.', 'code-archive' ) );
	}

	/**
	 * Parse plugin headers from the start of a file, as get_file_data() does.
	 *
	 * @param string $contents File contents (the first 8 KB is enough).
	 * @return array<string, string>
	 */
	public static function parse_headers( $contents ) {
		$contents = str_replace( "\r", "\n", substr( $contents, 0, 8 * KB_IN_BYTES ) );
		$data     = array();
		foreach ( self::HEADERS as $field => $header ) {
			$value = '';
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $match ) && $match[1] ) {
				$value = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
			$data[ $field ] = $value;
		}
		// The same extra fields as get_plugin_data() adds.
		$data['Network']    = ( 'true' === strtolower( $data['Network'] ) );
		$data['Title']      = $data['Name'];
		$data['AuthorName'] = $data['Author'];
		return $data;
	}

	/**
	 * Archive an inactive plugin: zip it into the archive folder, then remove it from plugins.
	 *
	 * The plugin is only removed once the zip is complete and checked; if anything
	 * fails before that, the plugin is left untouched.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $replace     Internal: basename of an archived plugin whose zip this one replaces (after an update).
	 * @return true|WP_Error
	 */
	public function archive( $plugin_file, $replace = '' ) {
		$ready = $this->prepare();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$check = $this->check_archivable( $plugin_file );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$slug   = self::get_slug( $plugin_file );
		$zip    = $this->get_dir() . '/' . $slug . '.zip';
		$old    = '';
		$cached = $this->get_plugins();
		if ( '' !== $replace && isset( $cached[ $replace ] ) ) {
			$old = $this->get_dir() . '/' . $cached[ $replace ]['zip'];
		}
		if ( file_exists( $zip ) && $zip !== $old ) {
			return new WP_Error(
				'code_archive_exists',
				/* translators: %s: Archive file name. */
				sprintf( __( 'The archive already holds %s.', 'code-archive' ), $slug . '.zip' )
			);
		}

		$tmp   = $zip . '.tmp';
		$built = $this->build_zip( $plugin_file, $tmp );
		if ( is_wp_error( $built ) ) {
			$this->delete_path( $tmp );
			return $built;
		}

		global $wp_filesystem;
		if ( '' !== $old && $old !== $zip ) {
			$wp_filesystem->delete( $old );
		}
		if ( ! $wp_filesystem->move( $tmp, $zip, true ) ) {
			$this->delete_path( $tmp );
			return new WP_Error( 'code_archive_move', __( 'The archive file could not be saved.', 'code-archive' ) );
		}

		// The zip is complete: now remove the plugin from the plugins folder.
		$source = WP_PLUGIN_DIR . '/' . ( str_contains( $plugin_file, '/' ) ? $slug : $plugin_file );
		$this->delete_path( $source );

		$this->reset();
		// Core's update data may still list the plugin: clear it so core checks again.
		wp_clean_plugins_cache( true );

		if ( file_exists( $source ) ) {
			return new WP_Error(
				'code_archive_not_removed',
				/* translators: %s: Plugin folder or file name. */
				sprintf( __( 'The plugin was archived, but %s could not be completely removed from the plugins folder. Remove it by hand.', 'code-archive' ), basename( $source ) )
			);
		}

		/**
		 * Fires after a plugin has been archived.
		 *
		 * @param string $plugin_file Plugin basename.
		 */
		do_action( 'code_archive_archived', $plugin_file );
		return true;
	}

	/**
	 * Check a plugin can be archived.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return true|WP_Error
	 */
	public function check_archivable( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new WP_Error( 'code_archive_not_found', __( 'The plugin is not installed.', 'code-archive' ) );
		}
		if ( plugin_basename( CODE_ARCHIVE_FILE ) === $plugin_file ) {
			return new WP_Error( 'code_archive_self', __( 'Code Archive cannot archive itself.', 'code-archive' ) );
		}
		if ( is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ) ) {
			return new WP_Error( 'code_archive_active', __( 'Only inactive plugins can be archived. Deactivate it first.', 'code-archive' ) );
		}
		$source = WP_PLUGIN_DIR . '/' . ( str_contains( $plugin_file, '/' ) ? self::get_slug( $plugin_file ) : $plugin_file );
		if ( is_link( $source ) ) {
			return new WP_Error( 'code_archive_link', __( 'The plugin is a symbolic link, so it is not archived.', 'code-archive' ) );
		}
		return true;
	}

	/**
	 * Zip a plugin from the plugins folder and check the result.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $target      Full path of the zip to write.
	 * @return true|WP_Error
	 */
	private function build_zip( $plugin_file, $target ) {
		$slug  = self::get_slug( $plugin_file );
		$files = array();
		$dirs  = array();
		if ( str_contains( $plugin_file, '/' ) ) {
			$base   = WP_PLUGIN_DIR . '/' . $slug;
			$iter   = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$dirs[] = $slug;
			foreach ( $iter as $item ) {
				$name = $slug . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $base ) + 1 ) );
				if ( $item->isLink() ) {
					return new WP_Error( 'code_archive_link', __( 'The plugin contains a symbolic link, so it is not archived.', 'code-archive' ) );
				}
				if ( $item->isDir() ) {
					$dirs[] = $name;
				} else {
					$files[ $name ] = $item->getPathname();
				}
			}
		} else {
			$files[ $plugin_file ] = WP_PLUGIN_DIR . '/' . $plugin_file;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'code_archive_zip', __( 'The archive file could not be created.', 'code-archive' ) );
		}
		foreach ( $dirs as $dir ) {
			$zip->addEmptyDir( $dir );
		}
		foreach ( $files as $name => $path ) {
			if ( ! $zip->addFile( $path, $name ) ) {
				$zip->close();
				return new WP_Error( 'code_archive_zip', __( 'The archive file could not be created.', 'code-archive' ) );
			}
		}
		if ( ! $zip->close() ) {
			return new WP_Error( 'code_archive_zip', __( 'The archive file could not be written.', 'code-archive' ) );
		}

		// Check: it opens, holds every entry, and its main file is the plugin being archived.
		$check = new ZipArchive();
		if ( true !== $check->open( $target, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'code_archive_zip', __( 'The archive file could not be checked.', 'code-archive' ) );
		}
		$count = count( $check );
		$main  = $check->statName( $plugin_file );
		$check->close();
		if ( count( $files ) + count( $dirs ) !== $count || false === $main || filesize( WP_PLUGIN_DIR . '/' . $plugin_file ) !== $main['size'] ) {
			return new WP_Error( 'code_archive_zip', __( 'The archive file is incomplete.', 'code-archive' ) );
		}
		$read = $this->read_zip( $target, $slug );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		if ( $read['file'] !== $plugin_file ) {
			return new WP_Error( 'code_archive_zip', __( 'The archive file does not hold the expected plugin.', 'code-archive' ) );
		}
		return true;
	}

	/**
	 * Install an archived plugin into the plugins folder with core's plugin installer, leaving the zip in place.
	 *
	 * The zip is passed to Plugin_Upgrader::install() as a local package, so the
	 * plugin is put back the way WordPress installs any plugin zip (and core's
	 * install hooks run). A single-file plugin is put into a folder of its own,
	 * so `hello.php` comes back as `hello/hello.php`: core would name that folder
	 * after its working folder, which has a random suffix since WP 7.1.4, so
	 * extract() wraps the file in `<slug>/` itself with a temporary
	 * `upgrader_source_selection` filter.
	 *
	 * Refuses if the plugins folder already holds that folder or file; never overwrites.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string|WP_Error Basename of the installed plugin.
	 */
	public function extract( $plugin_file ) {
		$ready = $this->prepare();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$plugins = $this->get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new WP_Error( 'code_archive_not_archived', __( 'The plugin is not in the archive.', 'code-archive' ) );
		}
		$slug    = self::get_slug( $plugin_file );
		$targets = array( WP_PLUGIN_DIR . '/' . $slug );
		if ( ! str_contains( $plugin_file, '/' ) ) {
			$targets[] = WP_PLUGIN_DIR . '/' . $plugin_file;
		}
		foreach ( $targets as $target ) {
			if ( file_exists( $target ) ) {
				return new WP_Error(
					'code_archive_target_exists',
					/* translators: %s: Plugin folder or file name. */
					sprintf( __( 'The plugins folder already holds %s, so the archived copy was not restored.', 'code-archive' ), basename( $target ) )
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// A single-file plugin: put it in `<slug>/` before core picks the destination folder name.
		$wrap = null;
		if ( ! str_contains( $plugin_file, '/' ) ) {
			$wrap = function ( $source, $remote_source, $current ) use ( $upgrader, $plugin_file, $slug ) {
				global $wp_filesystem;
				if ( $current !== $upgrader || is_wp_error( $source ) ) {
					return $source;
				}
				$from = trailingslashit( $source ) . $plugin_file;
				if ( ! $wp_filesystem->is_file( $from ) ) {
					return $source;
				}
				// Move the file into `<working folder>/<slug>/` and install from there.
				$folder = trailingslashit( $remote_source ) . $slug;
				if ( ( ! $wp_filesystem->is_dir( $folder ) && ! $wp_filesystem->mkdir( $folder, FS_CHMOD_DIR ) )
					|| ! $wp_filesystem->move( $from, $folder . '/' . $plugin_file ) ) {
					return new WP_Error( 'code_archive_unzip', __( 'The plugin could not be restored from the archive.', 'code-archive' ) );
				}
				return trailingslashit( $folder );
			};
			add_filter( 'upgrader_source_selection', $wrap, 10, 3 );
		}

		$level = ob_get_level();
		try {
			$result = $upgrader->install( $this->get_dir() . '/' . $plugins[ $plugin_file ]['zip'], array( 'clear_update_cache' => false ) );
		} finally {
			if ( $wrap ) {
				remove_filter( 'upgrader_source_selection', $wrap, 10 );
			}
			// Automatic_Upgrader_Skin can leave its output buffer open when an install fails.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		$new_file = $upgrader->plugin_info();
		if ( ! $result || is_wp_error( $result ) || ! $new_file || ! file_exists( WP_PLUGIN_DIR . '/' . $new_file ) ) {
			$this->delete_path( WP_PLUGIN_DIR . '/' . $slug );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return new WP_Error( 'code_archive_unzip', __( 'The plugin could not be restored from the archive.', 'code-archive' ) );
		}
		wp_clean_plugins_cache( false );
		return $new_file;
	}

	/**
	 * Unarchive a plugin: install it into the plugins folder, then delete its zip.
	 *
	 * The plugin is left inactive. A single-file plugin comes back in a folder of
	 * its own (see extract()), so the basename returned can differ.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string|WP_Error Basename of the unarchived plugin.
	 */
	public function unarchive( $plugin_file ) {
		$new_file = $this->extract( $plugin_file );
		if ( is_wp_error( $new_file ) ) {
			return $new_file;
		}
		$this->delete_zip( $plugin_file );
		// Let core check the plugin, now installed, for updates.
		wp_clean_plugins_cache( true );

		/**
		 * Fires after a plugin has been unarchived (put back in the plugins folder, inactive).
		 *
		 * @param string $plugin_file Plugin basename while archived.
		 * @param string $new_file    Plugin basename now (differs for a single-file plugin, which comes back in a folder).
		 */
		do_action( 'code_archive_unarchived', $plugin_file, $new_file );
		return $new_file;
	}

	/**
	 * Remove a plugin extracted by extract() from the plugins folder (the zip is kept).
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return void
	 */
	public function remove_extracted( $plugin_file ) {
		if ( is_wp_error( $this->prepare() ) ) {
			return;
		}
		$target = WP_PLUGIN_DIR . '/' . ( str_contains( $plugin_file, '/' ) ? self::get_slug( $plugin_file ) : $plugin_file );
		$this->delete_path( $target );
		wp_clean_plugins_cache( false );
	}

	/**
	 * Delete an archived plugin's zip and its cache entry.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return void
	 */
	private function delete_zip( $plugin_file ) {
		$plugins = $this->get_plugins();
		if ( isset( $plugins[ $plugin_file ] ) ) {
			$this->delete_path( $this->get_dir() . '/' . $plugins[ $plugin_file ]['zip'] );
			unset( $plugins[ $plugin_file ] );
			$this->save_cache( $plugins );
		}
		$this->reset();
	}

	/**
	 * Set up the filesystem and the archive folder (with its index.php and .htaccess).
	 *
	 * @return true|WP_Error
	 */
	public function prepare() {
		$can = $this->can_write();
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return new WP_Error( 'code_archive_fs', __( 'The filesystem could not be accessed.', 'code-archive' ) );
		}

		$dir = $this->get_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'code_archive_mkdir', __( 'The archive folder could not be created.', 'code-archive' ) );
		}
		// Archived zips must not be downloadable. nginx ignores .htaccess and needs its own rule (see readme.txt).
		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "# Code Archive: archived plugins are not served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
		);
		foreach ( $guards as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				$wp_filesystem->put_contents( $dir . '/' . $name, $content, FS_CHMOD_FILE );
			}
		}
		return true;
	}

	/**
	 * Delete a file or folder (recursively) if it exists.
	 *
	 * @param string $path Full path.
	 * @return void
	 */
	private function delete_path( $path ) {
		global $wp_filesystem;
		if ( $wp_filesystem && file_exists( $path ) ) {
			$wp_filesystem->delete( $path, true );
		}
	}
}
