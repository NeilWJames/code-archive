<?php
/**
 * Code Archive: Plugins screen integration and admin actions.
 *
 * @package code-archive
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Silence is golden.' );
}

/**
 * Main class: shows archived plugins on the Plugins screen and handles Archive, Unarchive and Update.
 */
class Code_Archive {

	/**
	 * List-table status (view) for archived plugins.
	 */
	const STATUS = 'archived';

	/**
	 * The archive.
	 *
	 * @var Code_Archive_Store
	 */
	public $store;

	/**
	 * Updates for archived plugins.
	 *
	 * @var Code_Archive_Updates
	 */
	public $updates;

	/**
	 * Set up and hook in.
	 */
	public function __construct() {
		$this->store   = new Code_Archive_Store();
		$this->updates = new Code_Archive_Updates( $this->store );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_code_archive_archive', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_code_archive_unarchive', array( $this, 'handle_unarchive' ) );
		add_action( 'admin_post_code_archive_update', array( $this, 'handle_update' ) );

		add_action( 'load-plugins.php', array( $this, 'load_plugins_screen' ) );
		add_filter( 'plugins_list', array( $this, 'plugins_list' ) );
		add_filter( 'views_plugins', array( $this, 'views' ) );
		add_filter( 'views_plugins-network', array( $this, 'views' ) );
		add_filter( 'plugin_action_links', array( $this, 'action_links' ), 20, 4 );
		add_filter( 'network_admin_plugin_action_links', array( $this, 'action_links' ), 20, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 4 );
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_html' ), 10, 2 );
		add_action( 'after_plugin_row', array( $this, 'update_row' ), 10, 3 );
		add_filter( 'bulk_actions-plugins', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-plugins-network', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-plugins', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-plugins-network', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'network_admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Whether archived plugins are shown and managed on this screen.
	 *
	 * Plugins are network-wide, so on multisite only in Network Admin.
	 *
	 * @return bool
	 */
	public function is_managed_here() {
		return ! is_multisite() || is_network_admin();
	}

	/**
	 * Whether a plugin is archived and not also in the plugins folder.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	public function is_archived_only( $plugin_file ) {
		return $this->store->is_archived( $plugin_file ) && ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
	}

	/**
	 * Whether a list-table row is an archived plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $context     List-table status.
	 * @return bool
	 */
	private function is_archived_row( $plugin_file, $context ) {
		return $this->is_managed_here() && ( ( self::STATUS === $context && $this->store->is_archived( $plugin_file ) ) || $this->is_archived_only( $plugin_file ) );
	}

	/**
	 * Plugins screen loading: read the archive and keep archived plugins out of core's bulk actions.
	 *
	 * Archived rows have checkboxes like any other row. Core's own bulk actions
	 * (activate, delete, ...) must never be applied to them, so they are taken out
	 * of the selection; only this plugin's bulk actions use them.
	 *
	 * @return void
	 */
	public function load_plugins_screen() {
		$this->store->sync();

		if ( ! isset( $_POST['checked'], $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'bulk-plugins' ) ) {
			return;
		}
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}
		if ( str_starts_with( $action, 'code-archive-' ) ) {
			return;
		}
		$checked = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['checked'] ) );
		$kept    = array_values( array_filter( $checked, fn( $file ) => ! $this->is_archived_only( $file ) ) );
		if ( count( $kept ) !== count( $checked ) ) {
			$_POST['checked']    = $kept;
			$_REQUEST['checked'] = $kept;
		}
	}

	/**
	 * Add archived plugins to the list table: their own view, and All (and Search).
	 *
	 * @param array[] $plugins Plugin data by status.
	 * @return array[]
	 */
	public function plugins_list( $plugins ) {
		global $s, $status;

		if ( ! $this->is_managed_here() ) {
			return $plugins;
		}
		$archived = $this->store->get_plugins();
		if ( ! $archived ) {
			return $plugins;
		}

		// Before WP 7.0 the list table only accepts its own statuses and falls back to 'all'.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view selector, as core reads it.
		if ( 'all' === $status && isset( $_REQUEST['plugin_status'] ) && self::STATUS === sanitize_key( wp_unslash( $_REQUEST['plugin_status'] ) ) ) {
			$status = self::STATUS; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the list table's own status global.
		}

		$search = isset( $s ) && is_string( $s ) ? urldecode( $s ) : '';
		foreach ( $archived as $file => $entry ) {
			$data                 = $entry['data'];
			$data['code_archive'] = true;
			if ( $this->updates->get_update( $file ) ) {
				$data['update'] = true;
			}
			$plugins[ self::STATUS ][ $file ] = $data;
			if ( ! isset( $plugins['all'][ $file ] ) ) {
				$plugins['all'][ $file ] = $data;
			}
			if ( '' !== $search && isset( $plugins['search'] ) && ! isset( $plugins['search'][ $file ] ) && $this->matches( $data, $search ) ) {
				$plugins['search'][ $file ] = $data;
			}
		}
		return $plugins;
	}

	/**
	 * Whether plugin data matches a search, as core's list table does.
	 *
	 * @param array  $data   Plugin data.
	 * @param string $search Search text.
	 * @return bool
	 */
	private function matches( $data, $search ) {
		foreach ( $data as $value ) {
			if ( is_string( $value ) && false !== stripos( wp_strip_all_tags( $value ), $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Give the Archived view its label (core only has labels for its own views before WP 7.0).
	 *
	 * @param string[] $views View links keyed by status.
	 * @return string[]
	 */
	public function views( $views ) {
		global $totals, $status;

		if ( ! isset( $views[ self::STATUS ] ) ) {
			return $views;
		}
		$count                 = (int) ( $totals[ self::STATUS ] ?? count( $this->store->get_plugins() ) );
		$current               = ( self::STATUS === $status );
		$views[ self::STATUS ] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( add_query_arg( 'plugin_status', self::STATUS, self_admin_url( 'plugins.php' ) ) ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html_x( 'Archived', 'plugins view', 'code-archive' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	/**
	 * Row actions: Unarchive only for archived plugins; Archive added for inactive ones.
	 *
	 * @param string[] $actions     Action links.
	 * @param string   $plugin_file Plugin basename.
	 * @param array    $plugin_data Plugin data.
	 * @param string   $context     List-table status.
	 * @return string[]
	 */
	public function action_links( $actions, $plugin_file, $plugin_data, $context ) {
		if ( ! $this->is_managed_here() ) {
			return $actions;
		}

		if ( $this->is_archived_row( $plugin_file, $context ) ) {
			$actions = array();
			if ( current_user_can( 'install_plugins' ) ) {
				$actions['code-archive-unarchive'] = sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $this->action_url( 'unarchive', $plugin_file ) ),
					/* translators: %s: Plugin name. */
					esc_attr( sprintf( _x( 'Unarchive %s', 'plugin', 'code-archive' ), $plugin_data['Name'] ) ),
					esc_html__( 'Unarchive', 'code-archive' )
				);
			}
			return $actions;
		}

		if ( $this->can_archive( $plugin_file, $context ) ) {
			$actions['code-archive-archive'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $this->action_url( 'archive', $plugin_file ) ),
				/* translators: %s: Plugin name. */
				esc_attr( sprintf( _x( 'Archive %s', 'plugin', 'code-archive' ), $plugin_data['Name'] ) ),
				esc_html__( 'Archive', 'code-archive' )
			);
		}
		return $actions;
	}

	/**
	 * Whether to offer Archive for a plugin row.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $context     List-table status.
	 * @return bool
	 */
	private function can_archive( $plugin_file, $context ) {
		if ( in_array( $context, array( 'mustuse', 'dropins' ), true ) || ! current_user_can( 'install_plugins' ) || ! current_user_can( 'delete_plugins' ) ) {
			return false;
		}
		if ( is_wp_error( $this->store->can_write() ) ) {
			return false;
		}
		return true === $this->store->check_archivable( $plugin_file );
	}

	/**
	 * Mark archived rows (in the All and Search views they sit among installed plugins).
	 *
	 * @param string[] $meta        Row meta.
	 * @param string   $plugin_file Plugin basename.
	 * @param array    $plugin_data Plugin data.
	 * @param string   $status      List-table status.
	 * @return string[]
	 */
	public function row_meta( $meta, $plugin_file, $plugin_data, $status ) {
		if ( $this->is_archived_row( $plugin_file, (string) $status ) ) {
			array_unshift( $meta, '<strong>' . esc_html_x( 'Archived', 'plugin state', 'code-archive' ) . '</strong>' );
		}
		return $meta;
	}

	/**
	 * No auto-update toggle for archived plugins: they are updated through the archive.
	 *
	 * @param string $html        Toggle HTML.
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	public function auto_update_html( $html, $plugin_file ) {
		global $status;
		return $this->is_archived_row( $plugin_file, (string) $status ) ? '' : $html;
	}

	/**
	 * Update notice under an archived plugin with an update available.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param array  $plugin_data Plugin data.
	 * @param string $status      List-table status.
	 * @return void
	 */
	public function update_row( $plugin_file, $plugin_data, $status ) {
		if ( ! $this->is_archived_row( $plugin_file, (string) $status ) ) {
			return;
		}
		$update = $this->updates->get_update( $plugin_file );
		if ( null === $update ) {
			return;
		}

		$columns = 4;
		$table   = _get_list_table( 'WP_Plugins_List_Table', array( 'screen' => get_current_screen() ) );
		if ( $table ) {
			$columns = $table->get_column_count();
		}

		$message = sprintf(
			/* translators: 1: Plugin name, 2: Version number. */
			esc_html__( 'There is a new version of %1$s available: %2$s.', 'code-archive' ),
			esc_html( wp_strip_all_tags( $plugin_data['Name'] ) ),
			esc_html( $update->new_version )
		);
		if ( current_user_can( 'update_plugins' ) ) {
			$message .= ' ' . sprintf(
				'<a href="%1$s">%2$s</a> %3$s',
				esc_url( $this->action_url( 'update', $plugin_file ) ),
				esc_html__( 'Update now', 'code-archive' ),
				esc_html__( '(the new version is installed and archived again).', 'code-archive' )
			);
		}

		printf(
			'<tr class="plugin-update-tr code-archive-update"><td colspan="%1$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			(int) $columns,
			wp_kses( $message, array( 'a' => array( 'href' => array() ) ) )
		);
	}

	/**
	 * Bulk actions: only Unarchive in the Archived view; Archive and Unarchive elsewhere.
	 *
	 * @param string[] $actions Bulk actions.
	 * @return string[]
	 */
	public function bulk_actions( $actions ) {
		global $status;

		if ( ! $this->is_managed_here() ) {
			return $actions;
		}
		$unarchive = current_user_can( 'install_plugins' ) ? array( 'code-archive-unarchive' => __( 'Unarchive', 'code-archive' ) ) : array();
		if ( self::STATUS === $status ) {
			return $unarchive;
		}
		if ( current_user_can( 'install_plugins' ) && current_user_can( 'delete_plugins' ) && ! is_wp_error( $this->store->can_write() ) ) {
			$actions['code-archive-archive'] = __( 'Archive', 'code-archive' );
		}
		if ( $this->store->get_plugins() ) {
			$actions += $unarchive;
		}
		return $actions;
	}

	/**
	 * Handle this plugin's bulk actions (after checking the bulk-plugins nonce).
	 *
	 * @param string   $sendback Redirect URL.
	 * @param string   $action   Bulk action.
	 * @param string[] $plugins  Selected plugin basenames.
	 * @return string
	 */
	public function handle_bulk( $sendback, $action, $plugins ) {
		if ( ! in_array( $action, array( 'code-archive-archive', 'code-archive-unarchive' ), true ) || ! $this->is_managed_here() ) {
			return $sendback;
		}
		// Core's plugins.php has checked this nonce before calling the filter; check it here too, so this
		// handler never relies on its caller.
		check_admin_referer( 'bulk-plugins' );
		$archive = ( 'code-archive-archive' === $action );
		if ( ! current_user_can( 'install_plugins' ) || ( $archive && ! current_user_can( 'delete_plugins' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage archived plugins.', 'code-archive' ), 403 );
		}

		$done = 0;
		foreach ( (array) $plugins as $file ) {
			$file = $this->clean_plugin_file( $file );
			if ( '' === $file ) {
				continue;
			}
			if ( $archive && $this->store->is_archived( $file ) && $this->is_archived_only( $file ) ) {
				continue;
			}
			if ( ! $archive && ! $this->store->is_archived( $file ) ) {
				continue;
			}
			$result = $archive ? $this->store->archive( $file ) : $this->store->unarchive( $file );
			if ( is_wp_error( $result ) ) {
				$this->add_notice( 'error', $this->plugin_name( $file ) . ': ' . $result->get_error_message() );
			} else {
				++$done;
			}
		}
		if ( $done ) {
			$this->add_notice(
				'success',
				$archive
					/* translators: %s: Number of plugins. */
					? sprintf( _n( '%s plugin archived.', '%s plugins archived.', $done, 'code-archive' ), number_format_i18n( $done ) )
					/* translators: %s: Number of plugins. */
					: sprintf( _n( '%s plugin unarchived. It is in the plugins folder, inactive.', '%s plugins unarchived. They are in the plugins folder, inactive.', $done, 'code-archive' ), number_format_i18n( $done ) )
			);
		}
		return $sendback ? $sendback : self_admin_url( 'plugins.php' );
	}

	/**
	 * Admin action: archive one plugin.
	 *
	 * @return void
	 */
	public function handle_archive() {
		$file = $this->request_plugin( 'archive' );
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to archive plugins.', 'code-archive' ), 403 );
		}
		$name   = $this->plugin_name( $file );
		$result = $this->store->archive( $file );
		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $name . ': ' . $result->get_error_message() );
		} else {
			/* translators: %s: Plugin name. */
			$this->add_notice( 'success', sprintf( __( '%s archived.', 'code-archive' ), $name ) );
		}
		$this->redirect_back();
	}

	/**
	 * Admin action: unarchive one plugin.
	 *
	 * @return void
	 */
	public function handle_unarchive() {
		$file = $this->request_plugin( 'unarchive' );
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to unarchive plugins.', 'code-archive' ), 403 );
		}
		$name   = $this->plugin_name( $file );
		$result = $this->store->unarchive( $file );
		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $name . ': ' . $result->get_error_message() );
		} else {
			/* translators: %s: Plugin name. */
			$this->add_notice( 'success', sprintf( __( '%s unarchived. It is in the plugins folder, inactive.', 'code-archive' ), $name ) );
		}
		$this->redirect_back();
	}

	/**
	 * Admin action: update one archived plugin (install the new version, archive it again).
	 *
	 * @return void
	 */
	public function handle_update() {
		$file = $this->request_plugin( 'update' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update plugins.', 'code-archive' ), 403 );
		}
		$name   = $this->plugin_name( $file );
		$result = $this->updates->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $name . ': ' . $result->get_error_message() );
		} else {
			$this->store->reset();
			$version = $this->store->get_plugins()[ $result ]['data']['Version'] ?? '';
			/* translators: 1: Plugin name, 2: Version number. */
			$this->add_notice( 'success', sprintf( __( '%1$s updated to version %2$s and archived again.', 'code-archive' ), $name, $version ) );
		}
		$this->redirect_back();
	}

	/**
	 * URL of one of this plugin's admin actions.
	 *
	 * @param string $action      archive, unarchive or update.
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	public function action_url( $action, $plugin_file ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'code_archive_' . $action,
					'plugin' => rawurlencode( $plugin_file ),
				),
				admin_url( 'admin-post.php' )
			),
			'code_archive_' . $action . '_' . $plugin_file
		);
	}

	/**
	 * The plugin named in an admin action request, after checking its nonce.
	 *
	 * @param string $action archive, unarchive or update.
	 * @return string Plugin basename.
	 */
	private function request_plugin( $action ) {
		$file = isset( $_GET['plugin'] ) ? $this->clean_plugin_file( sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) ) : '';
		check_admin_referer( 'code_archive_' . $action . '_' . $file );
		if ( '' === $file ) {
			wp_die( esc_html__( 'No plugin was given.', 'code-archive' ), 400 );
		}
		return $file;
	}

	/**
	 * A plugin basename from a request, or '' if it isn't a valid one.
	 *
	 * @param string $file Plugin basename.
	 * @return string
	 */
	private function clean_plugin_file( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		if ( 0 !== validate_file( $file ) || ! str_ends_with( $file, '.php' ) || substr_count( $file, '/' ) > 1 ) {
			return '';
		}
		return $file;
	}

	/**
	 * Display name of an installed or archived plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	private function plugin_name( $plugin_file ) {
		$archived = $this->store->get_plugins();
		if ( isset( $archived[ $plugin_file ] ) ) {
			return $archived[ $plugin_file ]['data']['Name'];
		}
		$installed = get_plugins();
		return $installed[ $plugin_file ]['Name'] ?? $plugin_file;
	}

	/**
	 * Queue a notice for the current user's next Plugins screen.
	 *
	 * @param string $type    success or error.
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private function add_notice( $type, $message ) {
		$key     = 'code_archive_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Show queued notices on the Plugins screen, and why archiving is unavailable if it is.
	 *
	 * @return void
	 */
	public function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) || ! $this->is_managed_here() ) {
			return;
		}

		$key     = 'code_archive_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( is_array( $notices ) ) {
			delete_transient( $key );
			foreach ( $notices as $notice ) {
				wp_admin_notice(
					esc_html( $notice['message'] ),
					array(
						'type'        => 'error' === $notice['type'] ? 'error' : 'success',
						'dismissible' => true,
					)
				);
			}
		}

		$can = $this->store->can_write();
		if ( is_wp_error( $can ) && current_user_can( 'install_plugins' ) ) {
			wp_admin_notice(
				esc_html( $can->get_error_message() ),
				array(
					'type'        => 'warning',
					'dismissible' => false,
				)
			);
		}
	}

	/**
	 * Go back to the Plugins screen the action came from.
	 *
	 * @return void
	 */
	private function redirect_back() {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
