<?php
/**
 * PHPUnit bootstrap — isolates tests from the real DB/config.
 */

// Prevent session_start warnings when including panel files
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// Stub minimal constants that config.php would normally define
if (!defined('ADMIN_SESSION_NAME')) define('ADMIN_SESSION_NAME', 'admin_logged_in');
if (!defined('SITE_NAME')) define('SITE_NAME', 'News Feed');
