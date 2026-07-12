<?php
/**
 * Entry point for standalone mode (no core_lib)
 *
 * When apidcms is installed standalone (cloned directly into document root),
 * all requests go through init.php which handles admin, frontend, static files,
 * and plugin loading.
 *
 * Project mode (with core_lib): uses skeleton index.php:
 *   require __DIR__ . '/core_lib/init.php';
 */
require __DIR__ . '/init.php';
