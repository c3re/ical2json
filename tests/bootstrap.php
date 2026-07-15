<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the application's Composer autoloader (which also provides the
 * ics-parser library used by the tests) and the pure helper functions
 * under test.
 */

require_once __DIR__ . '/../htdocs/vendor/autoload.php';
require_once __DIR__ . '/../htdocs/api/lib.php';
require_once __DIR__ . '/Support/HttpTestServer.php';
