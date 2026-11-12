<?php
/**
 * Exception thrown in place of a redirect.
 *
 * @package plugin-archive
 */

/**
 * Thrown by the wp_redirect filter from Plugin_Archive_Fixtures::expect_redirect().
 *
 * The message is the redirect location.
 */
class Plugin_Archive_Redirect_Exception extends Exception {
}
