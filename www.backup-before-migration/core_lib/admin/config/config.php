<?php
/**
 * admin/config/config.php — конфигурация админки (ядро)
 *
 * Базовые пути и настройки по умолчанию.
 * Проектный конфиг (PROJECT_ROOT/admin/config/config.php) мерджится поверх.
 */

$coreRoot = realpath(__DIR__ . '/../..');   // core_lib/
$projectRoot = realpath($coreRoot . '/..'); // www/ (проект)

return [
    'database' => [
        'path' => $projectRoot . '/admin/storage/database/',
        'file' => 'cms.db',
        'full_path' => $projectRoot . '/admin/storage/database/cms.db'
    ],
    'paths' => [
        'root' => $projectRoot,
        'admin' => $coreRoot . '/admin',
        'admin_app' => $coreRoot . '/admin/app',
        'storage' => $projectRoot . '/storage'
    ],
    'security' => [
        'admin_username' => 'admin',
        'admin_password' => 'admin',
        'session_timeout' => 3600
    ],
    'ai' => [
        'api_key' => '',
        'model' => 'deepseek-chat'
    ],
];
