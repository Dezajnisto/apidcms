<?php
/**
 * apidcms — entry point
 */

define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('CORE_PATH', __DIR__ . '/core');

require CORE_PATH . '/bootstrap.php';

// === Installer / security checks ===
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');

$dbFile      = STORAGE_PATH . '/database/cms.db';
$installFile = PUBLIC_PATH . '/install.php';

if (!file_exists($dbFile) && $uri !== 'install.php') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>apidcms — Setup</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#f5f3ff;color:#1e1b4b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:16px;padding:48px 40px;max-width:520px;width:100%;box-shadow:0 4px 24px rgba(139,92,246,.12);text-align:center}
h1{font-size:24px;font-weight:700;margin-bottom:8px}
p{color:#6b7280;font-size:16px;line-height:1.6;margin-bottom:8px}
.btn{display:inline-block;background:#8b5cf6;color:#fff;padding:14px 32px;border-radius:10px;font-size:16px;font-weight:600;text-decoration:none}
.btn:hover{background:#7c3aed}
</style>
</head>
<body>
<div class="card">
<h1>Welcome to apidcms</h1>
<p>Site is not installed yet.</p>
<a href="/install.php" class="btn">Run installer</a>
</div>
</body>
</html>
HTML;
    exit;
}

if (file_exists($dbFile) && file_exists($installFile) && $uri !== 'install.php') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Remove install.php</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#fef2f2;color:#991b1b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:16px;padding:48px 40px;max-width:520px;width:100%;box-shadow:0 4px 24px rgba(239,68,68,.12);text-align:center}
h1{font-size:24px;font-weight:700;margin-bottom:8px}
p{color:#6b7280;font-size:16px;line-height:1.6;margin-bottom:8px}
code{background:#f3f4f6;padding:4px 10px;border-radius:6px;font-size:15px}
</style>
</head>
<body>
<div class="card">
<h1>Remove install.php</h1>
<p>The site is installed but <code>install.php</code> is still present.</p>
<p>Remove it: <code>rm www/install.php</code></p>
</div>
</body>
</html>
HTML;
    exit;
}

require CORE_PATH . '/router.php';
