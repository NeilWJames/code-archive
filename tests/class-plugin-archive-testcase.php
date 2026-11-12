<?php
/**
 * Base class for Plugin Archive tests.
 *
 * @package plugin-archive
 */

/**
 * Test case with the Plugin Archive fixtures.
 */
abstract class Plugin_Archive_TestCase extends WP_UnitTestCase {

	use Plugin_Archive_Fixtures;

	/**
	 * Set up a clean archive and plugins folder.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->pa_set_up();
	}

	/**
	 * Remove everything the test created.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->pa_tear_down();
		parent::tear_down();
	}
}
