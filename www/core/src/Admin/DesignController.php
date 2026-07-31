<?php
/**
 * Контроллер дизайна — редактирование CSS-файла
 *
 * Styles stored in project: {ROOT_PATH}/storage/css/custom.css
 * Included via <link> in base.html.twig on the frontend.
 */

namespace Admin;

use Exception;

class DesignController extends BaseController
{
    /**
     * Path to project CSS directory
     */
    private function getCssDir(): string
    {
        $root = $this->app->getConfig()['paths']['root'];
        return $root . '/storage/css';
    }

    /**
     * Full path to custom.css
     */
    private function getCssPath(): string
    {
        return $this->getCssDir() . '/custom.css';
    }

    /**
     * URL of custom.css (for <link> in template)
     */
    private function getCssUrl(): string
    {
        return '/storage/css/custom.css';
    }

    /**
     * CSS editor page
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

            $cssSize = $this->formatFileSize(filesize($cssPath));

            $this->render('design/css', [
                'title' => $this->lang->t('design.css_title'),
                'css_content' => $currentCss,
                'css_url' => $cssUrl,
                'cssSize' => $cssSize,
                'saved' => $saved
            ]);
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => $this->lang->t('design.css_load_error') . ' ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Save CSS to file
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
                throw new Exception($this->lang->t('design.css_write_error'));
            }

            // Автоматически сбрасываем кэш браузера — инкрементим версию
            $this->bumpCssVersion();

            $this->redirect('/design/css?saved=1');
        } catch (Exception $e) {
            $this->redirect('/design/css?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Increment CSS version (for cache-busting ?v=N)
     */
    private function bumpCssVersion()
    {
        $current = $this->getSetting('custom_css_version') ?: '0';
        $new = ((int)$current) + 1;
        $this->setSetting('custom_css_version', (string)$new);
    }

    /**
     * Get setting from system_settings
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
     * Save setting to system_settings
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

    /**
     * Format file size in human-readable form
     */
    private function formatFileSize($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / 1048576, 1) . ' MB';
        }
    }

}
