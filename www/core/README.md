# apidcms / apidcms

[English version below](#english)

---

Лёгкая CMS с ИИ-ассистентом. PHP + SQLite. Устанавливается за минуту.

## Установка на хостинг

```bash
cd ~/мой-сайт.ru/www
wget https://github.com/Dezajnisto/apidcms/archive/refs/heads/main.zip
unzip main.zip && cp -r apidcms-main/www/core . && rm -rf apidcms-main main.zip
php install.php
```

## Структура проекта (v2)

```
project/
├── www/                  ← DOCUMENT_ROOT
│   ├── index.php         ← единая точка входа
│   ├── .htaccess
│   ├── assets → core/assets/
│   └── core/             ← ядро CMS
├── config/               ← конфиги проекта
├── storage/              ← БД, загрузки, кэш
├── themes/               ← темы оформления
└── plugins/              ← плагины
```

## Системные требования

- PHP 8.1+
- Расширения: `sqlite3`, `curl`, `mbstring`, `json`, `gd`, `openssl`, `fileinfo`, `zip`, `xml`

## Возможности

- **AI Ассистент** — нейросеть в админке
- **Конструктор таблиц** — БД без SQL
- **Twig-шаблоны** — гибкая система тем
- **Файловый менеджер** — загрузка, превью, WebP
- **Плагины** — система расширений
- **Формы** — конструктор с email
- **Статистика** — встроенный дашборд
- **SQLite** — не нужен MySQL
- **Мультиязычность** — ru/en интерфейс

## Документация

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

## Лицензия

[MIT](LICENSE)

---

## English {#english}

A lightweight AI-powered CMS. PHP + SQLite. Installs in under a minute.

### Installation

```bash
cd ~/mysite.com/www
wget https://github.com/Dezajnisto/apidcms/archive/refs/heads/main.zip
unzip main.zip && cp -r apidcms-main/www/core . && rm -rf apidcms-main main.zip
php install.php
```

### Project Structure (v2)

```
project/
├── www/                  ← DOCUMENT_ROOT
│   ├── index.php         ← single entry point
│   ├── .htaccess
│   ├── assets → core/assets/
│   └── core/             ← CMS core
├── config/               ← project configuration
├── storage/              ← database, uploads, cache
├── themes/               ← themes
└── plugins/              ← plugins
```

### Requirements

- PHP 8.1+
- Extensions: `sqlite3`, `curl`, `mbstring`, `json`, `gd`, `openssl`, `fileinfo`, `zip`, `xml`

### Features

- **AI Assistant** — neural network in admin panel
- **Table Builder** — database without SQL
- **Twig Templates** — flexible theme system
- **File Manager** — upload, preview, WebP
- **Plugins** — extension system
- **Forms** — builder with email notifications
- **Analytics** — built-in dashboard
- **SQLite** — no MySQL needed
- **i18n** — RU/EN interface

### Documentation

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

### License

[MIT](LICENSE)
