<?php
/**
 * apidcms — router
 *
 * Routes requests: storage proxy → admin panel → frontend.
 * Called from www/index.php after bootstrap.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');

// ===== STORAGE PROXY =====
// Serves files from storage/uploads/, storage/images/, storage/css/
// These are user content — database and cache are blocked by .htaccess
if (strpos($uri, 'storage/') === 0) {
    $allowed = ['uploads', 'images', 'css'];
    $parts = explode('/', $uri, 3); // ['storage', 'uploads', 'path/to/file']
    
    if (count($parts) >= 2 && in_array($parts[1], $allowed, true)) {
        $relative = isset($parts[2]) ? $parts[2] : '';
        $filePath = STORAGE_PATH . '/' . $parts[1] . '/' . $relative;
        $filePath = realpath($filePath);

        if ($filePath && strpos($filePath, STORAGE_PATH) === 0 && is_file($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'css'   => 'text/css',
                'js'    => 'application/javascript',
                'png'   => 'image/png',
                'jpg'   => 'image/jpeg',
                'jpeg'  => 'image/jpeg',
                'gif'   => 'image/gif',
                'webp'  => 'image/webp',
                'svg'   => 'image/svg+xml',
                'ico'   => 'image/x-icon',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
                'pdf'   => 'application/pdf',
                'json'  => 'application/json',
                'xml'   => 'application/xml',
                'txt'   => 'text/plain',
                'html'  => 'text/html',
            ];
            $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=86400');
            
            // Range support for video/audio
            if (isset($_SERVER['HTTP_RANGE'])) {
                // Simplified: just serve the whole file
                header('Accept-Ranges: bytes');
            }

            readfile($filePath);
            exit;
        }
    }

    // Not found or not allowed
    http_response_code(404);
    exit;
}

// ===== ADMIN =====
if (strpos($uri, 'admin') === 0 || $uri === 'admin') {
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
    exit;
}

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
