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
            'title' => $this->lang->t('plugins.title'),
            'plugins' => $plugins
        ]);
    }

    public function toggle($name)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $plugin = $pm->getPlugin($name);
            if (!$plugin) {
                $this->setFlash('error', $this->lang->t('plugins.not_found', ['name' => $name]));
                $this->redirect('/plugins');
                return;
            }
            if (!empty($plugin['enabled'])) {
                $pm->deactivate($name);
                $this->setFlash('success', $this->lang->t('plugins.deactivated', ['name' => $name]));
            } else {
                $pm->activate($name);
                $this->setFlash('success', $this->lang->t('plugins.activated', ['name' => $name]));
            }
        } catch (\Throwable $e) {
            $this->setFlash('error', $this->lang->t('common.error') . ': ' . $e->getMessage());
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
            $this->setFlash('error', $this->lang->t('plugins.not_found', ['name' => $name]));
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
        $rawConfig = json_encode(
            $this->manifestPreview($plugin),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $this->render('plugins/view', [
            'title' => $this->lang->t('plugins.plugin_title', ['name' => $name]),
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
            $this->setFlash('error', $this->lang->t('plugins.not_found', ['name' => $name]));
            $this->redirect('/plugins');
            return;
        }

        $templatesDir = $plugin['path'] . '/views';
        $filePath = $templatesDir . '/' . basename($file);

        $realFilePath = realpath($filePath) ?: '';
        $realTemplatesDir = realpath($templatesDir) ?: '___';
        if (strpos($realFilePath, $realTemplatesDir) !== 0) {
            $this->setFlash('error', $this->lang->t('plugins.edit_tpl.error_path'));
            $this->redirect("/plugins/{$name}?tab=templates");
            return;
        }

        if (!file_exists($filePath)) {
            $this->setFlash('error', $this->lang->t('plugins.edit_tpl.error_notfound'));
            $this->redirect("/plugins/{$name}?tab=templates");
            return;
        }

        // Сохранение
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newContent = $_POST['content'] ?? '';
            if (file_put_contents($filePath, $newContent) !== false) {
                $this->setFlash('success', $this->lang->t('plugins.edit_tpl.saved'));
                $this->redirect("/plugins/{$name}?tab=templates");
                return;
            } else {
                $this->setFlash('error', $this->lang->t('plugins.edit_tpl.error_save'));
            }
        }

        $content = file_get_contents($filePath);
        $this->render('plugins/edit_template', [
            'title' => $this->lang->t('plugins.edit_template_title', ['file' => $file]),
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

    /**
     * What the "plugin.json" tab shows: the manifest as it is on disk, i.e.
     * defaults only. Values from the database are not dumped into the page and
     * secret ones are masked — the screen is reachable from the admin panel and
     * ends up in screenshots much more often than it should.
     */
    private function manifestPreview(array $plugin): array
    {
        unset($plugin['path']);

        if (!empty($plugin['settings']) && is_array($plugin['settings'])) {
            foreach ($plugin['settings'] as $index => $setting) {
                if (!is_array($setting)) {
                    continue;
                }
                unset($setting['from_db']);

                $manifestValue = array_key_exists('manifest_value', $setting)
                    ? $setting['manifest_value']
                    : ($setting['value'] ?? null);
                unset($setting['manifest_value']);

                $setting['value'] = !empty($setting['secret']) && is_string($manifestValue) && $manifestValue !== ''
                    ? '***'
                    : $manifestValue;

                $plugin['settings'][$index] = $setting;
            }
        }

        return $plugin;
    }

    /**
     * Сохранение настроек плагина.
     *
     * Значения теперь пишутся в таблицу plugin_settings, а не в plugin.json.
     * Манифест — это код: он коммитится и уезжает деплоем. Персональные и
     * секретные значения (ключ оплаты, ключ подписи) в нём жить не должны —
     * именно так живой ключ ЮKassa годами ездил через git и бэкапы кода.
     */
    private function handleSaveSettings(array $plugin, string $name): void
    {
        try {
            $store = new \Core\PluginSettings($this->db);
        } catch (\Throwable $e) {
            $this->setFlash('error', $this->lang->t('plugins.settings_error') . ': ' . $e->getMessage());
            return;
        }

        foreach ($plugin['settings'] ?? [] as $setting) {
            if (!is_array($setting) || !isset($setting['key'])) {
                continue;
            }

            $key = (string) $setting['key'];
            $field = 'setting_' . $key;

            if (!empty($setting['secret'])) {
                // Empty submit means "leave as it is": the form never renders the
                // current value, so it cannot be posted back. Clearing is an
                // explicit checkbox, otherwise a masked field would overwrite
                // the real key with nothing.
                if (isset($_POST['clear_' . $field])) {
                    $store->delete($name, $key);
                    continue;
                }
                $posted = $_POST[$field] ?? '';
                if (is_string($posted) && $posted !== '') {
                    $store->set($name, $key, $posted);
                }
                continue;
            }

            if (($setting['type'] ?? 'text') === 'checkbox') {
                $store->set($name, $key, isset($_POST[$field]) ? '1' : '0');
                continue;
            }

            if (isset($_POST[$field])) {
                $store->set($name, $key, (string) $_POST[$field]);
            }
        }

        $this->setFlash('success', $this->lang->t('plugins.settings_saved', ['name' => $name]));
    }
}
