<?php
/**
 * Точка входа для админки
 */

// Определяем контекст админки
define('ADMIN_ACCESS', true);

// Загружаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Загружаем конфигурацию
$config = require_once __DIR__ . '/config/config.php';

// Используем пространства имен
use Admin\App;

// Создаем и запускаем приложение
try {
    $app = new App($config);
    $app->run();
} catch (Exception $e) {
    http_response_code(500);
    echo "<h1>Внутренняя ошибка сервера</h1>";
    echo "<p>Произошла непредвиденная ошибка в панели управления. Пожалуйста, попробуйте позже.</p>";
    
    if (ini_get('display_errors')) {
        echo "<pre>Ошибка: " . htmlspecialchars($e->getMessage()) . "</pre>";
        echo "<pre>Файл: " . htmlspecialchars($e->getFile()) . ":" . htmlspecialchars($e->getLine()) . "</pre>";
        echo "<pre>Трассировка: " . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}
?>