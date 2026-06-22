# Рекомендации для разработки apidcms

## Железобетонные правила

### 1. Админка — ТОЛЬКО Twig, не PHP
- Все admin-шаблоны — `.html.twig`. PHP-шаблоны (`.php`) в `admin/app/views/` — не используются.
- `BaseController::render()` всегда рендерит через Twig, добавляя `.html.twig`.
- Любое изменение admin-вьюх — править `.html.twig` файлы.
- PHP-файлы в `admin/app/views/` — легаси (удалены, не восстанавливать).

### 2. Все изменения — только в ядре
- `apidcms-core/www/` — единый источник правды.
- Никогда не править файлы в проектах напрямую (dezajno.ru, prostostihi.ru и т.д.).
- После правок: `sync-core all`.
- Исключение: кастомные front-шаблоны проекта (front/app/views/), если у проекта свой front.

### 3. sync-core all — после каждого изменения
Скрипт `/home/c91469/bin/sync-core all` разливает core_lib во все 5 проектов (включая bajto.ru на отдельном сервере).

### 4. Git-коммит — после каждой правки
После завершения работы и тестов — `git add -A && git commit`. Без напоминаний.

## Архитектурные заметки

### Шаблонизация
- **Frontend (сайт):** Twig (`.html.twig` в `front/app/views/`)
- **Admin:** Twig (`.html.twig` в `admin/app/views/`), extends `base.html.twig`
- **Плагины:** свои шаблоны в `plugins/{name}/views/`
- Исключение: у проектов может быть свой front — sync-core не трогает их шаблоны

### Хостинг
- Проекты на shared-хостинге (h48.netangels.ru): без sudo, без перезапуска Apache.
- OPcache на уровне веб-сервера — для сброса запустить PHP-файл с `opcache_reset()` через HTTP.
- Twig-кэш: admin — `admin/app/views/cache/`, front — `storage/cache/twig/`. Чистить вручную.

### Кэширование
1. **OPcache:** `opcache_reset()` через HTTP (создать временный .php файл, запросить, удалить).
2. **Twig кэш:** удалить файлы из `storage/cache/twig/` и `admin/app/views/cache/`.

## Известные грабли (прочитай перед правками!)

### PHP single-quoted строки и regex
```php
// В PHP single-quoted строках `\'` экранирует кавычку и ЗАКРЫВАЕТ строку:
// ❌ Сломается:
$re = '/[`"\'']?/';
// ✅ Работает (hex-escape):
$re = '/[`"\x27]?/';
```

### str_replace hack — не использовать
`str_replace('</head>', '<style>...</style>', $html)` — хак, отрефакторен. CSS теперь в `storage/css/custom.css`.

### Пустые строки vs NULL в формах
При редактировании записей empty POST-поля передаются как пустая строка.
Если колонка nullable и имеет FOREIGN KEY, пустая строка → 0 в SQLite → FK constraint fail.
Фикс: в TableController::update() и ::create() пустые строки для nullable-полей → null.
