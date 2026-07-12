<?php
/**
 * install.php — apidcms installer
 *
 * CLI: php install.php [--auto]
 * Web: open /install.php in browser
 */

define('INSTALLER_VERSION', '1.0.0');

$isCLI = (php_sapi_name() === 'cli');
$isAuto = in_array('--auto', $argv ?? [], true);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

// ── Web: GET request → show install page ──
if (!$isCLI && !$isPost) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка apidcms</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; background: #f5f3ff; color: #1e1b4b; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
  .card { background: #fff; border-radius: 20px; padding: 56px 48px; max-width: 580px; width: 100%; box-shadow: 0 8px 40px rgba(139,92,246,0.12); }
  .logo { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
  .logo span { background: #8b5cf6; color: #fff; font-size: 12px; padding: 2px 8px; border-radius: 12px; margin-left: 8px; vertical-align: middle; }
  h1 { font-size: 28px; font-weight: 700; margin: 16px 0 8px; }
  .sub { color: #6b7280; font-size: 16px; line-height: 1.6; margin-bottom: 32px; }
  .checklist { background: #f9fafb; border-radius: 14px; padding: 24px; margin-bottom: 32px; }
  .checklist .row { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 15px; }
  .checklist .row .icon { width: 24px; text-align: center; flex-shrink: 0; }
  .checklist .row .ok { color: #22c55e; }
  .checklist .row .warn { color: #eab308; }
  .checklist .row .err { color: #ef4444; }
  .btn { display: inline-flex; align-items: center; gap: 8px; background: #8b5cf6; color: #fff; border: none; padding: 16px 36px; border-radius: 12px; font-size: 17px; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .2s; }
  .btn:hover { background: #7c3aed; }
  .btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .skip { color: #9ca3af; font-size: 14px; margin-top: 16px; }
  .skip a { color: #9ca3af; }
  .result { margin-top: 24px; font-size: 14px; }
  .result .ok { color: #22c55e; }
  .result .err { color: #ef4444; }
  .log { background: #1e1e2e; color: #cdd6f4; border-radius: 12px; padding: 20px 24px; margin-top: 16px; font-family: monospace; font-size: 13px; line-height: 1.7; max-height: 300px; overflow-y: auto; display: none; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">apidcms <span>v1.0</span></div>
  <h1>Установка</h1>
  <p class="sub">Сейчас всё проверим, установим зависимости и настроим базу данных. Это займёт меньше минуты.</p>

  <div class="checklist" id="checks"></div>

  <form id="installForm" method="post">
    <button type="submit" class="btn" id="installBtn">
      <span>🚀</span> Запустить установку
    </button>
  </form>

  <div class="skip">
    или <a href=".">перейти на сайт</a>
  </div>

  <div class="log" id="log"></div>
</div>

<script>
var checks = document.getElementById('checks');
var logEl = document.getElementById('log');
var btn = document.getElementById('installBtn');
var form = document.getElementById('installForm');

// Pre-check
var items = [
  ['PHP ' + '8.1+', 'checking', ''],
  ['SQLite', 'checking', ''],
  ['Права записи', 'checking', ''],
  ['Composer', 'checking', ''],
];
items.forEach(function(item) {
  var row = document.createElement('div');
  row.className = 'row';
  row.id = 'chk-' + item[0].replace(/[^a-z0-9]/gi,'');
  row.innerHTML = '<span class="icon">⏳</span>' + item[0];
  checks.appendChild(row);
});

form.addEventListener('submit', function(e) {
  e.preventDefault();
  btn.disabled = true;
  btn.innerHTML = '<span>⏳</span> Установка...';
  logEl.style.display = 'block';
  logEl.textContent = '';

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '', true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 3 || xhr.readyState === 4) {
      var lines = xhr.responseText.split('\n');
      lines.forEach(function(line) {
        if (line.trim()) {
          logEl.textContent += line + '\n';
          logEl.scrollTop = logEl.scrollHeight;
        }
      });
    }
    if (xhr.readyState === 4) {
      btn.disabled = false;
      btn.innerHTML = '<span>✅</span> Готово! Перейти на сайт';
      btn.onclick = function() { window.location = './'; };
    }
  };
  xhr.send();
});
</script>
</body>
</html>
HTML;
    exit;
}

// ── Helper functions ──

function _log(string $msg): void { echo "  $msg\n"; flush(); }

function log_ok(string $msg): void { echo "  ✅ $msg\n"; flush(); }

function log_err(string $msg): void { echo "  ❌ $msg\n"; flush(); }

function step(string $title): void { echo "\n━━━ $title ━━━\n"; flush(); }

function check_php(): bool {
    if (PHP_VERSION_ID < 80100) {
        log_err("PHP 8.1+ required, got " . PHP_VERSION);
        return false;
    }
    log_ok("PHP " . PHP_VERSION);
    return true;
}

function check_ext(): bool {
    $req = ['pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'json', 'gd', 'openssl', 'fileinfo', 'zip', 'xml', 'session'];
    $ok = true;
    foreach ($req as $ext) {
        if (extension_loaded($ext)) { log_ok($ext); } else { log_err("$ext — missing"); $ok = false; }
    }
    return $ok;
}

function find_composer(): ?string {
    $w = trim((string) shell_exec('which composer 2>/dev/null'));
    if ($w && is_executable($w)) return $w;
    foreach (['composer.phar', __DIR__ . '/composer.phar'] as $p) {
        if (is_file($p)) return realpath($p);
    }
    return null;
}

function run_composer(string $corePath): bool {
    $c = find_composer();
    if (!$c) { log_err("Composer not found"); return false; }
    log("Composer: $c");
    $cmd = escapeshellcmd($c) . ' install --no-dev --no-interaction -d ' . escapeshellarg($corePath) . ' 2>&1';
    passthru($cmd, $rc);
    if ($rc !== 0) { log_err("Composer failed"); return false; }
    log_ok("Dependencies installed");
    return true;
}

function ensure_dir(string $path): bool {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0755, true)) { log_err("Cannot create: $path"); return false; }
    }
    return true;
}

// ── Web: POST or CLI:auto → run installation ──
if ($isCLI || $isPost) {
    if (!$isCLI) {
        header('Content-Type: text/plain; charset=utf-8');
        while (ob_get_level()) ob_end_flush();
    }

    echo "apidcms Installer v" . INSTALLER_VERSION . "\n\n";

    // 1. Environment
    step("1/5: Environment");
    $ok = check_php();
    $ok = check_ext() && $ok;
    if (!$ok) { log_err("Fix issues and retry"); exit(1); }

    // 2. Paths
    step("2/5: Paths");
    $root = realpath(__DIR__);
    $core = getenv('APIDCMS_CORE');
    if (!$core) {
        if (is_dir($root . '/core_lib')) $core = realpath($root . '/core_lib');
        elseif (is_dir(dirname($root) . '/core_lib')) $core = realpath(dirname($root) . '/core_lib');
        else $core = $root;
    }
    $core = rtrim($core, '/');
    log("Root: $root");
    log("Core: $core");

    if (!is_dir($core . '/vendor')) log("Vendor not found — will install");

    // 3. Directories
    step("3/5: Directories");
    ensure_dir($root . '/admin/storage/database');
    ensure_dir($root . '/storage/cache/twig');
    ensure_dir($root . '/storage/uploads');
    ensure_dir($root . '/storage/logs');
    foreach (['/storage/uploads/.gitkeep', '/storage/logs/.gitkeep'] as $gk) {
        $p = $root . $gk;
        if (!file_exists($p)) file_put_contents($p, '');
    }

    // 4. Config
    step("4/5: Config files");
    if (!file_exists($root . '/admin/config/config.php')) {
        $c = "<?php\nreturn ['security' => ['admin_username' => 'admin', 'admin_password' => 'admin'], 'ai' => ['api_key' => '', 'model' => 'deepseek-chat']];\n";
        file_put_contents($root . '/admin/config/config.php', $c);
        log_ok("admin/config/config.php created");
    } else { log_ok("admin/config/config.php exists"); }

    if (!file_exists($root . '/front/config/config.php')) {
        $c = "<?php\nif (!defined('ROOT_PATH')) { define('ROOT_PATH', realpath(__DIR__.'/../..')); define('FRONT_PATH', ROOT_PATH.'/front'); define('FRONT_APP_PATH', FRONT_PATH.'/app'); define('PUBLIC_PATH', ROOT_PATH.'/public'); define('STORAGE_PATH', ROOT_PATH.'/storage'); }\nreturn ['database' => ['path' => ROOT_PATH.'/admin/storage/database/', 'file' => 'cms.db', 'full_path' => ROOT_PATH.'/admin/storage/database/cms.db'], 'twig' => ['cache' => STORAGE_PATH.'/cache/twig', 'auto_reload' => true]];\n";
        file_put_contents($root . '/front/config/config.php', $c);
        log_ok("front/config/config.php created");
    } else { log_ok("front/config/config.php exists"); }

    // 5. Composer + DB
    step("5/5: Dependencies & Database");
    if (!is_dir($core . '/vendor')) {
        if (!run_composer($core)) log("Composer skipped — run manually");
    } else {
        log_ok("Vendor exists");
    }

    $init = $root . '/init_system_tables.php';
    if (!file_exists($init)) $init = $core . '/init_system_tables.php';
    if (file_exists($init)) {
        log("Initializing database...");
        require $init;
        log_ok("Database ready");
    } else {
        log_err("init_system_tables.php not found");
    }

    echo "\n══════════════════════════\n";
    echo "  ✅ Installation complete\n";
    echo "══════════════════════════\n\n";
    echo "  Site:  open in browser\n";
    echo "  Admin: /admin (login: admin / admin)\n\n";
    exit(0);
}
