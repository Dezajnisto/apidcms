<?php
namespace Admin;

use Core\Database;
use Admin\Core\AuthMiddleware;

class App {
    private $database;
    private $config;
    private $routes = [];
    private $auth;
    
    /**
     * Конструктор приложения
     * 
     * @param array $config Конфигурация приложения
     */
    public function __construct($config) {
        // Сохраняем конфигурацию
        $this->config = $config;
        
        if (!$this->config || !isset($this->config['database'])) {
            die("Ошибка: Неверная конфигурация приложения");
        }
        
        // Инициализируем базу данных
        $this->database = new Database($this->config['database']);

        // Инициализируем PluginManager и загружаем плагины
        try {
            $pluginsDir = $this->config['paths']['root'] . '/plugins';
            $pm = \Core\PluginManager::getInstance($pluginsDir);
            $pm->loadPlugins($this->database);
        } catch (\Throwable $e) {
            // PluginManager не инициализирован — ok
        }
        
        // Инициализируем аутентификацию
        $this->auth = new AuthMiddleware($this->config);
        
        // Инициализируем базовые маршруты
        $this->initRoutes();
    }
    
    /**
     * Инициализация базовых маршрутов
     */
    private function initRoutes() {
        // Главная страница
        $this->addRoute('/', 'HomeController', 'index');
        
        // Выход из системы
        $this->addRoute('/logout', 'AuthController', 'logout');
        
        // Просмотр таблицы
        $this->addRoute('/table/{table}', 'TableController', 'view');
        
        // Просмотр записи
        $this->addRoute('/table/{table}/id/{id}', 'TableController', 'viewItem');
        
        // CRUD операции для данных
        $this->addRoute('/table/{table}/create', 'TableController', 'createForm');
        $this->addRoute('/table/{table}/store', 'TableController', 'create');
        $this->addRoute('/table/{table}/edit/{id}', 'TableController', 'editForm');
        $this->addRoute('/table/{table}/update/{id}', 'TableController', 'update');
        $this->addRoute('/table/{table}/delete/{id}', 'TableController', 'delete');
        
        // Управление структурой таблиц
        $this->addRoute('/table/{table}/structure', 'TableController', 'structure');
        $this->addRoute('/table/{table}/add-column', 'TableController', 'addColumnForm');
        $this->addRoute('/table/{table}/store-column', 'TableController', 'addColumn');
        $this->addRoute('/table/{table}/delete-column/{column}', 'TableController', 'deleteColumn');
        $this->addRoute('/create-table', 'HomeController', 'createTableForm');
        $this->addRoute('/store-table', 'HomeController', 'createTable');
        $this->addRoute('/table/{table}/delete-table', 'HomeController', 'deleteTable');
        
        // +++ ДОБАВЛЯЕМ НОВЫЕ МАРШРУТЫ ДЛЯ УПРАВЛЕНИЯ ШАБЛОНАМИ +++
        
        // Список всех шаблонов
        $this->addRoute('/templates', 'TemplateController', 'index');
        
        // Создание нового шаблона
        $this->addRoute('/templates/create', 'TemplateController', 'create');
        
        // Редактирование шаблона
        $this->addRoute('/templates/edit/{templateName}', 'TemplateController', 'edit');
        
        // Предпросмотр шаблона
        $this->addRoute('/templates/preview/{templateName}', 'TemplateController', 'preview');
        
        // Удаление шаблона
        $this->addRoute('/templates/delete/{templateName}', 'TemplateController', 'delete');

            // +++ ДОБАВЛЯЕМ МАРШРУТЫ ДЛЯ УПРАВЛЕНИЯ КЭШЕМ +++
    
        // Страница управления кэшем
        $this->addRoute('/cache', 'CacheController', 'index');
        
        // Очистка кэша
        $this->addRoute('/cache/clear', 'CacheController', 'clear');

        // +++ ДОБАВЛЯЕМ МАРШРУТЫ ДЛЯ ФАЙЛОВОГО МЕНЕДЖЕРА +++

        // Файловый менеджер
        $this->addRoute('/filemanager', 'FileManagerController', 'index');
        $this->addRoute('/filemanager/upload', 'FileManagerController', 'upload');
        $this->addRoute('/filemanager/delete', 'FileManagerController', 'delete');
        $this->addRoute('/filemanager/create-folder', 'FileManagerController', 'createFolder');
        $this->addRoute('/filemanager/file-info', 'FileManagerController', 'getFileInfo');
        $this->addRoute('/filemanager/thumb', 'FileManagerController', 'thumbnail');
        $this->addRoute('/filemanager/rename', 'FileManagerController', 'rename');
        $this->addRoute('/filemanager/popup', 'FileManagerController', 'popup');
        $this->addRoute('/filemanager/upload-popup', 'FileManagerController', 'uploadPopup');

        // Уведомления о заявках форм
        $this->addRoute('/notifications', 'NotificationsController', 'index');
        $this->addRoute('/notifications/form/{table}', 'NotificationsController', 'viewForm');
        $this->addRoute('/notifications/submission/{table}/id/{id}', 'NotificationsController', 'viewSubmission');
        $this->addRoute('/notifications/delete/{table}/id/{id}', 'NotificationsController', 'deleteSubmission');
        $this->addRoute('/notifications/mark-all-read/{table}', 'NotificationsController', 'markAllRead');

        // +++ AI МАРШРУТЫ +++
        $this->addRoute('/ai/assistant', 'AIController', 'assistant');
        $this->addRoute('/ai/generate-template', 'AIController', 'generateTemplate');
        $this->addRoute('/ai/generate-table', 'AIController', 'generateTable');
        $this->addRoute('/ai/generate-content', 'AIController', 'generateContent');
        $this->addRoute('/ai/insert-content', 'AIController', 'insertContent');
        $this->addRoute('/ai/fill-form', 'AIController', 'fillForm');
        // +++ ПЛАГИНЫ +++
        $this->addRoute('/plugins', 'PluginAdminController', 'index');
        $this->addRoute('/plugins/toggle/{name}', 'PluginAdminController', 'toggle');
        $this->addRoute('/plugins/{name}', 'PluginAdminController', 'view');
        $this->addRoute('/plugins/{name}/templates/{file}', 'PluginAdminController', 'editTemplate');

                $this->addRoute('/stats', 'StatsController', 'index');
        $this->addRoute('/settings', 'SettingsController', 'index');
        $this->addRoute('/settings/save', 'SettingsController', 'saveSetting');
        $this->addRoute('/settings/save-site', 'SettingsController', 'saveSiteSetting');
        $this->addRoute('/settings/save-multiple', 'SettingsController', 'saveSettings');
        $this->addRoute('/settings/delete', 'SettingsController', 'deleteSetting');
    }
    
    /**
     * Добавление маршрута
     * 
     * @param string $path URL путь
     * @param string $controller Название контроллера
     * @param string $action Название метода
     */
    public function addRoute($path, $controller, $action) {
        $this->routes[$path] = [
            'controller' => $controller,
            'action' => $action
        ];
    }
    
    /**
     * Получить экземпляр базы данных
     * 
     * @return Database Объект базы данных
     */
    public function getDatabase() {
        return $this->database;
    }
    
    /**
     * Получить конфигурацию
     * 
     * @return array Конфигурация приложения
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * Запуск приложения
     * 
     * Обрабатывает запрос и вызывает соответствующий контроллер
     */
    public function run() {
        // Проверяем аутентификацию для всех маршрутов, кроме выхода
        $currentPath = $this->getCurrentPath();
        
        if ($currentPath !== '/logout') {
            if (!$this->auth->authenticate()) {
                return; // Аутентификация не пройдена, показываем форму входа
            }
        }
        
        // Получаем текущий путь
        $path = $this->getCurrentPath();
        
        // Ищем подходящий маршрут
        $route = $this->findRoute($path);
        
        if ($route) {
            // Вызываем соответствующий контроллер и метод
            $this->callController($route['controller'], $route['action'], $route['params']);
        } else {
            // Маршрут не найден - показываем 404
            $this->show404();
        }
    }

        /**
     * Получить текущий путь запроса
     */
    private function getCurrentPath() {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? 'index.php';
        
        // Убираем имя скрипта из пути
        $basePath = dirname($scriptName);
        
        // Обрабатываем разные случаи путей
        if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
            $path = $requestUri;
        } else {
            // Убираем базовый путь из URI
            $path = substr($requestUri, strlen($basePath));
        }
        
        // Убираем начальные и конечные слеши
        $path = trim($path, '/');
        
        // Убираем параметры запроса (все что после ?)
        $path = parse_url($path, PHP_URL_PATH) ?? '';
        
        // Если путь пустой - используем корневой
        if (empty($path)) {
            $path = '/';
        } else {
            $path = '/' . $path;
        }
        
        return $path;
    }
    
    /**
     * Поиск подходящего маршрута
     * 
     * @param string $path Запрашиваемый путь
     * @return array|null Найденный маршрут или null
     */
    private function findRoute($path) {
        foreach ($this->routes as $routePath => $routeConfig) {
            // Проверяем точное совпадение
            if ($routePath === $path) {
                return array_merge($routeConfig, ['params' => []]);
            }
            
            // Проверяем маршруты с параметрами (например, /table/{table})
            if (strpos($routePath, '{') !== false) {
                $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $routePath);
                $pattern = str_replace('/', '\/', $pattern);
                
                if (preg_match('/^' . $pattern . '$/', $path, $matches)) {
                    array_shift($matches); // Убираем полное совпадение
                    
                    // Извлекаем имена параметров из маршрута
                    preg_match_all('/\{([^}]+)\}/', $routePath, $paramNames);
                    $params = [];
                    foreach ($paramNames[1] as $index => $paramName) {
                        if (isset($matches[$index])) {
                            $params[$paramName] = $matches[$index];
                        }
                    }
                    
                    return array_merge($routeConfig, ['params' => $params]);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Вызов контроллера
     * 
     * @param string $controllerName Имя контроллера
     * @param string $actionName Имя метода
     * @param array $params Параметры
     */
    private function callController($controllerName, $actionName, $params = []) {
        // Используем ADMIN_APP_PATH из конфигурации вместо старой APP_PATH
        $adminAppPath = $this->config['paths']['admin_app'];
        $controllerFile = $adminAppPath . '/controllers/' . $controllerName . '.php';
        
        if (file_exists($controllerFile)) {
            require_once $controllerFile;
            
            // Проверяем существование класса
            $fullClassName = 'Admin\\' . $controllerName;
            if (!class_exists($fullClassName)) {
                die("Класс {$fullClassName} не найден в файле {$controllerFile}");
            }
            
            // Создаем экземпляр контроллера
            $controller = new $fullClassName($this);
            
            // Вызываем метод
            if (method_exists($controller, $actionName)) {
                call_user_func_array([$controller, $actionName], $params);
            } else {
                die("Метод {$actionName} не найден в контроллере {$fullClassName}");
            }
        } else {
            die("Контроллер {$controllerName} не найден по пути: {$controllerFile}");
        }
    }
    
    /**
     * Показать страницу 404
     */
    private function show404() {
        http_response_code(404);
        echo "<h1>404 - Страница не найдена</h1>";
        echo "<p>Запрошенная страница не существует.</p>";
        echo "<p><a href='/'>Вернуться на главную</a></p>";
    }
}
?>