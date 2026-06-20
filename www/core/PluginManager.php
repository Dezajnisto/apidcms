<?php
/**
 * PluginManager — система плагинов apidcms
 *
 * Загружает плагины из PROJECT_ROOT/plugins/* /plugin.json
 * Предоставляет хуки: add_action, add_filter, do_action, apply_filters
 *
 * Плагин — это директория plugins/name/ с plugin.json и init.php
 * plugin.json: {"name":"account","version":"1.0","enabled":true,"dependencies":[]}
 *
 * Использование из плагина:
 *   $pm = \Core\PluginManager::getInstance();
 *   $pm->addAction('front.router.before', function($path, $fc) { ... });
 */

namespace Core;

class PluginManager
{
    private static $instance = null;

    /** @var array<string, array{name:string,version:string,enabled:bool,dependencies:array,path:string,config:array}> */
    private array $plugins = [];

    /** @var array<string, array<int, array{callback:callable, priority:int}>> */
    private array $actions = [];

    /** @var array<string, array<int, array{callback:callable, priority:int}>> */
    private array $filters = [];

    /** @var Database|null */
    private ?Database $db = null;

    /** @var string */
    private string $pluginsDir;

    /**
     * Приватный конструктор — синглтон
     */
    private function __construct(string $pluginsDir)
    {
        $this->pluginsDir = rtrim($pluginsDir, '/');
    }

    /**
     * Получить экземпляр PluginManager
     */
    public static function getInstance(?string $pluginsDir = null): self
    {
        if (self::$instance === null) {
            if ($pluginsDir === null) {
                throw new \RuntimeException('PluginManager: первый вызов getInstance() требует $pluginsDir');
            }
            self::$instance = new self($pluginsDir);
        }
        return self::$instance;
    }

    /**
     * Сбросить синглтон (для тестов)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Загрузить все активные плагины
     */
    public function loadPlugins(?Database $db = null): void
    {
        $this->db = $db;

        if (!is_dir($this->pluginsDir)) {
            return;
        }

        // Сканируем директории плагинов
        $dirs = glob($this->pluginsDir . '/*', GLOB_ONLYDIR);
        if (!$dirs) {
            return;
        }

        foreach ($dirs as $pluginDir) {
            $pluginJson = $pluginDir . '/plugin.json';
            if (!file_exists($pluginJson)) {
                continue;
            }

            $config = json_decode(file_get_contents($pluginJson), true);
            if (!$config || empty($config['name'])) {
                continue;
            }

            $pluginName = $config['name'];
            $config['path'] = $pluginDir;

            // Проверяем enabled
            if (empty($config['enabled'])) {
                $this->plugins[$pluginName] = $config;
                continue; // плагин зарегистрирован, но не загружен
            }

            // Проверяем зависимости
            if (!$this->checkDependencies($config)) {
                error_log("PluginManager: плагин '{$pluginName}' не загружен — не удовлетворены зависимости");
                $this->plugins[$pluginName] = $config;
                continue;
            }

            // Загружаем init.php плагина
            $initFile = $pluginDir . '/init.php';
            if (file_exists($initFile)) {
                try {
                    $pm = $this; // плагин получает доступ через $pm
                    require $initFile;
                    $this->plugins[$pluginName] = $config;
                } catch (\Throwable $e) {
                    error_log("PluginManager: ошибка загрузки плагина '{$pluginName}': " . $e->getMessage());
                }
            }
        }

        // Выполняем миграции для активных плагинов
        $this->doAction('db.migrate', $this->db);
    }

    /**
     * Проверить зависимости плагина
     */
    private function checkDependencies(array $config): bool
    {
        if (empty($config['dependencies'])) {
            return true;
        }

        foreach ($config['dependencies'] as $dep) {
            if (!isset($this->plugins[$dep]) || empty($this->plugins[$dep]['enabled'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получить список всех плагинов
     *
     * @return array<int, array{name:string, version:string, enabled:bool, dependencies:array, path:string, config:array}>
     */
    public function getPlugins(): array
    {
        return array_values($this->plugins);
    }

    /**
     * Получить информацию об одном плагине
     *
     * @return array{name:string, version:string, enabled:bool, dependencies:array, path:string, config:array}|null
     */
    public function getPlugin(string $name): ?array
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Активировать плагин
     */
    public function activate(string $name): bool
    {
        if (!isset($this->plugins[$name])) {
            return false;
        }

        $config = $this->plugins[$name];
        $config['enabled'] = true;

        // Сохраняем в plugin.json
        $pluginJson = $config['path'] . '/plugin.json';
        file_put_contents($pluginJson, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Загружаем init.php
        $initFile = $config['path'] . '/init.php';
        if (file_exists($initFile)) {
            try {
                $pm = $this;
                require $initFile;
            } catch (\Throwable $e) {
                error_log("PluginManager: ошибка активации '{$name}': " . $e->getMessage());
                return false;
            }
        }

        $this->plugins[$name] = $config;

        // Запускаем миграции
        $this->doAction('db.migrate', $this->db);

        return true;
    }

    /**
     * Деактивировать плагин
     */
    public function deactivate(string $name): bool
    {
        if (!isset($this->plugins[$name])) {
            return false;
        }

        $config = $this->plugins[$name];
        $config['enabled'] = false;

        // Сохраняем в plugin.json
        $pluginJson = $config['path'] . '/plugin.json';
        file_put_contents($pluginJson, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Удаляем зарегистрированные хуки этого плагина
        $this->removePluginHooks($name);

        $this->plugins[$name] = $config;

        return true;
    }

    /**
     * Удалить хуки, зарегистрированные плагином
     */
    private function removePluginHooks(string $pluginName): void
    {
        // Удаляем actions
        foreach ($this->actions as $hook => &$callbacks) {
            $callbacks = array_filter($callbacks, function ($cb) use ($pluginName) {
                return ($cb['plugin'] ?? null) !== $pluginName;
            });
        }

        // Удаляем filters
        foreach ($this->filters as $hook => &$callbacks) {
            $callbacks = array_filter($callbacks, function ($cb) use ($pluginName) {
                return ($cb['plugin'] ?? null) !== $pluginName;
            });
        }
    }

    // ======== Хуки ========

    /**
     * Зарегистрировать action-хук
     *
     * @param string   $hook     Имя хука (core.init, front.router.before, ...)
     * @param callable $callback Функция-обработчик
     * @param int      $priority Приоритет (меньше = раньше)
     * @param string   $plugin   Имя плагина (для отслеживания)
     */
    public function addAction(string $hook, callable $callback, int $priority = 10, string $plugin = ''): void
    {
        if (!isset($this->actions[$hook])) {
            $this->actions[$hook] = [];
        }

        $this->actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'plugin' => $plugin,
        ];

        // Сортируем по приоритету
        usort($this->actions[$hook], function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }

    /**
     * Зарегистрировать filter-хук
     *
     * @param string   $hook     Имя хука
     * @param callable $callback Функция-фильтр (принимает значение, возвращает изменённое)
     * @param int      $priority Приоритет
     * @param string   $plugin   Имя плагина
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10, string $plugin = ''): void
    {
        if (!isset($this->filters[$hook])) {
            $this->filters[$hook] = [];
        }

        $this->filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'plugin' => $plugin,
        ];

        usort($this->filters[$hook], function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }

    /**
     * Выполнить action-хук
     *
     * @param string $hook Имя хука
     * @param mixed  ...$args Аргументы для обработчиков
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        if (empty($this->actions[$hook])) {
            return;
        }

        foreach ($this->actions[$hook] as $entry) {
            try {
                call_user_func_array($entry['callback'], $args);
            } catch (\Throwable $e) {
                error_log("PluginManager: ошибка в action '{$hook}': " . $e->getMessage());
            }
        }
    }

    /**
     * Применить filter-хук
     *
     * @param string $hook  Имя хука
     * @param mixed  $value Значение для фильтрации
     * @param mixed  ...$args Дополнительные аргументы
     * @return mixed Отфильтрованное значение
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (empty($this->filters[$hook])) {
            return $value;
        }

        foreach ($this->filters[$hook] as $entry) {
            try {
                // Передаём value первым аргументом + дополнительные
                $allArgs = array_merge([$value], $args);
                $value = call_user_func_array($entry['callback'], $allArgs);
            } catch (\Throwable $e) {
                error_log("PluginManager: ошибка в filter '{$hook}': " . $e->getMessage());
            }
        }

        return $value;
    }

    /**
     * Проверить, зарегистрирован ли хук
     */
    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    /**
     * Проверить, зарегистрирован ли фильтр
     */
    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /**
     * Получить путь к директории плагина
     */
    public function getPluginPath(string $name): ?string
    {
        return $this->plugins[$name]['path'] ?? null;
    }
}
