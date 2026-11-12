<?php
/**
 * Plugin Archive: update information for archived plugins, and updating them.
 *
 * @package plugin-archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Silence is golden.' );
}

/**
 * Checks for, counts and applies updates to archived plugins.
 *
 * The check runs alongside core's own plugin update check: on core's twice-daily
 * `wp_update_plugins` event and when the Plugins or Updates screen loads, with
 * the same intervals core uses. Core's own update data is never changed:
 * results are kept in this plugin's option and only added to the Updates counts.
 */
class Plugin_Archive_Updates {

	/**
	 * Option holding the last check and the updates found.
	 */
	const OPTION = 'plugin_archive_updates';

	/**
	 * Time between checks of the same archived plugins (as core: 12 hours).
	 */
	const CHECK_INTERVAL = 12 * HOUR_IN_SECONDS;

	/**
	 * Time between checks when the Updates screen is opened (as core: 1 minute).
	 */
	const UPDATES_SCREEN_INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * WordPress.org update API.
	 */
	const API_URL = 'https://api.wordpress.org/plugins/update-check/1.1/';

	/**
	 * The archive.
	 *
	 * @var Plugin_Archive_Store
	 */
	private $store;

	/**
	 * Hook the check to core's update-check events, and the counts.
	 *
	 * @param Plugin_Archive_Store $store The archive.
	 */
	public function __construct( Plugin_Archive_Store $store ) {
		$this->store = $store;

		add_action( 'wp_update_plugins', array( $this, 'scheduled_check' ), 20 );
		add_action( 'load-plugins.php', array( $this, 'scheduled_check' ), 20 );
		add_action( 'load-update.php', array( $this, 'scheduled_check' ), 20 );
		add_action( 'load-update-core.php', array( $this, 'updates_screen_check' ), 20 );
		add_filter( 'wp_get_update_data', array( $this, 'update_data' ), 10, 2 );
	}

	/**
	 * Core's scheduled check, or the Plugins screen: check if the last check is old.
	 *
	 * @return void
	 */
	public function scheduled_check() {
		$this->maybe_check( self::CHECK_INTERVAL );
	}

	/**
	 * The Updates screen: check unless checked within the last minute, as core does there.
	 *
	 * @return void
	 */
	public function updates_screen_check() {
		$this->maybe_check( self::UPDATES_SCREEN_INTERVAL );
	}

	/**
	 * Check, unless the same archived plugins were checked less than $max_age ago.
	 *
	 * A change in the archived plugins or their versions always triggers a check.
	 *
	 * @param int $max_age Seconds a check stays current.
	 * @return void
	 */
	public function maybe_check( $max_age = self::CHECK_INTERVAL ) {
		$saved = $this->get_saved();
		if ( $saved['signature'] === $this->signature() && time() - $saved['checked'] < $max_age ) {
			return;
		}
		$this->check();
	}

	/**
	 * Signature of the archived plugins and their versions.
	 *
	 * @return string
	 */
	private function signature() {
		$versions = array();
		foreach ( $this->store->get_plugins() as $file => $entry ) {
			$versions[ $file ] = $entry['data']['Version'];
		}
		return md5( (string) wp_json_encode( $versions ) );
	}

	/**
	 * Saved check: 'checked' (time), 'signature', 'response' (updates by plugin basename).
	 *
	 * @return array{checked: int, signature: string, response: array<string, object>}
	 */
	private function get_saved() {
		$saved = get_site_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'checked'   => 0,
				'signature' => '',
				'response'  => array(),
			)
		);
	}

	/**
	 * Save the check result.
	 *
	 * @param array $saved As get_saved().
	 * @return void
	 */
	private function save( $saved ) {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $saved );
		} else {
			update_option( self::OPTION, $saved, false );
		}
	}

	/**
	 * Ask for updates to the archived plugins, as wp_update_plugins() does for installed ones.
	 *
	 * WordPress.org plugins are checked in one request. A plugin with an Update URI
	 * on another host is passed to the `update_plugins_{$hostname}` filter, as core does,
	 * so an active updater plugin can answer for it.
	 *
	 * @return void
	 */
	public function check() {
		$archived = $this->store->get_plugins();
		$response = array();
		$locales  = array( get_user_locale() );

		$wporg = array();
		foreach ( $archived as $file => $entry ) {
			$data     = $entry['data'];
			$hostname = '';
			if ( ! empty( $data['UpdateURI'] ) ) {
				$hostname = (string) wp_parse_url( sanitize_url( $data['UpdateURI'] ), PHP_URL_HOST );
			}
			if ( '' === $hostname || in_array( $hostname, array( 'wordpress.org', 'w.org' ), true ) ) {
				$wporg[ $file ] = $data;
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, so updaters written for it also serve archived plugins.
			$update = apply_filters( "update_plugins_{$hostname}", false, $data, $file, $locales );
			if ( is_array( $update ) && isset( $update['version'], $update['package'] ) && version_compare( $update['version'], $data['Version'], '>' ) ) {
				$update              = (object) $update;
				$update->plugin      = $file;
				$update->new_version = $update->version;
				$update->slug        = $update->slug ?? $update->id ?? Plugin_Archive_Store::get_slug( $file );
				$response[ $file ]   = $update;
			}
		}

		if ( $wporg ) {
			$response = array_merge( $response, $this->ask_wporg( $wporg, $locales ) );
		}

		/**
		 * Filters the updates found for archived plugins.
		 *
		 * @param object[] $response Update objects (as in core's update_plugins transient), keyed by plugin basename.
		 * @param array[]  $archived Archived plugins (see Plugin_Archive_Store::get_plugins()).
		 */
		$response = (array) apply_filters( 'plugin_archive_updates', $response, $archived );

		$this->save(
			array(
				'checked'   => time(),
				'signature' => $this->signature(),
				'response'  => $response,
			)
		);
	}

	/**
	 * Ask WordPress.org about some archived plugins.
	 *
	 * @param array[]  $plugins Plugin headers keyed by basename.
	 * @param string[] $locales Locales.
	 * @return object[] Updates keyed by basename.
	 */
	private function ask_wporg( $plugins, $locales ) {
		$args = array(
			'timeout'    => wp_doing_cron() ? 30 : 5,
			'body'       => array(
				'plugins'      => wp_json_encode(
					array(
						'plugins' => $plugins,
						'active'  => array(),
					)
				),
				'translations' => wp_json_encode( array() ),
				'locale'       => wp_json_encode( $locales ),
				'all'          => wp_json_encode( false ),
			),
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		);

		$raw = wp_remote_post( self::API_URL, $args );
		if ( is_wp_error( $raw ) || 200 !== wp_remote_retrieve_response_code( $raw ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $raw ), true );
		if ( ! is_array( $body ) || empty( $body['plugins'] ) || ! is_array( $body['plugins'] ) ) {
			return array();
		}

		$updates = array();
		foreach ( $body['plugins'] as $file => $update ) {
			if ( isset( $plugins[ $file ], $update['new_version'] ) && version_compare( $update['new_version'], $plugins[ $file ]['Version'], '>' ) ) {
				$updates[ $file ] = (object) $update;
			}
		}
		return $updates;
	}

	/**
	 * Updates available for archived plugins (only those still newer than the archived copy).
	 *
	 * @return array<string, object>
	 */
	public function get_updates() {
		$archived = $this->store->get_plugins();
		$updates  = array();
		foreach ( $this->get_saved()['response'] as $file => $update ) {
			$update = (object) $update;
			if ( isset( $archived[ $file ], $update->new_version ) && version_compare( $update->new_version, $archived[ $file ]['data']['Version'], '>' ) ) {
				$updates[ $file ] = $update;
			}
		}
		return $updates;
	}

	/**
	 * The update available for an archived plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return object|null
	 */
	public function get_update( $plugin_file ) {
		return $this->get_updates()[ $plugin_file ] ?? null;
	}

	/**
	 * Forget the update for a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return object|null The update that was removed.
	 */
	private function forget( $plugin_file ) {
		$saved  = $this->get_saved();
		$update = $saved['response'][ $plugin_file ] ?? null;
		if ( null !== $update ) {
			unset( $saved['response'][ $plugin_file ] );
			$this->save( $saved );
		}
		return null === $update ? null : (object) $update;
	}

	/**
	 * Count archived plugins' updates in the admin Updates counts (archived plugins are installed).
	 *
	 * @param array $update_data Counts and title.
	 * @param array $titles      Title parts.
	 * @return array
	 */
	public function update_data( $update_data, $titles = array() ) {
		$count = count( $this->get_updates() );
		if ( ! $count || ! current_user_can( 'update_plugins' ) ) {
			return $update_data;
		}
		$update_data['counts']['plugins'] += $count;
		$update_data['counts']['total']   += $count;

		$plugins = $update_data['counts']['plugins'];
		// Core's own string, so no text domain: it is translated by WordPress, exactly as in wp_get_update_data().
		/* translators: %d: Number of available plugin updates. */
		$titles['plugins']    = sprintf( _n( '%d Plugin Update', '%d Plugin Updates', $plugins ), $plugins ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core string, see above.
		$update_data['title'] = esc_attr( implode( ', ', (array) $titles ) );
		return $update_data;
	}

	/**
	 * Update an archived plugin: install the new version, then archive it in place of the old zip.
	 *
	 * This is the unarchive, update, archive workflow without unzipping the old
	 * version first (the update would replace it anyway). The new version is
	 * installed into the plugins folder with core's plugin installer, never
	 * activated, and archived straight away. If the install fails, nothing is
	 * left in the plugins folder and the old zip is untouched. If archiving the
	 * new version fails, it is left, inactive, in the plugins folder and the
	 * error says so.
	 *
	 * @param string           $plugin_file Plugin basename.
	 * @param WP_Upgrader_Skin $skin        Upgrader skin; default a silent one.
	 * @return string|WP_Error Basename of the updated plugin.
	 */
	public function upgrade( $plugin_file, $skin = null ) {
		$update = $this->get_update( $plugin_file );
		if ( null === $update || empty( $update->package ) ) {
			return new WP_Error( 'plugin_archive_no_update', __( 'There is no update available for this archived plugin.', 'plugin-archive' ) );
		}

		$ready = $this->store->prepare();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$target = WP_PLUGIN_DIR . '/' . ( str_contains( $plugin_file, '/' ) ? Plugin_Archive_Store::get_slug( $plugin_file ) : $plugin_file );
		if ( file_exists( $target ) ) {
			return new WP_Error(
				'plugin_archive_target_exists',
				/* translators: %s: Plugin folder or file name. */
				sprintf( __( 'The plugins folder already holds %s, so the update was not installed.', 'plugin-archive' ), basename( $target ) )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = $skin ?? new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$level  = ob_get_level();
		$result = $upgrader->install( $update->package, array( 'clear_update_cache' => false ) );
		// Automatic_Upgrader_Skin can leave its output buffer open when a download fails.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		$new_file = $upgrader->plugin_info();
		if ( ! $result || is_wp_error( $result ) || ! $new_file || ! file_exists( WP_PLUGIN_DIR . '/' . $new_file ) ) {
			// Remove anything the failed install left; the old zip stays.
			$this->store->remove_extracted( $new_file ? $new_file : $plugin_file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
			return new WP_Error( 'plugin_archive_update_failed', trim( __( 'The update failed; the archived copy is unchanged.', 'plugin-archive' ) . ' ' . html_entity_decode( wp_strip_all_tags( implode( ' ', $messages ) ), ENT_QUOTES, 'UTF-8' ) ) );
		}

		$this->store->reset();
		$archived = $this->store->archive( $new_file, $plugin_file );
		if ( is_wp_error( $archived ) ) {
			return new WP_Error(
				'plugin_archive_rearchive_failed',
				/* translators: %s: Error message. */
				sprintf( __( 'The plugin was updated but could not be archived again, so it has been left (inactive) in the plugins folder: %s', 'plugin-archive' ), $archived->get_error_message() )
			);
		}
		$this->forget( $plugin_file );
		return $new_file;
	}
}
