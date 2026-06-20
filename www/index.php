<?php
/**
 * Главный роутер приложения
 * 
 * Определяет, запрашивается ли админка или фронтенд
 */

// Загружаем автозагрузчик Composer
require_once __DIR__ . '/vendor/autoload.php';

// Определяем корневую директорию
$rootPath = realpath(__DIR__);

// Базовые пути
define('ROOT_PATH', $rootPath);
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('FRONT_PATH', ROOT_PATH . '/front');
define('STORAGE_PATH', ROOT_PATH . '/storage');
// УДАЛИТЬ ЭТИ СТРОКИ:
// define('DB_PATH', STORAGE_PATH . '/database/');
// define('DB_FILE', 'cms.db');

// Дополнительные пути (добавьте эти строки)
if (!defined('ADMIN_APP_PATH')) {
    define('ADMIN_APP_PATH', ADMIN_PATH . '/app');
}
if (!defined('FRONT_APP_PATH')) {
    define('FRONT_APP_PATH', FRONT_PATH . '/app');
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', ROOT_PATH . '/public');
}

// ... остальной код без изменений ...

// Настройки отображения ошибок
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Получаем путь запроса
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

// Убираем базовый путь из URI
$basePath = dirname($scriptName);
if ($basePath === '/') {
    $path = $requestUri;
} else {
    $path = substr($requestUri, strlen($basePath));
}

// Очищаем путь
$path = trim($path, '/');
$path = parse_url($path, PHP_URL_PATH) ?? '';

// Логирование для отладки
error_log("=== MAIN ROUTER DEBUG ===");
error_log("Request URI: " . $requestUri);
error_log("Script Name: " . $scriptName);
error_log("Base Path: " . $basePath);
error_log("Final Path: " . $path);

// Разрешаем доступ к статическим файлам
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $path)) {
    $staticFile = ROOT_PATH . '/' . $path;
    if (file_exists($staticFile)) {
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'text/plain'));
        readfile($staticFile);
        exit;
    }
}

// Определяем, куда перенаправлять
if ($path === 'admin' || strpos($path, 'admin/') === 0) {
    // Запрос к админке - убираем 'admin/' из пути
    $adminPath = substr($path, strlen('admin'));
    $adminPath = ltrim($adminPath, '/');
    
    error_log("Routing to ADMIN with path: " . $adminPath);
    
    $_SERVER['REQUEST_URI'] = '/' . $adminPath;
    require_once ADMIN_PATH . '/index.php';
} else {
    // Запрос к фронтенду
    error_log("Routing to FRONTEND with path: " . $path);
    
    $_SERVER['REQUEST_URI'] = '/' . $path;
    require_once FRONT_PATH . '/index.php';
}

?>