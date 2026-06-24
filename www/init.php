<?php
/**
 * API CMS — ядро (bootstrap)
 *
 * Поддерживает два режима:
 *   1. Через проект:  define('PROJECT_ROOT', __DIR__); require '/path/to/core/init.php';
 *   2. Автономно:     index.php (PROJECT_ROOT = CORE_PATH)
 *
 * Пути:
 *   CORE_PATH     — корень ядра (core/, admin/, vendor/)
 *   PROJECT_ROOT  — корень проекта (storage/, front/, admin/config/)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/core/Parsedown.php';
require_once __DIR__ . '/core/PluginManager.php';

// === Определяем пути ===

define('CORE_PATH', __DIR__);
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);       // Автономный / дистрибутив
}

define('ROOT_PATH', PROJECT_ROOT);
define('ADMIN_PATH', CORE_PATH . '/admin');
define('ADMIN_APP_PATH', ADMIN_PATH . '/app');
define('ADMIN_CONFIG_PATH', PROJECT_ROOT . '/admin/config/config.php');
define('FRONT_PATH', PROJECT_ROOT . '/front');
define('PUBLIC_PATH', PROJECT_ROOT . '/public');
define('STORAGE_PATH', PROJECT_ROOT . '/storage');

// FRONT_APP_PATH — сначала проект, потом ядро (для кастомных шаблонов)
$projectFrontApp = FRONT_PATH . '/app';
if (is_dir($projectFrontApp)) {
    define('FRONT_APP_PATH', $projectFrontApp);
} else {
    define('FRONT_APP_PATH', CORE_PATH . '/front/app');
}

// FRONT_CONFIG_PATH — сначала проект, потом ядро (дефолтный конфиг)
$projectFrontConfig = PROJECT_ROOT . '/front/config/config.php';
if (file_exists($projectFrontConfig)) {
    define('FRONT_CONFIG_PATH', $projectFrontConfig);
} else {
    define('FRONT_CONFIG_PATH', CORE_PATH . '/front/config/config.php');
}

// === Единый автозагрузчик (ядро + проект) ===

spl_autoload_register(function ($class_name) {
    // --- Core\ классы (Database, AI, FormRenderer…) ---
    if (strpos($class_name, 'Core\\') === 0) {
        $relative = str_replace('Core\\', '', $class_name);
        foreach ([CORE_PATH . '/core/', PROJECT_ROOT . '/core/'] as $base) {
            $f = $base . $relative . '.php';
            if (file_exists($f)) { require_once $f; return; }
        }
    }

    // --- Admin\ классы (контроллеры, App, AuthMiddleware…) ---
    if (strpos($class_name, 'Admin\\') === 0) {
        $relative = str_replace('Admin\\', '', $class_name);
        $parts = explode('\\', $relative);
        $simpleName = array_pop($parts);        // AuthMiddleware
        $subDir = !empty($parts) ? strtolower($parts[0]) . '/' : '';
        
        foreach ([ADMIN_APP_PATH, PROJECT_ROOT . '/admin/app'] as $base) {
            $rbase = rtrim($base, '/');
            
            // 1. Если есть под-namespace (Core\...) → ищем в соотв. поддиректории
            if ($subDir) {
                $f = $rbase . '/' . $subDir . $simpleName . '.php';
                if (file_exists($f)) { require_once $f; return; }
            }
            
            // 2. Прямой путь: Admin\App → admin/app/App.php
            $f = $rbase . '/' . $relative . '.php';
            if (file_exists($f)) { require_once $f; return; }
            
            // 3. Fallback: Admin\App мог быть в admin/app/core/App.php
            foreach (['core/', 'controllers/', 'models/'] as $tryDir) {
                $f = $rbase . '/' . $tryDir . $simpleName . '.php';
                if (file_exists($f)) { require_once $f; return; }
            }
        }
    }

    // --- Front\ классы ---
    if (strpos($class_name, 'Front\\') === 0) {
        $relative = str_replace('Front\\', '', $class_name);
        foreach ([FRONT_APP_PATH, CORE_PATH . '/front/app'] as $base) {
            foreach (['/controllers/', '/models/', '/'] as $sub) {
                $f = $base . $sub . $relative . '.php';
                if (file_exists($f)) { require_once $f; return; }
            }
        }
    }
});

// === Плагины ===

$pluginsDir = PROJECT_ROOT . '/plugins';
$pm = \Core\PluginManager::getInstance($pluginsDir);
$pm->loadPlugins(); // загружает init.php всех активных плагинов
$pm->doAction('core.init');

// === Маршрутизация ===

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');

// Статические файлы (CSS/JS/images)
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $uri)) {
    $staticFile = CORE_PATH . '/' . $uri;
    if (file_exists($staticFile)) {
        $mimeTypes = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
            'woff' => 'font/woff', 'woff2' => 'font/woff2',
            'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject'
        ];
        $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'text/plain'));
        readfile($staticFile);
        exit;
    }
}

if (strpos($uri, 'admin') === 0 || $uri === 'admin') {
    // ===== АДМИНКА =====
    define('ADMIN_ACCESS', true);
    
    // Стрипаем /admin и передаём App'у только оставшийся путь
    $adminPath = substr($uri, strlen('admin'));
    $adminPath = ltrim($adminPath, '/');
    $_SERVER['REQUEST_URI'] = '/' . $adminPath;
    
    try {
        $adminCoreConfig = CORE_PATH . "/admin/config/config.php";
        if (file_exists($adminCoreConfig)) {
            $config = require $adminCoreConfig;
            if (file_exists(ADMIN_CONFIG_PATH)) {
                $projectCfg = require ADMIN_CONFIG_PATH;
                $config = array_replace_recursive($config, $projectCfg);
            }
        } else {
            $config = require ADMIN_CONFIG_PATH;
        }
        $app = new \Admin\App($config);
        $app->run();
        exit;  // App должен exit, но на всякий случай
    } catch (\Throwable $e) {
        exit;
        http_response_code(500);
        echo "<h1>Внутренняя ошибка сервера</h1>";
        echo "<p>Произошла непредвиденная ошибка в панели управления.</p>";
        if (ini_get('display_errors')) {
            echo "<pre>Ошибка: " . htmlspecialchars($e->getMessage()) . "</pre>";
            echo "<pre>Файл: " . htmlspecialchars($e->getFile()) . ":" . htmlspecialchars($e->getLine()) . "</pre>";
        }
    }
    
} else {
    // ===== ФРОНТЕНД =====
    define('FRONT_ACCESS', true);
    
    try {
        $config = require FRONT_CONFIG_PATH;
        $front = new \Front\FrontController($config);
        $front->run();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo "<h1>Внутренняя ошибка сервера</h1>";
        echo "<p>Произошла непредвиденная ошибка.</p>";
        if (ini_get('display_errors')) {
            echo "<pre>Ошибка: " . htmlspecialchars($e->getMessage()) . "</pre>";
        }
    }
}
