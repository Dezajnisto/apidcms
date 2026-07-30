<?php
/**
 * Base admin controller with Twig support and i18n.
 */

namespace Admin;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class BaseAdminController {
    protected $twig;
    protected $config;
    protected $lang;
    
    public function __construct($config) {
        $this->config = $config;
        $this->initLang();
        $this->initTwig();
    }

    /**
     * Initialize i18n from system_settings.
     */
    private function initLang() {
        $locale = 'ru';
        try {
            $db = $this->config->getDatabase();
            $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_language'")->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                $locale = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // DB not available yet — keep default 'ru'
        }
        $this->lang = Lang::getInstance($locale);
        $this->adminLang = $locale;
    }
    
    /**
     * Initialize Twig
     */
    private function initTwig() {
        // Create cache folder if not exists
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
        
        // URL helper: /admin/ prefix
        $this->twig->addFunction(new TwigFunction('admin_url', [$this, 'generateAdminUrl']));
        
        // Range helper
        $this->twig->addFunction(new TwigFunction('range', 'range'));

        // i18n: {{ lang('key') }} in templates
        $this->twig->addFunction(new TwigFunction('lang', [$this->lang, 't']));
        
        // i18n: {{ admin_lang }} for help panel JS locale resolution
        $this->twig->addGlobal('admin_lang', $this->adminLang ?? 'ru');
    }
    
    /**
     * Generate admin URL with /admin prefix
     */
    public function generateAdminUrl($path = '') {
        return '/admin/' . ltrim($path, '/');
    }
    
    /**
     * Render template
     */
    protected function render($template, $data = []) {
        return $this->twig->render($template, $data);
    }
    
    /**
     * Send response
     */
    protected function sendResponse($content) {
        echo $content;
    }
}
