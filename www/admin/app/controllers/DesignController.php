<?php
/**
 * Контроллер дизайна — редактирование CSS-файла
 *
 * Стили хранятся в проекте: {ROOT_PATH}/storage/css/custom.css
 * Подключаются через <link> в base.html.twig на фронтенде.
 */

namespace Admin;

use Exception;

class DesignController extends BaseController
{
    /**
     * Путь к директории с CSS-файлами проекта
     */
    private function getCssDir(): string
    {
        $root = $this->app->getConfig()['paths']['root'];
        return $root . '/storage/css';
    }

    /**
     * Полный путь к файлу custom.css
     */
    private function getCssPath(): string
    {
        return $this->getCssDir() . '/custom.css';
    }

    /**
     * URL файла custom.css (для <link> в шаблоне)
     */
    private function getCssUrl(): string
    {
        return '/storage/css/custom.css';
    }

    /**
     * Страница редактора CSS
     */
    public function css()
    {
        try {
            $cssDir = $this->getCssDir();
            $cssPath = $this->getCssPath();

            // Создаём директорию, если её нет
            if (!is_dir($cssDir)) {
                mkdir($cssDir, 0755, true);
            }

            // Создаём пустой файл, если его нет
            if (!file_exists($cssPath)) {
                file_put_contents($cssPath, '');
            }

            $currentCss = file_get_contents($cssPath);
            $cssUrl = $this->getCssUrl();
            $saved = isset($_GET['saved']);

            $this->render('design/css', [
                'title' => 'Редактор CSS-стилей',
                'css_content' => $currentCss,
                'css_url' => $cssUrl,
                'saved' => $saved
            ]);
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => 'Ошибка загрузки редактора CSS: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Сохранить CSS в файл
     */
    public function saveCss()
    {
        try {
            $css = $_POST['css'] ?? '';
            $cssPath = $this->getCssPath();
            $cssDir = $this->getCssDir();

            if (!is_dir($cssDir)) {
                mkdir($cssDir, 0755, true);
            }

            if (file_put_contents($cssPath, $css) === false) {
                throw new Exception('Не удалось записать файл CSS');
            }

            // Автоматически сбрасываем кэш браузера — инкрементим версию
            $this->bumpCssVersion();

            $this->redirect('/design/css?saved=1');
        } catch (Exception $e) {
            $this->redirect('/design/css?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Сбросить кэш браузера для CSS
     */
    public function clearCssCache()
    {
        $this->bumpCssVersion();
        $this->redirect('/design/css?cache_cleared=1');
    }

    /**
     * Инкрементировать версию CSS (для cache-busting ?v=N)
     */
    private function bumpCssVersion()
    {
        $current = $this->getSetting('custom_css_version') ?: '0';
        $new = ((int)$current) + 1;
        $this->setSetting('custom_css_version', (string)$new);
    }

    /**
     * Получить настройку из system_settings
     */
    private function getSetting($key)
    {
        try {
            $result = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            )->fetch();
            return $result ? $result['setting_value'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Сохранить настройку в system_settings
     */
    private function setSetting($key, $value)
    {
        try {
            $existing = $this->db->query(
                "SELECT id FROM system_settings WHERE setting_key = ?",
                [$key]
            )->fetch();
            if ($existing) {
                $this->db->query(
                    "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?",
                    [$value, $key]
                );
            } else {
                $this->db->query(
                    "INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'text')",
                    [$key, $value]
                );
            }
        } catch (\Exception $e) {
            // ignore
        }
    }

}
