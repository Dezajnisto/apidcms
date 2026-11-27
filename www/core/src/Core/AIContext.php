<?php
/**
 * Core\AIContext — что именно уходит в модель вместе с вопросом.
 *
 * Раньше сборка контекста жила внутри Admin\AIController, и проверить её было
 * нечем: ошибка в путях не падала, а молча отдавала пустой массив. Так и
 * вышло — контроллер искал шаблоны в www/front/app/views, www/core/views/front
 * и www/core_lib/front/app/views, то есть в путях СТАРОЙ структуры. Ни одного
 * из них в проекте нет, поэтому генератор шаблонов и CSS работал вообще без
 * контекста шаблонов, а никто этого не видел.
 *
 * Теперь сборка — отдельный класс с одной ответственностью: отдать ровно те
 * файлы, которые реально участвуют в рендере страницы (тема поверх ядра), и
 * уметь рассказать, сколько это в байтах. Второе нужно админке: объём
 * промпта не должен быть невидимым.
 */

namespace Core;

class AIContext
{
    /** Лимит на один .twig-файл, байт. */
    public const DEFAULT_TEMPLATE_MAX = 20000;

    /** Лимит на все шаблоны вместе, байт. */
    public const DEFAULT_TEMPLATE_TOTAL = 60000;

    /** Лимит на custom.css, байт. */
    public const DEFAULT_CSS_MAX = 20000;

    /**
     * Ключевые шаблоны: то, что чаще всего нужно для CSS и как образец.
     *
     * @return string[]
     */
    public static function keyTemplates(): array
    {
        return [
            'base.html.twig',
            'page.html.twig',
            'home.html.twig',
            'glavnaya.html.twig',
            'blog.html.twig',
            'blog/list.html.twig',
            'blog/single.html.twig',
            'blog_single.html.twig',
            'single.html.twig',
            'post.html.twig',
            'form.html.twig',
            'form/_base.html.twig',
            'form/default.html.twig',
            'search.html.twig',
            '404.html.twig',
            'external.html.twig',
        ];
    }

    /**
     * Активная тема сайта.
     *
     * Берём из front-конфига, а не из admin: именно front решает, какой каталог
     * рендерится (FrontController: THEMES_PATH/<active>/front). В admin-конфиге
     * значение по умолчанию 'default', и проект его часто не переопределяет —
     * на сайте с темой 'nldb' admin-конфиг отдал бы 'default'.
     */
    public static function activeTheme(): string
    {
        try {
            $front = load_config('front');
            $active = (string) ($front['theme']['active'] ?? '');
            if ($active !== '') {
                return $active;
            }
        } catch (\Throwable $e) {
            // ignore: остаётся 'default' ниже
        }

        return 'default';
    }

    /**
     * Каталоги с front-шаблонами в порядке приоритета: тема, потом ядро.
     *
     * Порядок ровно как у Twig в FrontController: тема перекрывает ядро
     * пофайлово, ядро добирает то, чего в теме нет.
     *
     * @return array<string,string> метка => путь
     */
    public static function templateDirs(): array
    {
        $themeDir = THEMES_PATH . '/' . self::activeTheme() . '/front';
        $coreDir = CORE_PATH . '/views/front';

        $dirs = [];
        if (is_dir($themeDir) && $themeDir !== $coreDir) {
            $dirs['theme'] = $themeDir;
        }
        if (is_dir($coreDir)) {
            $dirs['core'] = $coreDir;
        }

        return $dirs;
    }

    /**
     * Собрать шаблоны для промпта.
     *
     * @param int $maxPerFile лимит на файл, 0 или меньше — без лимита
     * @param int $totalMax   лимит на всё вместе, 0 или меньше — без лимита
     * @return array<string,string> имя шаблона => содержимое
     */
    public static function templates(int $maxPerFile = 0, int $totalMax = 0): array
    {
        $dirs = self::templateDirs();
        if (!$dirs) {
            return [];
        }

        $templates = [];
        $total = 0;

        $marker = "\n{# ...обрезано для контекста AI #}";

        $take = function (string $name, string $file) use (&$templates, &$total, $maxPerFile, $totalMax, $marker): bool {
            if (isset($templates[$name])) {
                return false; // тема уже дала этот файл
            }

            // Остаток общего бюджета считаем ДО добавления: иначе проверка
            // «уже набрали» пропускает файл, который её переполняет — на bajto
            // так уехало 50 КБ при лимите 40 КБ.
            $remaining = $totalMax > 0 ? $totalMax - $total : -1;
            if ($remaining === 0) {
                return false;
            }

            $content = @file_get_contents($file);
            if ($content === false || trim($content) === '') {
                return false;
            }

            $limit = $maxPerFile > 0 ? $maxPerFile : 0;
            if ($remaining > 0 && ($limit === 0 || $remaining < $limit)) {
                $limit = $remaining;
            }

            if ($limit > 0 && strlen($content) > $limit) {
                // Совсем маленький остаток не режем: половина тега читается
                // моделью как ошибка в шаблоне, а не как обрезка.
                if ($limit < 2000) {
                    return false;
                }
                $content = substr($content, 0, $limit - strlen($marker)) . $marker;
            }

            $templates[$name] = $content;
            $total += strlen($content);

            return true;
        };

        // Порядок:
        //   1. base.html.twig — скелет страницы, без него остальное бесполезно;
        //   2. остальные корневые шаблоны темы по алфавиту — это страницы
        //      (catalog, catalog_single, home, page, search …). Имена у каждого
        //      сайта свои, списком их не перечислить: раньше брались только
        //      имена из жёсткого списка, и на bajto модель не видела
        //      catalog.html.twig / catalog_single.html.twig — главные шаблоны
        //      сайта — потому что таких имён в списке нет;
        //   3. корневые шаблоны ЯДРА, которых в теме нет — как образец;
        //   4. подкаталоги (партиалы: карточки, поля форм) — если остался бюджет.
        $key = self::keyTemplates();
        $themeDir = $dirs['theme'] ?? null;
        $coreDir = $dirs['core'] ?? null;

        $order = [];
        if ($themeDir !== null) {
            $order[] = ['base.html.twig', $themeDir . '/base.html.twig'];
            $root = glob($themeDir . '/*.twig') ?: [];
            sort($root);
            foreach ($root as $file) {
                $order[] = [basename($file), $file];
            }
        } elseif ($coreDir !== null) {
            $order[] = ['base.html.twig', $coreDir . '/base.html.twig'];
        }

        foreach ($order as [$name, $file]) {
            if ($totalMax > 0 && $total >= $totalMax) {
                break;
            }
            if (is_file($file)) {
                $take($name, $file);
            }
        }

        // Ядро добирает только те шаблоны, которых в теме НЕТ ВОВСЕ.
        // Проверяем наличие файла в теме, а не «попал ли он в контекст»: иначе
        // шаблон, не влезший в бюджет, подменялся бы ядровым — и модель
        // получила бы чужой HTML вместо сайтового.
        if ($coreDir !== null) {
            foreach ($key as $name) {
                if ($totalMax > 0 && $total >= $totalMax) {
                    break;
                }
                if (isset($templates[$name])) {
                    continue;
                }
                if ($themeDir !== null && is_file($themeDir . '/' . $name)) {
                    continue; // в теме есть, просто не влез
                }
                if (is_file($coreDir . '/' . $name)) {
                    $take($name, $coreDir . '/' . $name);
                }
            }
        }

        // Подкаталоги: там лежат партиалы (карточки, поля форм).
        foreach (['blog', 'form', 'form/fields'] as $sub) {
            if ($totalMax > 0 && $total >= $totalMax) {
                break;
            }
            foreach ($dirs as $dir) {
                $subPath = $dir . '/' . $sub;
                if (!is_dir($subPath)) {
                    continue;
                }
                $files = glob($subPath . '/*.twig') ?: [];
                sort($files);
                foreach ($files as $file) {
                    if ($totalMax > 0 && $total >= $totalMax) {
                        break;
                    }
                    $take($sub . '/' . basename($file), $file);
                }
            }
        }

        return $templates;
    }

    /**
     * Файл custom.css, который реально подключён на сайте (без обрезки).
     *
     * Порядок тот же, что у сайта: custom.css в хранилище — это то, что правит
     * редактор «Дизайн». Путь к теме строится по активной теме: раньше слово
     * 'default' было захардкожено, и на сайте с другой темой файл не находился.
     */
    public static function cssFile(): ?string
    {
        $candidates = [
            STORAGE_PATH . '/css/custom.css',
            THEMES_PATH . '/' . self::activeTheme() . '/assets/css/custom.css',
            PUBLIC_PATH . '/assets/css/custom.css',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $content = @file_get_contents($path);
                if ($content !== false && trim($content) !== '') {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Содержимое custom.css, обрезанное по лимиту.
     *
     * @param int $maxSize байт, 0 или меньше — без лимита
     */
    public static function css(int $maxSize = 0): string
    {
        $path = self::cssFile();
        if ($path === null) {
            return '';
        }

        $content = (string) @file_get_contents($path);
        if ($maxSize > 0 && strlen($content) > $maxSize) {
            $marker = "\n/* ...обрезано для контекста AI */";
            $content = substr($content, 0, max(0, $maxSize - strlen($marker))) . $marker;
        }

        return $content;
    }

    /**
     * Сколько уходит в модель: для экрана настроек.
     *
     * Показывает и «сколько всего на диске», и «сколько уедет с лимитами» —
     * чтобы владелец видел цену, а не догадывался.
     *
     * @return array<string,mixed>
     */
    public static function summary(int $templateMax = 0, int $templateTotal = 0, int $cssMax = 0): array
    {
        $dirs = self::templateDirs();
        $templates = self::templates($templateMax, $templateTotal);

        $sent = 0;
        foreach ($templates as $body) {
            $sent += strlen($body);
        }

        $cssFile = self::cssFile();
        $cssOnDisk = $cssFile !== null ? (int) @filesize($cssFile) : 0;
        $cssSent = strlen(self::css($cssMax));

        return [
            'theme' => self::activeTheme(),
            'dirs' => array_keys($dirs),
            'templates_count' => count($templates),
            'templates_sent' => $sent,
            'templates_truncated' => $templateTotal > 0 && $sent >= $templateTotal,
            'css_file' => $cssFile,
            'css_on_disk' => $cssOnDisk,
            'css_sent' => $cssSent,
            'total_sent' => $sent + $cssSent,
        ];
    }
}
