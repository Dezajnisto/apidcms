<?php
/**
 * Базовый контроллер админки с поддержкой Twig
 */

namespace Admin\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class BaseAdminController {
    protected $twig;
    protected $config;
    
    public function __construct($config) {
        $this->config = $config;
        $this->initTwig();
    }
    
    /**
     * Инициализация Twig
     */
    private function initTwig() {
        // Создаем папку для кэша, если её нет
        $cachePath = $this->config['paths']['storage'] . '/cache/twig_admin';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        
        $loader = new FilesystemLoader($this->config['paths']['admin_app'] . '/views');
        $this->twig = new Environment($loader, [
            'cache' => $cachePath,
            'auto_reload' => true,
            'debug' => true
        ]);
        
        // Добавляем функцию для генерации URL с префиксом /admin
        $this->twig->addFunction(new TwigFunction('admin_url', [$this, 'generateAdminUrl']));
        
        // Добавляем функцию для работы с массивами
        $this->twig->addFunction(new TwigFunction('range', 'range'));
    }
    
    /**
     * Генерация URL для админки с префиксом /admin
     */
    public function generateAdminUrl($path = '') {
        return '/admin/' . ltrim($path, '/');
    }
    
    /**
     * Рендер шаблона
     */
    protected function render($template, $data = []) {
        return $this->twig->render($template, $data);
    }
    
    /**
     * Отправка ответа
     */
    protected function sendResponse($content) {
        echo $content;
    }
}
?>