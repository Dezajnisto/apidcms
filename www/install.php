<?php
/**
 * install.php — Установщик API CMS
 *
 * Разворачивает apidcms-проект с нуля:
 * - проверяет окружение (PHP, расширения, Composer)
 * - создаёт структуру папок
 * - создаёт конфиги, если их нет
 * - устанавливает зависимости Composer
 * - инициализирует базу данных
 *
 * Запуск: php install.php
 * Запуск без диалогов: php install.php --auto
 */
declare(strict_types=1);

define('INSTALLER_VERSION', '1.0.0');

// === Функции ===

function log_info(string $msg): void { echo "  ℹ️  $msg\n"; }

function log_ok(string $msg): void { echo "  ✅ $msg\n"; }

function log_warn(string $msg): void { echo "  ⚠️  $msg\n"; }

function log_error(string $msg): void { echo "  ❌ $msg\n"; }

function log_step(string $title): void { echo "\n━━━ $title ━━━\n"; }

function check_php_version(): bool {
    if (PHP_VERSION_ID < 80100) {
        log_error("PHP 8.1+ required, got " . PHP_VERSION);
        return false;
    }
    log_ok("PHP " . PHP_VERSION);
    return true;
}

function check_extensions(): bool {
    $required = ['pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'json', 'gd',
                 'openssl', 'fileinfo', 'zip', 'xml', 'session'];
    $optional = ['imagick', 'intl', 'SimpleXML'];
    $allOk = true;

    foreach ($required as $ext) {
        if (extension_loaded($ext)) {
            log_ok("$ext");
        } else {
            log_error("$ext — required, install it");
            $allOk = false;
        }
    }
    foreach ($optional as $ext) {
        if (extension_loaded($ext)) {
            log_ok("$ext (optional)");
        } else {
            log_info("$ext — optional, skipping");
        }
    }
    return $allOk;
}

function find_composer(): ?string {
    $paths = [
        'composer',
        'composer.phar',
        'composer-setup.php',
        __DIR__ . '/composer.phar',
        __DIR__ . '/composer-setup.php',
    ];

    // Try PATH first
    $which = trim((string) shell_exec('which composer 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    foreach ($paths as $p) {
        if (is_file($p) && is_readable($p)) {
            return realpath($p);
        }
    }
    return null;
}

function run_composer_install(string $corePath): bool {
    $composer = find_composer();
    if ($composer === null) {
        log_error("Composer not found. Install it or place composer.phar in project root.");
        log_info("Download: php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\"");
        return false;
    }

    log_info("Composer found: $composer");

    $cmd = escapeshellcmd($composer) . ' install --no-dev --no-interaction -d ' . escapeshellarg($corePath) . ' 2>&1';
    echo "  → Running composer install...\n";
    passthru($cmd, $exitCode);

    if ($exitCode !== 0) {
        log_error("Composer install failed (exit code $exitCode)");
        return false;
    }

    log_ok("Dependencies installed");
    return true;
}

function ensure_dir(string $path): bool {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0755, true)) {
            log_error("Cannot create: $path");
            return false;
        }
        log_ok("Created: " . basename(dirname($path)) . '/' . basename($path));
    } else {
        log_ok("Exists: " . basename(dirname($path)) . '/' . basename($path));
    }
    return true;
}

function write_default_config(string $path, string $content): bool {
    if (file_exists($path)) {
        log_ok("Already exists: " . basename($path));
        return true;
    }
    if (@file_put_contents($path, $content) === false) {
        log_error("Cannot write: $path");
        return false;
    }
    log_ok("Created: " . basename($path));
    return true;
}

// === Главный скрипт ===

echo "\n══════════════════════════════════════════\n";
echo "  API CMS Installer v" . INSTALLER_VERSION . "\n";
echo "══════════════════════════════════════════\n\n";

$isAuto = in_array('--auto', $argv ?? [], true);

// === Фаза 1: Проверка окружения ===

log_step("1/6: Environment check");

$ok = check_php_version();
$ok = check_extensions() && $ok;

if (!$ok) {
    echo "\n";
    log_error("Environment check failed. Fix issues above and re-run.");
    exit(1);
}

// === Фаза 2: Пути ===

log_step("2/6: Paths");

$rootPath = realpath(__DIR__);
echo "  Root: $rootPath\n";

// core_lib — либо рядом, либо через APIDCMS_CORE
$corePath = getenv('APIDCMS_CORE');
if ($corePath === false || $corePath === '') {
    $candidate = __DIR__ . '/core_lib';
    if (is_dir($candidate)) {
        $corePath = realpath($candidate);
    } elseif (is_dir(__DIR__ . '/../core_lib')) {
        $corePath = realpath(__DIR__ . '/../core_lib');
    } else {
        $corePath = $candidate; // will be created later
    }
}

if (!is_dir($corePath) || !file_exists($corePath . '/init.php')) {
    log_error("Core not found at: $corePath");
    log_info("Set APIDCMS_CORE env var or place core_lib/ next to install.php");
    log_info("Example: export APIDCMS_CORE=/var/www/apidcms-core/www");
    exit(1);
}

$corePath = rtrim($corePath, '/');
echo "  Core: $corePath\n";

// Проверяем vendor
if (!is_dir($corePath . '/vendor')) {
    log_warn("Vendor not found — need composer install");
}

// === Фаза 3: Структура папок ===

log_step("3/6: Directory structure");

ensure_dir($rootPath . '/admin/storage/database');
ensure_dir($rootPath . '/storage/cache/twig');
ensure_dir($rootPath . '/storage/uploads');
ensure_dir($rootPath . '/storage/logs');

// .gitkeep в uploads и cache
foreach (['/storage/uploads/.gitkeep', '/storage/logs/.gitkeep'] as $gk) {
    $gkPath = $rootPath . $gk;
    if (!file_exists($gkPath)) {
        file_put_contents($gkPath, '');
    }
}

// === Фаза 4: Конфиги ===

log_step("4/6: Configuration files");

// admin/config/config.php
$adminConfig = "<?php
/**
 * admin/config/config.php — project configuration
 *
 * Merged over core_lib/admin/config/config.php on runtime.
 * Change passwords and keys here.
 */

if (php_sapi_name() === 'cli') {
    // CLI ok
} elseif (isset(\$_SERVER['HTTP_HOST'])) {
    if (strpos(\$_SERVER['SCRIPT_FILENAME'], 'config.php') !== false) {
        header('HTTP/1.0 403 Forbidden');
        die('Direct access not allowed.');
    }
}

return [
    'security' => [
        'admin_username' => 'admin',
        'admin_password' => 'admin', // Change after install!
    ],
    'ai' => [
        'api_key' => '',
        'model' => 'deepseek-chat',
    ],
];
";

// front/config/config.php
$frontConfig = "<?php
/**
 * front/config/config.php — frontend configuration (project)
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/../..'));
    define('FRONT_PATH', ROOT_PATH . '/front');
    define('FRONT_APP_PATH', FRONT_PATH . '/app');
    define('PUBLIC_PATH', ROOT_PATH . '/public');
    define('STORAGE_PATH', ROOT_PATH . '/storage');
}

return [
    'database' => [
        'path' => ROOT_PATH . '/admin/storage/database/',
        'file' => 'cms.db',
        'full_path' => ROOT_PATH . '/admin/storage/database/cms.db'
    ],
    'paths' => [
        'root' => ROOT_PATH,
        'front' => FRONT_PATH,
        'front_app' => FRONT_APP_PATH,
        'public' => PUBLIC_PATH,
        'storage' => STORAGE_PATH,
        'admin' => ROOT_PATH . '/admin'
    ],
    'twig' => [
        'cache' => STORAGE_PATH . '/cache/twig',
        'auto_reload' => true
    ]
];
";

write_default_config($rootPath . '/admin/config/config.php', $adminConfig);
write_default_config($rootPath . '/front/config/config.php', $frontConfig);

// .htaccess — копируем из ядра, если есть
$htaccessDefault = "RewriteEngine On\nRewriteRule ^static/ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)\$ index.php [QSA,L]\nOptions -Indexes\n";
$htPath = $rootPath . '/.htaccess';
if (!file_exists($htPath)) {
    // try copy from core
    $coreHt = $corePath . '/.htaccess';
    if (file_exists($coreHt)) {
        copy($coreHt, $htPath);
        log_ok("Copied .htaccess from core");
    } else {
        file_put_contents($htPath, $htaccessDefault);
        log_ok("Created default .htaccess");
    }
} else {
    log_ok("Already exists: .htaccess");
}

// === 4b: index.php, если нет ===
$indexPath = $rootPath . '/index.php';
if (!file_exists($indexPath)) {
    $indexContent = "<?php
/**
 * Entry point for API CMS project
 */

define('PROJECT_ROOT', __DIR__);

if (getenv('APIDCMS_CORE')) {
    \$corePath = getenv('APIDCMS_CORE');
} else {
    \$corePath = __DIR__ . '/core_lib';
}

require \$corePath . '/init.php';
";
    file_put_contents($indexPath, $indexContent);
    log_ok("Created index.php");
} else {
    log_ok("Already exists: index.php");
}

// === Фаза 5: Composer install ===

log_step("5/6: Dependencies");

if (!is_dir($corePath . '/vendor')) {
    if (!run_composer_install($corePath)) {
        log_warn("Composer install skipped or failed. Run manually:");
        echo "       cd $corePath && composer install\n";
    }
} else {
    log_ok("Vendor already exists in core");
}

// === Фаза 6: Инициализация БД ===

log_step("6/6: Database initialization");

$dbFile = $rootPath . '/admin/storage/database/cms.db';
if (file_exists($dbFile)) {
    // Попросим подтверждение
    if ($isAuto) {
        log_warn("Database file exists, re-initializing...");
    } else {
        echo "  ⚠️  Database already exists at: $dbFile\n";
        echo "     Re-initialize? [y/N] ";
        $input = trim(fgets(STDIN));
        if (strtolower($input) !== 'y') {
            log_info("Skipping DB init");
            goto summary;
        }
    }
}

$initScript = $rootPath . '/init_system_tables.php';
if (!file_exists($initScript)) {
    // try core
    $coreInit = $corePath . '/init_system_tables.php';
    if (file_exists($coreInit)) {
        $initScript = $coreInit;
    }
}

if (file_exists($initScript)) {
    echo "  → Running init_system_tables.php...\n";
    require $initScript;
    log_ok("Database initialized at admin/storage/database/cms.db");
} else {
    log_error("init_system_tables.php not found in project or core");
    log_info("You can initialize manually: php init_system_tables.php");
}

// === Финал ===

summary:

echo "\n══════════════════════════════════════════\n";
echo "  ✅ Installation complete\n";
echo "══════════════════════════════════════════\n\n";
echo "  Site:     php -S localhost:8000 -t " . $rootPath . "\n";
echo "  Admin:    http://localhost:8000/admin\n";
echo "  Login:    admin / admin\n";
echo "\n  📝 IMPORTANT: Change the admin password after first login!\n";
echo "  📝 Configure AI API key in Settings > AI or edit admin/config/config.php\n";
echo "\n";
