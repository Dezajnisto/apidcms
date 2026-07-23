<?php
/**
 * init_system_tables.php — инициализация системных таблиц для нового проекта
 * 
 * Запуск: php init_system_tables.php
 * Создаёт обязательные таблицы и заполняет базовые настройки.
 * Можно запускать повторно — используется IF NOT EXISTS.
 */

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', __DIR__);
}
define('ROOT_PATH', PROJECT_ROOT);
define('CORE_PATH', __DIR__);
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
$pdo->exec("
    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT '',
        slug TEXT NOT NULL DEFAULT '',
        content TEXT,
        template TEXT DEFAULT 'default',
        meta_description TEXT,
        featured_image TEXT,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
echo "[OK] pages\n";

// 2. Формы
$pdo->exec("
    CREATE TABLE IF NOT EXISTS forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL,
        source_table TEXT NOT NULL,
        fields TEXT NOT NULL DEFAULT '{}',
        notifications TEXT DEFAULT '{}',
        design TEXT DEFAULT '{}',
        template TEXT DEFAULT 'default',
        success_message TEXT DEFAULT 'Spasibo! Forma uspeshno otpravlena.',
        enable_csrf INTEGER DEFAULT 1,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME
    )
");
echo "[OK] forms\n";

// 3. Навигация / типы страниц
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

// 4. Системные настройки (заменяет старую таблицу settings)
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
// 9. Pivot table for many-to-many relations
$pdo->exec('
    CREATE TABLE IF NOT EXISTS entity_relations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_table TEXT NOT NULL,
        source_id INTEGER NOT NULL,
        relation_name TEXT NOT NULL,
        target_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
');
echo "[OK] entity_relations\n";

// Indexes for entity_relations
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_er_lookup ON entity_relations(source_table, source_id, relation_name)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_er_reverse ON entity_relations(relation_name, target_id, source_table)");


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
    ['external_default_token', null, 'string'],

    
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
// Default pages
$pdo->exec("INSERT OR IGNORE INTO pages (id, title, slug, content, status) VALUES (1, 'Main', 'home', '<h1>Welcome!</h1><p>Site installed. <a href=/admin>Go to admin</a> to add content.</p>', 'active')");
$pdo->exec("INSERT OR IGNORE INTO navigation (id, title, url, page_id, page_type, location, menu_order, status) VALUES (1, 'Main', 'home', 1, 'page', 'header', 1, 'active')");
echo "[OK] default pages\n";


echo "\n=== Инициализация завершена ===\n";
