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
- **Защита:** sync-core не разносит install.php на рабочие проекты (исключён из rsync)

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

### sync-core — защита от рекурсии и stray-файлов

- **Рекурсия:** каждый запуск sync-core создавал вложенный `core_lib/core_lib/` (до 5 уровней), разнося это по всем проектам
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
- `bin/sync-core` (защита от рекурсии, исключения)


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
