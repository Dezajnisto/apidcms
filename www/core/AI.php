<?php
/**
 * Клиент для работы с API DeepSeek
 *
 * Предоставляет универсальный интерфейс для вызова AI-моделей.
 * Используется во всех местах CMS: генерация шаблонов, структур таблиц,
 * наполнение контентом, общий AI-ассистент.
 */

namespace Core;

class AI {
    private $apiKey;
    private $apiUrl = "https://api.deepseek.com/v1/chat/completions";
    private $model = "deepseek-chat";

    /**
     * Конструктор
     *
     * @param string $apiKey API ключ DeepSeek
     * @param string $model  Модель (по умолчанию deepseek-chat)
     */
    public function __construct($apiKey = "", $model = "") {
        $this->apiKey = $apiKey;
        if ($model) {
            $this->model = $model;
        }
    }

    /**
     * Отправить запрос к DeepSeek API
     *
     * @param array  $messages     Массив сообщений [["role" => "user", "content" => "..."]]
     * @param string $systemPrompt Системный промпт (опционально)
     * @param float  $temperature  Температура (0-2)
     * @param int    $maxTokens    Максимум токенов в ответе
     * @return string Ответ от модели
     * @throws \Exception
     */
    public function chat($messages, $systemPrompt = "", $temperature = 0.7, $maxTokens = 4096) {
        if (empty($this->apiKey)) {
            throw new \Exception("API ключ DeepSeek не настроен. Укажите его в config.php");
        }

        // Формируем полный список сообщений
        $fullMessages = [];

        if (!empty($systemPrompt)) {
            $fullMessages[] = [
                "role" => "system",
                "content" => $systemPrompt
            ];
        }

        foreach ($messages as $msg) {
            $fullMessages[] = $msg;
        }

        // Тело запроса
        $payload = json_encode([
            "model" => $this->model,
            "messages" => $fullMessages,
            "temperature" => $temperature,
            "max_tokens" => $maxTokens,
            "stream" => false
        ]);

        // Отправляем запрос
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("Ошибка соединения с DeepSeek API: " . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $data["error"]["message"] ?? "HTTP " . $httpCode;
            throw new \Exception("Ошибка DeepSeek API: " . $errorMsg);
        }

        return $data["choices"][0]["message"]["content"] ?? "";
    }

    /**
     * Сгенерировать Twig-шаблон на основе описания
     *
     * @param string $userPrompt Описание желаемого шаблона
     * @param array  $context    Контекст: таблицы, поля, существующий шаблон
     * @return string Сгенерированный Twig-код
     */
    public function generateTemplate($userPrompt, $context = [], $customPrompt = "") {
        $systemPrompt = <<<PROMPT
Ты — эксперт по Twig 3.x и веб-разработке. Твоя задача — создавать и редактировать Twig-шаблоны для PHP-сайта.

ВАЖНЫЕ ПРАВИЛА:
1. Отвечай ТОЛЬКО Twig-кодом, без пояснений и markdown-обёртки (```twig ... ```).
2. Первая строка ответа должна быть кодом, а не текстом.
3. Используй синтаксис Twig 3.x: {{ variable }}, {% for item in items %}, {% if condition %}, {% block name %}.
4. Для JavaScript внутри Twig используй {% verbatim %}...{% endverbatim %}.
5. Шаблоны наследуются от base.html.twig: {% extends "base.html.twig" %}.
6. СТРОГО: Используй ТОЛЬКО поля, перечисленные в структуре таблицы ниже. Не выдумывай поля (например, description, category, tags, author_name — их может не быть в таблице). Проверяй каждое поле по списку.

КОНТЕКСТ САЙТА:
- Базовый шаблон: base.html.twig (содержит блоки: title, content)
- Доступные глобальные переменные: navigation (массив пунктов меню), site_title, site_description
- Доступные Twig-функции: url(path), asset(path), get_navigation(location), get_setting(key), render_form(tableName, config)

ПЕРЕМЕННЫЕ ШАБЛОНОВ (зависят от типа страницы):

Для списков (блог, каталог — blog.html.twig, list.html.twig, blog/list.html.twig):
  items — массив записей (каждая: title, slug, content, featured_image, created_at, author_name, excerpt и другие поля таблицы)
  nav_item — объект пункта меню (url, title, page_type, getPageConfig())
  structure — структура таблицы (массив колонок)
  title — заголовок страницы
  current_page — номер текущей страницы
  total_pages — всего страниц
  total_count — всего записей
  ВАЖНО: Для ссылок пагинации и ссылок на записи используй nav_item.url (не хардкодь slug!):
    Правильно: url(nav_item.url ~ '/page/' ~ current_page)
    Правильно: url(nav_item.url ~ '/' ~ item.slug)
    Неправильно: url('blog/page/' ~ page) — blog может отличаться!

ДЛЯ ОТДЕЛЬНЫХ ЗАПИСЕЙ (single — single.html.twig, blog/single.html.twig, blog_single.html.twig):
  ⚠️ КРИТИЧНО: item — это ОДИН объект, НЕ массив! НИКОГДА не используй {% for item in items %} или {% if items is not empty %}!
  ⚠️ НЕ используй циклы, пагинацию, грид-раскладку — это шаблон ОДНОЙ записи!
  ⚠️ Все поля записи — через item.префикс: {{ item.title }}, {{ item.content|raw }}, {{ asset(item.featured_image) }}
  
  Доступные переменные:
  item — ОДНА запись (объект, не массив): item.title, item.slug, item.content, item.featured_image, item.created_at, item.excerpt
  nav_item — объект пункта меню (.url, .title)
  prev_item — предыдущая запись или null (prev_item.title, prev_item.slug)
  next_item — следующая запись или null (next_item.title, next_item.slug)
  title — заголовок записи

  ❌ НЕПРАВИЛЬНО (так делать НЕЛЬЗЯ):
  {% for item in items %} ... {% endfor %}
  {% if items is not empty %} ... {% endif %}
  {{ content|raw }}   ← нет префикса item.!
  
  ✅ ПРАВИЛЬНАЯ СТРУКТУРА:
  {% extends "base.html.twig" %}
  {% block content %}
  <article>
    <h1>{{ item.title }}</h1>
    {% if item.featured_image %}<img src="{{ asset(item.featured_image) }}" alt="{{ item.title }}">{% endif %}
    <time>{{ item.created_at|date('d.m.Y') }}</time>
    <div>{{ item.content|raw }}</div>
    {% if prev_item %}<a href="{{ url(nav_item.url ~ '/' ~ prev_item.slug) }}">← {{ prev_item.title }}</a>{% endif %}
    {% if next_item %}<a href="{{ url(nav_item.url ~ '/' ~ next_item.slug) }}">{{ next_item.title }} →</a>{% endif %}
    <a href="{{ url(nav_item.url) }}">← Все записи</a>
  </article>
  {% endblock %}

Для статических страниц (page.html.twig):
  page — массив: title, content, meta_title, meta_description, featured_image, slug

Для главной (home.html.twig, glavnaya.html.twig):
  page — массив страницы (или null)
  is_home — true
  title — заголовок

Для форм (form.html.twig, form_base.html.twig):
  nav_item — объект пункта меню
  form_html — HTML-код формы (строка)
  title — заголовок
  config — конфигурация формы

Для страницы 404 (404.html.twig):
  title — "Страница не найдена"
PROMPT;

        if (!empty($customPrompt)) {
            $systemPrompt .= "\n\nДОПОЛНИТЕЛЬНЫЕ ИНСТРУКЦИИ ОТ ПОЛЬЗОВАТЕЛЯ:\n" . $customPrompt;
        }

        // Добавляем информацию о таблицах в контекст
        $contextInfo = "";
        if (!empty($context["tables"])) {
            $contextInfo .= "\n\nДОСТУПНЫЕ ТАБЛИЦЫ БАЗЫ ДАННЫХ (используй ТОЛЬКО поля, которые перечислены, не выдумывай отсутствующие):\n";
            foreach ($context["tables"] as $table) {
                $contextInfo .= "- {$table["name"]} (поля: " . implode(", ", $table["columns"]) . ")\n";
            }
        }

        // Если есть существующий шаблон — передаём его и просим редактировать, а не переписывать
        // Если есть существующий шаблон — передаём его
        if (!empty($context["existing_content"])) {
            $contextInfo .= "\n\nСУЩЕСТВУЮЩИЙ ШАБЛОН ДЛЯ РЕДАКТИРОВАНИЯ:\n" . $context["existing_content"] . "\n";
            $contextInfo .= "\nВАЖНО: Верни ПОЛНЫЙ шаблон целиком с внесёнными изменениями. Не возвращай только изменённые фрагменты — верни весь код полностью.\n";
        }

        // Если указан тип страницы — уточняем
        if (!empty($context["page_type"])) {
            $contextInfo .= "\n\nТИП СТРАНИЦЫ: {$context["page_type"]}\n";
        }

        // Если указана основная таблица — сообщаем AI
        if (!empty($context["source_table"])) {
            $contextInfo .= "\nОСНОВНАЯ ТАБЛИЦА: {$context["source_table"]}\n";
            $contextInfo .= "Используй ТОЛЬКО поля из этой таблицы. Не выдумывай поля которых нет в списке.\n";
        }

        $messages = [
            ["role" => "user", "content" => $userPrompt . $contextInfo]
        ];

        return $this->chat($messages, $systemPrompt, 0.6, 4096);
    }

    /**
     * Сгенерировать структуру таблицы на основе описания
     *
     * @param string $userPrompt Описание: для каких данных нужна таблица
     * @return string JSON с описанием таблицы
     */
    public function generateTableStructure($userPrompt, $customPrompt = "") {
        $systemPrompt = <<<PROMPT
Ты — эксперт по проектированию баз данных SQLite. Твоя задача — создавать оптимальные структуры таблиц на основе описания.

Отвечай ТОЛЬКО JSON-массивом колонок, без markdown-обёртки и пояснений.

Формат каждой колонки:
{"name": "field_name", "type": "TEXT|INTEGER|REAL|DATETIME", "nullable": true/false, "default": null}

ПРАВИЛА:
- id добавляется автоматически, НЕ включай его в ответ
- created_at и updated_at добавляются отдельно, не включай их
- Используй snake_case для имён полей
- Для текста: TEXT, для чисел: INTEGER, для дробных: REAL, для дат: DATETIME
- Для обязательных полей: "nullable": false
- Первое поле делай "title" (TEXT, NOT NULL) — это заголовок записи
- Максимум 12 колонок
PROMPT;

        if (!empty($customPrompt)) {
            $systemPrompt .= "\n\nДОПОЛНИТЕЛЬНЫЕ ИНСТРУКЦИИ:\n" . $customPrompt;
        }

        $messages = [
            ["role" => "user", "content" => $userPrompt]
        ];

        return $this->chat($messages, $systemPrompt, 0.3, 2048);
    }

    /**
     * Сгенерировать контент для записей
     *
     * @param string $tableName Имя таблицы
     * @param array  $columns   Массив колонок [["name" => "...", "type" => "..."]]
     * @param string $prompt    Описание желаемого контента
     * @param int    $count     Количество записей
     * @return string JSON-массив с данными для вставки
     */
    public function generateContent($tableName, $columns, $prompt, $count = 5, $customPrompt = "") {
        $columnsJson = json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemPrompt = <<<PROMPT
Ты — генератор контента для базы данных сайта. Твоя задача — создавать реалистичные записи для таблицы.

Отвечай ТОЛЬКО JSON-массивом, без markdown-обёртки и пояснений.

Формат ответа — массив объектов:
[{"field1": "value1", "field2": "value2"}, ...]
PROMPT;

        $userMessage = <<<MSG
Сгенерируй {$count} записей для таблицы "{$tableName}" со следующими полями:
{$columnsJson}

Описание желаемого контента: {$prompt}

Верни JSON-массив с {$count} объектами. Все значения должны быть реалистичными и разнообразными.
MSG;

        $messages = [
            ["role" => "user", "content" => $userMessage]
        ];

        return $this->chat($messages, $systemPrompt, 0.8, 4096);
    }

    /**
     * Универсальный AI-ассистент для админки
     *
     * @param string $userMessage Запрос пользователя
     * @param array  $context     Контекст (текущая страница, таблицы и т.д.)
     * @return string Ответ ассистента
     */
    public function assistant($userMessage, $context = [], $customPrompt = "") {
        $systemPrompt = <<<PROMPT
Ты — AI-ассистент, встроенный в панель управления CMS на PHP+SQLite.

ТВОИ ВОЗМОЖНОСТИ:
1. Помогать с Twig-шаблонами (синтаксис, отладка, создание)
2. Советовать структуры таблиц
3. Помогать с SQL-запросами
4. Отвечать на вопросы по PHP, HTML, CSS, JS
5. Подсказывать по настройке сайта

Отвечай на русском языке, кратко и по делу.
Если нужно показать код — оформляй его в markdown-блоки.
PROMPT;

        if (!empty($customPrompt)) {
            $systemPrompt .= "\n\nДОПОЛНИТЕЛЬНЫЕ ИНСТРУКЦИИ:\n" . $customPrompt;
        }

        // Добавляем контекст о сайте
        if (!empty($context["tables"])) {
            $systemPrompt .= "\n\nТАБЛИЦЫ В БАЗЕ ДАННЫХ:\n";
            foreach ($context["tables"] as $table) {
                $systemPrompt .= "- {$table["name"]}: " . implode(", ", $table["columns"]) . "\n";
            }
        }

        $messages = [
            ["role" => "user", "content" => $userMessage]
        ];

        return $this->chat($messages, $systemPrompt, 0.7, 4096);
    }
}
