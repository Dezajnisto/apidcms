<?php
/**
 * Контроллер дизайна — управление CSS, темами
 *
 * Позволяет редактировать глобальные CSS-стили сайта
 * через админку. Стили хранятся в system_settings (ключ custom_css)
 * и подключаются на всех страницах фронтенда.
 */

namespace Admin;

use Core\Settings;
use Exception;

class DesignController extends BaseController
{
    /**
     * Страница редактора CSS
     */
    public function css()
    {
        try {
            $settings = new Settings($this->db);
            $currentCss = $settings->get('custom_css', '');
            $saved = isset($_GET['saved']);

            $this->render('design/css', [
                'title' => 'Редактор CSS-стилей',
                'css_content' => $currentCss,
                'saved' => $saved
            ]);
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => 'Ошибка загрузки редактора CSS: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Сохранить CSS
     */
    public function saveCss()
    {
        try {
            $css = $_POST['css'] ?? '';

            $settings = new Settings($this->db);
            $settings->set('custom_css', $css, 'text');

            $this->redirect('/design/css?saved=1');
        } catch (Exception $e) {
            $this->redirect('/design/css?error=' . urlencode($e->getMessage()));
        }
    }
}
