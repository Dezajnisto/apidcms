<?php
/**
 * Точка входа для админки
 *
 * Загружает конфиг ядра (базовые пути), затем проектный конфиг (БД, пароли, AI).
 * Проектный конфиг мерджится поверх ядерного через array_replace_recursive.
 */
define('ADMIN_ACCESS', true);
require_once __DIR__ . '/../vendor/autoload.php';

// Конфиг ядра — базовые пути, настройки по умолчанию
$coreConfig = require_once __DIR__ . '/config/config.php';

// Проектный конфиг — только то, что отличается (БД, пароли, AI-ключ)
$projectConfigPath = dirname(dirname(__DIR__)) . '/admin/config/config.php';
if (file_exists($projectConfigPath)) {
    $projectConfig = require_once $projectConfigPath;
    $config = array_replace_recursive($coreConfig, $projectConfig);
} else {
    $config = $coreConfig;
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
