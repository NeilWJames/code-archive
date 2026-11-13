<?php
/**
 * Tests for Code_Archive: the Plugins screen and the admin actions.
 *
 * @package code-archive
 */

/**
 * Admin tests.
 */
class Code_Archive_Test_Admin extends Code_Archive_TestCase {

	/**
	 * Set up: an administrator on the (network) Plugins screen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->login_admin();
		set_current_screen( is_multisite() ? 'plugins-network' : 'plugins' );
	}

	/**
	 * Archive a dummy plugin.
	 *
	 * @param string $slug Slug.
	 * @return string Plugin basename.
	 */
	private function archived( $slug ) {
		$file = $this->make_plugin( $slug );
		$this->assertTrue( $this->ca->store->archive( $file ) );
		return $file;
	}

	/**
	 * Archived plugins get their own view and are added to All.
	 *
	 * @return void
	 */
	public function test_plugins_list_adds_archived() {
		$file    = $this->archived( 'ca-test-list' );
		$plugins = $this->ca->plugins_list(
			array(
				'all'    => array(),
				'search' => array(),
			)
		);
		$this->assertArrayHasKey( $file, $plugins['archived'] );
		$this->assertArrayHasKey( $file, $plugins['all'] );
		$this->assertTrue( $plugins['archived'][ $file ]['code_archive'] );
		$this->assertSame( 'Test ca-test-list', $plugins['all'][ $file ]['Name'] );
	}

	/**
	 * On a multisite sub-site's Plugins screen archived plugins are not shown.
	 *
	 * @return void
	 */
	public function test_not_shown_on_sub_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->archived( 'ca-test-sub' );
		set_current_screen( 'plugins' );
		$this->assertSame( array( 'all' => array() ), $this->ca->plugins_list( array( 'all' => array() ) ) );
	}

	/**
	 * The Archived view has its own label and count.
	 *
	 * @return void
	 */
	public function test_view_label() {
		global $totals, $status;
		$totals = array( 'archived' => 3 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- list-table global set as core does.
		$status = 'archived'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- list-table global set as core does.

		$views = $this->ca->views( array( 'archived' => 'archived (3)' ) );
		$this->assertStringContainsString( '>Archived <span class="count">(3)</span>', $views['archived'] );
		$this->assertStringContainsString( 'aria-current="page"', $views['archived'] );
		$this->assertStringContainsString( 'plugin_status=archived', $views['archived'] );
	}

	/**
	 * An archived plugin's only action is Unarchive.
	 *
	 * @return void
	 */
	public function test_archived_row_only_unarchive() {
		$file    = $this->archived( 'ca-test-row' );
		$actions = $this->ca->action_links(
			array(
				'activate' => 'x',
				'delete'   => 'y',
			),
			$file,
			array( 'Name' => 'Test ca-test-row' ),
			'all'
		);
		$this->assertSame( array( 'code-archive-unarchive' ), array_keys( $actions ) );
		$this->assertStringContainsString( 'action=code_archive_unarchive', $actions['code-archive-unarchive'] );
		$this->assertStringContainsString( '_wpnonce=', $actions['code-archive-unarchive'] );
	}

	/**
	 * Inactive plugins get Archive; active ones don't.
	 *
	 * @return void
	 */
	public function test_archive_action_for_inactive_only() {
		$file    = $this->make_plugin( 'ca-test-act' );
		$actions = $this->ca->action_links( array( 'activate' => 'x' ), $file, array( 'Name' => 'Test' ), 'all' );
		$this->assertArrayHasKey( 'code-archive-archive', $actions );
		$this->assertArrayHasKey( 'activate', $actions );

		update_option( 'active_plugins', array( $file ) );
		$actions = $this->ca->action_links( array( 'deactivate' => 'x' ), $file, array( 'Name' => 'Test' ), 'all' );
		update_option( 'active_plugins', array() );
		$this->assertArrayNotHasKey( 'code-archive-archive', $actions );
	}

	/**
	 * Users who cannot install plugins get no actions.
	 *
	 * @return void
	 */
	public function test_no_actions_without_capability() {
		$file = $this->make_plugin( 'ca-test-cap' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$actions = $this->ca->action_links( array(), $file, array( 'Name' => 'Test' ), 'all' );
		$this->assertSame( array(), $actions );
	}

	/**
	 * Archived rows are labelled and have no auto-update toggle.
	 *
	 * @return void
	 */
	public function test_row_meta_and_auto_update() {
		$file = $this->archived( 'ca-test-meta' );
		$meta = $this->ca->row_meta( array( 'By me' ), $file, array(), 'all' );
		$this->assertStringContainsString( 'Archived', $meta[0] );
		$this->assertSame( '', $this->ca->auto_update_html( '<a>toggle</a>', $file ) );
		$this->assertSame( '<a>toggle</a>', $this->ca->auto_update_html( '<a>toggle</a>', 'ca-test-other/ca-test-other.php' ) );
	}

	/**
	 * In the Archived view the only bulk action is Unarchive.
	 *
	 * @return void
	 */
	public function test_bulk_actions() {
		global $status;
		$this->archived( 'ca-test-bulkview' );

		$status = 'archived'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- list-table global set as core does.
		$this->assertSame( array( 'code-archive-unarchive' ), array_keys( $this->ca->bulk_actions( array( 'delete-selected' => 'Delete' ) ) ) );

		$status  = 'all'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- list-table global set as core does.
		$actions = $this->ca->bulk_actions( array( 'delete-selected' => 'Delete' ) );
		$this->assertArrayHasKey( 'delete-selected', $actions );
		$this->assertArrayHasKey( 'code-archive-archive', $actions );
		$this->assertArrayHasKey( 'code-archive-unarchive', $actions );
	}

	/**
	 * Core bulk actions never receive archived plugins.
	 *
	 * @return void
	 */
	public function test_core_bulk_actions_skip_archived() {
		$archived  = $this->archived( 'ca-test-skip' );
		$installed = $this->make_plugin( 'ca-test-keep' );

		$_POST['_wpnonce'] = wp_create_nonce( 'bulk-plugins' );
		$_POST['action']   = 'delete-selected';
		$_POST['checked']  = array( $archived, $installed );
		$this->ca->load_plugins_screen();
		$this->assertSame( array( $installed ), $this->posted_checked() );

		// This plugin's own bulk actions keep them.
		$_POST['action']  = 'code-archive-unarchive';
		$_POST['checked'] = array( $archived, $installed );
		$this->ca->load_plugins_screen();
		$this->assertSame( array( $archived, $installed ), $this->posted_checked() );
	}

	/**
	 * Bulk archive and unarchive.
	 *
	 * @return void
	 */
	public function test_handle_bulk() {
		$one = $this->make_plugin( 'ca-test-b1' );
		$two = $this->make_plugin( 'ca-test-b2' );
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'bulk-plugins' ) ) );

		$back = $this->ca->handle_bulk( 'http://example.org/wp-admin/plugins.php', 'code-archive-archive', array( $one, $two ) );
		$this->assertSame( 'http://example.org/wp-admin/plugins.php', $back );
		$this->assertTrue( $this->ca->store->is_archived( $one ) );
		$this->assertTrue( $this->ca->store->is_archived( $two ) );
		$this->assertSame( '2 plugins archived.', $this->queued_notices()[0]['message'] );

		$this->ca->handle_bulk( 'x', 'code-archive-unarchive', array( $one, '../evil.php' ) );
		$this->assertFalse( $this->ca->store->is_archived( $one ) );
		$this->assertTrue( $this->ca->store->is_archived( $two ) );

		// Other actions pass through untouched.
		$this->assertSame( 'y', $this->ca->handle_bulk( 'y', 'activate-selected', array( $two ) ) );
	}

	/**
	 * The Archive admin action checks its nonce, archives, queues a notice and goes back.
	 *
	 * @return void
	 */
	public function test_handle_archive() {
		$file = $this->make_plugin( 'ca-test-handle' );
		$this->set_request(
			array(
				'plugin'   => $file,
				'_wpnonce' => wp_create_nonce( 'code_archive_archive_' . $file ),
			)
		);
		$this->expect_redirect();

		try {
			$this->ca->handle_archive();
			$this->fail( 'Expected a redirect.' );
		} catch ( Code_Archive_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'plugins.php', $e->getMessage() );
		}
		$this->assertTrue( $this->ca->store->is_archived( $file ) );
		$this->assertSame( 'success', $this->queued_notices()[0]['type'] );
	}

	/**
	 * A bad nonce stops the action.
	 *
	 * @return void
	 */
	public function test_handle_unarchive_bad_nonce() {
		$file = $this->archived( 'ca-test-nonce' );
		$this->set_request(
			array(
				'plugin'   => $file,
				'_wpnonce' => 'bad',
			)
		);

		$this->expectException( WPDieException::class );
		try {
			$this->ca->handle_unarchive();
		} finally {
			$this->assertTrue( $this->ca->store->is_archived( $file ) );
		}
	}

	/**
	 * A bad nonce stops Archive; the plugin stays where it is.
	 *
	 * @return void
	 */
	public function test_handle_archive_bad_nonce() {
		$file = $this->make_plugin( 'ca-test-nonce-a' );
		$this->set_request(
			array(
				'plugin'   => $file,
				'_wpnonce' => 'bad',
			)
		);

		$this->expectException( WPDieException::class );
		try {
			$this->ca->handle_archive();
		} finally {
			$this->assertFileExists( WP_PLUGIN_DIR . '/' . $file );
			$this->assertFalse( $this->ca->store->is_archived( $file ) );
		}
	}

	/**
	 * A nonce is only good for the plugin it was made for.
	 *
	 * @return void
	 */
	public function test_nonce_is_per_plugin() {
		$mine  = $this->make_plugin( 'ca-test-nonce-mine' );
		$other = $this->make_plugin( 'ca-test-nonce-other' );
		$this->set_request(
			array(
				'plugin'   => $other,
				'_wpnonce' => wp_create_nonce( 'code_archive_archive_' . $mine ),
			)
		);

		$this->expectException( WPDieException::class );
		try {
			$this->ca->handle_archive();
		} finally {
			$this->assertFalse( $this->ca->store->is_archived( $other ) );
			$this->assertFalse( $this->ca->store->is_archived( $mine ) );
		}
	}

	/**
	 * A bad nonce stops Update now; the archived copy is untouched.
	 *
	 * @return void
	 */
	public function test_handle_update_bad_nonce() {
		$file = $this->archived( 'ca-test-nonce-u' );
		$zip  = $this->archive_dir . '/ca-test-nonce-u.zip';
		$md5  = md5_file( $zip );
		$this->set_request(
			array(
				'plugin'   => $file,
				'_wpnonce' => wp_create_nonce( 'code_archive_unarchive_' . $file ),
			)
		);

		$this->expectException( WPDieException::class );
		try {
			$this->ca->handle_update();
		} finally {
			$this->assertSame( $md5, md5_file( $zip ) );
			$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/ca-test-nonce-u' );
		}
	}

	/**
	 * Bulk Archive and Unarchive check the bulk-plugins nonce themselves.
	 *
	 * @return void
	 */
	public function test_handle_bulk_bad_nonce() {
		$file = $this->make_plugin( 'ca-test-nonce-b' );
		$this->set_request( array( '_wpnonce' => 'bad' ) );

		$this->expectException( WPDieException::class );
		try {
			$this->ca->handle_bulk( 'x', 'code-archive-archive', array( $file ) );
		} finally {
			$this->assertFalse( $this->ca->store->is_archived( $file ) );
			$this->assertFileExists( WP_PLUGIN_DIR . '/' . $file );
		}
	}

	/**
	 * Notices are shown once on the Plugins screen.
	 *
	 * @return void
	 */
	public function test_notices_shown_once() {
		$file = $this->make_plugin( 'ca-test-notice' );
		$this->set_request( array( '_wpnonce' => wp_create_nonce( 'bulk-plugins' ) ) );
		$this->ca->handle_bulk( 'x', 'code-archive-archive', array( $file ) );

		ob_start();
		$this->ca->notices();
		$out = ob_get_clean();
		$this->assertStringContainsString( '1 plugin archived.', $out );

		ob_start();
		$this->ca->notices();
		$this->assertStringNotContainsString( 'archived.', ob_get_clean() );
	}

	/**
	 * The update row offers Update now for an archived plugin with an update.
	 *
	 * @return void
	 */
	public function test_update_row() {
		$file = $this->archived( 'ca-test-urow' );
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

		ob_start();
		$this->ca->update_row( $file, array( 'Name' => 'Test ca-test-urow' ), 'archived' );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'There is a new version of Test ca-test-urow available: 1.1.', $out );
		$this->assertStringContainsString( 'action=code_archive_update', $out );
	}

	/**
	 * Core's Plugins list table shows archived rows with Unarchive only, in the Archived view and in All.
	 *
	 * @return void
	 */
	public function test_list_table_renders_archived_rows() {
		global $status, $s;
		$s    = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- set by plugins.php before the table is built.
		$file = $this->archived( 'ca-test-table' );
		$this->make_plugin( 'ca-test-installed' );

		foreach ( array( 'archived', 'all' ) as $view ) {
			$_REQUEST['plugin_status'] = $view;
			$table                     = _get_list_table( 'WP_Plugins_List_Table', array( 'screen' => get_current_screen() ) );
			$table->prepare_items();
			$this->assertSame( $view, $status );

			ob_start();
			$table->views();
			$table->display_rows();
			$out = ob_get_clean();

			$this->assertStringContainsString( 'Test ca-test-table', $out, $view );
			$this->assertStringContainsString( '>Archived <span class="count">(1)</span>', $out, $view );
			$this->assertStringContainsString( 'action=code_archive_unarchive', $out, $view );
			$this->assertStringNotContainsString( 'plugin=' . rawurlencode( $file ) . '&amp;plugin_status', $out, $view . ': no core action links for the archived row.' );
		}
		$this->assertStringContainsString( 'Test ca-test-installed', $out );
		$this->assertStringContainsString( 'action=code_archive_archive', $out );
	}

	/**
	 * The bulk selection left in the request, read as plugin code must read it.
	 *
	 * @return string[]
	 */
	private function posted_checked() {
		if ( ! isset( $_POST['_wpnonce'], $_POST['checked'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'bulk-plugins' ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['checked'] ) );
	}

	/**
	 * Set the query arguments of an admin action request.
	 *
	 * @param array $args Query arguments.
	 * @return void
	 */
	private function set_request( $args ) {
		$_GET     = $args;
		$_REQUEST = $args;
	}
}
