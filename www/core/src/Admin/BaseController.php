<?php
/**
 * Base controller with Twig support and i18n.
 * 
 * Contains common logic for all admin controllers.
 */

namespace Admin;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Admin\Lang;

class BaseController {
    protected $app;
    protected $db;
    protected $twig;
    protected $lang;
    
    /**
     * @param mixed $app Application instance
     */
    public function __construct($app) {
        $this->app = $app;
        $this->db = $app->getDatabase();
        $this->initLang();
        $this->initTwig();
    }

    /**
     * Initialize i18n from system_settings.
     */
    private function initLang() {
        $locale = 'ru';
        try {
            $row = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_language'")->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                $locale = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // DB not available yet — keep default 'ru'
        }
        $this->lang = Lang::getInstance($locale);
    }
    
    /**
     * Initialize Twig
     */
    private function initTwig() {
        $config = $this->app->getConfig();
        
        // Create cache folder if not exists
        $cachePath = $config['paths']['storage'] . '/cache/twig_admin';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        
        $loader = new FilesystemLoader($config['paths']['admin_views']);
        $this->twig = new Environment($loader, [
            'cache' => $cachePath,
            'auto_reload' => true,
            'debug' => true
        ]);
        
        // URL helper: /admin prefix
        $this->twig->addFunction(new TwigFunction('admin_url', [$this, 'generateAdminUrl']));
        
        // Range helper
        $this->twig->addFunction(new TwigFunction('range', 'range'));
        $this->twig->addFilter(new \Twig\TwigFilter('json_decode', function($str) { return ($str === null ? [] : json_decode($str, true)); }));

        // i18n: {{ lang('key') }} in templates
        $this->twig->addFunction(new TwigFunction('lang', [$this->lang, 't']));

        // Base template global
        $this->twig->addGlobal('base_template', 'base.html.twig');

        // Хук: плагин может добавить пути к своим шаблонам и Twig-функции.
        // Без него админ-страницам плагинов негде жить: BaseController создаёт
        // загрузчик только по admin_views ядра.
        try {
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('admin.twig.init', $this, $this->twig);
        } catch (\Throwable $e) {
            // PluginManager не инициализирован — ok
        }
    }
    
    /**
     * Generate admin URL with /admin prefix
     */
    public function generateAdminUrl($path = '') {
        return '/admin/' . ltrim($path, '/');
    }
    
    /**
     * Render template
     */
    protected function render($templateName, $data = []) {
        $currentSection = $this->getCurrentSection();
        
        $favicon = '';
        try {
            $result = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_favicon'")->fetch();
            if ($result) {
                $favicon = $result['setting_value'];
            }
        } catch (\Exception $e) {
            // ignore
        }

        $globalData = [
            'total_unread' => $this->getUnreadNotificationsCount(),
            'current_section' => $currentSection,
            'flash' => $this->getFlash(),
            '_GET' => $_GET,
            'site_favicon' => $favicon,
            'admin_lang' => $this->lang->getLocale(),
            'lang_all' => $this->lang->all(),
            'admin_menu_extra' => $this->getMenuExtra(),
        ];
        
        $templateData = array_merge($globalData, $data);
        
        if (!preg_match('/\.html\.twig$/', $templateName)) {
            $templateName .= '.html.twig';
        }
        
        $content = $this->twig->render($templateName, $templateData);
        echo $content;
    }

    /**
     * Дополнительные пункты бокового меню от плагинов.
     *
     * Фильтр admin.menu был описан в документации и уже использовался
     * плагинами, но в шаблоне не применялся — пункты молча терялись.
     *
     * @return array<int, array{title:string,url:string,icon?:string,section?:string,badge?:int}>
     */
    private function getMenuExtra(): array
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $items = $pm->applyFilters('admin.menu', []);
            if (!is_array($items)) {
                return [];
            }

            // Плагины задают 'order' в манифесте пункта — без сортировки
            // порядок зависел бы от порядка загрузки плагинов.
            usort($items, function ($a, $b) {
                return ((int) ($a['order'] ?? 50)) <=> ((int) ($b['order'] ?? 50));
            });

            return $items;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Determine current section for menu highlighting
     */
    private function getCurrentSection() {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $section = 'home';

        if (strpos($path, '/templates') !== false) {
            $section = 'templates';
        } elseif (strpos($path, '/tables') !== false ||
                strpos($path, '/table/') !== false ||
                strpos($path, '/create-table') !== false ||
                strpos($path, '/store-table') !== false) {
            $section = 'tables';
        } elseif (strpos($path, '/filemanager') !== false) {
            $section = 'filemanager';
        } elseif (strpos($path, '/notifications') !== false) {
            $section = 'notifications';
        } elseif (strpos($path, '/plugins') !== false) {
            $section = 'plugins';
        } elseif (strpos($path, '/stats') !== false) {
            $section = 'stats';
        } elseif (strpos($path, '/cache') !== false) {
            $section = 'cache';
        } elseif (strpos($path, '/design') !== false) {
            $section = 'design';
        } elseif (strpos($path, '/settings') !== false) {
            $section = 'settings';
        }

        // Хук: плагин подсвечивает свой пункт меню (свои URL ядро не знает)
        try {
            $pm = \Core\PluginManager::getInstance();
            $section = $pm->applyFilters('admin.current_section', $section, $path);
        } catch (\Throwable $e) {
            // ok
        }

        return $section;
    }
    
    /**
     * Redirect to URL
     */
    protected function redirect($url, $permanent = false) {
        if ($permanent) {
            header('HTTP/1.1 301 Moved Permanently');
        }
        
        if (strpos($url, '/admin') !== 0 && strpos($url, 'http') !== 0) {
            $url = '/admin' . $url;
        }
        
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Set flash message
     */
    protected function setFlash($type, $message) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Get flash message
     */
    protected function getFlash() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }

    /**
     * Get unread notifications count
     */
    protected function getUnreadNotificationsCount() {
        $count = 0;
        
        try {
            // Use DISTINCT to avoid double-counting when multiple forms share the same table
            $forms = $this->db->query(
                "SELECT DISTINCT source_table FROM forms WHERE status = 'active'"
            )->fetchAll();
            
            foreach ($forms as $form) {
                $tableName = $form['source_table'];
                
                if ($this->db->tableExists($tableName)) {
                    $structure = $this->db->getTableStructure($tableName);
                    $hasReadStatus = false;
                    
                    foreach ($structure as $column) {
                        if ($column['name'] === 'read_status') {
                            $hasReadStatus = true;
                            break;
                        }
                    }
                    
                    if ($hasReadStatus) {
                        $unread = $this->db->query(
                            "SELECT COUNT(*) as count FROM {$tableName} WHERE read_status = 'unread' OR read_status IS NULL"
                        )->fetch()['count'];
                    } else {
                        $unread = $this->db->query("SELECT COUNT(*) as count FROM {$tableName}")->fetch()['count'];
                    }
                    
                    $count += $unread;
                }
            }
        } catch (\Exception $e) {
            error_log("Error counting notifications: " . $e->getMessage());
        }
        
        return $count;
    }

    /**
     * Admin static asset URL
     */
    protected function admin_asset($path) {
        $baseUrl = $this->app->getConfig()['paths']['admin'] ?? '';
        return $baseUrl . '/public/' . ltrim($path, '/');
    }

}
