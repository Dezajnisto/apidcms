<?php
/**
 * apidcms — bootstrap
 *
 * Initializes paths, autoloader, sessions, config loader, plugins.
 * Called from www/index.php after PROJECT_ROOT and CORE_PATH are defined.
 *
 * Constants available after bootstrap:
 *   CORE_PATH, PROJECT_ROOT, ROOT_PATH, STORAGE_PATH, CONFIG_PATH,
 *   THEMES_PATH, PLUGINS_PATH, PUBLIC_PATH,
 *   CORE_VIEWS_ADMIN, CORE_VIEWS_FRONT
 */

// === Composer autoload (Twig, Parsedown...) ===
require_once CORE_PATH . '/vendor/autoload.php';

// === Manual class loads (before autoloader) ===
require_once CORE_PATH . '/src/Core/Parsedown.php';

// === Path constants ===
define('ROOT_PATH', PROJECT_ROOT);
define('STORAGE_PATH', PROJECT_ROOT . '/storage');
define('CONFIG_PATH', PROJECT_ROOT . '/config');
define('THEMES_PATH', PROJECT_ROOT . '/themes');
define('PLUGINS_PATH', PROJECT_ROOT . '/plugins');
define('PUBLIC_PATH', PROJECT_ROOT . '/www');

define('CORE_VIEWS_ADMIN', CORE_PATH . '/views/admin');
define('CORE_VIEWS_FRONT', CORE_PATH . '/views/front');

// === PSR-4 Autoloader ===
spl_autoload_register(function ($class) {
    // Core\*      → src/Core/
    // Admin\*     → src/Admin/
    // Front\*     → src/Front/
    $prefixes = ['Core' => 'Core', 'Admin' => 'Admin', 'Front' => 'Front'];

    foreach ($prefixes as $ns => $dir) {
        $prefix = $ns . '\\';
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = CORE_PATH . '/src/' . $dir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// === Sessions ===
$sessionPath = PROJECT_ROOT . '/tmp/php/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);

// === Config loader ===
function load_config(string $name): array {
    $coreFile    = CORE_PATH . '/config/' . $name . '.php';
    $projectFile = CONFIG_PATH . '/' . $name . '.php';

    $core    = file_exists($coreFile)    ? require $coreFile    : [];
    $project = file_exists($projectFile) ? require $projectFile : [];

    return array_replace_recursive($core, $project);
}

// === Plugins ===
$pm = \Core\PluginManager::getInstance(PLUGINS_PATH);
$pm->loadPlugins();
$pm->doAction('core.init');
