<?php
/**
 * Базовый контроллер с поддержкой Twig
 * 
 * Содержит общую логику для всех контроллеров
 */

namespace Admin;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class BaseController {
    protected $app;
    protected $db;
    protected $twig;
    
    /**
     * Конструктор базового контроллера
     * 
     * @param mixed $app Экземпляр приложения
     */
    public function __construct($app) {
        $this->app = $app;
        $this->db = $app->getDatabase();
        $this->initTwig();
    }
    
    /**
     * Инициализация Twig
     */
    private function initTwig() {
        $config = $this->app->getConfig();
        
        // Создаем папку для кэша, если её нет
        $cachePath = $config['paths']['storage'] . '/cache/twig_admin';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        
        $loader = new FilesystemLoader($config['paths']['admin_app'] . '/views');
        $this->twig = new Environment($loader, [
            'cache' => $cachePath,
            'auto_reload' => true,
            'debug' => true
        ]);
        
        // Добавляем функцию для генерации URL с префиксом /admin
        $this->twig->addFunction(new TwigFunction('admin_url', [$this, 'generateAdminUrl']));
        
        // Добавляем функцию для работы с массивами
        $this->twig->addFunction(new TwigFunction('range', 'range'));
        $this->twig->addFilter(new \Twig\TwigFilter('json_decode', function($str) { return ($str === null ? [] : json_decode($str, true)); }));
        // +++ ДОБАВЛЯЕМ НАСЛЕДОВАНИЕ ОТ НОВОГО БАЗОВОГО ШАБЛОНА +++
        $this->twig->addGlobal('base_template', 'base.html.twig');
    }
    
    /**
     * Генерация URL для админки с префиксом /admin
     */
    public function generateAdminUrl($path = '') {
        return '/admin/' . ltrim($path, '/');
    }
    
    /**
     * Отображение шаблона
     * 
     * @param string $templateName Имя файла шаблона (без расширения .html.twig)
     * @param array $data Данные для передачи в шаблон
     */
    protected function render($templateName, $data = []) {
        // Определяем текущий раздел для подсветки меню
        $currentSection = $this->getCurrentSection();
        
        // Получаем список таблиц для меню
        $tables = $this->db->getTables();
        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $tablesInfo[] = [
                'name' => $tableName,
                'columns' => count($this->db->getTableStructure($tableName))
            ];
        }
        
        // Получаем favicon
        $favicon = '';
        try {
            $result = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_favicon'")->fetch();
            if ($result) {
                $favicon = $result['setting_value'];
            }
        } catch (\Exception $e) {
            // ignore
        }

        // Добавляем общие данные для всех шаблонов
        $globalData = [
            'total_unread' => $this->getUnreadNotificationsCount(),
            'current_section' => $currentSection,
            'tables' => $tablesInfo,
            'flash' => $this->getFlash(),
            '_GET' => $_GET,
            'site_favicon' => $favicon
        ];
        
        // Объединяем с переданными данными
        $templateData = array_merge($globalData, $data);
        
        // +++ ДОБАВЛЯЕМ РАСШИРЕНИЕ .html.twig ЕСЛИ ЕГО НЕТ +++
        if (!preg_match('/\.html\.twig$/', $templateName)) {
            $templateName .= '.html.twig';
        }
        
        $content = $this->twig->render($templateName, $templateData);
        echo $content;
    }

    /**
     * Определяет текущий раздел для подсветки меню
     * 
     * @return string Идентификатор раздела
     */
    private function getCurrentSection() {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        
        // Главный роутер обрезает /admin/ из REQUEST_URI,
        // поэтому проверяем без префикса /admin/
        if (strpos($path, '/templates') !== false) {
            return 'templates';
        } elseif (strpos($path, '/table/') !== false || 
                strpos($path, '/create-table') !== false ||
                strpos($path, '/store-table') !== false) {
            return 'tables';
        } elseif (strpos($path, '/filemanager') !== false) {
            return 'filemanager';
        } elseif (strpos($path, '/notifications') !== false) {
            return 'notifications';
        } elseif (strpos($path, '/plugins') !== false) {
            return 'plugins';
        } elseif (strpos($path, '/stats') !== false) {
            return 'stats';
        } elseif (strpos($path, '/cache') !== false) {
            return 'cache';
                } elseif (strpos($path, '/design') !== false) {
            return 'design';
        } elseif (strpos($path, '/settings') !== false) {
            return 'settings';
        } else {
            return 'home';
        }
    }
    
    /**
     * Редирект на указанный URL
     * 
     * @param string $url URL для перенаправления
     * @param bool $permanent Постоянное перенаправление (301)
     */
    protected function redirect($url, $permanent = false) {
        if ($permanent) {
            header('HTTP/1.1 301 Moved Permanently');
        }
        
        // Добавляем префикс /admin если его нет
        if (strpos($url, '/admin') !== 0 && strpos($url, 'http') !== 0) {
            $url = '/admin' . $url;
        }
        
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Установка флеш-сообщения
     * 
     * @param string $type Тип сообщения (success, error, warning, info)
     * @param string $message Текст сообщения
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
     * Получение флеш-сообщения
     * 
     * @return array|null Массив с сообщением или null
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
     * Получить количество непрочитанных уведомлений
     */
    protected function getUnreadNotificationsCount() {
        $count = 0;
        
        try {
            // Получаем все таблицы форм
            $forms = $this->db->query(
                "SELECT source_table FROM navigation WHERE page_type = 'form' AND status = 'active'"
            )->fetchAll();
            
            foreach ($forms as $form) {
                $tableName = $form['source_table'];
                
                if ($this->db->tableExists($tableName)) {
                    // Проверяем наличие поля read_status
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
                        // Если нет поля статуса, считаем все записи непрочитанными
                        $unread = $this->db->query("SELECT COUNT(*) as count FROM {$tableName}")->fetch()['count'];
                    }
                    
                    $count += $unread;
                }
            }
        } catch (\Exception $e) {
            // В случае ошибки возвращаем 0
            error_log("Ошибка при подсчете уведомлений: " . $e->getMessage());
        }
        
        return $count;
    }

    /**
     * Генерация URL для статических файлов админки
     */
    protected function admin_asset($path) {
        $baseUrl = $this->app->getConfig()['paths']['admin'] ?? '';
        return $baseUrl . '/public/' . ltrim($path, '/');
    }

}
?>