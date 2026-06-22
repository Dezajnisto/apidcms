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

            $this->redirect('/design/css?saved=1');
        } catch (Exception $e) {
            $this->redirect('/design/css?error=' . urlencode($e->getMessage()));
        }
    }
}
