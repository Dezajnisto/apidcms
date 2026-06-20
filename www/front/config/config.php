<?php
/**
 * front/config/config.php — настройки фронтенда apidcms.dezajno.ru
 */

if (php_sapi_name() === 'cli') {
    // CLI ok
} elseif (isset($_SERVER['HTTP_HOST'])) {
    if (strpos($_SERVER['SCRIPT_FILENAME'], 'config.php') !== false) {
        header('HTTP/1.0 403 Forbidden');
        die('Direct access to configuration files is not allowed.');
    }
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/../..'));
    define('FRONT_PATH', ROOT_PATH . '/front');
    define('FRONT_APP_PATH', FRONT_PATH . '/app');
    define('PUBLIC_PATH', ROOT_PATH . '/public');
    define('STORAGE_PATH', ROOT_PATH . '/storage');
    define('ADMIN_PATH', ROOT_PATH . '/admin');
}

return [
    'database' => [
        'path' => ROOT_PATH . '/admin/storage/database/',
        'file' => 'cms.db',
        'full_path' => ROOT_PATH . '/admin/storage/database/cms.db'
    ],
    'paths' => [
        'root' => ROOT_PATH,
        'front' => FRONT_PATH,
        'front_app' => FRONT_APP_PATH,
        'public' => PUBLIC_PATH,
        'storage' => STORAGE_PATH,
        'admin' => ADMIN_PATH
    ],
    'twig' => [
        'cache' => STORAGE_PATH . '/cache/twig',
        'auto_reload' => true
    ]
];
