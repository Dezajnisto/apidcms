# Связи таблиц (Relations) в apidcms

apidcms поддерживает два подхода к связям между таблицами на фронте.

## 1. Twig-функции для резолва связей

Доступны в любом Twig-шаблоне:

### `get_record(table, id)`
Получить одну запись по ID из указанной таблицы.

```
{% set category = get_record("poetry_category", item.category_id) %}
{% if category %}
    <span>{{ category.name }}</span>
{% endif %}
```

### `get_records(table, ids)`
Получить несколько записей по строке ID через запятую.

```
{% for cat in get_records("poetry_category", item.categories) %}
    <span class="tag">{{ cat.name }}</span>
{% endfor %}
```

### `get_all(table)`
Получить все записи из таблицы.

```
{% set categories = get_all("poetry_category") %}
{% for cat in categories %}
    <a href="?category={{ cat.id }}">{{ cat.name }}</a>
{% endfor %}
```

Параметры: `get_all(table, orderBy='id', orderDir='ASC')`

---

## 2. GET-фильтрация динамических списков

Позволяет при клике на ссылку с GET-параметром отфильтровать записи
в blog/catalog-страницах с корректной пагинацией и total_count.

### Настройка

1. В админке открыть запись navigation для нужной страницы
2. В поле `page_config` указать JSON:

```json
{
  "get_filters": {
    "category": "category_id"
  }
}
```

Где **ключ** (`category`) — имя GET-параметра в URL,
а **значение** (`category_id`) — колонка в source_table для фильтрации.

3. В шаблоне сделать ссылки:

```html
<a href="?category=1">Свадебные стихи</a>
```

### Как это работает

- При запросе `/examples?category=1` GET-параметр `category` = `1`
- FrontController добавляет `WHERE category_id = '1'` к SQL
- Пагинация и total_count считаются с учётом фильтра (в отличие от Twig-фильтрации)

---

## 3. Структура БД

Таблица `navigation` содержит колонку `page_config` (TEXT, JSON):

```sql
ALTER TABLE navigation ADD COLUMN page_config TEXT;
```

`NavigationItem::getPageConfig()` автоматически парсит JSON и мержит
с базовой конфигурацией.

---

## 4. Пример: категории стихов

```sql
CREATE TABLE poetry_category (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

В шаблоне:

```
{% set categories = get_all("poetry_category") %}
<div class="category-buttons">
    {% for cat in categories %}
        <a href="?category={{ cat.id }}">{{ cat.name }}</a>
    {% endfor %}
</div>

{% for item in items %}
    <article>
        <h3>{{ item.title }}</h3>
        {% if item.category_id %}
            {% set cat = get_record("poetry_category", item.category_id) %}
            {% if cat %}
                <span class="tag">{{ cat.name }}</span>
            {% endif %}
        {% endif %}
    </article>
{% endfor %}
```
