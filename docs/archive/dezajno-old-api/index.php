<?php

// Используем относительный путь для подключения autoload.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/models/NocoDBModel.php';
require_once __DIR__ . '/models/UrlModel.php';
require_once __DIR__ . '/models/SettingsModel.php';
require_once __DIR__ . '/models/RedisCache.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/controllers/MainController.php';
require_once __DIR__ . '/controllers/WebhookController.php'; // Добавляем подключение нового контроллера

// Подключаем конфигурационный файл
$config = require_once __DIR__ . '/config.php';

// Инициализация Redis
$redisCache = new App\Models\RedisCache($config);

// Очистка кэша, если кэширование отключено
if (!$config['cache']['enabled']) {
    $redisCache->flushAll();
}

// Инициализация модели для настроек с пустым значением для срока жизни кэша
$settingsModel = new App\Models\SettingsModel(
    $config['api']['url'],
    $config['api']['token'],
    $config['tables']['settings'],
    $redisCache,
    3600 // Значение по умолчанию для срока жизни кэша
);

$settingsData = $settingsModel->getSettingByRecordId(1); // Используем primary key 1 для получения данных
$cacheTtl = isset($settingsData['Cache']) ? (int)$settingsData['Cache'] : 3600; // По умолчанию 1 час

// Инициализация модели для маршрутов
$urlModel = new App\Models\UrlModel(
    $config['api']['url'],
    $config['api']['token'],
    $redisCache,
    $cacheTtl
);

// Инициализация модели для настроек с переданным сроком жизни кэша
$settingsModel = new App\Models\SettingsModel(
    $config['api']['url'],
    $config['api']['token'],
    $config['tables']['settings'],
    $redisCache,
    $cacheTtl
);

// Инициализация маршрутизатора
$router = new Router($urlModel, $settingsModel, $redisCache); // Передаем экземпляр RedisCache в маршрутизатор

// Получение текущего пути
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Диспетчеризация запроса
$router->dispatch($path);