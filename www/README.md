# apidcms

Лёгкая CMS с ИИ-ассистентом. PHP + SQLite. Устанавливается за минуту.

## Установка на хостинг

Зайдите в папку проекта и выполните:

```bash
cd ~/мой-сайт.ru
rm -rf www/* tmp
git clone https://github.com/Dezajnisto/apidcms.git tmp
cp -r tmp/www/* www/ && rm -rf tmp
php www/install.php
```

Замените `мой-сайт.ru` на название папки вашего сайта.

## Установка через ZIP (если нет Git)

```bash
cd ~/мой-сайт.ru
rm -rf www/*
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
- Composer (установщик установит зависимости)

## Возможности

- **AI Ассистент** — нейросеть в админке: создаёт таблицы, формы, шаблоны и контент
- **Конструктор таблиц** — БД-таблицы через UI без SQL
- **Twig-шаблоны** — гибкая система шаблонов
- **Файловый менеджер** — загрузка, превью, WebP
- **Плагины** — система расширений с хуками
- **Формы** — конструктор с email-уведомлениями
- **Статистика** — встроенный дашборд посещений
- **SQLite** — не нужен MySQL/PostgreSQL сервер
- **Автоустановка** — `install.php` сам всё настраивает

## Документация

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

## Лицензия

[MIT](LICENSE)
