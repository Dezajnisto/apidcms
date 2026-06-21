<?php

class Router
{
    private $urlModel;
    private $settingsModel;
    private $redisCache;
    private $parsedown;

    public function __construct($urlModel, $settingsModel, $redisCache)
    {
        $this->urlModel = $urlModel;
        $this->settingsModel = $settingsModel;
        $this->redisCache = $redisCache;
        $this->parsedown = new Parsedown(); // Инициализация Parsedown
    }

    public function dispatch($path)
    {
        // Добавляем новый маршрут для webhook
        if ($path === '/webhook') {
            $webhookController = new WebhookController($this->redisCache, $this->urlModel);
            $webhookController->handleWebhook();
            return;
        }

        // Получение первой записи из таблицы настроек
        $settingsData = $this->settingsModel->getSettingByRecordId(1); // Используем primary key 1 для получения данных

        if (is_array($settingsData) && isset($settingsData['Pages']) && isset($settingsData['Plugins'])) {
            $pagesTableId = $settingsData['Pages'];
            $pluginsTableId = $settingsData['Plugins'];

            // Получение страницы по slug
            $page = $this->urlModel->getPageBySlug($path, $pagesTableId);

            if ($page) {
                $controllerName = 'MainController';
                $template = $page['Template'];

                // Подключаем контроллер
                require_once __DIR__ . "/controllers/{$controllerName}.php";

                // Получаем конфигурацию
                $config = require_once __DIR__ . '/config.php';

                // Проверяем, что $config является массивом и содержит ключ 'cache'
                if (is_array($config) && isset($config['cache']) && isset($config['cache']['enabled'])) {
                    // Создаем экземпляр контроллера с передачей параметра cacheEnabled
                    $controller = new $controllerName($config['cache']['enabled']);
                } else {
                    // Если конфигурация некорректна, создаем контроллер без кэширования
                    $controller = new $controllerName(false);
                }

                $data = [
                    'settingsData' => $settingsData, // Добавляем settingsData в массив данных
                ];

                if ($page['Type'] === 'Item') {
                    // Если тип страницы 'Item', просто отдаем страницу шаблону
                    $data['record'] = $page;

                    // Преобразуем Markdown в HTML, если есть поле с Markdown
                    if (isset($data['record']['Notes'])) {
                        $data['record']['Notes'] = $this->parsedown->text($data['record']['Notes']);
                    }
                } elseif ($page['Type'] === 'List') {
                    // Если тип страницы 'List', получаем список записей по группе
                    $group = $page['Group'];
                    $countGroup = $page['Count group']; // Количество записей на странице

                    // Получаем параметры пагинации из URL
                    $pageNumber = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; // Убедитесь, что pageNumber не меньше 1
                    $count = isset($_GET['count']) ? (int)$_GET['count'] : $countGroup;

                    // Рассчитываем смещение и лимит
                    $offset = ($pageNumber - 1) * $count;
                    $limit = $count;

                    // Получаем записи с учетом пагинации
                    $records = $this->urlModel->getRecordsByGroup($pagesTableId, $group, $offset, $limit);

                    // Преобразуем Markdown в HTML для каждой записи
                    foreach ($records as &$record) {
                        if (isset($record['Content'])) {
                            $record['Content'] = $this->parsedown->text($record['Content']);
                        }
                    }

                    // Получаем общее количество записей для расчета пагинации
                    $totalRecords = $this->urlModel->getTotalRecordsByGroup($pagesTableId, $group);
                    $totalPages = ceil($totalRecords / $count);

                    // Отдаем список записей и данные пагинации шаблону
                    $data['records'] = $records;
                    $data['pagination'] = [
                        'currentPage' => $pageNumber,
                        'totalPages' => $totalPages,
                        'count' => $count,
                    ];
                }

                // Получаем плагины по ключу Template
                $templatePlugins = $this->urlModel->getPluginsByTemplate($pluginsTableId, $template);

                // Получаем глобальные плагины
                $globalPlugins = $this->urlModel->getGlobalPlugins($pluginsTableId);

                // Объединяем шаблон-специфичные и глобальные плагины
                $plugins = array_merge($templatePlugins, $globalPlugins);

                // Вызываем методы плагинов, если они есть
                foreach ($plugins as $plugin) {
                    $pluginName = $plugin['Name'];
                    $pluginClass = ucfirst($pluginName) . 'Plugin';
                    $pluginPathLower = "plugins/{$pluginName}/{$pluginClass}.php";

                    // Подключаем файл плагина, если он существует
                    if (file_exists(__DIR__ . "/{$pluginPathLower}")) {
                        require_once __DIR__ . "/{$pluginPathLower}";
                    } else {
                        echo "Plugin file not found: {$pluginPathLower}\n"; // Добавьте эту строку для отладки
                        continue;
                    }

                    // Создаем экземпляр плагина и вызываем его метод
                    $pluginInstance = new $pluginClass();
                    $pluginData = $pluginInstance->execute($data, $plugin, $this->urlModel); 

                    // Объединяем данные от плагина с основными данными
                    $data = array_merge($data, $pluginData);

                    //var_dump($data); // Добавьте эту строку для отладки
                }

                // Рендерим шаблон один раз с собранными данными
                $controller->renderTemplate($template, $data);

                return;
            } else {
                echo "Page not found for slug: $path\n"; // Добавьте эту строку для отладки
            }
        } else {
            echo "Settings Data is null or missing 'pages_table' or 'plugins_table' key\n"; // Добавьте эту строку для отладки
        }

        // Если маршрут не найден, выводим 404 ошибку
        http_response_code(404);
        echo "404 Not Found";
    }
}