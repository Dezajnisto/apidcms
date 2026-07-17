## 2026-07-14 (fix) — Порядок инициализации сессий и NavigationItem::$page_config

## 2026-07-17 — v1.3.7 — Авто-инкремент views_count в dynamic-страницах

### showDynamicItem: авто-инкремент views_count

- **Суть:** если в таблице есть колонка `views_count`, при каждом просмотре записи значение автоматически увеличивается на 1
- **Где:** `core_lib/front/app/controllers/FrontController.php` → `showDynamicItem()`
- **Безопасность:** обёрнуто в try/catch — ошибка инкремента не ломает страницу

### Авто-поддерживаемые колонки dynamic-страниц

| Колонка | Назначение |
|---------|-----------|
| `slug` | URL-идентификатор записи (поиск для `_single`) |
| `status` | Фильтрация: только `active` записи в списке |
| `sort_order` | Сортировка списка (приоритетнее `created_at`) |
| `created_at` | Сортировка и prev/next-навигация |
| `views_count` | **Новое:** авто-инкремент при просмотре |

### `_GET` в шаблонах фронтенда

- **Суть:** в `FrontController::render()` добавлен `$data['_GET'] = $_GET`
- GET-параметры теперь доступны в любом Twig-шаблоне через переменную `_GET`
- Позволяет строить серверные фильтры без JS: `{% if _GET.category == 'text' %} active{% endif %}`

### `get_filters` в page_config (документирование)

- **Суть:** dynamic-страницы поддерживают GET-фильтры через `page_config.get_filters`
- Конфиг: `{"get_filters": {"category": "category", "tool": "ai_tool"}}`
- `?category=text&tool=GPT` → автоматический WHERE в SQL
- Пагинация и `total_count` учитывают фильтры
- Комбинируются с `filters` (жёсткие фильтры из конфига)

### `sort_options` — серверная сортировка через GET

- **Суть:** dynamic-страницы поддерживают `?sort=` через `page_config.sort_options`
- Конфиг: `{"sort_options": {"newest": {"field": "created_at", "order": "DESC"}}}`
- Валидация имени колонки через структуру таблицы (защита от SQL-инъекций)
- Комбинируется с `get_filters`: `?category=text&sort=popular`
- **Безопасность:** только если `sort_options` задан в конфиге — существующие проекты не затронуты

### Файлы

### `json_decode` Twig-фильтр

- **Суть:** добавлен фильтр `|json_decode` для разбора JSON-строк в шаблонах
- Пример: `{% set features = plan.features|json_decode %}`
- Полезно для данных из колонок с JSON (features, settings, variables и т.д.)

### Файлы

- `core_lib/front/app/controllers/FrontController.php` (+27 строк: views_count, _GET, sort_options, json_decode)

### session_save_path: перенос перед плагинами

- **Проблема:** `session_save_path()` вызывался ПОСЛЕ `loadPlugins()` + `doAction('core.init')`
- Плагин `account` на хуке `core.init` вызывает `session_start()`, что делало невозможным смену пути сессий
- **Решение:** блок настройки сессий (`session_save_path`) перенесён в `init.php` ДО загрузки плагинов

### NavigationItem::$page_config = null

- **Проблема:** свойство `$page_config` не имело значения по умолчанию → PHP 8.1+ выдавал Warning при чтении
- **Решение:** `public $page_config = null` (и `$form_config` тоже)

### Удаление старых копий NavigationItem в проектах

- **Проблема:** 6 проектов имели устаревшие копии `front/app/models/NavigationItem.php` без `$page_config`/`$page_id`
- Автозагрузчик брал проектную версию вместо каноничной ядерной
- **Решение:** все проектные копии удалены, используется единая версия из `core_lib/`

### Файлы

- `core_lib/init.php` (порядок блоков: сессии → плагины)
- `core_lib/front/app/models/NavigationItem.php` (+ = null)
- Удалены `front/app/models/NavigationItem.php` на: wearefun, dezajno, apidcms.dezajno, izkino, prosto-stihi, my-project

## 2026-07-14 — Сортировка dynamic-страниц и синхронизация версионирования

### order_field / order_dir в page_config

- **Проблема:** записи на dynamic-страницах (например changelog) выводились в порядке sort_order ASC — старые сверху
- **Причина:** `showDynamicList` использовал только `sort.field`/`sort.order` из page_config, без поддержки переопределения
- **Решение:** добавлена поддержка ключей `order_field` и `order_dir` в page_config:
  - `order_field` — колонка для сортировки (валидируется по структуре таблицы)
  - `order_dir` — ASC или DESC
  - Работает в `showDynamicList` (dynamic-страницы) и в landing-обработчике
- **Использование:** `{"sort": {"field": "sort_order", "order": "DESC"}}` или `{"order_dir": "DESC"}`

### Синхронизация версионирования

- Git-теги приведены к схеме из БД changelog на apidcms.dezajno.ru (SemVer)
- Старые git-теги (v1.0.0–v1.0.2) удалены — это была параллельная система
- VERSION и INSTALLER_VERSION синхронизированы с актуальной версией 1.2.0
- Добавлен VERSIONING.md: правила MAJOR.MINOR.PATCH, release-чеклист, источники истины

### Файлы

- `front/app/controllers/FrontController.php` (showDynamicList: +14 строк, order_field/order_dir)
- `VERSIONING.md` (новый)
- `VERSION` (1.2.0)
- `www/install.php` (INSTALLER_VERSION → 1.2.0)

## 2026-07-13 — UX-тестирование установщика и восстановление core_lib

### Восстановление после аварии

- **Проблема:** старый обновление ядра с rsync --delete удалил core_lib/ на всех проектах после реструктуризации репозитория
- **Восстановление:** core_lib восстановлен на всех 5 проектах через rsync из apidcms-core/www/core_lib/
- **обновление ядра починено:** 7 правок путей на $CORE_DIR/core_lib/
- **Авто-синхронизация views:** для проектов без собственного front/app/ теперь синхронизируются дефолтные views

### CSS-фиксы

- **admin.css перекомпилирован:** после реструктуризации admin.css потерял критичные spacing-классы (px-1/2/3/6, py-0.5/1/1.5, mb-1, ml-1/2)
- **Восстановлен:** рабочий admin.css (31KB) из wearefun, содержащий все spacing-утилиты
- **Cache-шаблон:** admin/app/views/cache/index.html.twig восстановлен после случайного удаления при реструктуризации
- **.gitignore исправлен:** admin/app/views/cache/* ошибочно игнорировал исходники (это НЕ runtime-кэш)

### UX-тестирование установки с нуля (3 раунда: izkino.ru, prosto-stihi.ru, daristihi.ru, nldb.ru)

#### Раунд 1 — базовые проблемы
- **403 на /admin:** install.php не создавал .htaccess и admin/index.php — добавлена генерация обоих файлов
- **Session warnings:** PHP session.save_path не задан явно — добавлен session_save_path в init.php, .htaccess запрещает доступ к /tmp/
- **API-ключ:** шаг ввода API-ключа убран из установщика (не нужен для базовой установки)

#### Раунд 2 — структура и конфигурация
- **Seed-данные:** установщик создаёт 6 страниц (главная, блог, каталог и др.) и 3 тестовых блог-поста
- **front/config:** создаётся папка front/config/ с файлом config.php (ключ paths)
- **core_lib/front/config/config.php:** разморожен из .gitignore, теперь доступен как fallback
- **base.html.twig:** добавлен padding на <main> для корректных отступов

#### Раунд 3 — совместимость и стили
- **Template fallback:** проект -> core_lib через prependPath в Twig, гарантирует загрузку шаблонов из ядра
- **custom.css:** дефолтные стили создаются при установке
- **PHP 8.1 compat:** scandir вместо RecursiveDirectoryIterator/SplFileInfo::getSubPathName()
- **защита при обновлении:** apidcms-core исключён из списка синхронизации (он сам — источник)
- **Скелетон больше не нужен:** install.php делает всё, apidcms-skeleton устарел

### Ключевые уроки
- admin/app/views/cache/ — исходные Twig-шаблоны, НЕ удалять при очистке кэша
- storage/cache/ — runtime-кэш, можно удалять
- Scandir надёжнее RecursiveDirectoryIterator для PHP 8.1
- front/config/ обязательно создавать установщиком
- custom.css — правильный путь для стилей, не Twig-шаблоны

### Файлы
- www/install.php (+генерация .htaccess, admin/index.php, seed-данные, -шаг API-ключа, INSTALLER_VERSION)
- core_lib/front/app/core/init.php (+session_save_path)
- core_lib/front/config/config.php (разморожен из .gitignore)
- core_lib/admin/app/views/cache/index.html.twig (восстановлен)
- core_lib/static/admin.css (восстановлен рабочий)
- инструмент обновления (7 правок путей, авто-синхронизация views, защита apidcms-core)

## 2026-07-12 — Автоустановка и безопасность БД

### install.php — скрипт установки

- **Назначение:** разворачивает новый apidcms-проект одной командой
- **Что делает:**
  - Проверяет PHP 8.1+ и все обязательные расширения (sqlite3, curl, mbstring, json, gd, openssl, fileinfo, zip, xml, session)
  - Определяет пути (core_lib, APIDCMS_CORE)
  - Создаёт структуру папок (storage/cache/twig, storage/uploads, storage/logs, database)
  - Создаёт конфиги (admin/config.php, front/config.php), если их нет
  - Устанавливает зависимости Composer (`composer install --no-dev`)
  - Инициализирует БД через init_system_tables.php
- **Запуск:** `php install.php` в папке проекта
- **Защита:** обновление ядра не разносит install.php на рабочие проекты (исключён из rsync)

### WAL-режим SQLite и busy_timeout

- **Проблема:** конкурентный доступ к БД (веб-запрос + CLI-утилита) вызывал крах FastCGI-процессов на всём аккаунте хостинга
- **Причина:** `journal_mode=delete` блокирует чтение при записи, `busy_timeout=0` — мгновенная ошибка вместо ожидания
- **Исправление в Database.php:**
  - `PRAGMA journal_mode = WAL` — параллельные чтение и запись, писатели не блокируют читателей
  - `PRAGMA busy_timeout = 5000` — ждать до 5 секунд при конфликте блокировок вместо мгновенного падения
- **Эффект:** CLI-обновления БД больше не могут уронить сайт

### init_system_tables.php — исправление

- Удалён дубликат таблицы `forms` (ошибочно была под комментарием «Страницы», второй экземпляр под «Формы» имел битый SQL-quoting)
- Первый экземпляр переименован в `forms`, echo исправлен с `[OK] pages` на `[OK] forms`

### Защита от рекурсии при обновлении

- **Рекурсия:** каждый запуск обновление ядра создавал вложенный `core_lib/core_lib/` (до 5 уровней), разнося это по всем проектам
- **Исправление:** `--exclude=core_lib` в rsync + apidcms-core исключён из списка синхронизации (он сам — источник)
- **Stray-файлы:** добавлены исключения `--exclude=storage`, `--exclude=CHANGELOG.md`, `--exclude=README.md`, `--exclude=LICENSE` — файлы из корня www/ ядра больше не попадают в core_lib проектов

### Документация

- README.md: добавлен раздел установки (git clone + ZIP), системные требования, что делать после установки
- Страница «Установка и требования» на apidcms.dezajno.ru/docs: переписана с нуля, добавлены git-инструкции, решение типичных проблем

### Файлы

- `www/install.php` (новый, ~385 строк)
- `core/Database.php` (+2 PRAGMA)
- `www/init_system_tables.php` (удалён дубликат, исправлен SQL-quoting)
- `www/README.md` (+69 строк, раздел установки)
- инструмент обновления ядра (защита от рекурсии, исключения)


# Changelog


## 2026-06-26 — Рефакторинг уведомлений и form_name

### Уведомления: переход с navigation на forms

- **Проблема:** NotificationsController искал формы через `navigation.page_type = 'form'` — устаревший тип страниц, который больше не используется
- **Исправление:** `getFormTables()` и `getFormInfoByTable()` теперь запрашивают таблицу `forms` напрямую
- **Обратная совместимость:** поле `'' AS url` оставлено для шаблонов

### form_name: привязка заявок к форме

- **Проблема:** несколько форм могут писать в одну таблицу (напр. `contacts`), но нельзя было понять, какая форма создала запись
- **Решение:**
  - `FormRenderer::processSubmission()` — при сохранении автоматически пишет `form_name` (если колонка есть в source_table)
  - `NotificationsController` — все методы переписаны с `{table}` на `{formName}`, фильтрация по `form_name`
  - Маршруты: `/notifications/form/{formName}`, `/notifications/submission/{formName}/id/{id}` и т.д.
- **Метод `hasColumn($table, $columnName)`** — проверяет наличие колонки, обеспечивает обратную совместимость с таблицами без `form_name`

### Шаблоны уведомлений

- `index.html.twig`: ссылки используют `form.name`, убран вывод URL
- `view_form.html.twig`, `view_submission.html.twig`: ссылки используют `form_name`

### Фикс бага

- Исправлен порядок параметров в SQL-запросах: `form_name` должен идти до `LIMIT/OFFSET`

### Файлы

- `core/FormRenderer.php` (+5 строк)
- `admin/app/controllers/NotificationsController.php` (полный рерайт, ~300 строк)
- `admin/app/core/App.php` (4 маршрута)
- `admin/app/views/notifications/*.twig` (3 шаблона)

## 2026-06-17 — AI-страницы: новый тип `ai` и настройки фронтенда

### Новый тип страниц: `ai` — чат с ИИ

- **Назначение:** страница становится интерфейсом чата с ИИ. Модель получает полный контекст базы данных сайта (структура + контент) и отвечает посетителям на основе реальных данных.
- **Где:** `FrontController.php` (case 'ai'), `ai.html.twig`, `ai-chat.js`
- **Архитектура:**
  - Страница чата: `handleAiChat()` → `ai.html.twig`
  - Серверный эндпоинт: POST `/ai-handler` — принимает `{message, history}`, возвращает `{response, quick_links}`
  - Контекст: все таблицы БД + sample_rows (до 50) + navigation → JSON в системном промте
- **Quick links:** ИИ может генерировать кликабельные подсказки через формат `[quick_links:...]` в ответе

### Разделение настроек AI: Админка / Фронтенд

Новые ключи в `system_settings`:
- `ai_frontend_use_system` ("1"/"0") — использовать системные `ai_api_key`/`ai_model` или отдельные
- `ai_frontend_api_key` — отдельный API-ключ для фронтенда
- `ai_frontend_model` — отдельная модель
- `ai_frontend_prompt` — системный промт для ai-страниц (с плейсхолдерами `{site_title}` и `{context}`)

### Безопасность
- API-ключ никогда не передаётся клиенту (серверный прокси)
- Rate limiting: 2 сек между запросами (по сессии)
- Входные данные валидируются

### Клиент (ai-chat.js)
- Отправка сообщений через fetch → `/ai-handler`
- Конвертация Markdown → HTML (заголовки, жирный, курсив, ссылки, код, списки, таблицы)
- Индикатор печати (анимированные точки)
- Быстрые ссылки — кликабельные кнопки-подсказки
- Отправка по Enter, история сообщений в памяти сессии

### Файлы
- `core_lib/front/app/controllers/FrontController.php` (+105 строк: case 'ai', handleAiChat, handleAiRequest, collectAiContext)
- `core_lib/front/app/views/ai.html.twig` (новый)
- `core_lib/static/ai-chat.js` (новый)
- `init_system_tables.php` (+4 настройки ai_frontend_*)

### Развёртывание
- Проекты с кастомным front: шаблон `ai.html.twig` нужно копировать в `front/app/views/` проекта
- Проекты без кастомного front: используют шаблон из ядра
- `init_system_tables.php` можно запустить повторно — настройки добавляются через `INSERT OR IGNORE`

### UI в админке (22:57–23:09)
- **Секция «AI Фронтенд»** в Настройки системы:
  - Галочка «Использовать системные настройки» (переключает ai_frontend_use_system, скрывает/показывает поля ключа и модели)
  - Поля: системный промт, личность ассистента
  - Чекбокс с hidden-инпутом для корректного сохранения 0/1
- Редактирование: `admin/app/views/settings/index.html.twig` (+JS для toggle)

### Разделение промта (22:57–23:09)
- **Новый ключ:** `ai_frontend_personality` — личность, характер, правила, лидогенерация
- **Логика в handleAiRequest():**
  - Если personality заполнен → личность + автоматический контекст БД
  - Если пусто → fallback на `ai_frontend_prompt` (старое поведение)
- `{site_title}` подставляется в обоих режимах
- **Файлы:** `FrontController.php`, `init_system_tables.php`

## 2026-06-16 — Связи таблиц и GET-фильтрация

### Новые возможности
