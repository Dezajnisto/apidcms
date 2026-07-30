<?php
/**
 * apidcms — entry point
 *
 * Single entry point for all requests.
 * Define PROJECT_ROOT then load core bootstrap + router.
 */

define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('CORE_PATH', PROJECT_ROOT . '/core');

require CORE_PATH . '/bootstrap.php';

// === Installer / security checks ===
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');

$dbFile      = STORAGE_PATH . '/database/cms.db';
$installFile = PUBLIC_PATH . '/install.php';

// No database and not installer → show setup prompt
if (!file_exists($dbFile) && $uri !== 'install.php') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>apidcms — Setup</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#f5f3ff;color:#1e1b4b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:16px;padding:48px 40px;max-width:520px;width:100%;box-shadow:0 4px 24px rgba(139,92,246,.12);text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:24px;font-weight:700;margin-bottom:8px}
p{color:#6b7280;font-size:16px;line-height:1.6;margin-bottom:8px}
.steps{text-align:left;background:#f9fafb;border-radius:12px;padding:20px 24px;margin:24px 0;font-size:15px;line-height:1.8}
.steps a{color:#8b5cf6;font-weight:600;text-decoration:none}
.steps a:hover{text-decoration:underline}
.step{display:flex;gap:10px;margin-bottom:6px}
.step .num{color:#8b5cf6;font-weight:700;flex-shrink:0}
.btn{display:inline-block;background:#8b5cf6;color:#fff;padding:14px 32px;border-radius:10px;font-size:16px;font-weight:600;text-decoration:none;transition:background .2s}
.btn:hover{background:#7c3aed}
</style>
</head>
<body>
<div class="card">
<div class="icon">🚀</div>
<h1>Welcome to apidcms</h1>
<p>One more step to finish setup.</p>
<div class="steps">
<div class="step"><span class="num">1.</span> Open the installer:</div>
<div class="step" style="padding-left:22px"><a href="/install.php">→ /install.php</a></div>
<div class="step"><span class="num">2.</span> The installer will check your server and configure everything</div>
<div class="step"><span class="num">3.</span> Your site will be live automatically</div>
</div>
<a href="/install.php" class="btn">Run installer</a>
</div>
</body>
</html>
HTML;
    exit;
}

// Database exists but install.php still on server → security risk
if (file_exists($dbFile) && file_exists($installFile) && $uri !== 'install.php') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>⚠️ Remove install.php</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#fef2f2;color:#991b1b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:16px;padding:48px 40px;max-width:520px;width:100%;box-shadow:0 4px 24px rgba(239,68,68,.12);text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:24px;font-weight:700;margin-bottom:8px}
p{color:#6b7280;font-size:16px;line-height:1.6;margin-bottom:8px}
code{background:#f3f4f6;padding:4px 10px;border-radius:6px;font-size:15px}
</style>
</head>
<body>
<div class="card">
<div class="icon">⚠️</div>
<h1>Remove install.php</h1>
<p>The site is installed, but <code>install.php</code> is still on the server.</p>
<p>This is a security risk — anyone can reinstall your site.</p>
<p>Remove it with:<br><code>rm www/install.php</code></p>
</div>
</body>
</html>
HTML;
    exit;
}

// === All good → route the request ===
require CORE_PATH . '/router.php';
