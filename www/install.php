<?php
/**
 * install.php — apidcms installer
 *
 * CLI: php install.php [--auto]
 * Web: open /install.php in browser
 */
define('INSTALLER_VERSION', '1.0.0');
$isCLI = (php_sapi_name() === 'cli');

function i_log(string $msg): void { echo "  $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_ok(string $msg): void { echo "  ✅ $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_err(string $msg): void { echo "  ❌ $msg\n"; if (ob_get_level()) { ob_flush(); flush(); } }
function i_step(string $t): void { echo "\n━━━ $t ━━━\n"; if (ob_get_level()) { ob_flush(); flush(); } }

function check_env(): bool {
    if (PHP_VERSION_ID < 80100) { i_err("PHP 8.1+ required, got ".PHP_VERSION); return false; }
    i_ok("PHP ".PHP_VERSION);
    $ok = true;
    foreach (['pdo_sqlite','sqlite3','curl','mbstring','json','gd','openssl','fileinfo','zip','xml','session'] as $e) {
        if (extension_loaded($e)) i_ok($e); else { i_err("$e missing"); $ok = false; }
    }
    return $ok;
}

function find_composer(): ?string {
    $w = trim((string)shell_exec('which composer 2>/dev/null'));
    if ($w && is_executable($w)) return $w;
    foreach (['composer.phar',__DIR__.'/composer.phar'] as $p) if (is_file($p)) return realpath($p);
    return null;
}

function run_install(array $opts): bool {
    $root = realpath(__DIR__);
    $core = getenv('APIDCMS_CORE') ?: (is_dir($root.'/core_lib') ? realpath($root.'/core_lib') : (is_dir(dirname($root).'/core_lib') ? realpath(dirname($root).'/core_lib') : $root));
    $core = rtrim($core, '/');
    i_step("Environment"); if (!check_env()) return false;
    i_step("Structure");
    foreach (['/admin/config','/admin/storage/database','/storage/cache/twig','/storage/css','/storage/uploads','/front/config','/storage/logs','/tmp/php/sessions'] as $d) {
        $p = $root.$d; if (!is_dir($p)) @mkdir($p, 0755, true);
    }
    @file_put_contents($root.'/storage/uploads/.gitkeep',''); @file_put_contents($root.'/storage/logs/.gitkeep','');

    // .htaccess — main routing (Apache)
    $htaccessPath = $root.'/.htaccess';
    if (!file_exists($htaccessPath)) {
        $htaccess = base64_decode('UmV3cml0ZUVuZ2luZSBPbgoKIyBTMyBQcm94eQpSZXdyaXRlUnVsZSBeczMtcHJveHkvKC4qKSQgL2Zyb250L3MzLXByb3h5LnBocD9wYXRoPSQxIFtMLFFTQV0KCiMgQWRtaW4g4oCUIGFsd2F5cyByb3V0ZSB0aHJvdWdoIGluZGV4LnBocApSZXdyaXRlUnVsZSBeYWRtaW4oLiopJCBpbmRleC5waHAgW1FTQSxMXQoKIyBCbG9jayBkaXJlY3QgYWNjZXNzIHRvIHRtcC8gKHNlc3Npb25zLCBjYWNoZSkKUmV3cml0ZVJ1bGUgXnRtcC8gLSBbRixMXQoKIyBBbGwgb3RoZXIgcmVxdWVzdHMgdG8gaW5kZXgucGhwCiMgKGluaXQucGhwIHNlcnZlcyBzdGF0aWMgZmlsZXMgZnJvbSBjb3JlX2xpYi9zdGF0aWMvIOKAlCBubyBzeW1saW5rIG5lZWRlZCkKUmV3cml0ZUNvbmQgJXtSRVFVRVNUX0ZJTEVOQU1FfSAhLWYKUmV3cml0ZUNvbmQgJXtSRVFVRVNUX0ZJTEVOQU1FfSAhLWQKUmV3cml0ZVJ1bGUgXiguKikkIGluZGV4LnBocCBbUVNBLExdCgojIFByb3RlY3Qgc2Vuc2l0aXZlIGZpbGVzCjxGaWxlcyB+ICJcLihlbnZ8anNvbnxjb25maWdcLmpzfG1kfGdpdGlnbm9yZXxnaXRhdHRyaWJ1dGVzfGxvY2t8c3FsKSQiPgogICAgT3JkZXIgYWxsb3csZGVueQogICAgRGVueSBmcm9tIGFsbAo8L0ZpbGVzPgoKPEZpbGVzIH4gIihjb21wb3NlclwuanNvbnxjb21wb3NlclwubG9ja3xwYWNrYWdlXC5qc29ufHBhY2thZ2UtbG9ja1wuanNvbikiPgogICAgT3JkZXIgYWxsb3csZGVueQogICAgRGVueSBmcm9tIGFsbAo8L0ZpbGVzPgoKIyBEaXNhYmxlIGRpcmVjdG9yeSBsaXN0aW5nCk9wdGlvbnMgLUluZGV4ZXMKCiMgU3RhdGljIGZpbGUgY2FjaGluZwo8SWZNb2R1bGUgbW9kX2V4cGlyZXMuYz4KICAgIEV4cGlyZXNBY3RpdmUgT24KICAgIEV4cGlyZXNCeVR5cGUgdGV4dC9jc3MgImFjY2VzcyBwbHVzIDEgbW9udGgiCiAgICBFeHBpcmVzQnlUeXBlIGFwcGxpY2F0aW9uL2phdmFzY3JpcHQgImFjY2VzcyBwbHVzIDEgbW9udGgiCiAgICBFeHBpcmVzQnlUeXBlIGltYWdlL2pwZWcgImFjY2VzcyBwbHVzIDEgbW9udGgiCiAgICBFeHBpcmVzQnlUeXBlIGltYWdlL3BuZyAiYWNjZXNzIHBsdXMgMSBtb250aCIKICAgIEV4cGlyZXNCeVR5cGUgaW1hZ2UvZ2lmICJhY2Nlc3MgcGx1cyAxIG1vbnRoIgogICAgRXhwaXJlc0J5VHlwZSBpbWFnZS9zdmcreG1sICJhY2Nlc3MgcGx1cyAxIG1vbnRoIgo8L0lmTW9kdWxlPgo=');
        file_put_contents($htaccessPath, $htaccess);
        i_ok(".htaccess created");
    } else {
        i_ok(".htaccess exists");
    }

    // admin/index.php — fallback entry point
    $adminIndexPath = $root.'/admin/index.php';
    if (!file_exists($adminIndexPath)) {
        $adminIndex = base64_decode('PD9waHAKLyoqCiAqIGFwaWRjbXMgYWRtaW4gZW50cnkgcG9pbnQgKGZhbGxiYWNrKQogKgogKiBVc2VkIHdoZW4gd2ViIHNlcnZlciBkb2Vzbid0IHByb2Nlc3MgLmh0YWNjZXNzIHJld3JpdGVzLgogKiBSb3V0ZXMgYWxsIC9hZG1pbiByZXF1ZXN0cyB0aHJvdWdoIHRoZSBjb3JlLgogKi8KZGVmaW5lKCdQUk9KRUNUX1JPT1QnLCBfX0RJUl9fIC4gJy8uLicpOwpyZXF1aXJlIF9fRElSX18gLiAnLy4uL2NvcmVfbGliL2luaXQucGhwJzsK');
        file_put_contents($adminIndexPath, $adminIndex);
        i_ok("admin/index.php created");
    } else {
        i_ok("admin/index.php exists");
    }
    i_ok("Directories created");

    // Default custom.css — user-editable via admin panel
    $customCssPath = $root . '/storage/css/custom.css';
    if (!file_exists($customCssPath)) {
        $customCss = base64_decode('LyogZGVmYXVsdCBjdXN0b20uY3NzIOKAlCDRg9C/0YDQsNCy0LvRj9C10YLRgdGPINGH0LXRgNC10Lcg0L/QsNC90LXQu9GMINCw0LTQvNC40L3QuNGB0YLRgNCw0YLQvtGA0LAKICog0JjQt9C80LXQvdGP0LnRgtC1LCDRgdC+0YXRgNCw0L3Rj9C50YLQtSDQvtGA0LjQs9C40L3QsNC7INC4INC90LUg0YPRgtGA0LDRgtC40YLQtSDQv9GA0Lgg0L7QsdC90L7QstC70LXQvdC40Lgg0Y/QtNGA0LAKICovCgovKiDQntGB0L3QvtCy0L3Ri9C1INC+0YLRgdGC0YPQv9GLICovCm1haW4gewogICAgcGFkZGluZzogMCAxcmVtOwp9CgovKiDQotC40L/QvtCz0YDQsNGE0LjQutCwICovCmFydGljbGUgewogICAgbWF4LXdpZHRoOiA4MDBweDsKICAgIG1hcmdpbjogMCBhdXRvOwogICAgcGFkZGluZzogMnJlbSAxcmVtOwp9CgovKiDQkdC70L7QsyDQuCDQv9GD0L3QutGC0Ysg0YHQv9C40YHQutCwICovCi5jb250ZW50LWxpc3QgewogICAgbWF4LXdpZHRoOiA4MDBweDsKICAgIG1hcmdpbjogMCBhdXRvOwogICAgcGFkZGluZzogMXJlbTsKfQouY29udGVudC1pdGVtIHsKICAgIG1hcmdpbi1ib3R0b206IDJyZW07CiAgICBwYWRkaW5nOiAxLjVyZW07CiAgICBiYWNrZ3JvdW5kOiAjZmZmOwogICAgYm9yZGVyLXJhZGl1czogOHB4OwogICAgYm94LXNoYWRvdzogMCAxcHggM3B4IHJnYmEoMCwwLDAsMC4wOCk7Cn0KLml0ZW0tdGl0bGUgewogICAgZm9udC1zaXplOiAxLjI1cmVtOwogICAgbWFyZ2luOiAwIDAgMC43NXJlbTsKfQouaXRlbS10aXRsZSBhIHsKICAgIHRleHQtZGVjb3JhdGlvbjogbm9uZTsKICAgIGNvbG9yOiAjMWExYTFhOwp9Ci5pdGVtLW1ldGEgewogICAgY29sb3I6ICM4ODg7CiAgICBmb250LXNpemU6IDAuOXJlbTsKICAgIG1hcmdpbi1ib3R0b206IDAuNzVyZW07Cn0KLml0ZW0tZXhjZXJwdCB7CiAgICBjb2xvcjogIzY2NjsKICAgIGxpbmUtaGVpZ2h0OiAxLjY7Cn0KCi8qINCn0YLQtdC90LjQtSDRgdC+0LPQu9Cw0YjQtdC90LjQuSAqLwouYnRuIHsKICAgIGRpc3BsYXk6IGlubGluZS1ibG9jazsKICAgIHBhZGRpbmc6IDhweCAyMHB4OwogICAgYmFja2dyb3VuZDogIzhiNWNmNjsKICAgIGNvbG9yOiAjZmZmOwogICAgYm9yZGVyLXJhZGl1czogNnB4OwogICAgdGV4dC1kZWNvcmF0aW9uOiBub25lOwogICAgZm9udC13ZWlnaHQ6IDYwMDsKfQouYnRuOmhvdmVyIHsKICAgIGJhY2tncm91bmQ6ICM3YzNhZWQ7Cn0K');
        file_put_contents($customCssPath, $customCss);
        i_ok("custom.css created (editable in admin)");
    } else {
        i_ok("custom.css exists");
    }

    // Copy default templates from core to project (editable by user)
    $templatesSrc = $core . '/front/app/views';
    $templatesDst = $root . '/front/app/views';
    if (is_dir($templatesSrc) && !is_dir($templatesDst)) {
        mkdir($templatesDst, 0755, true);
        $copied = 0;
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($templatesSrc, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $target = $templatesDst . '/' . $item->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0755);
            } else {
                copy($item, $target);
                $copied++;
            }
        }
        i_ok("Templates copied to project ($copied files)");
    }

    i_step("Config");
    $adminConfigPath = $root.'/admin/config/config.php';
    if (file_exists($adminConfigPath)) {
        $existing = include $adminConfigPath;
        if (!is_array($existing)) $existing = [];
        $existing['security'] = [
            'admin_username' => $opts['username'],
            'admin_password' => $opts['password'],
            'session_timeout' => $existing['security']['session_timeout'] ?? 3600,
        ];
        $existing['ai'] = [
            'api_key' => $opts['api_key'],
            'model' => $opts['model'],
        ];
        file_put_contents($adminConfigPath, "<?php\nreturn ".var_export($existing, true).";\n");
        i_ok("admin/config/config.php updated");
    } else {
        file_put_contents($adminConfigPath, "<?php\nreturn ['security'=>['admin_username'=>'".addslashes($opts['username'])."','admin_password'=>'".addslashes($opts['password'])."','session_timeout'=>3600],'ai'=>['api_key'=>'".addslashes($opts['api_key'])."','model'=>'".addslashes($opts['model'])."']];\n");
        i_ok("admin/config/config.php created");
    }
    if (!file_exists($root.'/front/config/config.php')) {
        $c="<?php\nif(!defined('ROOT_PATH')){define('ROOT_PATH',realpath(__DIR__.'/../..'));define('FRONT_PATH',ROOT_PATH.'/front');define('FRONT_APP_PATH',FRONT_PATH.'/app');define('PUBLIC_PATH',ROOT_PATH.'/public');define('STORAGE_PATH',ROOT_PATH.'/storage');define('ADMIN_PATH',ROOT_PATH.'/admin');}\nreturn ['database'=>['path'=>ROOT_PATH.'/admin/storage/database/','file'=>'cms.db','full_path'=>ROOT_PATH.'/admin/storage/database/cms.db'],'paths'=>['root'=>ROOT_PATH,'front'=>FRONT_PATH,'front_app'=>FRONT_APP_PATH,'public'=>PUBLIC_PATH,'storage'=>STORAGE_PATH,'admin'=>ADMIN_PATH],'twig'=>['cache'=>STORAGE_PATH.'/cache/twig','auto_reload'=>true]];\n";
        file_put_contents($root.'/front/config/config.php',$c);
        i_ok("front/config/config.php created");
    } else i_ok("front/config/config.php exists");
    i_step("Dependencies");
    if (!is_dir($core.'/vendor')) {
        $c = find_composer();
        if ($c) { passthru(escapeshellcmd($c).' install --no-dev --no-interaction -d '.escapeshellarg($core).' 2>&1',$rc); if ($rc===0) i_ok("Composer done"); else i_err("Composer failed"); }
        else { i_err("Composer not found"); return false; }
    } else i_ok("Vendor exists");
    i_step("Database");
    $init = file_exists($root.'/init_system_tables.php') ? $root.'/init_system_tables.php' : $core.'/init_system_tables.php';
    if (file_exists($init)) { define('INSTALL_MODE',true); require $init; i_ok("Database initialized"); }
    else { i_err("init_system_tables.php not found"); return false; }
    if ($opts['seed']) add_seed($root);
    return true;
}

function add_seed(string $root): void {
    i_step("Sample content");
    $dbf = $root.'/admin/storage/database/cms.db';
    try {
        $db = new PDO("sqlite:$dbf"); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000");

        // Home page
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(1,'Home','home','<section style=\"padding:4rem 0;text-align:center\"><h1 style=\"font-size:2.5rem;margin-bottom:1rem\">Welcome to apidcms</h1><p style=\"font-size:1.25rem;color:#6b7280;margin-bottom:2rem\">A lightweight PHP CMS. Create pages, blog posts, forms, and more.</p><a href=\"/blog\" style=\"display:inline-block;padding:12px 32px;background:#8b5cf6;color:#fff;border-radius:8px;text-decoration:none;font-weight:600\">Read the blog</a></section>','active')");

        // About page
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(2,'About us','about','<h1>About apidcms</h1><p>apidcms is a minimalist content management system built with PHP 8.1+ and SQLite.</p><h2>Features</h2><ul><li>No database server needed — SQLite</li><li>Twig templates</li><li>Built-in blog, forms, navigation</li><li>Plugin system</li><li>AI-powered pages</li></ul><p><a href=\"/\">Back to home</a></p>','active')");

        // Contact page
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(3,'Contact','contact','<h1>Contact us</h1><p>Have questions? Reach out via email.</p><p>Email: <strong>hello@example.com</strong></p><p><a href=\"/\">Back to home</a></p>','active')");

        // Blog posts
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(4,'Getting started','getting-started','<h2>Installation</h2><p>Getting started with apidcms takes only a few minutes. Clone the repository, upload to your server, and run install.php.</p><h2>First steps</h2><p>After installation, log in to the admin panel at /admin. From there you can create pages, customize navigation, and manage content.</p><h2>Creating your first page</h2><p>Go to Pages in the admin panel, click New Page, write your content, and publish.</p>','active')");
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(5,'Customizing your site','customizing','<h2>Templates</h2><p>apidcms uses Twig templates. You can override any template by creating your own in the admin panel or editing files directly.</p><h2>CSS Styling</h2><p>Add your custom CSS in the admin panel under Design. Changes take effect immediately after clearing the cache.</p><h2>Navigation</h2><p>Manage your site menu in the admin panel. Add links, reorder items, and create dropdown menus.</p>','active')");
        $db->exec("INSERT OR IGNORE INTO pages(id,title,slug,content,status) VALUES(6,'Using plugins','plugins','<h2>What are plugins?</h2><p>Plugins extend apidcms functionality without modifying the core. Each plugin lives in its own directory under /plugins.</p><h2>Available plugins</h2><p>Check the admin panel under Plugins to see what is installed and active.</p><h2>Creating your own</h2><p>Plugin development is straightforward: a plugin.json manifest, an init.php bootstrap, and optional templates.</p>','active')");

        // Navigation — location='main' (what getNavigation() expects)
        $db->exec("UPDATE navigation SET title='Home', url='home', page_id=1, page_type='page', source_table='', location='main', menu_order=1, status='active' WHERE id=1");
        $db->exec("INSERT OR IGNORE INTO navigation(id,title,url,page_id,page_type,source_table,location,menu_order,status) VALUES(2,'About','about',2,'page','','main',2,'active')");
        $db->exec("INSERT OR IGNORE INTO navigation(id,title,url,page_id,page_type,source_table,location,menu_order,status) VALUES(3,'Blog','blog',null,'dynamic','pages','main',3,'active')");
        $db->exec("INSERT OR IGNORE INTO navigation(id,title,url,page_id,page_type,source_table,location,menu_order,status) VALUES(4,'Contact','contact',3,'page','','main',4,'active')");

        i_ok("Sample site: Home, About, Blog (3 posts), Contact");
    } catch (\Exception $e) { i_err("Seed failed: ".$e->getMessage()); }
}


// CLI
if ($isCLI) {
    echo "\napidcms Installer v".INSTALLER_VERSION."\n\n";
    $ok = run_install(['username'=>'admin','password'=>'admin','api_key'=>'','model'=>'deepseek-chat','seed'=>false]);
    echo "\n".($ok ? "Done. Admin: /admin\nDelete install.php: rm www/install.php\n" : "Failed\n");
    exit($ok ? 0 : 1);
}

// Web POST: run
if (($_POST['step'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    while (ob_get_level()) ob_end_flush();
    $opts = ['username'=>$_POST['username']??'admin','password'=>(strlen($_POST['password']??'')>=4)?$_POST['password']:'admin','api_key'=>$_POST['api_key']??'','model'=>$_POST['model']??'deepseek-chat','seed'=>!empty($_POST['seed'])];
    echo "apidcms Installer v".INSTALLER_VERSION."\n\n";
    $ok = run_install($opts);
    if ($ok) { echo "\n=== DONE ===\nSite ready. Admin: /admin (".$opts['username'].")\nDELETE install.php: rm www/install.php\n"; }
    else echo "\nFAILED\n";
    exit;
}

// Web GET: form
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка apidcms</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#f5f3ff;color:#1e1b4b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#fff;border-radius:20px;padding:48px 44px;max-width:540px;width:100%;box-shadow:0 8px 40px rgba(139,92,246,.12)}
.logo{font-size:24px;font-weight:700;margin-bottom:4px}
.logo span{background:#8b5cf6;color:#fff;font-size:12px;padding:2px 8px;border-radius:12px;margin-left:8px;vertical-align:middle}
h1{font-size:26px;font-weight:700;margin:12px 0 6px}
.sub{color:#6b7280;font-size:15px;line-height:1.6;margin-bottom:28px}
label{display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:#374151}
input[type=text],input[type=password]{width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:10px;font-size:15px;margin-bottom:16px;outline:none;transition:border-color .2s}
input:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.1)}
.row{display:flex;gap:12px}.row>div{flex:1}
.btn{display:inline-flex;align-items:center;gap:6px;background:#8b5cf6;color:#fff;border:none;padding:12px 24px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;width:100%;justify-content:center}
.btn:hover{background:#7c3aed}.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-s{padding:8px 14px;font-size:13px;width:auto}
.btn-gh{background:#f3f4f6;color:#374151}.btn-gh:hover{background:#e5e7eb}
.opt{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;font-size:14px;color:#6b7280}
.opt input[type=checkbox]{width:18px;height:18px;accent-color:#8b5cf6;margin-top:3px}
.progress{display:flex;gap:4px;margin-bottom:28px}
.progress .dot{flex:1;height:4px;background:#e5e7eb;border-radius:2px;transition:background .3s}
.progress .dot.active{background:#8b5cf6}
.hint{font-size:12px;color:#9ca3af;margin-top:-8px;margin-bottom:16px}
.log-box{background:#1e1e2e;color:#cdd6f4;border-radius:12px;padding:20px 24px;font-family:monospace;font-size:13px;line-height:1.7;max-height:300px;overflow-y:auto;display:none;margin-top:20px}
.result{text-align:center;margin-top:20px;display:none}
.result h2{font-size:20px;margin-bottom:8px}
.result p{color:#6b7280;margin-bottom:12px;font-size:14px}
.result code{background:#f3f4f6;padding:3px 8px;border-radius:5px;font-size:13px}
.inp-group{display:flex;gap:8px;margin-bottom:8px}
.inp-group input{margin-bottom:0;flex:1}
</style></head>
<body>
<div class="card">
<div class="logo">apidcms <span>v1.0</span></div>
<h1>Установка</h1>
<p class="sub">Пара минут — и сайт готов к работе.</p>
<div class="progress" id="progress"><div class="dot active"></div><div class="dot"></div><div class="dot"></div></div>
<form id="form" method="post"><input type="hidden" name="step" value="1">
<div id="s1">
<label>Логин администратора</label>
<input type="text" name="username" value="admin" required>
<div class="row"><div><label>Пароль</label><input type="password" name="password" id="pw1" required minlength="4"></div><div><label>Повторите</label><input type="password" id="pw2" required minlength="4"></div></div>
<p class="hint">Минимум 4 символа</p>
<button type="button" class="btn" onclick="next()">Продолжить →</button></div>

<div id="s2" style="display:none">
<div class="opt"><input type="checkbox" name="seed" value="1" id="seedChk"><label for="seedChk" style="margin:0;font-weight:400">Добавить тестовые страницы (Home, About, Contact, Blog)</label></div>
<p class="hint">Примеры страниц и записей. Можно удалить позже.</p>
<div style="display:flex;gap:8px;margin-top:4px"><button type="button" class="btn btn-gh" onclick="prev()">← Назад</button><button type="submit" class="btn" id="goBtn">🚀 Запустить установку</button></div></div>
</form>
<div class="log-box" id="log"></div>
<div class="result" id="result">
<h2>✅ Готово!</h2><p>Сайт установлен.</p>
<p style="color:#ef4444;font-size:14px">⚠️ Удалите install.php:<br><code>rm www/install.php</code></p>
<div style="margin-top:20px;display:flex;gap:8px;justify-content:center">
<a href="./" class="btn btn-gh" style="width:auto;padding:10px 20px">На сайт</a>
<a href="admin" class="btn" style="width:auto;padding:10px 20px">В админку</a></div></div></div>
<script>
var s=1,d=document.querySelectorAll('.progress .dot');
function sh(n){s=n;['s1','s2'].forEach(function(id,i){document.getElementById(id).style.display=(i+1===n)?'block':'none'});d.forEach(function(x,i){x.classList.toggle('active',i<n)})}
function prev(){if(s>1)sh(s-1)}
function next(){if(s===1){var p=document.getElementById('pw1').value;if(p.length<4){alert('4+ symbols');return}if(p!==document.getElementById('pw2').value){alert('Passwords differ');return}}if(s<2)sh(s+1)}
document.getElementById('form').addEventListener('submit',function(e){e.preventDefault();document.getElementById('form').style.display='none';d.forEach(function(x,i){x.classList.toggle('active',i<3)});document.getElementById('goBtn').disabled=true;var l=document.getElementById('log'),p=0;l.style.display='block';var x=new XMLHttpRequest();x.open('POST','',true);x.onreadystatechange=function(){if(x.readyState>=3){var t=x.responseText.substring(p);if(t){l.textContent+=t;l.scrollTop=l.scrollHeight;p=x.responseText.length}}if(x.readyState===4)document.getElementById('result').style.display='block'};x.send(new FormData(this))});
</script></body></html>
