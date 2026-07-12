<?php
/**
 * install.php — apidcms installer
 *
 * CLI: php install.php [--auto]
 * Web: open /install.php in browser
 */
define('INSTALLER_VERSION', '1.0.0');

$isCLI = (php_sapi_name() === 'cli');

// ── Helpers ──
function i_log(string $msg): void { echo "  $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_ok(string $msg): void { echo "  ✅ $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_err(string $msg): void { echo "  ❌ $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_step(string $t): void { echo "\n━━━ $t ━━━\n"; if (ob_get_level()) { ob_flush(); flush(); } }

function check_php(): bool {
    if (PHP_VERSION_ID < 80100) { i_err("PHP 8.1+ required, got " . PHP_VERSION); return false; }
    i_ok("PHP " . PHP_VERSION); return true;
}
function check_ext(): bool {
    $req = ['pdo_sqlite','sqlite3','curl','mbstring','json','gd','openssl','fileinfo','zip','xml','session'];
    $ok = true;
    foreach ($req as $e) { if (extension_loaded($e)) i_ok($e); else { i_err("$e — missing"); $ok = false; } }
    return $ok;
}
function find_composer(): ?string {
    $w = trim((string)shell_exec('which composer 2>/dev/null'));
    if ($w && is_executable($w)) return $w;
    foreach (['composer.phar', __DIR__.'/composer.phar'] as $p) if (is_file($p)) return realpath($p);
    return null;
}
function run_install(array $opts): bool {
    $root = realpath(__DIR__);
    $core = getenv('APIDCMS_CORE') ?: (is_dir($root.'/core_lib') ? realpath($root.'/core_lib') : (is_dir(dirname($root).'/core_lib') ? realpath(dirname($root).'/core_lib') : $root));
    $core = rtrim($core, '/');

    i_step("Environment");
    $ok = check_php() && check_ext();
    if (!$ok) return false;

    i_step("Structure");
    foreach (['/admin/storage/database','/storage/cache/twig','/storage/uploads','/storage/logs','/tmp/php/sessions'] as $d) {
        $p = $root.$d;
        if (!is_dir($p)) @mkdir($p, 0755, true);
    }
    @file_put_contents($root.'/storage/uploads/.gitkeep', '');
    @file_put_contents($root.'/storage/logs/.gitkeep', '');
    i_ok("Directories created");

    i_step("Config");
    if (!file_exists($root.'/admin/config/config.php')) {
        file_put_contents($root.'/admin/config/config.php',
            "<?php\nreturn ['security'=>['admin_username'=>'".addslashes($opts['username'])."','admin_password'=>'".addslashes($opts['password'])."'],'ai'=>['api_key'=>'".addslashes($opts['api_key'])."','model'=>'".addslashes($opts['model'])."']];\n");
        i_ok("admin/config/config.php created");
    } else { i_ok("admin/config/config.php exists"); }
    if (!file_exists($root.'/front/config/config.php')) {
        $c = "<?php\nif(!defined('ROOT_PATH')){define('ROOT_PATH',realpath(__DIR__.'/../..'));define('FRONT_PATH',ROOT_PATH.'/front');define('FRONT_APP_PATH',FRONT_PATH.'/app');define('PUBLIC_PATH',ROOT_PATH.'/public');define('STORAGE_PATH',ROOT_PATH.'/storage');}\nreturn ['database'=>['path'=>ROOT_PATH.'/admin/storage/database/','file'=>'cms.db','full_path'=>ROOT_PATH.'/admin/storage/database/cms.db'],'twig'=>['cache'=>STORAGE_PATH.'/cache/twig','auto_reload'=>true]];\n";
        file_put_contents($root.'/front/config/config.php', $c);
        i_ok("front/config/config.php created");
    } else { i_ok("front/config/config.php exists"); }

    i_step("Dependencies");
    if (!is_dir($core.'/vendor')) {
        $c = find_composer();
        if ($c) {
            passthru(escapeshellcmd($c).' install --no-dev --no-interaction -d '.escapeshellarg($core).' 2>&1', $rc);
            if ($rc === 0) i_ok("Composer done"); else i_err("Composer failed");
        } else { i_err("Composer not found — skip"); }
    } else { i_ok("Vendor exists"); }

    i_step("Database");
    $init = file_exists($root.'/init_system_tables.php') ? $root.'/init_system_tables.php' : $core.'/init_system_tables.php';
    if (file_exists($init)) {
        define('INSTALL_MODE', true);
        require $init;
        i_ok("Database initialized");
    } else { i_err("init_system_tables.php not found"); return false; }

    if ($opts['seed']) {
        i_step("Sample content");
        $dbFile = $root.'/admin/storage/database/cms.db';
        try {
            $db = new PDO("sqlite:$dbFile");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec("INSERT OR IGNORE INTO pages (id, title, slug, content, status) VALUES (2, 'About', 'about', '<h1>About</h1><p>Sample page.</p>', 'active')");
            $db->exec("INSERT OR IGNORE INTO navigation (id, title, url, page_id, page_type, location, menu_order, status) VALUES (2, 'About', 'about', 2, 'page', 'header', 2, 'active')");
            $db->exec("INSERT OR IGNORE INTO pages (id, title, slug, content, status) VALUES (3, 'Contact', 'contact', '<h1>Contact</h1><p>Email: hello@example.com</p>', 'active')");
            $db->exec("INSERT OR IGNORE INTO navigation (id, title, url, page_id, page_type, location, menu_order, status) VALUES (3, 'Contact', 'contact', 3, 'page', 'header', 3, 'active')");
            i_ok("Sample pages added (About, Contact)");
        } catch (\Exception $e) { i_err("Sample content failed: ".$e->getMessage()); }
    }

    return true;
}

// ── CLI mode ──
if ($isCLI) {
    $auto = in_array('--auto', $argv ?? [], true);
    echo "\napidcms Installer v".INSTALLER_VERSION."\n\n";
    $ok = run_install(['username'=>'admin','password'=>'admin','api_key'=>'','model'=>'deepseek-chat','seed'=>false]);
    echo "\n".($ok ? "✅ Done. Admin: /admin (admin / admin)\n" : "❌ Failed\n");
    exit($ok ? 0 : 1);
}

// ── Web mode ──
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 0);

// Step 0: Landing page
if ($step === 0) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка apidcms</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#f5f3ff;color:#1e1b4b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:20px;padding:56px 48px;max-width:540px;width:100%;box-shadow:0 8px 40px rgba(139,92,246,.12)}
.logo{font-size:24px;font-weight:700;margin-bottom:4px}
.logo span{background:#8b5cf6;color:#fff;font-size:12px;padding:2px 8px;border-radius:12px;margin-left:8px;vertical-align:middle}
h1{font-size:28px;font-weight:700;margin:16px 0 8px}
.sub{color:#6b7280;font-size:16px;line-height:1.6;margin-bottom:32px}
label{display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:#374151}
input[type=text],input[type=password],select{width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;margin-bottom:20px;outline:none;transition:border-color .2s}
input:focus,select:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.1)}
.row{display:flex;gap:16px}.row>div{flex:1}
.btn{display:inline-flex;align-items:center;gap:8px;background:#8b5cf6;color:#fff;border:none;padding:16px 36px;border-radius:12px;font-size:17px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;width:100%;justify-content:center}
.btn:hover{background:#7c3aed}.btn:disabled{opacity:.5;cursor:not-allowed}
.opt{display:flex;align-items:center;gap:10px;margin-bottom:20px;font-size:15px;color:#6b7280}
.opt input[type=checkbox]{width:18px;height:18px;accent-color:#8b5cf6}
.back{color:#9ca3af;font-size:14px;text-align:center;margin-top:16px}
.back a{color:#9ca3af}
.progress{display:flex;gap:4px;margin-bottom:32px}
.progress .dot{flex:1;height:4px;background:#e5e7eb;border-radius:2px;transition:background .3s}
.progress .dot.active{background:#8b5cf6}
.hint{font-size:13px;color:#9ca3af;margin-top:-12px;margin-bottom:20px}
.log-box{background:#1e1e2e;color:#cdd6f4;border-radius:12px;padding:20px 24px;font-family:monospace;font-size:13px;line-height:1.7;max-height:300px;overflow-y:auto;display:none;margin-top:24px}
.result{text-align:center;margin-top:24px;display:none}
.result h2{font-size:22px;margin-bottom:8px}
.result p{color:#6b7280;margin-bottom:16px}
</style>
</head>
<body>
<div class="card">
<div class="logo">apidcms <span>v1.0</span></div>
<h1>Установка</h1>
<p class="sub">Пара минут — и сайт готов к работе.</p>

<div class="progress" id="progress">
  <div class="dot active"></div><div class="dot"></div><div class="dot"></div><div class="dot"></div>
</div>

<form id="form" method="post">
<input type="hidden" name="step" value="1">
<div id="s1">
  <label>Логин администратора</label>
  <input type="text" name="username" value="admin" required>
  <div class="row">
    <div><label>Пароль</label><input type="password" name="password" id="pw1" required></div>
    <div><label>Повторите пароль</label><input type="password" id="pw2" required></div>
  </div>
  <p class="hint">Минимум 4 символа</p>
  <button type="button" class="btn" onclick="next()">Продолжить →</button>
</div>
<div id="s2" style="display:none">
  <label>AI API-ключ</label>
  <input type="text" name="api_key" placeholder="sk-... (можно пропустить)">
  <label>Модель</label>
  <select name="model">
    <option value="deepseek-chat">DeepSeek Chat</option>
    <option value="gpt-4o">GPT-4o</option>
    <option value="gpt-4o-mini">GPT-4o Mini</option>
    <option value="claude-3.5-sonnet">Claude 3.5 Sonnet</option>
  </select>
  <p class="hint">Можно настроить позже в админке</p>
  <button type="button" class="btn" onclick="prev()" style="background:#d1d5db;color:#374151;margin-bottom:12px">← Назад</button>
  <button type="button" class="btn" onclick="next()">Продолжить →</button>
</div>
<div id="s3" style="display:none">
  <div class="opt"><input type="checkbox" name="seed" value="1" id="seedChk"><label for="seedChk" style="margin:0;font-weight:400">Добавить тестовые страницы (About, Contact)</label></div>
  <p class="hint">Поможет понять как устроен сайт. Можно удалить позже.</p>
  <button type="button" class="btn" onclick="prev()" style="background:#d1d5db;color:#374151;margin-bottom:12px">← Назад</button>
  <button type="submit" class="btn" id="goBtn">🚀 Запустить установку</button>
</div>
</form>

<div class="log-box" id="log"></div>
<div class="result" id="result">
  <h2>✅ Готово!</h2>
  <p id="resultMsg"></p>
  <a href="./" class="btn" style="width:auto">Перейти на сайт</a> &nbsp;
  <a href="admin" class="btn" style="background:#d1d5db;color:#374151;width:auto">В админку</a>
</div>
<div class="back" id="backLink"><a href=".">вернуться на сайт</a></div>
</div>
<script>
var step = 1, dots = document.querySelectorAll('.progress .dot');
function show(n) {
  step = n;
  ['s1','s2','s3'].forEach(function(id,i){ document.getElementById(id).style.display = (i+1===n)?'block':'none'; });
  dots.forEach(function(d,i){ d.classList.toggle('active', i < n); });
}
function next() {
  if (step === 1) {
    var p = document.getElementById('pw1').value;
    if (p.length < 4) { alert('Пароль должен быть минимум 4 символа'); return; }
    if (p !== document.getElementById('pw2').value) { alert('Пароли не совпадают'); return; }
  }
  if (step < 3) show(step + 1);
}
function prev() { if (step > 1) show(step - 1); }

document.getElementById('form').addEventListener('submit', function(e) {
  e.preventDefault();
  document.getElementById('form').style.display = 'none';
  document.getElementById('backLink').style.display = 'none';
  dots.forEach(function(d,i){ d.classList.toggle('active', i < 4); });
  document.getElementById('goBtn').disabled = true;
  var logEl = document.getElementById('log');
  logEl.style.display = 'block';

  var fd = new FormData(this);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '', true);
  var lastLen = 0;
  xhr.onreadystatechange = function() {
    if (xhr.readyState >= 3) {
      var t = xhr.responseText.substring(lastLen);
      if (t) { logEl.textContent += t; logEl.scrollTop = logEl.scrollHeight; lastLen = xhr.responseText.length; }
    }
    if (xhr.readyState === 4) {
      var res = document.getElementById('result');
      res.style.display = 'block';
      if (xhr.status === 200) {
        document.getElementById('resultMsg').textContent = 'Сайт установлен. Можно перейти на сайт или в админку.';
      } else {
        document.getElementById('resultMsg').textContent = 'Что-то пошло не так. Попробуйте ещё раз.';
      }
    }
  };
  xhr.send(fd);
});
</script>
</body>
</html>
HTML;
    exit;
}

// Step 1: POST — run installation
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    while (ob_get_level()) ob_end_flush();

    $opts = [
        'username' => $_POST['username'] ?? 'admin',
        'password' => $_POST['password'] ?? 'admin',
        'api_key'  => $_POST['api_key'] ?? '',
        'model'    => $_POST['model'] ?? 'deepseek-chat',
        'seed'     => !empty($_POST['seed']),
    ];

    if (strlen($opts['password']) < 4) $opts['password'] = 'admin';

    echo "apidcms Installer v".INSTALLER_VERSION."\n\n";
    $ok = run_install($opts);
    echo "\n".($ok ? "✅ Installation complete\n" : "❌ Installation failed\n");
    exit;
}
