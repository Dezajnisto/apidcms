# apidcms

Lightweight PHP CMS with AI assistant. SQLite + Twig. Installs in a minute.

## Quick Install

```bash
cd ~/your-site.com
git clone https://github.com/Dezajnisto/apidcms.git _clone
cp -r _clone/www/* www/ && rm -rf _clone
php www/install.php
```

## Install via ZIP (no Git)

```bash
cd ~/your-site.com
wget https://github.com/Dezajnisto/apidcms/archive/refs/heads/main.zip
unzip main.zip && cp -r apidcms-main/www/* www/ && rm -rf apidcms-main main.zip
php www/install.php
```

## Local Dev

```bash
git clone https://github.com/Dezajnisto/apidcms.git
cd apidcms/www
php install.php
php -S localhost:8000
```

Open http://localhost:8000. Admin: `/admin`, login `admin`, password `admin`.

## Requirements

- PHP 8.1+
- Extensions: `sqlite3`, `curl`, `mbstring`, `json`, `gd`, `openssl`, `fileinfo`, `zip`, `xml`
- Composer

## Features

- **AI Assistant** — built-in neural network in the admin panel
- **Multilingual** — opt-in frontend i18n with URL prefixes (/en/, /ru/)
- **Table Builder** — manage DB tables without SQL
- **Twig Templates** — flexible templating engine
- **File Manager** — upload, preview, WebP support
- **Plugin System** — extensible architecture with hooks
- **Form Builder** — drag-and-drop with email notifications
- **Statistics** — built-in traffic dashboard
- **SQLite** — no MySQL needed

## Documentation

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

## License

[MIT](LICENSE)

---

# apidcms

Лёгкая CMS с ИИ-ассистентом. PHP + SQLite. Устанавливается за минуту.

## Установка на хостинг

```bash
cd ~/мой-сайт.ru
git clone https://github.com/Dezajnisto/apidcms.git _clone
cp -r _clone/www/* www/ && rm -rf _clone
php www/install.php
```

## Установка через ZIP (если нет Git)

```bash
cd ~/мой-сайт.ru
wget https://github.com/Dezajnisto/apidcms/archive/refs/heads/main.zip
unzip main.zip && cp -r apidcms-main/www/* www/ && rm -rf apidcms-main main.zip
php www/install.php
```

## Локальный запуск

```bash
git clone https://github.com/Dezajnisto/apidcms.git
cd apidcms/www
php install.php
php -S localhost:8000
```

Откройте http://localhost:8000. Админка: `/admin`, логин: `admin`, пароль: `admin`.

## Системные требования

- PHP 8.1+
- Расширения: `sqlite3`, `curl`, `mbstring`, `json`, `gd`, `openssl`, `fileinfo`, `zip`, `xml`
- Composer

## Возможности

- **AI Ассистент** — нейросеть в админке
- **Мультиязычность** — i18n фронтенда с URL-префиксами (/en/, /ru/)
- **Конструктор таблиц** — БД без SQL
- **Twig-шаблоны** — гибкая система
- **Файловый менеджер** — загрузка, превью, WebP
- **Плагины** — система расширений
- **Формы** — конструктор с email-уведомлениями
- **Статистика** — встроенный дашборд
- **SQLite** — не нужен MySQL

## Документация

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

## Лицензия

[MIT](LICENSE)
