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
        // Template resolution: project first, core fallback
        $loader = new FilesystemLoader(CORE_PATH . '/front/app/views');
        $projectViewsPath = $this->config['paths']['front_app'] . '/views';
        if (is_dir($projectViewsPath) && $projectViewsPath !== CORE_PATH . '/front/app/views') {
            $loader->prependPath($projectViewsPath);
        }
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
        $this->twig->addFilter(new TwigFilter('json_decode', function(\$str) {
            return \$str ? json_decode(\$str, true) : [];
        }));
            return $parsedown->text($text ?? '');
        }));
        $this->twig->addFilter(new TwigFilter('json_decode', function($str) {
            return $str ? json_decode($str, true) : [];
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
     * Рендерит форму по имени (новая система)
     * Вызывается из Twig: {{ render_form('contacts') }}
     *
     * @param string $formName Имя формы из таблицы forms
     * @param array $options   Опции: template, submit_text, submit_class, field_class, form_class
     * @return \Twig\Markup
     */
    public function renderForm($formName, $options = []) {
        // Если options не массив (undefined/null из Twig) — сбрасываем
        if (!is_array($options)) {
            $options = [];
        }
        $formRenderer = new \Core\FormRenderer($this->database, $this->twig, $this->config);
        $formHtml = $formRenderer->renderForm($formName, $options);
        
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
        $items = $this->database->query(
            "SELECT * FROM navigation WHERE location = ? AND status = 'active' ORDER BY menu_order ASC",
            [$location]
        )->fetchAll();
        
        // Build parent->children map
        $childrenMap = [];
        foreach ($items as $item) {
            $pid = (int)($item['parent_id'] ?? 0);
            if ($pid > 0) {
                $childrenMap[$pid][] = $item;
            }
        }
        
        // Attach children to parents and build result
        $result = [];
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $item['children'] = $childrenMap[$id] ?? [];
            // Only top-level items (parent_id = 0 or null) go to root
            $pid = (int)($item['parent_id'] ?? 0);
            if ($pid === 0) {
                $result[] = $item;
            }
        }
        
        return $result;
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
        // Anchors: #section or /#section — both stay as #section
        if (strlen($path) > 0 && $path[0] === '#') {
            return $path;
        }
        if (strpos($path, '/#') === 0) {
            return substr($path, 1);
        }
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
            if ($navItem && in_array($navItem->page_type, ["dynamic"])) {
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
        // В БД url может быть как /price так и examples (слеш или без)
        $urls = [$url];
        if (strpos($url, '/') !== 0) {
            $urls[] = '/' . $url;
        }
        $placeholders = implode(',', array_fill(0, count($urls), '?'));
        $result = $this->database->query(
            "SELECT * FROM navigation WHERE url IN ({$placeholders}) AND status = 'active' LIMIT 1",
            $urls
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
                    // Поддержка кастомного шаблона из page_config или поля template
                    $config = $navItem->getPageConfig();
                    $template = ($config['template'] !== 'default') ? $config['template'] . '.html.twig' : 'page.html.twig';
                    $this->render($template, [
                        'page' => $page,
                        'title' => $page['title']
                    ]);
                } else {
                    $this->show404();
                }
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
                            // page_config can override order field and direction
                            $pageConfig = json_decode($navItem->page_config ?: '{}', true);
                            if (!empty($pageConfig['order_field'])) {
                                $customField = preg_replace('/[^a-zA-Z_]/', '', $pageConfig['order_field']);
                                $validFields = array_column($structure, 'name');
                                if (in_array($customField, $validFields)) {
                                    $orderField = $customField;
                                }
                            }
                            if (!empty($pageConfig['order_dir'])) {
                                $orderDir = strtoupper($pageConfig['order_dir']) === 'DESC' ? 'DESC' : 'ASC';
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
                
            case 'search':
                $this->handleSearch($navItem);
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
            
            if ($parentNav && in_array($parentNav->page_type, ['dynamic'])) {
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
        $data['_GET'] = $_GET;
        $data['navigation'] = $this->getNavigation();
        $data['site_title'] = $this->getSetting('site_title') ?: 'Мой сайт';
        $data['site_description'] = $this->getSetting('site_description') ?: 'Описание сайта';
        $data['custom_css'] = $this->getSetting('custom_css') ?: '';
        $data['css_version'] = $this->getSetting('custom_css_version') ?: '1';
        $data["session"] = new \Core\SessionProxy();

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
        // page_config can override order field and direction
        $orderField = $config['sort']['field'];
        $orderDir = $config['sort']['order'];
        if (!empty($config['order_field'])) {
            $structureAll = $this->database->getTableStructure($tableName);
            $customField = preg_replace('/[^a-zA-Z_]/', '', $config['order_field']);
            $validFields = array_column($structureAll, 'name');
            if (in_array($customField, $validFields)) {
                $orderField = $customField;
            }
        }
        if (!empty($config['order_dir'])) {
            $orderDir = strtoupper($config['order_dir']) === 'DESC' ? 'DESC' : 'ASC';
        }
        // GET-based sort override (from sort_options in page_config)
        if (!empty($_GET['sort']) && !empty($config['sort_options'][$_GET['sort']])) {
            $opt = $config['sort_options'][$_GET['sort']];
            $structureAll = $this->database->getTableStructure($tableName);
            $customField = preg_replace('/[^a-zA-Z_]/', '', $opt['field']);
            $validFields = array_column($structureAll, 'name');
            if (in_array($customField, $validFields)) {
                $orderField = $customField;
                $orderDir = strtoupper($opt['order']) === 'DESC' ? 'DESC' : 'ASC';
            }
        }
        $orderBy = "{$orderField} {$orderDir}";
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
        
        // Build query string for pagination (exclude 'page' param)
        $queryParams = $_GET;
        unset($queryParams['page']);
        $queryString = http_build_query($queryParams);

        $this->render($template, [
            'items' => $data,
            'nav_item' => $navItem,
            'structure' => $structure,
            'title' => $navItem->title,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'config' => $config,
            'query_string' => $queryString
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
            
            // Increment view count if column exists
            if (in_array('views_count', $columnNames) && isset($item['id'])) {
                try {
                    $this->database->query(
                        "UPDATE {$tableName} SET views_count = views_count + 1 WHERE id = ?",
                        [$item['id']]
                    );
                } catch (\Exception $e) {
                    // Ignore view count errors
                }
            }
            
            // Получаем соседние записи для навигации (если есть created_at)
            $prevItem = null;
            $nextItem = null;
            
            // prev/next: sort_order предпочтительнее, затем created_at
            if (in_array('sort_order', $columnNames) && isset($item['sort_order'])) {
                $prevItem = $this->database->query(
                    "SELECT * FROM {$tableName} WHERE sort_order < ? AND status = 'active' ORDER BY sort_order DESC LIMIT 1",
                    [$item['sort_order']]
                )->fetch();
                $nextItem = $this->database->query(
                    "SELECT * FROM {$tableName} WHERE sort_order > ? AND status = 'active' ORDER BY sort_order ASC LIMIT 1",
                    [$item['sort_order']]
                )->fetch();
            } elseif (in_array('created_at', $columnNames) && isset($item['created_at'])) {
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
            
            // Sidebar: все активные записи (для навигации)
            $orderCol = in_array('sort_order', $columnNames) ? 'sort_order' : 'id';
            $allItems = $this->database->query(
                "SELECT * FROM {$tableName} WHERE status = 'active' ORDER BY {$orderCol} ASC"
            )->fetchAll();

            $this->render($template, [
                'item' => $item,
                'nav_item' => $parentNav,
                'prev_item' => $prevItem,
                'next_item' => $nextItem,
                'items' => $allItems,
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
     * Обработка страницы-формы (page_type: form)
     * Использует новую систему форм с FormRenderer
     */
private function handleFormSubmission() {
        // Новая система: используем form_name
        $formName = $_POST['form_name'] ?? '';
        if (!empty($formName)) {
            $formRenderer = new \Core\FormRenderer($this->database, $this->twig, $this->config);
            $result = $formRenderer->processSubmission();
            
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
            
            if ($result['success']) {
                $_SESSION['form_success'] = $formName;
                $_SESSION['form_message'] = $result['message'];
            } else {
                $_SESSION['form_error'] = $formName;
                $_SESSION['form_error_message'] = $result['message'];
                $_SESSION['form_data'] = $_POST;
            }
            
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            $hash = $_POST['form_hash'] ?? '';
            $redirectUrl = $referer . ($hash ? '#' . ltrim($hash, '#') : '');
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Старая система: обратная совместимость (form_table)
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
                if ($this->isSystemField($fieldName)) continue;
                if (isset($_POST[$fieldName])) {
                    $formData[$fieldName] = trim($_POST[$fieldName]);
                }
            }
            
            $newId = $this->database->insert($tableName, $formData);
            $this->sendNotificationsForCustomForm($tableName, $formData, $newId);
            
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Форма успешно отправлена!', 'id' => $newId]);
                exit;
            }
            
            $_SESSION['form_success'] = true;
            $_SESSION['form_message'] = 'Форма успешно отправлена!';
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header('Location: ' . $referer);
            exit;
            
        } catch (\Exception $e) {
            $_SESSION['form_error'] = $e->getMessage();
            $_SESSION['form_data'] = $_POST;
            
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header('Location: ' . $referer);
            exit;
        }
    }

    /**
     * Отправка уведомлений для произвольных форм (не привязанных к страницам)
     */

    private function handleFormPage($navItem) {
        $config = $navItem->getPageConfig();
        $tableName = $config['source_table'];

        // Загружаем связанную страницу (page_id), если указана
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

        if (!in_array($tableName, $this->database->getTables())) {
            $this->show404();
            return;
        }
        
        // Обработка отправки формы
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $_POST['form_name'] = $tableName;
            $formRenderer = new \Core\FormRenderer($this->database, $this->twig, $this->config);
            $result = $formRenderer->processSubmission();
            
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            if ($result['success']) {
                $_SESSION['form_success'] = $tableName;
                $_SESSION['form_message'] = $result['message'];
            } else {
                $_SESSION['form_error'] = $tableName;
                $_SESSION['form_error_message'] = $result['message'];
                $_SESSION['form_data'] = $_POST;
            }
            header('Location: ' . $referer);
            exit;
        }
        
        // Показываем форму
        $formRenderer = new \Core\FormRenderer($this->database, $this->twig, $this->config);
        $options = [];
        if ($config['template'] !== 'default' && $config['template'] !== 'form') {
            $options['template'] = $config['template'];
        }
        $formHtml = $formRenderer->renderForm($tableName, $options);

        // Flash-сообщения из сессии (после редиректа с предыдущей отправки)
        $errors = [];
        $success = null;
        $successMessage = null;

        if (isset($_SESSION['form_error']) && $_SESSION['form_error'] === $tableName) {
            $errors['general'] = $_SESSION['form_error_message'] ?? 'Проверьте правильность заполнения формы';
            unset($_SESSION['form_error'], $_SESSION['form_error_message'], $_SESSION['form_data']);
        }
        if (isset($_SESSION['form_success']) && $_SESSION['form_success'] === $tableName) {
            $success = true;
            $successMessage = $_SESSION['form_message'] ?? 'Форма успешно отправлена!';
            unset($_SESSION['form_success'], $_SESSION['form_message']);
        }
        
        $template = $config['template'] === 'default' ? 'form.html.twig' : $config['template'] . '.html.twig';
        
        $this->render($template, [
            'nav_item' => $navItem,
            'form_html' => $formHtml,
            'title' => $pageMeta['title'] ?? $navItem->title,
            'meta_title' => $pageMeta['meta_title'] ?? '',
            'meta_description' => $pageMeta['meta_description'] ?? '',
            'config' => $config,
            'pageContent' => $pageContent,
            'pageMeta' => $pageMeta,
            'errors' => $errors,
            'success' => $success,
            'success_message' => $successMessage
        ]);
    }



    /**
     * Определение типа поля на основе имени и типа данных
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

    /**
     * Обработка страницы поиска
     */
    private function handleSearch($navItem) {
        $query = trim($_GET['q'] ?? '');
        $results = [];

        if (!empty($query) && mb_strlen($query) >= 2) {
            $searchTerm = '%' . $query . '%';
            $allTables = $this->database->getTables();
            $skipTables = ['sqlite_sequence', 'system_settings', 'navigation', 'visit_stats'];

            // Collect searchable tables from active navigation
            $navItems = $this->database->query(
                "SELECT source_table, url FROM navigation WHERE status = 'active' AND source_table IS NOT NULL AND source_table != ''"
            )->fetchAll();

            // Map: table_name => base_url
            $tableUrls = [];
            $seen = [];
            foreach ($navItems as $nav) {
                $t = $nav['source_table'];
                if (!isset($seen[$t])) {
                    $tableUrls[$t] = '/' . ltrim($nav['url'], '/');
                    $seen[$t] = true;
                }
            }
            // Always search pages
            $tableUrls['pages'] = '/';

            foreach ($tableUrls as $tableName => $baseUrl) {
                if (!in_array($tableName, $allTables)) continue;
                if (in_array($tableName, $skipTables)) continue;

                // Find text columns via PRAGMA
                $structure = $this->database->getTableStructure($tableName);
                $textColumns = [];
                foreach ($structure as $col) {
                    $type = strtolower($col['type']);
                    $name = strtolower($col['name']);
                    if (strpos($type, 'text') !== false ||
                        strpos($type, 'varchar') !== false ||
                        strpos($type, 'char') !== false) {
                        if (in_array($name, ['status', 'slug', 'url', 'image', 'email', 'phone', 'password', 'token', 'api_key', 'meta_title', 'meta_description'])) continue;
                        $textColumns[] = $col['name'];
                    }
                }
                if (empty($textColumns)) continue;

                // Build WHERE with LIKE on all text columns
                $likeParts = [];
                $params = [];
                foreach ($textColumns as $col) {
                    $likeParts[] = '"' . $col . '" LIKE ?';
                    $params[] = $searchTerm;
                }
                $whereClause = implode(' OR ', $likeParts);

                // Add status filter if column exists
                $colNames = array_column($structure, 'name');
                $hasStatus = in_array('status', $colNames);
                $statusClause = $hasStatus ? " AND status = 'active'" : '';

                try {
                    $rows = $this->database->query(
                        "SELECT * FROM \"{$tableName}\" WHERE ({$whereClause}){$statusClause} LIMIT 20",
                        $params
                    )->fetchAll();
                } catch (\Exception $e) {
                    continue; // Skip broken tables
                }

                $hasSlug = in_array('slug', $colNames);

                foreach ($rows as $row) {
                    // Best title column
                    $title = $row['title'] ?? $row['name'] ?? $row['summary'] ?? $row['heading'] ?? 'Untitled';

                    // Build URL
                    if ($tableName === 'pages') {
                        $itemUrl = '/' . ltrim(($row['slug'] ?? ''), '/');
                    } else {
                        $slug = $row['slug'] ?? $row['id'] ?? '';
                        $itemUrl = rtrim($baseUrl, '/') . '/' . $slug;
                    }

                    // Build snippet with highlight
                    $snippet = $this->buildSearchSnippet($row, $query, $textColumns);

                    // Calculate relevance score
                    $relevance = $this->calculateSearchRelevance($row, $query, $textColumns);

                    $results[] = [
                        'title' => $title,
                        'url' => $itemUrl,
                        'snippet' => $snippet,
                        'relevance' => $relevance
                    ];
                }
            }

            // Deduplicate by URL
            $seenUrls = [];
            $results = array_values(array_filter($results, function($r) use (&$seenUrls) {
                $key = $r['url'];
                if (isset($seenUrls[$key])) return false;
                $seenUrls[$key] = true;
                return true;
            }));

            // Sort by relevance (highest first)
            usort($results, function($a, $b) {
                return $b['relevance'] - $a['relevance'];
            });
        }

        $this->render('search.html.twig', [
            'title' => $navItem->title,
            'query' => $query,
            'results' => $results,
            'results_count' => count($results)
        ]);
    }

    /**
     * Build search result snippet with highlighted query
     */
    private function buildSearchSnippet($row, $query, $textColumns) {
        $fullText = '';
        foreach ($textColumns as $col) {
            if (!empty($row[$col])) {
                $fullText .= strip_tags($row[$col]) . ' ';
            }
        }

        $pos = mb_stripos($fullText, $query);
        $maxLen = 200;

        if ($pos !== false) {
            $start = max(0, $pos - 80);
            $snippet = mb_substr($fullText, $start, $maxLen);
            if ($start > 0) $snippet = '...' . $snippet;
            if (mb_strlen($fullText) > $start + $maxLen) $snippet .= '...';
            $snippet = preg_replace('/(' . preg_quote($query, '/') . ')/iu', '<mark>$1</mark>', $snippet);
            return $snippet;
        }

        return mb_substr($fullText, 0, $maxLen) . (mb_strlen($fullText) > $maxLen ? '...' : '');
    }

    /**
     * Calculate relevance score for a search result
     */
    private function calculateSearchRelevance($row, $query, $textColumns) {
        $score = 0;
        $queryLower = mb_strtolower($query);

        // Priority title columns (highest weight)
        $titleCols = ['title', 'name', 'summary', 'heading'];
        foreach ($titleCols as $col) {
            if (!empty($row[$col])) {
                $val = mb_strtolower(strip_tags($row[$col]));
                if ($val === $queryLower) {
                    $score += 100;
                } elseif (mb_strpos($val, $queryLower) === 0) {
                    $score += 50;
                } elseif (mb_strpos($val, $queryLower) !== false) {
                    $score += 30;
                }
                break;
            }
        }

        // Content columns
        foreach ($textColumns as $col) {
            if (in_array(strtolower($col), $titleCols)) continue;
            if (!empty($row[$col])) {
                $val = mb_strtolower(strip_tags($row[$col]));
                $score += mb_substr_count($val, $queryLower) * 5;
            }
        }

        return $score;
    }
}
