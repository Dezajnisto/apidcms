<?php
/**
 * apidcms — router
 *
 * Routes requests to admin panel or frontend.
 * Called from www/index.php after bootstrap.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');

if (strpos($uri, 'admin') === 0 || $uri === 'admin') {
    // ===== ADMIN =====
    define('ADMIN_ACCESS', true);

    $adminPath = substr($uri, strlen('admin'));
    $adminPath = ltrim($adminPath, '/');
    $_SERVER['REQUEST_URI'] = '/' . $adminPath;

    try {
        $config = load_config('admin');
        $app = new \Admin\App($config);
        $app->run();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo "<h1>Internal Server Error</h1>";
        if (ini_get('display_errors')) {
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        }
    }
} else {
    // ===== FRONTEND =====
    define('FRONT_ACCESS', true);

    try {
        $config = load_config('front');
        $front = new \Front\FrontController($config);
        $front->run();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo "<h1>Internal Server Error</h1>";
        if (ini_get('display_errors')) {
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        }
    }
}
