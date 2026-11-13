<?php
/**
 * Exception thrown in place of a redirect.
 *
 * @package code-archive
 */

/**
 * Thrown by the wp_redirect filter from Code_Archive_Fixtures::expect_redirect().
 *
 * The message is the redirect location.
 */
class Code_Archive_Redirect_Exception extends Exception {
}
