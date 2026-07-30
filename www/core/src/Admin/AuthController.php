<?php
/**
 * Контроллер для аутентификации
 */

namespace Admin;

class AuthController extends BaseController {
    
    /**
     * Выход из системы
     */
    public function logout() {
        $auth = new \Admin\AuthMiddleware($this->app->getConfig());
        $auth->logout();
    }
}
?>