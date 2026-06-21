<?php
namespace Front;

use Core\Database;
use Parsedown;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class FrontController {
    private $database;
    private $config;
    private $twig;
    private $emailNotifier;
    
    /**
     * Конструктор фронтенд контроллера
     * 
     * @param array $config Конфигурация приложения
     */
    public function __construct($config) {
        // Запускаем сессию для фронтенда
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        $this->config = $config;
        $this->database = new Database($config['database']);

        // Загружаем email настройки из базы данных
        $settings = new \Core\Settings($this->database);
        $emailConfig = $settings->getEmailConfig();
        
        $this->emailNotifier = new \Core\EmailNotifier($emailConfig);
        
        $this->initTwig();

        // Выполняем миграции плагинов (при наличии БД)
        try {
            $pluginsDir = $this->config['paths']['root'] . '/plugins';
            $pm = \Core\PluginManager::getInstance($pluginsDir);
            $pm->loadPlugins($this->database);
        } catch (\Throwable $e) {
            // PluginManager не инициализирован — ok
        }

        // 📊 Встроенная статистика посещений
        $this->initStats();
    }
    
    /**
     * Инициализация Twig
     */
    private function initTwig() {
        // Создаем папку для кэша, если её нет
        if (!is_dir($this->config['twig']['cache'])) {
            mkdir($this->config['twig']['cache'], 0755, true);
        }
        
        // Настраиваем Twig
        $loader = new FilesystemLoader($this->config['paths']['front_app'] . '/views');
        $this->twig = new Environment($loader, [
            'cache' => $this->config['twig']['cache'],
            'auto_reload' => $this->config['twig']['auto_reload'],
            'debug' => true
        ]);
        
        // Добавляем пользовательские функции для Twig
        $this->twig->addFunction(new TwigFunction('get_navigation', [$this, 'getNavigation']));
        $this->twig->addFunction(new TwigFunction('get_setting', [$this, 'getSetting']));
        $this->twig->addFunction(new TwigFunction('url', [$this, 'generateUrl']));
        $this->twig->addFunction(new TwigFunction('asset', [$this, 'generateAssetUrl']));
        $this->twig->addFunction(new TwigFunction('session_id', 'session_id'));

        // Добавляем функцию для рендеринга форм
        $this->twig->addFunction(new TwigFunction('render_form', [$this, 'renderForm']));

        // Функции для работы со связанными таблицами
        $this->twig->addFunction(new TwigFunction('get_record', [$this, 'getRecord']));
        $this->twig->addFunction(new TwigFunction('get_records', [$this, 'getRecords']));
        $this->twig->addFunction(new TwigFunction('get_all', [$this, 'getAll']));

        // Фильтр для Markdown → HTML
        $this->twig->addFilter(new TwigFilter('markdown_to_html', function($text) {
            $parsedown = new Parsedown();
            $parsedown->setSafeMode(true);
            return $parsedown->text($text ?? '');
        }));

        // Хук: плагины могут добавить свои Twig-функции/фильтры
        try {
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('twig.init', $this, $this->twig);
        } catch (\Throwable $e) {
            // ok
        }

    }

    /**
     * Рендерит форму по названию таблицы
     */
    public function renderForm($tableName, $config = []) {
        $formRenderer = new \Core\FormRenderer($this->database);
        $formHtml = $formRenderer->renderForm($tableName, $config);
        
        // Возвращаем как безопасный HTML
        return new \Twig\Markup($formHtml, 'UTF-8');
    }

    /**
     * Получить одну запись по ID из указанной таблицы
     * 
     * @param string $table Название таблицы
     * @param int $id ID записи
     * @return array|null Запись или null
     */
    public function getRecord($table, $id) {
        $tables = $this->database->getTables();
        if (!in_array($table, $tables)) {
            return null;
        }
        
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        
        $result = $this->database->query(
            "SELECT * FROM \"" . $table . "\" WHERE id = ?",
            [$id]
        )->fetch();
        
        return $result ?: null;
    }

    /**
     * Получить несколько записей по ID из указанной таблицы
     * 
     * @param string $table Название таблицы
     * @param string $ids ID через запятую, напр. "1,3,5"
     * @return array Массив записей
     */
    public function getRecords($table, $ids) {
        if (empty($ids)) {
            return [];
        }
        
        $tables = $this->database->getTables();
        if (!in_array($table, $tables)) {
            return [];
        }
        
        // Разбираем строку "1,3,5" в массив int'ов
        $idList = array_map('intval', explode(',', $ids));
        $idList = array_filter($idList, function($v) { return $v > 0; });
        
        if (empty($idList)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        return $this->database->query(
            "SELECT * FROM \"" . $table . "\" WHERE id IN (" . $placeholders . ")",
            $idList
        )->fetchAll();
    }
    
    /**
     * Получить все записи из указанной таблицы
     * 
     * @param string $table Название таблицы
     * @param string $orderBy Поле для сортировки (опционально)
     * @param string $orderDir ASC или DESC (опционально)
     * @return array Массив записей
     */
    public function getAll($table, $orderBy = 'id', $orderDir = 'ASC') {
        $tables = $this->database->getTables();
        if (!in_array($table, $tables)) {
            return [];
        }
        
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
        
        return $this->database->query(
            "SELECT * FROM \"" . $table . "\" ORDER BY \"" . $orderBy . "\" " . $orderDir
        )->fetchAll();
    }

    /**
     * Получить навигацию
     */
    public function getNavigation($location = 'main') {
        return $this->database->query(
            "SELECT * FROM navigation WHERE location = ? AND status = 'active' ORDER BY menu_order ASC",
            [$location]
        )->fetchAll();
    }
    
    /**
     * Получить настройку
     */
    public function getSetting($key) {
        $result = $this->database->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = ?",
            [$key]
        )->fetch();
        
        return $result ? $result['setting_value'] : null;
    }
    
    /**
     * Генерация URL
     */
    public function generateUrl($path = '') {
        return '/' . ltrim($path, '/');
    }
    
    /**
     * Запуск фронтенда
     */
    public function run() {
        // Проверка режима обслуживания
        $maintenanceMode = $this->getSetting('maintenance_mode');
        if ($maintenanceMode === '1') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
                $this->showMaintenance();
                return;
            }
        }
        
        // Получаем текущий путь из SERVER переменных
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Отрезаем query string (?category=1 и т.д.)
        $path = parse_url($requestUri, PHP_URL_PATH);
        // Убираем начальные и конечные слеши
        $path = trim($path, '/');
        
        // Логирование для отладки

        
        // Хук: перед роутингом
        try {
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('front.router.before', $path, $this);
        } catch (\Throwable $e) {
            // PluginManager не инициализирован — ok
        }

        // Обрабатываем маршруты
        $this->handleRoute($path);

        // Хук: после роутинга (достижим только если страница не вызвала exit)
        try {
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('front.router.after', $path, $this);
        } catch (\Throwable $e) {
            // ok
        }
    }
    
    /**
     * Обработка маршрута
     */
    private function handleRoute($path) {
        // Обработка отправки форм
        if ($path === 'form-handler' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleFormSubmission();
            return;
        }

        // AI-чат: обработка запросов
        if ($path === 'ai-handler' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAiRequest();
            return;
        }

        // Если путь пустой - главная страница
        if (empty($path) || $path === '/') {
            $this->showHomePage();
            return;
        }
        
        // Ищем страницу в навигации
        $navItem = $this->getNavigationItemByUrl($path);
        
        if ($navItem) {
            // Обрабатываем страницу по ее типу
            $this->handlePageByType($navItem);
            return;
        }
        
        // Пытаемся найти статическую страницу по slug (для обратной совместимости)
        $page = $this->database->query(
            "SELECT * FROM pages WHERE slug = ? AND status = 'active'",
            [$path]
        )->fetch();
        
        if ($page) {
            $this->showPage($page);
            return;
        }
        
        // Поддержка пагинации вида /blog/page/2
        $parts = explode("/", $path);
        if (count($parts) === 3 && $parts[1] === "page" && is_numeric($parts[2])) {
            $navItem = $this->getNavigationItemByUrl($parts[0]);
            if ($navItem && in_array($navItem->page_type, ["blog", "catalog"])) {
                $this->showDynamicList($navItem, (int)$parts[2]);
                return;
            }
        }

        // Пытаемся найти динамический контент (пост блога, товар и т.д.)
        $this->handleDynamicContent($path);
        // ДОБАВЛЕНО: return после handleDynamicContent
        return;
    }

    /**
     * Получить элемент навигации по URL
     */
    private function getNavigationItemByUrl($url) {
        $result = $this->database->query(
            "SELECT * FROM navigation WHERE url = ? AND status = 'active'",
            [$url]
        )->fetch();
        
        if ($result) {
            return new \Front\NavigationItem($result);
        }
        
        return null;
    }

    /**
     * Обработка страницы по ее типу
     */
    private function handlePageByType($navItem) {
        $config = $navItem->getPageConfig();
        
        switch ($navItem->page_type) {
            case 'page':
                // Статическая страница - ищем в таблице pages
                $page = $this->database->query(
                    "SELECT * FROM pages WHERE slug = ? AND status = 'active'",
                    [$navItem->url]
                )->fetch();
                
                if ($page) {
                    $this->showPage($page);
                } else {
                    $this->show404();
                }
                break;
                
            case 'blog':
                // Блог - список записей из указанной таблицы
                $page = $_GET['page'] ?? 1;
                $this->showDynamicList($navItem, $page);
                break;
                
            case 'catalog':
                // Каталог - аналогично блогу, но с другим шаблоном
                $page = $_GET['page'] ?? 1;
                $this->showDynamicList($navItem, $page);
                break;
                
            case 'category':
                // Категория - фильтрованный список
                $this->showCategory($navItem);
                break;

            case 'form':
                // НОВЫЙ ТИП: Форма
                $this->handleFormPage($navItem);
                break;

            case 'ai':
                // AI-чат страница
                $this->handleAiChat($navItem);
                break;

            case 'landing':
                // Посадочная страница — загружаем секции из source_table
                $items = [];
                if ($navItem->source_table) {
                    $table = preg_replace('/[^a-z_]/', '', $navItem->source_table);
                    $tables = $this->database->getTables();
                    if (in_array($table, $tables)) {
                        // Проверяем, есть ли колонка page_id для фильтрации
                        $structure = $this->database->getTableStructure($table);
                        $hasPageId = false;
                        foreach ($structure as $col) {
                            if ($col['name'] === 'page_id') { $hasPageId = true; break; }
                        }
                        
                        if ($hasPageId) {
                            // Ищем связанную страницу
                            $page = $this->database->query(
                                "SELECT * FROM pages WHERE slug = ? AND status = 'active'",
                                [$navItem->url]
                            )->fetch();
                            if ($page) {
                                $items = $this->database->query(
                                    "SELECT * FROM {$table} WHERE page_id = ? ORDER BY sort_order ASC",
                                    [$page['id']]
                                )->fetchAll();
                            }
                        } else {
                            // Загружаем все записи (без привязки к странице)
                            $orderField = 'id';
                            $orderDir = 'ASC';
                            foreach ($structure as $col) {
                                if ($col['name'] === 'sort_order') { $orderField = 'sort_order'; break; }
                            }
                            $items = $this->database->query(
                                "SELECT * FROM {$table} WHERE status = 'active' ORDER BY {$orderField} {$orderDir}"
                            )->fetchAll();
                        }
                    }
                }
                
                $config = $navItem->getPageConfig();
                $template = ($config['template'] !== 'default') ? $config['template'] . '.html.twig' : 'landing.html.twig';
                
                $this->render($template, [
                    'title' => $navItem->title,
                    'items' => $items
                ]);
                break;
                
            default:
            // ЛЮБОЙ ДРУГОЙ ТИП - обрабатываем как динамический контент!
            $page = $_GET['page'] ?? 1;
            $this->showDynamicList($navItem, $page);
        }
    }

    /**
     * Обработка динамического контента (отдельные записи)
     */
    private function handleDynamicContent($path) {
        // Разбиваем путь на части
        $parts = explode('/', $path);
        
        if (count($parts) >= 2) {
            $parentSlug = $parts[0];
            $itemSlug = $parts[1];
            
            // Ищем родительскую страницу в навигации
            $parentNav = $this->getNavigationItemByUrl($parentSlug);
            
            if ($parentNav && in_array($parentNav->page_type, ['blog', 'catalog'])) {
                // Это отдельная запись блога/каталога
                $success = $this->showDynamicItem($parentNav, $itemSlug);
                if ($success) {
                    return; // ДОБАВЛЕНО: выходим только если запись успешно отображена
                }
                // Если showDynamicItem вернул false, продолжаем выполнение и показываем 404
            }
        }
        
        // Не нашли - 404
        $this->show404();
    }
        
    /**
     * Показать главную страницу
     */
    private function showHomePage() {
        // Сначала проверяем, есть ли запись в navigation для главной
        $navItem = $this->getNavigationItemByUrl('/');
        
        if ($navItem) {
            // Если есть - обрабатываем по типу из navigation
            $this->handlePageByType($navItem);
            return;
        }
        
        // Старая логика для обратной совместимости
        $page = $this->database->query(
            "SELECT * FROM pages WHERE slug = 'home' AND status = 'active'"
        )->fetch();
        
        if ($page) {
            $this->render('page.html.twig', [
                'page' => $page,
                'title' => $page['title'],
                'is_home' => true
            ]);
        } else {
            $this->render('home.html.twig', [
                'title' => 'Добро пожаловать'
            ]);
        }
    }
    
    /**
     * Показать страницу
     */
    private function showPage($page) {
        $this->render('page.html.twig', [
            'page' => $page,
            'title' => $page['title']
        ]);
    }
    
    /**
     * Показать пост блога
     */
    private function showPost($post) {
        $this->render('post.html.twig', [
            'post' => $post,
            'title' => $post['title']
        ]);
    }
    
    /**
     * Показать страницу 404
     */
    private function show404() {
        http_response_code(404);
        $this->render('404.html.twig', [
            'title' => 'Страница не найдена'
        ]);
    }
    
    /**
     * Отобразить шаблон
     */
    private function render($template, $data = []) {
        // Добавляем общие данные для всех шаблонов
        $data['navigation'] = $this->getNavigation();
        $data['site_title'] = $this->getSetting('site_title') ?: 'Мой сайт';
        $data['site_description'] = $this->getSetting('site_description') ?: 'Описание сайта';

        // Хук: фильтр данных перед рендером
        try {
            $pm = \Core\PluginManager::getInstance();
            $data = $pm->applyFilters('front.render', $data, $template);
        } catch (\Throwable $e) {
            // PluginManager не инициализирован — ok
        }
        
        echo $this->twig->render($template, $data);
        
        // ДОБАВЛЕНО: завершаем выполнение после успешного рендеринга
        exit;
    }

    /**
     * Генерация URL для статических файлов
     */
    public function generateAssetUrl($path) {
        // Убедимся, что конфигурация содержит путь к публичной папке
        if (!isset($this->config['paths']['public'])) {
            // Если путь не указан, используем относительный путь от front app
            $publicPath = $this->config['paths']['front_app'] . '/public';
        } else {
            $publicPath = $this->config['paths']['public'];
        }
        
        // Проверяем существование файла
        $fullPath = $publicPath . '/' . ltrim($path, '/');
        
        // Если файл существует, добавляем временную метку для избежания кэширования
        if (file_exists($fullPath)) {
            $timestamp = filemtime($fullPath);
            return $this->generateUrl($path) . '?v=' . $timestamp;
        }
        
        // Если файл не существует, возвращаем обычный URL
        return $this->generateUrl($path);
    }

    /**
     * Показать список постов блога
     */
    private function showBlogList($page = 1) {
        // Количество постов на страницу
        $perPage = 5;
        $offset = ($page - 1) * $perPage;
        
        // Получаем общее количество постов
        $totalPosts = $this->database->query(
            "SELECT COUNT(*) as count FROM posts WHERE status = 'active'"
        )->fetch()['count'];
        
        // Получаем посты для текущей страницы
        $posts = $this->database->query(
            "SELECT * FROM posts WHERE status = 'active' ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$perPage, $offset]
        )->fetchAll();
        
        // Рассчитываем общее количество страниц
        $totalPages = ceil($totalPosts / $perPage);
        
        $this->render('blog/list.html.twig', [
            'posts' => $posts,
            'title' => 'Блог',
            'subtitle' => 'Последние записи',
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_posts' => $totalPosts
        ]);
    }

    /**
     * Показать отдельный пост блога
     */
    private function showBlogPost($slug) {
        // Получаем пост
        $post = $this->database->query(
            "SELECT * FROM posts WHERE slug = ? AND status = 'active'",
            [$slug]
        )->fetch();
        
        if (!$post) {
            $this->show404();
            return;
        }
        
        // Получаем соседние посты для навигации
        $prevPost = $this->database->query(
            "SELECT * FROM posts WHERE status = 'active' AND created_at < ? ORDER BY created_at DESC LIMIT 1",
            [$post['created_at']]
        )->fetch();
        
        $nextPost = $this->database->query(
            "SELECT * FROM posts WHERE status = 'active' AND created_at > ? ORDER BY created_at ASC LIMIT 1",
            [$post['created_at']]
        )->fetch();
        
        $this->render('blog/single.html.twig', [
            'post' => $post,
            'prev_post' => $prevPost,
            'next_post' => $nextPost,
            'title' => $post['title']
        ]);
    }
    
    /**
     * Показать динамический список (блог, каталог и т.д.)
     */
    private function showDynamicList($navItem, $page = 1) {
        $config = $navItem->getPageConfig();
        $tableName = $config['source_table'];
        
        // Проверяем существование таблицы
        $tables = $this->database->getTables();
        if (!in_array($tableName, $tables)) {
            $this->show404();
            return;
        }
        
        // Параметры пагинации
        $perPage = $config['items_per_page'];
        $offset = ($page - 1) * $perPage;
        
        // Базовый запрос
        $whereConditions = ["status = 'active'"];
        $params = [];
        
        // Применяем фильтры из конфигурации
        if (!empty($config['filters'])) {
            foreach ($config['filters'] as $field => $value) {
                $whereConditions[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        // Применяем фильтры из GET-параметров (настраивается в page_config)
        if (!empty($config['get_filters'])) {
            foreach ($config['get_filters'] as $param => $field) {
                if (!empty($_GET[$param])) {
                    $whereConditions[] = "{$field} = ?";
                    $params[] = $_GET[$param];
                }
            }
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Получаем общее количество записей
        $totalCount = $this->database->query(
            "SELECT COUNT(*) as count FROM {$tableName} WHERE {$whereClause}",
            $params
        )->fetch()['count'];
        
        // Получаем записи
        $orderBy = "{$config['sort']['field']} {$config['sort']['order']}";
        $data = $this->database->query(
            "SELECT * FROM {$tableName} WHERE {$whereClause} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        )->fetchAll();
        
        // Получаем структуру таблицы для отображения
        $structure = $this->database->getTableStructure($tableName);
        
        // Рассчитываем общее количество страниц
        $totalPages = ceil($totalCount / $perPage);
        
        // Определяем шаблон для списка
        if ($config['template'] === 'default') {
            $template = 'blog/list.html.twig';
        } else {
            $template = $config['template'] . '.html.twig';
        }
        
        $this->render($template, [
            'items' => $data,
            'nav_item' => $navItem,
            'structure' => $structure,
            'title' => $navItem->title,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'config' => $config
        ]);
    }

    /**
     * Показать отдельную динамическую запись
     */
    private function showDynamicItem($parentNav, $itemSlug) {
        try {
            $config = $parentNav->getPageConfig();
            $tableName = $config['source_table'];
            
            // Получаем структуру таблицы для проверки наличия колонок
            $structure = $this->database->getTableStructure($tableName);
            $columnNames = array_map(function($col) { 
                return $col['name']; 
            }, $structure);
            
            // Формируем условия WHERE
            $whereConditions = [];
            $params = [$itemSlug];
            
            // Проверяем, есть ли колонка slug или используем id
            if (in_array('slug', $columnNames)) {
                $whereConditions[] = "slug = ?";
            } elseif (in_array('id', $columnNames)) {
                // Если нет slug, пытаемся использовать id
                $whereConditions[] = "id = ?";
                $params = [intval($itemSlug)];
            } else {
                // Если нет ни slug ни id, используем первую текстовую колонку
                $textColumn = null;
                foreach ($structure as $column) {
                    if (stripos($column['type'], 'text') !== false || 
                        stripos($column['type'], 'varchar') !== false) {
                        $textColumn = $column['name'];
                        break;
                    }
                }
                if ($textColumn) {
                    $whereConditions[] = "{$textColumn} = ?";
                } else {
                    // Если не можем найти запись - 404
                    $this->show404();
                    return false; // ДОБАВЛЕНО: возвращаем false
                }
            }
            
            // Добавляем условие status только если колонка существует
            if (in_array('status', $columnNames)) {
                $whereConditions[] = "status = 'active'";
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // Ищем запись
            $item = $this->database->query(
                "SELECT * FROM {$tableName} WHERE {$whereClause}",
                $params
            )->fetch();
            
            if (!$item) {
                $this->show404();
                return false; // ДОБАВЛЕНО: возвращаем false
            }
            
            // Получаем соседние записи для навигации (если есть created_at)
            $prevItem = null;
            $nextItem = null;
            
            if (in_array('created_at', $columnNames) && isset($item['created_at'])) {
                $prevItem = $this->database->query(
                    "SELECT * FROM {$tableName} WHERE created_at < ? ORDER BY created_at DESC LIMIT 1",
                    [$item['created_at']]
                )->fetch();
                
                $nextItem = $this->database->query(
                    "SELECT * FROM {$tableName} WHERE created_at > ? ORDER BY created_at ASC LIMIT 1",
                    [$item['created_at']]
                )->fetch();
            }
            
            // Определяем шаблон для отдельной записи
            if ($config['template'] === 'default') {
                $template = 'blog/single.html.twig';
            } else {
                // Если шаблон заканчивается на /list, заменяем на /single
                if (strpos($config['template'], '/list') !== false) {
                    $template = str_replace('/list', '/single', $config['template']);
                } else {
                    $template = $config['template'] . '_single';
                }
                $template .= '.html.twig';
            }
            
            $this->render($template, [
                'item' => $item,
                'nav_item' => $parentNav,
                'prev_item' => $prevItem,
                'next_item' => $nextItem,
                'title' => $item['title'] ?? $item['name'] ?? 'Запись'
            ]);
            
            return true; // ДОБАВЛЕНО: возвращаем true после успешного рендеринга
            
        } catch (Exception $e) {
            error_log("Ошибка в showDynamicItem: " . $e->getMessage());
            $this->show404();
            return false; // ДОБАВЛЕНО: возвращаем false при ошибке
        }
    }

    /**
     * Показать категорию
     */
    private function showCategory($navItem) {
        // Реализация для категорий (можно расширить)
        $this->render('category/view.html.twig', [
            'nav_item' => $navItem,
            'title' => $navItem->title
        ]);
    }

    /**
     * Обработка страницы-формы
     */
    private function handleFormPage($navItem) {

        $config = $navItem->getPageConfig();
        $tableName = $config['source_table'];

        // Загружаем связанную страницу (page_id), если указана
        // Контент страницы будет выведен ПЕРЕД формой
        $pageContent = null;
        $pageMeta = null;
        if (!empty($navItem->page_id)) {
            $page = $this->database->query(
                "SELECT * FROM pages WHERE id = ? AND status = 'active'",
                [$navItem->page_id]
            )->fetch();
            if ($page) {
                $pageContent = $page['content'] ?? null;
                $pageMeta = [
                    'title' => $page['title'] ?? $navItem->title,
                    'meta_title' => $page['meta_title'] ?? ($page['title'] ?? $navItem->title),
                    'meta_description' => $page['meta_description'] ?? '',
                ];
            }
        }        
        // Проверяем существование таблицы
        $tables = $this->database->getTables();
        if (!in_array($tableName, $tables)) {
            $this->show404();
            return;
        }
        
        // Обработка отправки формы
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processFormSubmission($navItem, $tableName);
            return;
        }
        
        // Показ формы
        $this->showForm($navItem, $tableName, $pageContent, $pageMeta);
    }

    /**
     * Показ формы
     */
    private function showForm($navItem, $tableName, $pageContent = null, $pageMeta = null) {
        $config = $navItem->getPageConfig();
        $structure = $this->database->getTableStructure($tableName);
        
        // Генерируем HTML формы
        $formHtml = $this->generateFormHtml($structure, $config, $navItem->url);
        
        // Определяем шаблон
        $template = $config['template'] === 'default' ? 'form.html.twig' : $config['template'] . '.html.twig';
        
        $this->render($template, [
            'nav_item' => $navItem,
            'form_html' => $formHtml,
            'title' => $pageMeta['title'] ?? $navItem->title,
            'meta_title' => $pageMeta['meta_title'] ?? '',
            'meta_description' => $pageMeta['meta_description'] ?? '',
            'config' => $config,
            'pageContent' => $pageContent,
            'pageMeta' => $pageMeta
        ]);
    }



    /**
     * Определение типа поля на основе имени и типа данных
     */
    private function determineFieldType($field, $fieldConfig) {
        // Если тип указан в конфиге - используем его
        if (isset($fieldConfig['type'])) {
            return $fieldConfig['type'];
        }
        
        $name = strtolower($field['name']);
        $type = strtolower($field['type']);
        
        // Умное определение по имени поля
        if (strpos($name, 'email') !== false) return 'email';
        if (strpos($name, 'phone') !== false || strpos($name, 'tel') !== false) return 'tel';
        if (strpos($name, 'message') !== false || strpos($name, 'content') !== false) return 'textarea';
        if (strpos($name, 'description') !== false) return 'textarea';
        
        // Определение по типу данных SQLite
        if (strpos($type, 'text') !== false && $this->isLongText($field)) return 'textarea';
        
        return 'text';
    }

    /**
     * Проверяет, является ли поле длинным текстом
     * 
     * @param array $field Информация о поле из структуры таблицы
     * @return bool True если поле считается длинным текстом
     */
    private function isLongText($field) {
        $name = strtolower($field['name']);
        $type = strtolower($field['type']);
        
        // Поля с определенными именами считаем длинным текстом
        $longTextNames = ['message', 'content', 'description', 'body', 'text', 'comment'];
        foreach ($longTextNames as $textName) {
            if (strpos($name, $textName) !== false) {
                return true;
            }
        }
        
        // Поля с типами TEXT считаем длинным текстом
        if (strpos($type, 'text') !== false && !strpos($type, 'var')) {
            return true;
        }
        
        return false;
    }

    /**
     * Проверка системных полей
     */
    private function isSystemField($fieldName) {
        $systemFields = ['id', 'created_at', 'updated_at', 'status', 'read_status', 'pd_consent'];
        return in_array($fieldName, $systemFields);
    }

    /**
     * Генерация человеко-читаемого label
     */
    private function generateLabel($fieldName) {
        $label = str_replace(['_', '-'], ' ', $fieldName);
        $label = ucfirst($label);
        return $label;
    }

    /**
     * Генерация CSRF поля
     */
    private function generateCsrfField() {
        // Генерируем новый токен только если его нет
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
    }

    /**
     * Обработка отправки формы
     * 
     * @param object $navItem Элемент навигации формы
     * @param string $tableName Название таблицы для сохранения
     */
    private function processFormSubmission($navItem, $tableName) {
        try {
            // Проверяем CSRF токен
            if (!$this->validateCsrfToken()) {
                throw new \Exception('Недействительный CSRF токен');
            }
            
            // Получаем структуру таблицы для валидации
            $structure = $this->database->getTableStructure($tableName);
            $config = $navItem->getPageConfig();
            
            // Подготавливаем данные для сохранения
            $formData = [];
            $errors = [];
            
            foreach ($structure as $field) {
                $fieldName = $field['name'];
                
                // Пропускаем системные поля
                if ($this->isSystemField($fieldName)) {
                    continue;
                }
                
                $fieldConfig = $config['fields'][$fieldName] ?? [];
                $value = $_POST[$fieldName] ?? '';
                
                // Валидация поля
                $validationError = $this->validateField($field, $fieldConfig, $value);
                if ($validationError) {
                    $errors[$fieldName] = $validationError;
                }
                
                // Очищаем и сохраняем значение
                $formData[$fieldName] = $this->sanitizeFieldValue($field, $value);
            }
            
            // Если есть ошибки - показываем форму снова
            if (!empty($errors)) {
                $this->showFormWithErrors($navItem, $tableName, $errors, $_POST);
                return;
            }
            
            // Сохраняем данные в базу
            $newId = $this->database->insert($tableName, $formData);
            
            // Отправляем уведомления
            $this->sendFormNotifications($navItem, $formData, $newId);
            
            // Показываем страницу успеха
            $this->showFormSuccess($navItem, $formData);
            
        } catch (\Exception $e) {
            $this->showFormWithErrors($navItem, $tableName, ['general' => $e->getMessage()], $_POST);
        }
    }

    /**
     * Обработка отправки форм со всех страниц
     */
    private function handleFormSubmission() {
        try {
            $tableName = $_POST['form_table'] ?? '';
            
            if (empty($tableName)) {
                throw new \Exception('Не указана таблица для формы');
            }
            
            // Проверяем CSRF токен
            if (!$this->validateCsrfToken()) {
                throw new \Exception('Недействительный CSRF токен');
            }
            
            // Проверяем существование таблицы
            if (!$this->database->tableExists($tableName)) {
                throw new \Exception("Таблица '{$tableName}' не найдена");
            }
            
            // Получаем структуру таблицы
            $structure = $this->database->getTableStructure($tableName);
            
            // Подготавливаем данные
            $formData = [];
            foreach ($structure as $field) {
                $fieldName = $field['name'];
                
                // Пропускаем системные поля
                if ($this->isSystemField($fieldName)) {
                    continue;
                }
                
                if (isset($_POST[$fieldName])) {
                    $formData[$fieldName] = trim($_POST[$fieldName]);
                }
            }
            
            // Вставляем данные
            $newId = $this->database->insert($tableName, $formData);

            // 🔥 ОТПРАВКА УВЕДОМЛЕНИЙ ДЛЯ ПРОИЗВОЛЬНЫХ ФОРМ
            $this->sendNotificationsForCustomForm($tableName, $formData, $newId);
            
            // 🔥 ПРОВЕРЯЕМ AJAX ЗАПРОС
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            
            if ($isAjax) {
                // Возвращаем JSON ответ для AJAX
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Форма успешно отправлена!',
                    'id' => $newId
                ]);
                exit;
            } else {
                // Обычный редирект для не-AJAX запросов
                $_SESSION['form_success'] = true;
                $_SESSION['form_message'] = 'Форма успешно отправлена!';
                $referer = $_SERVER['HTTP_REFERER'] ?? '/';
                header('Location: ' . $referer);
                exit;
            }
            
        } catch (\Exception $e) {
            // Сохраняем ошибку и данные формы
            $_SESSION['form_error'] = $e->getMessage();
            $_SESSION['form_data'] = $_POST;

        // 🔥 ОБРАБОТКА ОШИБОК ДЛЯ AJAX
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        } else {
            $_SESSION['form_error'] = $e->getMessage();
            $_SESSION['form_data'] = $_POST;
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header('Location: ' . $referer);
            exit;
        }            

        }
    }

    /**
     * Отправка уведомлений для произвольных форм (не привязанных к страницам)
     */
    private function sendNotificationsForCustomForm($tableName, $formData, $recordId) {
        try {
            
            // Ищем конфигурацию формы в таблице navigation
            $navItem = $this->database->query(
                "SELECT * FROM navigation WHERE source_table = ? AND page_type = 'form' AND status = 'active' LIMIT 1",
                [$tableName]
            )->fetch();

            if (!$navItem) {
                return;
            }


            // Парсим конфигурацию формы
            $formConfig = [];
            if (!empty($navItem['form_config'])) {
                $formConfig = json_decode($navItem['form_config'], true);
            } else {
                return;
            }

            $notificationsConfig = $formConfig['notifications'] ?? [];

            if (empty($notificationsConfig)) {
                return;
            }


            // Уведомление администраторам
            if (!empty($notificationsConfig['admin_notify'])) {
                error_log("👨💼 Отправка уведомления администраторам");
                $success = $this->emailNotifier->sendAdminNotification($formData, $notificationsConfig, $navItem['title']);
                if ($success) {
                } else {
                }
            }

            // Автоответ пользователю
            if (!empty($notificationsConfig['auto_reply'])) {
                $success = $this->emailNotifier->sendAutoReply($formData, $notificationsConfig, $navItem['title']);
                if ($success) {
                } else {
                }
            }

        } catch (\Exception $e) {
        }
    }

    /**
     * Валидация CSRF токена
     */
    private function validateCsrfToken() {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        // Отладочная информация (можно удалить после тестирования)
        
        if (empty($token)) {
            error_log("CSRF Error: No token in POST");
            return false;
        }
        
        if (empty($sessionToken)) {
            error_log("CSRF Error: No token in SESSION");
            return false;
        }
        
        if (!hash_equals($sessionToken, $token)) {
            error_log("CSRF Error: Tokens don't match");
            return false;
        }
        
        // Удаляем использованный токен
        unset($_SESSION['csrf_token']);
        return true;
    }

    /**
     * Валидация поля формы
     */
    private function validateField($field, $fieldConfig, $value) {
        $fieldName = $field['name'];
        $required = isset($fieldConfig['required']) ? $fieldConfig['required'] : ($field['notnull'] ? true : false);
        
        // Проверка обязательных полей - более строгая проверка
        if ($required) {
            // Проверяем не только на пустоту, но и на пробелы
            $trimmedValue = trim($value);
            if (empty($trimmedValue)) {
                $label = $fieldConfig['label'] ?? $this->generateLabel($fieldName);
                return "Поле '{$label}' обязательно для заполнения";
            }
        }
        
        // Если поле не обязательно и пустое - пропускаем дальнейшую валидацию
        if (empty(trim($value))) {
            return null;
        }
        
    // Валидация по типу поля
    $fieldType = $this->determineFieldType($field, $fieldConfig);

    switch ($fieldType) {
        case 'email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return "Введите корректный email адрес";
            }
            break;
            
        case 'tel':
            $cleanPhone = preg_replace('/[^0-9+]/', '', $value);
            if (strlen($cleanPhone) < 10) {
                return "Введите корректный номер телефона (минимум 10 цифр)";
            }
            break;
            
        case 'number':
            if (!is_numeric($value)) {
                return "Введите числовое значение";
            }
            if (isset($fieldConfig['min']) && $value < $fieldConfig['min']) {
                return "Значение должно быть не меньше " . $fieldConfig['min'];
            }
            if (isset($fieldConfig['max']) && $value > $fieldConfig['max']) {
                return "Значение должно быть не больше " . $fieldConfig['max'];
            }
            break;
            
        case 'checkbox':
            if ($required && empty($value)) {
                return "Это поле обязательно для заполнения";
            }
            break;
    }
        
        return null;
    }

    /**
     * Очистка значения поля
     */
    private function sanitizeFieldValue($field, $value) {
        $fieldType = strtolower($field['type']);
        
        // Для текстовых полей убираем лишние пробелы
        if (is_string($value)) {
            $value = trim($value);
            
            // Экранирование специальных символов
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        
        return $value;
    }

    /**
     * Показать форму с ошибками
     */
    private function showFormWithErrors($navItem, $tableName, $errors, $formData) {
        $config = $navItem->getPageConfig();
        $structure = $this->database->getTableStructure($tableName);
        
        // Генерируем HTML формы с сохраненными данными и ошибками
        $formHtml = $this->generateFormHtmlWithErrors($structure, $config, $navItem->url, $formData, $errors);
        
        $template = $config['template'] === 'default' ? 'form/default.html.twig' : $config['template'] . '.html.twig';
        
        $this->render($template, [
            'nav_item' => $navItem,
            'form_html' => $formHtml,
            'title' => $navItem->title,
            'config' => $config,
            'errors' => $errors,
            'form_data' => $formData
        ]);
    }

    /**
     * Генерация HTML формы с ошибками и сохраненными данными
     */
    private function generateFormHtmlWithErrors($structure, $config, $formAction, $formData, $errors) {
        $html = '<form method="post" action="' . $this->generateUrl($formAction) . '" enctype="multipart/form-data" class="space-y-6">';
        
        // Показываем общие ошибки
        if (isset($errors['general'])) {
            $html .= '
                <div class="bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="text-red-700">' . $errors['general'] . '</div>
                </div>
            ';
        }
        
        foreach ($structure as $field) {
            if ($this->isSystemField($field['name'])) {
                continue;
            }
            
            $fieldConfig = $config['fields'][$field['name']] ?? [];
            $fieldError = $errors[$field['name']] ?? null;
            $fieldValue = $formData[$field['name']] ?? '';
            
            $html .= $this->renderFormFieldWithError($field, $fieldConfig, $fieldValue, $fieldError);
        }
        
        // Добавляем CSRF защиту
        $html .= $this->generateCsrfField();
        
        // Согласие на обработку ПД
        $hasConsentColumn2 = false;
        foreach ($structure as $col) {
            if ($col['name'] === 'pd_consent') { $hasConsentColumn2 = true; break; }
        }
        $consentEnabled2 = $config['consent_enabled'] ?? $hasConsentColumn2;
        if ($consentEnabled2) {
            $reqAttr2 = ($config['consent_required'] ?? true) ? ' required' : '';
            $html .= '
            <div class="form-field consent-field bg-gray-50 p-4 rounded-lg border border-gray-200">
                <label class="flex items-start space-x-3 cursor-pointer">
                    <input type="checkbox" name="pd_consent" value="1"' . $reqAttr2 . '
                           class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-700 leading-relaxed">
                        Я даю согласие на обработку моих персональных данных в соответствии с
                        <a href="/confidential" target="_blank" class="text-blue-600 hover:text-blue-800 underline">Политикой конфиденциальности</a>
                        <span class="text-red-500">*</span>
                    </span>
                </label>
            </div>
            ';
        }

        // Кнопка отправки
        $html .= '
            <div class="form-submit">
                <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition">
                    Отправить
                </button>
            </div>
        ';
        
        $html .= '</form>';
        
        return $html;
    }

    /**
     * Рендер поля формы с ошибкой и сохраненным значением
     */
    private function renderFormFieldWithError($field, $fieldConfig, $value, $error) {
        $name = $field['name'];
        $label = $fieldConfig['label'] ?? $this->generateLabel($name);
        $required = isset($fieldConfig['required']) ? $fieldConfig['required'] : ($field['notnull'] ? true : false);
        $placeholder = $fieldConfig['placeholder'] ?? '';
        
        $fieldType = $this->determineFieldType($field, $fieldConfig);
        
        $html = '<div class="form-field">';
        $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">' . $label;
        if ($required) {
            $html .= ' <span class="text-red-500">*</span>';
        }
        $html .= '</label>';
        
        // Добавляем класс ошибки если есть ошибка
        $inputClass = 'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500';
        if ($error) {
            $inputClass .= ' border-red-300 bg-red-50';
            $html .= '<div class="text-red-600 text-sm mb-2">' . $error . '</div>';
        } else {
            $inputClass .= ' border-gray-300';
        }
        
        switch ($fieldType) {
            case 'textarea':
                $html .= '<textarea name="' . $name . '" ';
                $html .= 'placeholder="' . $placeholder . '" ';
                $html .= 'class="' . $inputClass . '" ';
                $html .= ($required ? 'required' : '') . '>' . htmlspecialchars($value) . '</textarea>';
                break;
                
            case 'select':
                $options = $fieldConfig['options'] ?? [];
                $html .= '<select name="' . $name . '" ';
                $html .= 'class="' . $inputClass . '" ';
                $html .= ($required ? 'required' : '') . '>';
                $html .= '<option value="">' . $placeholder . '</option>';
                foreach ($options as $optionValue => $optionLabel) {
                    $selected = ($value == $optionValue) ? 'selected' : '';
                    $html .= '<option value="' . $optionValue . '" ' . $selected . '>' . $optionLabel . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'email':
                $html .= '<input type="email" name="' . $name . '" ';
                $html .= 'placeholder="' . $placeholder . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                $html .= 'class="' . $inputClass . '" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            case 'tel':
                $html .= '<input type="tel" name="' . $name . '" ';
                $html .= 'placeholder="' . $placeholder . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                $html .= 'class="' . $inputClass . '" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            default:
                $html .= '<input type="text" name="' . $name . '" ';
                $html .= 'placeholder="' . $placeholder . '" ';
                $html .= 'value="' . htmlspecialchars($value) . '" ';
                $html .= 'class="' . $inputClass . '" ';
                $html .= ($required ? 'required' : '') . '>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Показать страницу успешной отправки
     */
    private function showFormSuccess($navItem, $formData) {
        $config = $navItem->getPageConfig();
        $successTemplate = $config['success_template'] ?? 'success.html.twig';
        
        $this->render($successTemplate, [
            'nav_item' => $navItem,
            'title' => 'Форма успешно отправлена',
            'form_data' => $formData,
            'config' => $config
        ]);
    }

    /**
     * Отправка уведомлений (заглушка - нужно реализовать)
     */
    private function sendFormNotifications($navItem, $formData, $recordId) {
        $config = $navItem->getPageConfig();
        $notificationsConfig = $config['notifications'] ?? [];
        
        if (empty($notificationsConfig)) {
            return;
        }
        
        try {
            // Уведомление администраторам
            if (!empty($notificationsConfig['admin_notify'])) {
                $this->emailNotifier->sendAdminNotification($formData, $notificationsConfig, $navItem->title);
            }
            
            // Автоответ пользователю
            if (!empty($notificationsConfig['auto_reply'])) {
                $this->emailNotifier->sendAutoReply($formData, $notificationsConfig, $navItem->title);
            }
            
        } catch (\Exception $e) {
            // Логируем ошибки отправки, но не прерываем выполнение
            error_log("Ошибка отправки уведомлений: " . $e->getMessage());
        }
    }

    /**
     * Генерация HTML формы с использованием расширенной конфигурации
     */
    private function generateFormHtml($structure, $config, $formAction) {
        $html = '<form method="post" action="' . $this->generateUrl($formAction) . '" enctype="multipart/form-data" class="space-y-6">';
        
        // Добавляем заголовок формы из конфигурации, если есть
        if (!empty($config['form_title'])) {
            $html .= '<h2 class="text-xl font-semibold text-gray-800 mb-4">' . htmlspecialchars($config['form_title']) . '</h2>';
        }
        
        // Добавляем описание формы из конфигурации, если есть
        if (!empty($config['form_description'])) {
            $html .= '<p class="text-gray-600 mb-6">' . htmlspecialchars($config['form_description']) . '</p>';
        }
        
        foreach ($structure as $field) {
            // Пропускаем системные поля
            if ($this->isSystemField($field['name'])) {
                continue;
            }
            
            $fieldConfig = $config['fields'][$field['name']] ?? [];
            
            // Проверяем, не скрыто ли поле в конфигурации
            if (isset($fieldConfig['hidden']) && $fieldConfig['hidden']) {
                continue;
            }
            
            $html .= $this->renderFormField($field, $fieldConfig);
        }
        
        // Добавляем CSRF защиту
        $html .= $this->generateCsrfField();
        
        // Согласие на обработку ПД (если есть колонка в таблице или consent_enabled)
        $hasConsentColumn = false;
        foreach ($structure as $col) {
            if ($col['name'] === 'pd_consent') { $hasConsentColumn = true; break; }
        }
        $consentEnabled = $config['consent_enabled'] ?? $hasConsentColumn;
        $consentRequired = $config['consent_required'] ?? true;
        if ($consentEnabled) {
            $reqAttr = $consentRequired ? ' required' : '';
            $html .= '
            <div class="form-field consent-field bg-gray-50 p-4 rounded-lg border border-gray-200">
                <label class="flex items-start space-x-3 cursor-pointer">
                    <input type="checkbox" name="pd_consent" value="1"' . $reqAttr . '
                           class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-700 leading-relaxed">
                        Я даю согласие на обработку моих персональных данных в соответствии с
                        <a href="/confidential" target="_blank" class="text-blue-600 hover:text-blue-800 underline">Политикой конфиденциальности</a>
                        ' . ($consentRequired ? '<span class="text-red-500">*</span>' : '') . '
                    </span>
                </label>
            </div>
            ';
        }

        // Кнопка отправки с кастомным текстом
        $submitText = $config['submit_text'] ?? 'Отправить';
        $html .= '
            <div class="form-submit">
                <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition font-medium">
                    ' . htmlspecialchars($submitText) . '
                </button>
            </div>
        ';
        
        $html .= '</form>';
        
        return $html;
    }

    /**
     * Улучшенный рендер поля формы с поддержкой расширенной конфигурации
     */
    private function renderFormField($field, $fieldConfig) {
        $name = $field['name'];
        $label = $fieldConfig['label'] ?? $this->generateLabel($name);
        $required = isset($fieldConfig['required']) ? $fieldConfig['required'] : ($field['notnull'] ? true : false);
        $placeholder = $fieldConfig['placeholder'] ?? '';
        $helpText = $fieldConfig['help_text'] ?? '';
        
        // Определяем тип поля с приоритетом конфигурации
        $fieldType = $fieldConfig['type'] ?? $this->determineFieldType($field, $fieldConfig);
        
        $html = '<div class="form-field">';
        
        // Label
        if ($fieldType !== 'checkbox') {
            $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">' . htmlspecialchars($label);
            if ($required) {
                $html .= ' <span class="text-red-500">*</span>';
            }
            $html .= '</label>';
        }
        
        // Field
        switch ($fieldType) {
            case 'textarea':
                $rows = $fieldConfig['rows'] ?? 4;
                $html .= '<textarea name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'rows="' . $rows . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '></textarea>';
                break;
                
            case 'select':
                $options = $fieldConfig['options'] ?? [];
                $html .= '<select name="' . $name . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
                foreach ($options as $value => $optionLabel) {
                    $html .= '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($optionLabel) . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'checkbox':
                $html .= '<label class="flex items-center">';
                $html .= '<input type="checkbox" name="' . $name . '" ';
                $html .= 'class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                $html .= '<span class="text-sm font-medium text-gray-700">' . htmlspecialchars($label) . '</span>';
                if ($required) {
                    $html .= ' <span class="text-red-500">*</span>';
                }
                $html .= '</label>';
                break;
                
            case 'radio':
                $options = $fieldConfig['options'] ?? [];
                foreach ($options as $value => $optionLabel) {
                    $html .= '<label class="flex items-center mr-4">';
                    $html .= '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($value) . '" ';
                    $html .= 'class="mr-2 rounded-full border-gray-300 text-blue-600 focus:ring-blue-500">';
                    $html .= '<span class="text-sm text-gray-700">' . htmlspecialchars($optionLabel) . '</span>';
                    $html .= '</label>';
                }
                break;
                
            case 'email':
                $html .= '<input type="email" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            case 'tel':
                $html .= '<input type="tel" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            case 'number':
                $min = $fieldConfig['min'] ?? '';
                $max = $fieldConfig['max'] ?? '';
                $step = $fieldConfig['step'] ?? '';
                $html .= '<input type="number" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                if ($min !== '') $html .= 'min="' . $min . '" ';
                if ($max !== '') $html .= 'max="' . $max . '" ';
                if ($step !== '') $html .= 'step="' . $step . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            case 'date':
                $html .= '<input type="date" name="' . $name . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            default:
                $html .= '<input type="text" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
        }
        
        // Help text
        if (!empty($helpText)) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($helpText) . '</p>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * Показать страницу заглушки режима обслуживания
     */
    private function showMaintenance() {
        http_response_code(503);
        $this->render('maintenance.html.twig', [
            'title' => $this->getSetting('site_title') ?: 'Сайт на обслуживании',
            'message' => $this->getSetting('maintenance_message') ?: 'Сайт временно недоступен. Проводятся технические работы.'
        ]);
    }

    /**
     * Обработка AI-чата страницы
     */
    private function handleAiChat($navItem) {
        $template = 'ai.html.twig';
        $this->render($template, [
            'title' => $navItem->title,
            'nav_item' => $navItem
        ]);
    }

    /**
     * Сбор контекста базы данных для AI
     */

    /**
     * Инициализация встроенной статистики посещений
     */
    private function initStats(): void
    {
        try {
            $enabled = $this->getSetting('stats_enabled');
            if ($enabled !== '1') return;

            // Создаём таблицу если нет
            \Core\VisitStats::initTable($this->database);

            // Сбор статистики на каждый запрос (через shutdown)
            $db = $this->database;
            $pageUrl = $_SERVER['REQUEST_URI'] ?? '/';
            register_shutdown_function(function () use ($db, $pageUrl) {
                \Core\VisitStats::collect($db, $pageUrl);
            });

            // Автоочистка старых записей (~1% запросов)
            if (mt_rand(1, 100) === 1) {
                $retentionDays = (int)($this->getSetting('stats_retention_days') ?: 90);
                \Core\VisitStats::cleanup($this->database, $retentionDays);
            }
        } catch (\Throwable $e) {
            // Статистика не должна ломать сайт
        }
    }

    private function collectAiContext() {
        $tables = $this->database->getTables();
        $context = [
            'site' => [
                'title' => $this->getSetting('site_title') ?: 'Сайт',
                'description' => $this->getSetting('site_description') ?: ''
            ],
            'tables' => [],
            'navigation' => []
        ];

        // 🔒 Чёрный список: таблицы, которые НИКОГДА не отдаются в AI
        $blacklist = ['sqlite_sequence', 'system_settings', 'users', 'user_tokens'];

        // ✅ Белый список: таблицы, явно разрешённые админом
        // null/пусто → ничего не разрешено (безопасность по умолчанию)
        $publicTablesJson = $this->getSetting('ai_public_tables');
        $publicTables = [];
        if (!empty($publicTablesJson)) {
            $publicTables = json_decode($publicTablesJson, true) ?: [];
        }

        foreach ($tables as $tableName) {
            if (in_array($tableName, $blacklist)) continue;
            // Если белый список настроен — проверяем; если нет — ничего не отдаём
            if (!in_array($tableName, $publicTables)) continue;

            $structure = $this->database->getTableStructure($tableName);
            $columns = [];
            foreach ($structure as $col) {
                $columns[] = ['name' => $col['name'], 'type' => $col['type']];
            }

            $rowCount = $this->database->query(
                "SELECT COUNT(*) as cnt FROM \"{$tableName}\""
            )->fetch()['cnt'];

            $sampleRows = [];
            $sampleLimit = (int)($this->getSetting('ai_sample_limit') ?: 50);
            if ($rowCount > 0 && $sampleLimit > 0) {
                $sampleRows = $this->database->query(
                    "SELECT * FROM \"{$tableName}\" LIMIT {$sampleLimit}"
                )->fetchAll();
            }

            $context['tables'][] = [
                'name' => $tableName,
                'columns' => $columns,
                'row_count' => $rowCount,
                'sample_rows' => $sampleRows
            ];
        }

        $navItems = $this->database->query(
            "SELECT title, url, page_type, source_table FROM navigation WHERE status = 'active' ORDER BY menu_order ASC"
        )->fetchAll();

        foreach ($navItems as $item) {
            $context['navigation'][] = [
                'title' => $item['title'],
                'url' => $item['url'],
                'type' => $item['page_type']
            ];
        }

        return $context;
    }

    /**
     * Обработка запроса AI (эндпоинт /ai-handler)
     */
    private function handleAiRequest() {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new \Exception('Method not allowed');
            }

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);

            if (!$input || empty($input['message'])) {
                throw new \Exception('Пустое сообщение');
            }

            $userMessage = trim($input['message']);
            $history = $input['history'] ?? [];

            // Rate limiting
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $now = time();
            $lastRequest = $_SESSION['ai_last_request'] ?? 0;
            if ($now - $lastRequest < 2) {
                throw new \Exception('Слишком частые запросы. Подождите немного.');
            }
            $_SESSION['ai_last_request'] = $now;

            // Загружаем настройки AI
            $useSystem = $this->getSetting('ai_frontend_use_system') !== '0';

            if ($useSystem) {
                $apiKey = $this->getSetting('ai_api_key');
                $model = $this->getSetting('ai_model') ?: 'deepseek-chat';
            } else {
                $apiKey = $this->getSetting('ai_frontend_api_key');
                $model = $this->getSetting('ai_frontend_model') ?: 'deepseek-chat';
            }

            if (empty($apiKey)) {
                throw new \Exception('AI не настроен. Добавьте API ключ в настройках.');
            }

            // Собираем контекст БД (нужен в любом случае)
            $context = $this->collectAiContext();
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // Пробуем использовать Личность ассистента (ai_frontend_personality)
            $personality = $this->getSetting('ai_frontend_personality');

            if (!empty($personality)) {
                // Новый режим: личность + автоматический контекст
                $personality = str_replace('{site_title}', $context['site']['title'], $personality);
                $systemPrompt = $personality . "\n\nКОНТЕКСТ САЙТА (используй ТОЛЬКО эти данные):\n" . $contextJson;
            } else {
                // Fallback: старый ai_frontend_prompt
                $customPrompt = $this->getSetting('ai_frontend_prompt');
                if (empty($customPrompt)) {
                    $customPrompt = "Ты — AI-ассистент сайта. Помогай посетителям находить информацию. Отвечай на русском языке, дружелюбно и по делу. Используй ТОЛЬКО данные из контекста ниже.\n\nКОНТЕКСТ САЙТА:\n{context}";
                }

                // Подставляем переменные в промпт
                $systemPrompt = str_replace(
                    ['{site_title}', '{context}'],
                    [$context['site']['title'], $contextJson],
                    $customPrompt
                );

                // Если промпт не содержит {context}, добавляем контекст в конец
                if (strpos($systemPrompt, $contextJson) === false) {
                    $systemPrompt .= "\n\nКОНТЕКСТ САЙТА:\n" . $contextJson;
                }
            }

            // Создаём AI клиент
            $ai = new \Core\AI($apiKey, $model);

            // Формируем сообщения
            $messages = [];
            foreach ($history as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = $msg;
                }
            }
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            // Отправляем запрос
            $response = $ai->chat($messages, $systemPrompt, 0.7, 2048);

            // Парсим quick_links
            $quickLinks = [];
            if (preg_match('/\\[quick_links:(.+?)\\]/s', $response, $matches)) {
                $linksJson = trim($matches[1]);
                $decoded = json_decode($linksJson, true);
                if (is_array($decoded)) {
                    $quickLinks = $decoded;
                }
                $response = trim(preg_replace('/\\[quick_links:.+?\\]/s', '', $response));
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'response' => $response,
                'quick_links' => $quickLinks
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
