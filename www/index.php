<?php
/**
 * Entry point — loads apidcms core.
 *
 * PROJECT_ROOT must be defined BEFORE init.php so the core
 * computes correct paths for database, storage, etc.
 */
define('PROJECT_ROOT', __DIR__);
require __DIR__ . '/core_lib/init.php';
