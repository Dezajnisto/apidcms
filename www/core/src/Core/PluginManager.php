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

    /** @var array<string, bool> */
    private array $loadedPlugins = [];

    /** @var string */
    private string $pluginsDir;

    /** Values from plugin_settings already overlaid on the manifests. */
    private bool $settingsOverlaid = false;

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
     * Загрузить все активные плагины.
     *
     * Порядок загрузки — по зависимостям, а не по алфавиту. Раньше плагины
     * перебирались в том порядке, в каком их отдаёт glob(), то есть по имени
     * каталога, а зависимости проверялись по уже загруженным. Плагин, чья
     * зависимость сортируется позже по алфавиту, не грузился НИКОГДА:
     * например enfesto-license зависит от subscription, а «e» раньше «s».
     *
     * Теперь три шага: прочитать все plugin.json, вычислить порядок
     * (зависимости первыми), загрузить init.php в этом порядке.
     */
    public function loadPlugins(?Database $db = null): void
    {
        $this->db = $db;

        if (!is_dir($this->pluginsDir)) {
            return;
        }

        $registry = $this->scanPlugins();
        if (!$registry) {
            return;
        }

        // Регистрируем все плагины сразу: админке нужен полный список, а
        // проверка зависимостей больше не зависит от того, что успело
        // загрузиться до текущего момента.
        foreach ($registry as $name => $config) {
            $this->plugins[$name] = $config;
        }

        // Values live in the database, not in the manifest (see
        // Core\PluginSettings). Overlay them BEFORE init.php runs: a plugin may
        // read its settings while loading. bootstrap.php calls loadPlugins()
        // without a database, Admin\App and FrontController call it again with
        // one — the overlay has to catch up on that second call, which is why
        // it is not guarded by "already loaded".
        $this->settingsOverlaid = false;
        $this->overlaySettings();

        [$order, $problems] = $this->resolveLoadOrder($registry);

        foreach ($order as $name) {
            $this->loadPlugin($registry[$name]);
        }

        // Причина отказа — конкретная, а не «не удовлетворены зависимости»:
        // иначе владелец сайта ищет проблему в своих plugin.json наугад.
        foreach ($problems as $name => $reason) {
            error_log("PluginManager: плагин '" . $name . "' не загружен — " . $reason);
        }

        // Выполняем миграции для активных плагинов (только если есть DB)
        if ($this->db !== null) {
            $this->doAction('db.migrate', $this->db);
        }
    }

    /**
     * Прочитать все plugin.json из plugins/ (без загрузки кода).
     *
     * @return array<string, array> [имя плагина => конфиг с добавленным path]
     */
    private function scanPlugins(): array
    {
        $dirs = glob($this->pluginsDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs); // одинаковая выборка при одинаковых данных

        $registry = [];
        foreach ($dirs as $pluginDir) {
            $pluginJson = $pluginDir . '/plugin.json';
            if (!file_exists($pluginJson)) {
                continue;
            }

            $config = json_decode((string) file_get_contents($pluginJson), true);
            if (!$config || empty($config['name'])) {
                continue;
            }

            $config['path'] = $pluginDir;
            $registry[$config['name']] = $config;
        }

        return $registry;
    }

    /**
     * Порядок загрузки: сначала зависимости, потом зависимые.
     *
     * @param array<string, array> $registry
     * @return array{0: string[], 1: array<string, string>} [порядок, причины отказа]
     */
    private function resolveLoadOrder(array $registry): array
    {
        $order = [];
        $problems = [];
        $state = [];

        foreach (array_keys($registry) as $name) {
            $this->visitPlugin($name, $registry, $state, $order, $problems);
        }

        return [$order, $problems];
    }

    /**
     * Обход в глубину: плагин попадает в очередь после своих зависимостей.
     *
     * @param array<string, int>    $state    1 — в процессе (значит цикл), 2 — разобран
     * @param string[]              $order    очередь загрузки
     * @param array<string, string> $problems имя => причина, почему не грузим
     */
    private function visitPlugin(string $name, array $registry, array &$state, array &$order, array &$problems): void
    {
        $current = $state[$name] ?? 0;
        if ($current === 2) {
            return;
        }
        if ($current === 1) {
            $problems[$name] = 'циклическая зависимость';
            return;
        }

        $state[$name] = 1;

        // Выключенные плагины регистрируем, но не грузим: они нужны списку в
        // админке и не должны тянуть за собой ничего.
        if (empty($registry[$name]['enabled'])) {
            $state[$name] = 2;
            return;
        }

        foreach ($registry[$name]['dependencies'] ?? [] as $dep) {
            $dep = (string) $dep;

            if (!isset($registry[$dep])) {
                $problems[$name] = "нужен плагин '" . $dep . "', а его нет в plugins/";
                continue;
            }
            if (empty($registry[$dep]['enabled'])) {
                $problems[$name] = "нужен плагин '" . $dep . "', но он выключен";
                continue;
            }

            $this->visitPlugin($dep, $registry, $state, $order, $problems);

            if (isset($problems[$dep])) {
                // Причину передаём дальше: иначе вся цепочка жалуется на
                // «не загружается» и настоящая поломка теряется в конце.
                $problems[$name] = "нужен плагин '" . $dep . "', а он не загружается"
                    . ($problems[$dep] === 'циклическая зависимость' ? ' (циклическая зависимость)' : '');
            }
        }

        $state[$name] = 2;
        if (!isset($problems[$name])) {
            $order[] = $name;
        }
    }

    /**
     * Загрузить init.php одного плагина — ровно один раз за запрос.
     */
    private function loadPlugin(array $config): void
    {
        $name = $config['name'];

        if (!empty($this->loadedPlugins[$name])) {
            // Do NOT reset the registry entry here: it would drop the settings
            // overlaid from the database (see overlaySettings). loadPlugins()
            // and activate() already write the config where it belongs.
            return;
        }

        $initFile = $config['path'] . '/init.php';
        if (!file_exists($initFile)) {
            // Плагин без init.php — это тоже нормально (только метаданные):
            // считаем его активным, иначе от него нельзя будет включить зависимый.
            $this->loadedPlugins[$name] = true;
            return;
        }

        try {
            $pm = $this; // плагин получает доступ к менеджеру через $pm
            require $initFile;
            $this->loadedPlugins[$name] = true;
        } catch (\Throwable $e) {
            error_log("PluginManager: plugin load error '" . $name . "': " . $e->getMessage());
        }
    }

    /**
     * Overlay stored settings on every loaded manifest.
     *
     * Runs after scanPlugins() and before init.php. Without a database (the
     * first call from bootstrap.php) it does nothing: manifests stay as-is and
     * the admin/front passes with a database fill them in.
     */
    private function overlaySettings(): void
    {
        if ($this->db === null || $this->settingsOverlaid) {
            return;
        }

        try {
            $store = new PluginSettings($this->db);
        } catch (\Throwable $e) {
            error_log('PluginManager: plugin_settings unavailable: ' . $e->getMessage());
            $this->settingsOverlaid = true;
            return;
        }

        foreach ($this->plugins as $name => $config) {
            if (empty($config['settings']) || !is_array($config['settings'])) {
                continue;
            }

            $adopted = [];
            $stale = [];
            $this->plugins[$name]['settings'] = $store->applyTo($name, $config['settings'], $adopted, $stale);

            foreach ($stale as $key) {
                error_log("PluginManager: {$name}.{$key} is a secret in plugin.json but the database already has a value — the file copy is ignored and removed");
            }

            if ($adopted || $stale) {
                $blank = array_merge($adopted, $stale);

                if ($adopted) {
                    // A secret was still in the file: it is in the database now, so
                    // blank it (best effort — on production plugins/ is not
                    // writable for PHP and the deploy brings the blanked file
                    // anyway). One error_log line per key, so it is visible that
                    // the site was migrated and not silently reconfigured.
                    foreach ($adopted as $key) {
                        error_log("PluginManager: plugin_settings adopted {$name}.{$key} from plugin.json");
                    }
                }

                if (!$this->writeManifest($name, $blank)) {
                    error_log("PluginManager: could not blank secrets in {$name}/plugin.json (not writable) — remove them by hand");
                } else {
                    // Keep in-memory state honest: the file no longer holds the
                    // secret, so a later activate()/deactivate() must not write
                    // it back from the old manifest_value.
                    foreach ($this->plugins[$name]['settings'] as $index => $setting) {
                        if (in_array((string) ($setting['key'] ?? ''), $blank, true)) {
                            $this->plugins[$name]['settings'][$index]['manifest_value'] = '';
                        }
                    }
                }
            }
        }

        $this->settingsOverlaid = true;
    }

    /**
     * Загрузить плагины поверх уже прочитанных манифестов (для тестов).
     */
    public function reloadSettings(?Database $db = null): void
    {
        if ($db !== null) {
            $this->db = $db;
        }
        $this->settingsOverlaid = false;
        $this->overlaySettings();
    }

    /**
     * Сохранить plugin.json, не записывая в него служебные поля и ЗНАЧЕНИЯ
     * настроек.
     *
     * Раньше activate()/deactivate() писали в файл весь конфиг вместе с
     * абсолютным путём к каталогу плагина на ЭТОМ сервере. Путь уезжал в git
     * проекта (на bajto.ru так и лежит /home/c91469/... от старого хостинга)
     * и ничего не значил при следующем деплое.
     *
     * Теперь значения настроек в файл не попадают вообще: они живут в
     * plugin_settings, а в манифесте остаётся значение по умолчанию. Иначе
     * переключение галочки «включён» возвращало бы секретный ключ в файл —
     * ровно та утечка, которую вынесли из манифеста.
     *
     * @param string[] $blankKeys keys whose value must be written empty
     */
    private function saveConfig(array $config, array $blankKeys = []): void
    {
        $path = $config['path'];
        file_put_contents(
            $path . '/plugin.json',
            json_encode(
                $this->manifestForSave($config, $blankKeys),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * Rewrite plugin.json for one plugin (used by the adoption path).
     */
    private function writeManifest(string $name, array $blankKeys = []): bool
    {
        $config = $this->plugins[$name] ?? null;
        if (!$config || empty($config['path'])) {
            return false;
        }

        $json = json_encode(
            $this->manifestForSave($config, $blankKeys),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return false;
        }

        return @file_put_contents($config['path'] . '/plugin.json', $json) !== false;
    }

    /**
     * Build the manifest as it is written to disk: no runtime fields, no
     * values taken from the database — the file keeps what git keeps.
     */
    private function manifestForSave(array $config, array $blankKeys = []): array
    {
        unset($config['path']);

        if (!empty($config['settings']) && is_array($config['settings'])) {
            foreach ($config['settings'] as $index => $setting) {
                if (!is_array($setting)) {
                    continue;
                }
                unset($setting['from_db']);

                if (array_key_exists('manifest_value', $setting)) {
                    $setting['value'] = $setting['manifest_value'];
                    unset($setting['manifest_value']);
                }

                if ($blankKeys && in_array((string) ($setting['key'] ?? ''), $blankKeys, true)) {
                    $setting['value'] = '';
                }

                $config['settings'][$index] = $setting;
            }
        }

        return $config;
    }

    /**
     * Получить список всех плагинов
     *
     * @return array<int, array{name:string, version:string, enabled:bool, dependencies:array, path:string, config:array}>
     */
    public function getPlugins(): array
    {
        $this->overlaySettings();
        return array_values($this->plugins);
    }

    /**
     * Получить информацию об одном плагине
     *
     * @return array{name:string, version:string, enabled:bool, dependencies:array, path:string, config:array}|null
     */
    public function getPlugin(string $name): ?array
    {
        $this->overlaySettings();
        return $this->plugins[$name] ?? null;
    }

    /**
     * Соединение с БД, с которым загружались плагины.
     *
     * Плагинам нужен тот же PDO, что у ядра: своё соединение — это второй
     * коннект на каждый запрос и отдельный набор блокировок SQLite.
     */
    public function getDatabase(): ?Database
    {
        return $this->db;
    }

    /**
     * Get plugin-to-tables mapping from plugin.json declarations
     *
     * @return array<string, string[]> [plugin_name => [table1, table2, ...]]
     */
    public function getPluginTables(): array
    {
        $result = [];
        foreach ($this->plugins as $name => $config) {
            if (!empty($config['tables']) && is_array($config['tables'])) {
                $result[$name] = $config['tables'];
            }
        }
        return $result;
    }

    /**
     * Активировать плагин
     */
    public function activate(string $name): bool
    {
        if (!isset($this->plugins[$name])) {
            return false;
        }

        // Включать плагин без включённых зависимостей нельзя: его init.php
        // рассчитывает на классы и хуки зависимости, падение будет непонятным.
        // Причина уходит в админку исключением — её показывают во flash.
        foreach ($this->plugins[$name]['dependencies'] ?? [] as $dep) {
            if (!isset($this->plugins[$dep])) {
                throw new \RuntimeException("плагин '" . $name . "' требует '" . $dep . "', а его нет в plugins/");
            }
            if (empty($this->plugins[$dep]['enabled'])) {
                throw new \RuntimeException("плагин '" . $name . "' требует '" . $dep . "' — сначала включите его");
            }
            if (empty($this->loadedPlugins[$dep])) {
                // Зависимость включена, но не загрузилась (её саму отсеяли по
                // цепочке) — иначе получим фатал в init.php.
                throw new \RuntimeException("плагин '" . $name . "' требует '" . $dep . "', а он не загружен");
            }
        }

        $config = $this->plugins[$name];
        $config['enabled'] = true;

        // Сохраняем в plugin.json (без служебного path и без значений — см. saveConfig)
        $this->saveConfig($config);

        // Загружаем init.php
        $this->loadPlugin($config);

        $this->plugins[$name] = $config;

        // Запускаем миграции (только если есть DB)
        if ($this->db !== null) {
            $this->doAction('db.migrate', $this->db);
        }

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

        // Выключить плагин, от которого зависят включённые, нельзя: они
        // останутся в состоянии «включён, но его init.php не выполнялся».
        foreach ($this->plugins as $otherName => $other) {
            if ($otherName === $name || empty($other['enabled'])) {
                continue;
            }
            if (in_array($name, $other['dependencies'] ?? [], true)) {
                throw new \RuntimeException("от '" . $name . "' зависит плагин '" . $otherName . "' — сначала выключите его");
            }
        }

        $config = $this->plugins[$name];
        $config['enabled'] = false;

        // Сохраняем в plugin.json (без служебного path и без значений — см. saveConfig)
        $this->saveConfig($config);

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
