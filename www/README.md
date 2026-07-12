# apidcms

Лёгкая CMS с ИИ-ассистентом. PHP + SQLite. Устанавливается за минуту.

## Установка на хостинг

Зайдите в папку проекта, замените `мой-сайт.ru` на свою:

```bash
cd ~/мой-сайт.ru
rm -rf tmp && git clone https://github.com/Dezajnisto/apidcms.git tmp
cp -r tmp/www/* www/ && rm -rf tmp
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
- **Конструктор таблиц** — БД без SQL
- **Twig-шаблоны** — гибкая система
- **Файловый менеджер** — загрузка, превью, WebP
- **Плагины** — система расширений
- **Формы** — конструктор с email
- **Статистика** — встроенный дашборд
- **SQLite** — не нужен MySQL

## Документация

[apidcms.dezajno.ru/docs](https://apidcms.dezajno.ru/docs)

## Лицензия

[MIT](LICENSE)
