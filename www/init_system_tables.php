<?php
/**
 * init_system_tables.php — инициализация системных таблиц для нового проекта
 * 
 * Запуск: php init_system_tables.php
 * Создаёт обязательные таблицы и заполняет базовые настройки.
 * Можно запускать повторно — используется IF NOT EXISTS.
 */

define('ROOT_PATH', realpath(__DIR__));
define('CORE_PATH', ROOT_PATH . '/core_lib');
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('ADMIN_ACCESS', true);
define('FRONT_ACCESS', true);
define('STORAGE_PATH', ROOT_PATH . '/storage');

require CORE_PATH . '/vendor/autoload.php';

spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'Core\\') === 0) {
        $relative = str_replace('Core\\', '', $class_name);
        $f = CORE_PATH . '/core/' . $relative . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// Определяем пути
$dbPath = ADMIN_PATH . '/storage/database/';
$dbFile = 'cms.db';
if (!is_dir($dbPath)) mkdir($dbPath, 0755, true);

$db = new Core\Database(['path' => $dbPath, 'file' => $dbFile, 'full_path' => $dbPath . $dbFile]);
$pdo = $db->getConnection();
$pdo->exec("PRAGMA foreign_keys = ON");

echo "=== Инициализация таблиц ===\n\n";

// ========== ОБЯЗАТЕЛЬНЫЕ ТАБЛИЦЫ ==========

// 1. Страницы
$pdo->exec('
    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        content TEXT,
        meta_title TEXT,
        meta_description TEXT,
        status TEXT DEFAULT "active",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
echo "[OK] pages\n";

// 2. Навигация / типы страниц
$pdo->exec('
    CREATE TABLE IF NOT EXISTS navigation (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT "",
        url TEXT NOT NULL DEFAULT "",
        page_id INTEGER DEFAULT NULL,
        page_type TEXT NOT NULL DEFAULT "page",
        source_table TEXT DEFAULT NULL,
        page_config TEXT DEFAULT NULL,
        location TEXT DEFAULT "header",
        menu_order INTEGER DEFAULT 0,
        parent_id INTEGER DEFAULT 0,
        status TEXT DEFAULT "active",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
    )
');
echo "[OK] navigation\n";

// 3. Системные настройки (заменяет старую таблицу settings)
$pdo->exec('
    CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type TEXT DEFAULT "text",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
echo "[OK] system_settings\n";

// ========== ОПЦИОНАЛЬНЫЕ ТАБЛИЦЫ ==========

// 4. Секции (для landing-страниц)
$pdo->exec('
    CREATE TABLE IF NOT EXISTS sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT,
        content TEXT,
        sort_order INTEGER DEFAULT 0,
        settings TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
    )
');
echo "[OK] sections\n";

// 5. Галерея
$pdo->exec('
    CREATE TABLE IF NOT EXISTS gallery (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        section_id INTEGER,
        image_url TEXT NOT NULL,
        alt_text TEXT,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    )
');
echo "[OK] gallery\n";

// 6. Отзывы
$pdo->exec('
    CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        author TEXT NOT NULL,
        text TEXT NOT NULL,
        rating INTEGER DEFAULT 5,
        sort_order INTEGER DEFAULT 0,
        status TEXT DEFAULT "active",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
echo "[OK] reviews\n";

// 7. Меню
$pdo->exec('
    CREATE TABLE IF NOT EXISTS menus (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        location TEXT DEFAULT "header",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
echo "[OK] menus\n";

// 8. Пункты меню
$pdo->exec('
    CREATE TABLE IF NOT EXISTS menu_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        menu_id INTEGER NOT NULL,
        page_id INTEGER,
        title TEXT NOT NULL,
        url TEXT,
        parent_id INTEGER DEFAULT 0,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
    )
');
echo "[OK] menu_items\n";

echo "\n=== Базовые настройки ===\n";

// ========== БАЗОВЫЕ НАСТРОЙКИ ==========

$settings = [
    // AI — настройки
    ['ai_api_key', null, 'string'],
    ['ai_model', 'deepseek-chat', 'string'],
    ['ai_prompt_template', 'ДОПОЛНИТЕЛЬНЫЕ ПРАВИЛА:
- Для шаблонов списка (list, blog) используй переменную items (массив) и цикл {% for item in items %}
- Для шаблонов одиночной записи (single) используй переменную item (один объект), НИКОГДА не делай цикл
- Все поля одиночной записи: {{ item.title }}, {{ item.content|raw }}, {{ asset(item.featured_image) }}
- Все поля в цикле списка: {{ item.title }}, {{ item.excerpt }}, {{ url(nav_item.url ~ "/" ~ item.slug) }}
- Изображения: {{ asset(item.featured_image) }} или {{ asset(page.featured_image) }}
- Для ссылок на записи: {{ url(nav_item.url ~ "/" ~ item.slug) }}, для пагинации: {{ url(nav_item.url ~ "?page=" ~ page) }}
- Используй ТОЛЬКО поля из структуры таблицы, не выдумывай несуществующие
- Если поле не указано в структуре — не используй его. Проверяй наличие через {% if item.field_name %} перед использованием.', 'string'],
    ['ai_prompt_table', 'Ты — эксперт по SQLite. Создавай структуры таблиц. Отвечай ТОЛЬКО JSON-массивом колонок.', 'string'],
    ['ai_prompt_content', 'Ты — генератор контента для сайта. Создавай реалистичные записи. Отвечай ТОЛЬКО JSON-массивом.', 'string'],
    ['ai_prompt_fill_form', 'Ты — помощник по заполнению форм. Генерируй JSON с подходящими значениями для полей.', 'string'],
    ['ai_prompt_assistant', 'Ты — AI-ассистент панели управления CMS на PHP+SQLite. Помогай с шаблонами, таблицами, кодом. Отвечай на русском.', 'string'],

    // AI — настройки фронтенда (для ai-страниц)
    ['ai_frontend_use_system', '1', 'string'],
    ['ai_frontend_api_key', null, 'string'],
    ['ai_frontend_model', null, 'string'],
    ['ai_frontend_prompt', 'Ты — AI-ассистент сайта {site_title}. Твоя задача — помогать посетителям находить информацию на сайте, используя данные из контекста ниже.\n\n{context}\n\nПравила:\n1. Отвечай на русском языке, дружелюбно и по делу\n2. Используй ТОЛЬКО информацию из контекста\n3. Если информации нет — честно скажи, что не знаешь\n4. Форматируй ответы богато: карточки, таблицы, списки\n5. В конце ответа добавляй быстрые ссылки в формате [quick_links:{"label": "...", "url": "..."}]\n6. Если пользователь спрашивает о чём-то, чего нет в контексте — вежливо объясни, что сайт не содержит такой информации', 'string'],

    ['ai_frontend_personality', null, 'string'],
    ['ai_public_tables', null, 'string'],
    ['ai_sample_limit', '50', 'string'],
    ['stats_enabled', '0', 'string'],
    ['stats_retention_days', '90', 'string'],
    
    // Основные настройки сайта
    ['site_title', 'Мой сайт', 'string'],
    ['site_description', 'Описание сайта', 'string'],
    ['site_email', null, 'string'],
    ['posts_per_page', '10', 'string'],
    ['site_favicon', null, 'string'],
    ['maintenance_mode', '0', 'string'],

    // Custom CSS — пользовательские стили фронтенда
    ['custom_css', '', 'text'],
    
    // Email — настройки почты
    ['email_driver', 'api', 'string'],
    ['email_api_provider', null, 'string'],
    ['email_api_key', null, 'string'],
    ['email_api_endpoint', null, 'string'],
    ['email_smtp_host', null, 'string'],
    ['email_smtp_port', '587', 'string'],
    ['email_smtp_username', null, 'string'],
    ['email_smtp_password', null, 'string'],
    ['email_smtp_encryption', 'tls', 'string'],
    ['email_from_email', null, 'string'],
    ['email_from_name', 'APIDCMS', 'string'],
];

$stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)");
$count = 0;
foreach ($settings as $s) {
    $stmt->execute($s);
    $count++;
}
echo "[OK] $count базовых настроек добавлено\n";

// Проверяем, есть ли таблица settings (старая) — дропаем
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'")->fetchAll();
if (count($tables) > 0) {
    $pdo->exec("DROP TABLE IF EXISTS settings");
    echo "[OK] Устаревшая таблица settings удалена\n";
}

// 9. Статистика посещений (таблица + индексы)
if (class_exists('Core\VisitStats')) {
    Core\VisitStats::initTable($db);
    echo "[OK] visit_stats\n";
}
echo "\n=== Инициализация завершена ===\n";
