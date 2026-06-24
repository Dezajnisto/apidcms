# Система форм apidcms

## Кратко

Одна таблица `forms` в БД → любое количество полей → любой Twig-шаблон → разные дизайны.

Форма состоит из:
- **source_table** — куда сохраняются данные
- **fields** — JSON с описанием полей (тип, label, placeholder, required)
- **template** — какой Twig-шаблон использовать (default, hero, minimal)
- **notifications** — кому слать письма и автоответ

Две формы могут ссылаться на одну таблицу, показывать разные поля и иметь разный дизайн.

---

## Быстрый старт

### 1. Создать форму

```sql
INSERT INTO forms (name, display_name, source_table, fields, template, success_message)
VALUES (
    'quick-callback',
    'Быстрый звонок',
    'contacts',
    '{
        "name": { "label": "Ваше имя", "type": "text", "required": true },
        "phone": { "label": "Телефон", "type": "tel", "required": true }
    }',
    'hero',
    'Спасибо! Мы перезвоним вам.'
);
```

### 2. Вставить в Twig

```twig
{{ render_form('quick-callback') }}

{{ render_form('quick-callback', { template: 'hero' }) }}

{{ render_form('quick-callback', {
    template: 'hero',
    submit_text: 'Перезвоните мне',
    submit_class: 'bg-gradient-to-r from-pink-500 to-orange-400 w-full py-4'
}) }}
```

---

## Таблица `forms`

| Поле | Тип | Описание |
|---|---|---|
| `name` | TEXT UNIQUE | Идентификатор формы (указывается в render_form) |
| `display_name` | TEXT | Человеческое название |
| `source_table` | TEXT | Таблица в БД, куда пишутся данные |
| `fields` | TEXT (JSON) | Описание полей (см. ниже) |
| `notifications` | TEXT (JSON) | Настройки уведомлений |
| `design` | TEXT (JSON) | CSS-классы по умолчанию |
| `template` | TEXT | Имя шаблона ('default', 'hero', 'minimal') |
| `success_message` | TEXT | Текст при успешной отправке |
| `enable_csrf` | INTEGER | Вкл/выкл CSRF-защиту |
| `status` | TEXT | 'active' или 'inactive' |

### Формат `fields`

Поля перечисляются в том порядке, в котором должны отображаться.

```json
{
    "email": {
        "label": "Email",
        "type": "email",
        "placeholder": "your@email.com",
        "required": true,
        "help_text": "Мы ответим на этот адрес"
    },
    "phone": {
        "label": "Телефон",
        "type": "tel",
        "placeholder": "+7 999 123-45-67",
        "required": false
    },
    "message": {
        "label": "Сообщение",
        "type": "textarea",
        "placeholder": "Расскажите о вашем проекте...",
        "rows": 6
    },
    "theme": {
        "label": "Тема обращения",
        "type": "select",
        "options": {
            "general": "Общий вопрос",
            "price": "Цены",
            "support": "Поддержка"
        }
    },
    "agree": {
        "label": "Я согласен с условиями",
        "type": "checkbox",
        "required": true
    }
}
```

**Поддерживаемые типы полей:** `text`, `email`, `tel`, `textarea`, `select`, `checkbox`, `date`, `number`, `radio`

### Формат `notifications`

```json
{
    "admin_notify": true,
    "admin_emails": ["vadim@dezajno.ru"],
    "admin_subject": "Новая заявка с сайта",
    "auto_reply": true,
    "auto_reply_subject": "Мы получили ваше сообщение",
    "auto_reply_field": "email"
}
```

### Формат `design`

```json
{
    "submit_text": "Отправить",
    "submit_class": "",
    "field_class": "",
    "label_class": "",
    "form_class": ""
}
```

---

## Шаблоны форм

Файлы лежат в `front/app/views/form/`:

```
form/
├── default.html.twig    — стандартная, светлая
├── hero.html.twig       — прозрачная на тёмном фоне
├── minimal.html.twig    — только линии, без рамок
├── fields/
│   ├── text.html.twig, email, tel, textarea
│   ├── select, checkbox, consent
│   └── _fallback.html.twig
└── messages.html.twig   — сообщения об успехе/ошибке
```

Можно создавать свои шаблоны. В шаблоне доступны переменные:
- `form_name` — имя формы
- `fields` — массив полей (каждый с ключом `name`)
- `show_consent` — показывать ли чекбокс согласия на ПД
- `csrf_token` — CSRF-токен
- `submit_text` — текст кнопки
- `submit_class`, `field_class`, `label_class`, `form_class` — CSS-классы
- `success_message` — текст успеха
- `action` — URL обработчика (по умолчанию `/form-handler`)
- `enable_csrf` — включён ли CSRF
- `session` — сессия (см. флеш-сообщения)

---

## Несколько форм на одной странице

```twig
<!-- Быстрая форма (имя + телефон) -->
{{ render_form('quick-callback', { template: 'hero' }) }}

<!-- Полная форма (имя + email + телефон + сообщение) -->
{{ render_form('contacts', { template: 'default' }) }}
```

Каждая форма использует свой `form_name`. Флеш-сообщения привязаны к имени формы, поэтому не конфликтуют.

---

## Флеш-сообщения (успех/ошибка)

После отправки форма:
1. Устанавливает `$_SESSION['form_success'] = 'quick-callback'` (или `form_error`)
2. Редиректит на ту же страницу
3. Шаблон `form/messages.html.twig` проверяет `session.form_success == form_name` и показывает сообщение
4. После показа — удаляет из сессии (`session.remove('form_success')`)

Для AJAX-запросов возвращается JSON:
```json
{"success": true, "message": "Спасибо!", "id": 123}
```

---


## Согласие на обработку ПД

Чекбокс согласия включается **автоматически**, если в таблице-источнике (`source_table`) есть колонка `pd_consent`. Ничего дополнительно настраивать в форме не нужно.

**Как это работает:**
1. Сервер проверяет структуру таблицы → видит колонку `pd_consent` → передаёт `show_consent = true` в шаблон
2. Шаблон отображает чекбокс (встроенный или через `form/fields/consent.html.twig`)
3. Сервер валидирует: чекбокс обязателен (required), без него форма не отправится
4. При успешной отправке — значение сохраняется в колонку `pd_consent` и пишется лог в `admin/storage/logs/pd_consent.log`

**Формат лога:** `дата | IP | таблица | id записи | User-Agent`

### Добавление колонки pd_consent в существующую таблицу

```sql
ALTER TABLE contacts ADD COLUMN pd_consent INTEGER DEFAULT 0;
```

Чекбокс появится во всех формах, ссылающихся на эту таблицу.

### В кастомном шаблоне

```twig
{% if show_consent %}
<label>
    <input type="checkbox" name="pd_consent" value="1" required>
    Я даю согласие на обработку персональных данных
</label>
{% endif %}
```

---

## AJAX-формы (без перезагрузки страницы)

Для форм в модальных окнах или на одностраничниках нужна отправка без перезагрузки.

### Вариант 1: атрибут `data-ajax-form`

Добавьте атрибут к `<form>` и JS-обработчик (как в `priceform.html.twig`):

```twig
<form method="post" action="/form-handler" data-ajax-form>
    ...
</form>
```

JS перехватывает `submit`, отправляет через `fetch` с заголовком `X-Requested-With: XMLHttpRequest`. Сервер возвращает JSON вместо редиректа.

### Вариант 2: встроенный fetch в шаблоне

```twig
<script>
form.addEventListener('submit', function(e) {
    e.preventDefault();
    fetch(this.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { /* показать успех */ }
        else { /* показать ошибки */ }
    });
});
</script>
```

**Ответ сервера (AJAX):**
```json
{"success": true, "message": "Спасибо!", "id": 123}
```
```json
{"success": false, "message": "Проверьте форму", "errors": {"name": "Поле 'Имя' обязательно"}}
```

**Отличие от обычной отправки:** при обычной (не-AJAX) сервер делает редирект с flash-сообщениями в сессии. При AJAX — возвращает JSON в ответе, страница не перезагружается.

---

## Обработка (эндпоинт `/form-handler`)

1. Читает `form_name` из POST
2. Загружает конфиг формы из `forms` по `name`
3. Проверяет CSRF-токен
4. Валидирует поля (required, email, etc.)
5. Вставляет данные только в те колонки, которые есть в таблице
6. Устанавливает `read_status = 'unread'` (если есть колонка)
7. Отправляет уведомления (email админу + автоответ)
8. Логирует согласие на ПД (если есть)
9. Возвращает JSON (AJAX) или редирект (обычный)

**Безопасность:**
- `form_name` проверяется по БД — левое имя формы не пройдёт
- `source_table` берётся из конфига, а не из POST
- Сохраняются только поля из конфига, только в существующие колонки
- CSRF-токен обязателен

---

## Создание формы для произвольной таблицы

Пример: добавить форму для заполнения контента в таблицу `poetry`:

```sql
INSERT INTO forms (name, display_name, source_table, fields, template, success_message)
VALUES (
    'add-poetry',
    'Добавить стихотворение',
    'poetry',
    '{
        "title": { "label": "Название", "type": "text", "required": true },
        "author": { "label": "Автор", "type": "text" },
        "text": { "label": "Текст стиха", "type": "textarea", "rows": 15, "required": true }
    }',
    'default',
    'Стихотворение добавлено!'
);
```

В шаблоне:
```twig
{{ render_form('add-poetry') }}
```

Поля, для которых нет колонок в `poetry`, не отобразятся. Поля, которые есть в `poetry`, но не в конфиге, не перезапишутся.
