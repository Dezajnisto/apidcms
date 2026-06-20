<?php
/**
 * Middleware для аутентификации администратора
 */

namespace Admin\Core;

class AuthMiddleware {
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Проверка аутентификации
     */
    public function authenticate() {
        // Запускаем сессию
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Проверяем, авторизован ли пользователь
        if ($this->isAuthenticated()) {
            // Обновляем время последней активности
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        // Пытаемся аутентифицировать через HTTP Basic Auth
        if ($this->checkBasicAuth()) {
            $_SESSION['authenticated'] = true;
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        // Если не авторизован - показываем форму входа
        $this->showLoginForm();
        return false;
    }
    
    /**
     * Проверка, авторизован ли пользователь
     */
    private function isAuthenticated() {
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            return false;
        }
        
        // Проверяем таймаут сессии
        if (isset($_SESSION['last_activity'])) {
            $timeout = $this->config['security']['session_timeout'];
            if (time() - $_SESSION['last_activity'] > $timeout) {
                $this->logout();
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Проверка HTTP Basic Authentication
     */
    private function checkBasicAuth() {
        // Проверяем, переданы ли credentials
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }
        
        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        $configUsername = $this->config['security']['admin_username'];
        $configPassword = $this->config['security']['admin_password'];
        
        return ($username === $configUsername && $password === $configPassword);
    }
    
    /**
     * Показать форму входа
     */
    private function showLoginForm() {
        header('WWW-Authenticate: Basic realm="Admin Panel"');
        header('HTTP/1.0 401 Unauthorized');
        
        // Красивая HTML страница вместо стандартного браузерного окна
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            // Если credentials были переданы, но неверные
            echo $this->getLoginPage(true);
        } else {
            echo $this->getLoginPage(false);
        }
        exit;
    }
    
    /**
     * Генерация HTML страницы входа
     */
    private function getLoginPage($showError = false) {
        $errorHtml = $showError ? '<div class="error-message">Неверные учетные данные</div>' : '';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ к панели управления</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .login-header p {
            color: #666;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .login-info {
            background: #e8f4fc;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 0.9em;
            color: #0c5460;
        }
        .login-button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .login-button:hover {
            background: #0056b3;
        }
        .alternative {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Панель управления</h1>
            <p>Требуется авторизация</p>
        </div>
        
        {$errorHtml}
        
        <div class="login-info">
            <strong>Информация:</strong> Для доступа к панели управления введите логин и пароль администратора.
        </div>
        
        <p style="text-align: center; margin-bottom: 20px;">
            Браузер запросит логин и пароль для доступа.
        </p>
        
        <div class="alternative">
            Если окно авторизации не появилось, <a href="javascript:location.reload()">обновите страницу</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Выход из системы
     */
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        session_unset();
        session_destroy();
        
        // Показываем сообщение о выходе
        echo $this->getLogoutPage();
        exit;
    }
    
    /**
     * Генерация страницы выхода
     */
    private function getLogoutPage() {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход из системы</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .logout-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }
        .success-message {
            color: #28a745;
            font-size: 1.2em;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 5px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="success-message">Вы успешно вышли из системы</div>
        <p>Для повторного входа в панель управления:</p>
        <div style="margin-top: 20px;">
            <a href="/admin/" class="btn">Войти снова</a>
            <a href="/" class="btn" style="background: #6c757d;">На главную</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
?>