<?php
/**
 * Initialize system tables — delegates to core.
 * Must set PROJECT_ROOT so the core writes to the project's admin/storage/,
 * not to core_lib/admin/storage/.
 */
define('PROJECT_ROOT', __DIR__);
require __DIR__ . '/core_lib/init_system_tables.php';
