<?php
/**
 * PluginAdminController — управление плагинами в админке
 */
namespace Admin;

class PluginAdminController extends BaseController
{
    public function index()
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $plugins = $pm->getPlugins();
        } catch (\Throwable $e) {
            $plugins = [];
        }
        $this->render('plugins/index', [
            'title' => 'Плагины',
            'plugins' => $plugins
        ]);
    }

    public function toggle($name)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $plugin = $pm->getPlugin($name);
            if (!$plugin) {
                $this->setFlash('error', "Плагин '{$name}' не найден");
                $this->redirect('/plugins');
                return;
            }
            if (!empty($plugin['enabled'])) {
                $pm->deactivate($name);
                $this->setFlash('success', "Плагин '{$name}' деактивирован");
            } else {
                $pm->activate($name);
                $this->setFlash('success', "Плагин '{$name}' активирован");
            }
        } catch (\Throwable $e) {
            $this->setFlash('error', "Ошибка: " . $e->getMessage());
        }
        $this->redirect('/plugins');
    }

    public function view($name)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $plugin = $pm->getPlugin($name);
        } catch (\Throwable $e) {
            $plugin = null;
        }

        if (!$plugin) {
            $this->setFlash('error', "Плагин '{$name}' не найден");
            $this->redirect('/plugins');
            return;
        }

        $tab = $_GET['tab'] ?? 'info';

        // Сохранение настроек
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_settings') {
            $this->handleSaveSettings($plugin, $name);
            $this->redirect("/plugins/{$name}?tab=settings&saved=1");
            return;
        }

        $templates = $this->getPluginTemplates($plugin);
        $settings = $plugin['settings'] ?? [];
        $rawConfig = json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $this->render('plugins/view', [
            'title' => "Плагин: {$name}",
            'plugin' => $plugin,
            'plugin_name' => $name,
            'tab' => $tab,
            'templates' => $templates,
            'settings' => $settings,
            'raw_config' => $rawConfig
        ]);
    }

    public function editTemplate($name, $file)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $plugin = $pm->getPlugin($name);
        } catch (\Throwable $e) {
            $plugin = null;
        }

        if (!$plugin) {
            $this->setFlash('error', "Плагин '{$name}' не найден");
            $this->redirect('/plugins');
            return;
        }

        $templatesDir = $plugin['path'] . '/views';
        $filePath = $templatesDir . '/' . basename($file);

        $realFilePath = realpath($filePath) ?: '';
        $realTemplatesDir = realpath($templatesDir) ?: '___';
        if (strpos($realFilePath, $realTemplatesDir) !== 0) {
            $this->setFlash('error', 'Недопустимый путь к шаблону');
            $this->redirect("/plugins/{$name}?tab=templates");
            return;
        }

        if (!file_exists($filePath)) {
            $this->setFlash('error', "Шаблон '{$file}' не найден");
            $this->redirect("/plugins/{$name}?tab=templates");
            return;
        }

        // Сохранение
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newContent = $_POST['content'] ?? '';
            if (file_put_contents($filePath, $newContent) !== false) {
                $this->setFlash('success', "Шаблон '{$file}' сохранён");
                $this->redirect("/plugins/{$name}?tab=templates");
                return;
            } else {
                $this->setFlash('error', 'Не удалось сохранить шаблон');
            }
        }

        $content = file_get_contents($filePath);
        $this->render('plugins/edit_template', [
            'title' => "Редактирование: {$file}",
            'plugin' => $plugin,
            'plugin_name' => $name,
            'file_name' => $file,
            'content' => $content,
            'file_path' => $filePath
        ]);
    }

    private function getPluginTemplates(array $plugin): array
    {
        $templatesDir = $plugin['path'] . '/views';
        $templates = [];
        if (!is_dir($templatesDir)) return $templates;
        $files = scandir($templatesDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            if (preg_match('/\.twig$/', $f)) {
                $fp = $templatesDir . '/' . $f;
                $templates[] = [
                    'name' => $f,
                    'size' => filesize($fp),
                    'modified' => filemtime($fp)
                ];
            }
        }
        usort($templates, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $templates;
    }

    private function handleSaveSettings(array $plugin, string $name): void
    {
        $pluginJsonPath = $plugin['path'] . '/plugin.json';
        if (!empty($plugin['settings'])) {
            foreach ($plugin['settings'] as &$setting) {
                $key = $setting['key'];
                if (isset($_POST['setting_' . $key])) {
                    $setting['value'] = $_POST['setting_' . $key];
                    if ($setting['type'] === 'checkbox') {
                        $setting['value'] = (bool)$_POST['setting_' . $key];
                    }
                }
            }
            unset($setting);
        }
        $saved = file_put_contents(
            $pluginJsonPath,
            json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        if ($saved !== false) {
            $this->setFlash('success', "Настройки плагина '{$name}' сохранены");
        } else {
            $this->setFlash('error', 'Не удалось сохранить настройки');
        }
    }
}
