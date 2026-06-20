<?php
/**
 * Точка входа для админки
 */
define('ADMIN_ACCESS', true);
require_once __DIR__ . '/../vendor/autoload.php';

// Сначала ищем конфиг в проекте (ROOT_PATH/admin/config/config.php)
$projectConfigPath = dirname(dirname(__DIR__)) . '/admin/config/config.php';
if (file_exists($projectConfigPath)) {
    $config = require_once $projectConfigPath;
} else {
    $config = require_once __DIR__ . '/config/config.php';
}

use Admin\App;

try {
    $app = new App($config);
    $app->run();
} catch (Exception $e) {
    http_response_code(500);
    echo '<h1>Внутренняя ошибка сервера</h1>';
    echo '<p>Произошла непредвиденная ошибка в панели управления.</p>';
    if (ini_get('display_errors')) {
        echo '<pre>Ошибка: ' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<pre>Файл: ' . htmlspecialchars($e->getFile()) . ':' . htmlspecialchars($e->getLine()) . '</pre>';
    }
}
