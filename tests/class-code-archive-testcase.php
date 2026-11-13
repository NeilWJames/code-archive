<?php
/**
 * Base class for Code Archive tests.
 *
 * @package code-archive
 */

/**
 * Test case with the Code Archive fixtures.
 */
abstract class Code_Archive_TestCase extends WP_UnitTestCase {

	use Code_Archive_Fixtures;

	/**
	 * Set up a clean archive and plugins folder.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->ca_set_up();
	}

	/**
	 * Remove everything the test created.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->ca_tear_down();
		parent::tear_down();
	}
}
