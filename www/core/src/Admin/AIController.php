<?php
/**
 * Контроллер для AI-функций (DeepSeek)
 *
 * Обрабатывает запросы из админки к AI:
 * - Генерация Twig-шаблонов
 * - Генерация структур таблиц
 * - Генерация контента
 * - Универсальный AI-ассистент
 */

namespace Admin;

use Core\AI;
use Admin\Lang;
use Exception;

class AIController extends BaseController {

    private $ai;

    /**
     * Конструктор
     */
    public function __construct($app) {
        parent::__construct($app);

        // Загружаем настройки AI из БД (system_settings)
        $s = new \Core\Settings($this->db);
        $apiKey = $s->get("ai_api_key", "");
        $model = $s->get("ai_model", "deepseek-chat");

        // На всякий случай — fallback на config.php
        if (empty($apiKey)) {
            $config = $app->getConfig();
            $apiKey = $config["ai"]["api_key"] ?? "";
            $model = $config["ai"]["model"] ?? "deepseek-chat";
        }

        $this->ai = new AI($apiKey, $model);

        // Сохраняем промты для доступа в методах
        $this->aiPrompts = [
            "template" => $s->get("ai_prompt_template", ""),
            "table" => $s->get("ai_prompt_table", ""),
            "content" => $s->get("ai_prompt_content", ""),
            "fill_form" => $s->get("ai_prompt_fill_form", ""),
            "assistant" => $s->get("ai_prompt_assistant", ""),
            "css" => $s->get("ai_prompt_css", ""),
        ];

        // AI context size limits (0 = unlimited)
        $this->contextLimits = [
            "template_max_size" => (int)($s->get("ai_template_max_size", "0")),
            "template_total_max" => (int)($s->get("ai_template_total_max", "0")),
            "css_max_size" => (int)($s->get("ai_css_max_size", "0")),
        ];
    }

    private $aiPrompts = [];

    private $contextLimits = [];

    /**
     * Get key Twig templates for AI context (HTML structure for CSS, style reference)
     */
    private function getTemplatesContext(): array {
        $templates = [];
        // Configurable limits (0 = unlimited)
        $maxSize = $this->contextLimits["template_max_size"] ?? 0;
        $totalMax = $this->contextLimits["template_total_max"] ?? 0;
        $total = 0;

        try {
            $rootPath = $this->app->getConfig()['paths']['root'];

            // Project frontend templates (overrides)
            $projectViews = $rootPath . '/front/app/views';
            // Core frontend templates (fallback)
            $coreViews = $rootPath . '/core/views/front';
            // Also check old-style core path
            $coreViewsAlt = $rootPath . '/core_lib/front/app/views';

            // Priority: project views first (actual active templates)
            $viewDirs = [];
            if (is_dir($projectViews)) $viewDirs[] = $projectViews;
            if (is_dir($coreViews)) $viewDirs[] = $coreViews;
            if (is_dir($coreViewsAlt)) $viewDirs[] = $coreViewsAlt;

            // Key templates to include (most relevant for CSS/structure)
            $keyTemplates = [
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

            foreach ($keyTemplates as $name) {
                if ($totalMax > 0 && $total >= $totalMax) break;

                foreach ($viewDirs as $dir) {
                    $file = $dir . '/' . $name;
                    if (file_exists($file)) {
                        $content = file_get_contents($file);
                        if (!empty(trim($content))) {
                            if ($maxSize > 0 && strlen($content) > $maxSize) {
                                $content = substr($content, 0, $maxSize)
                                    . "\n{# ... truncated for AI context #}";
                            }
                            $templates[$name] = $content;
                            $total += strlen($content);
                        }
                        break; // Found in first matching dir
                    }
                }
            }

            // Also include subdirectory templates (blog/, form/fields/)
            $subDirs = ['blog', 'form', 'form/fields'];
            foreach ($subDirs as $sub) {
                if ($totalMax > 0 && $total >= $totalMax) break;
                foreach ($viewDirs as $dir) {
                    $subPath = $dir . '/' . $sub;
                    if (is_dir($subPath)) {
                        $files = glob($subPath . '/*.twig');
                        if ($files) {
                            foreach ($files as $file) {
                                if ($totalMax > 0 && $total >= $totalMax) break;
                                $name = $sub . '/' . basename($file);
                                if (isset($templates[$name])) continue;
                                $content = file_get_contents($file);
                                if (!empty(trim($content))) {
                                    if ($maxSize > 0 && strlen($content) > $maxSize) {
                                        $content = substr($content, 0, $maxSize)
                                            . "\n{# ... truncated #}";
                                    }
                                    $templates[$name] = $content;
                                    $total += strlen($content);
                                }
                            }
                        }
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Templates not available - continue without
        }

        return $templates;
    }

    /**
     * Get custom CSS content for AI context
     */
    private function getCssContext(): string {
        $maxSize = $this->contextLimits["css_max_size"] ?? 0;

        try {
            $rootPath = $this->app->getConfig()['paths']['root'];

            // Try project CSS paths (themes first, then legacy)
            $cssPaths = [
                $rootPath . '/themes/default/assets/css/custom.css',
                $rootPath . '/storage/css/custom.css',
                $rootPath . '/assets/css/custom.css',
            ];

            foreach ($cssPaths as $path) {
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    if (!empty(trim($content))) {
                        if ($maxSize > 0 && strlen($content) > $maxSize) {
                            $content = substr($content, 0, $maxSize)
                                . "\n/* ... truncated for AI context */";
                        }
                        return $content;
                    }
                }
            }
        } catch (\Throwable $e) {
            // CSS not available - continue without
        }

        return "";
    }

    /**
     * Отправка JSON-ответа
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Получить контекст таблиц для AI
     */
    private function getTablesContext() {
        $tables = $this->db->getTables();
        $result = [];
        foreach ($tables as $tableName) {
            $structure = $this->db->getTableStructure($tableName);
            $columns = array_map(function($col) {
                return $col["name"] . " (" . $col["type"] . ")";
            }, $structure);
            $result[] = [
                "name" => $tableName,
                "columns" => $columns
            ];
        }
        return $result;
    }

    /**
     * POST /ai/assistant
     * Универсальный AI-ассистент.
     *
     * По умолчанию работает через tools (Core\AgentTools): в промпте только
     * компактное описание возможностей, документация и данные подтягиваются
     * по запросу модели. Раньше весь корпус документации (~58k токенов) и
     * дамп таблиц уходили в каждый запрос — это стоило денег и мешало думать.
     */
    public function assistant() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $message = $input["message"] ?? "";
            $currentPage = $input["current_page"] ?? "";

            if (empty($message)) {
                $this->jsonResponse(["error" => $this->lang->t("common.empty_request")], 400);
            }

            $useTools = $this->setting("ai_assistant_tools", "1") === "1";

            if ($useTools) {
                $response = $this->assistantWithTools($message, $currentPage);
            } else {
                $response = $this->assistantWithDocs($message, $currentPage);
            }

            // Convert Markdown to HTML for chat UI rendering
            if (!class_exists('\\Parsedown')) {
                require_once CORE_PATH . '/src/Core/Parsedown.php';
            }
            $parsedown = new \Parsedown();
            $responseHtml = $parsedown->text($response);

            $this->jsonResponse([
                "response" => $response,
                "response_html" => $responseHtml,
                "mode" => $useTools ? "tools" : "docs",
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * Assistant over tools: the model asks for schema or docs when it needs them.
     *
     * Bounded loop: at most ai_assistant_max_steps rounds of tool calls, and
     * each tool result is truncated so one big table cannot flood the context.
     */
    private function assistantWithTools($message, $currentPage) {
        $tools = new \Core\AgentTools($this->db);

        // Documentation follows the admin language, same as the site.
        try {
            $tools->setLang($this->setting("admin_language", "ru") ?: "ru");
        } catch (\Throwable $e) {
            // ignore: docs fall back to ru
        }

        $systemPrompt = $this->assistantToolPrompt($currentPage);
        $definitions = $tools->functionDefinitions();

        $messages = [
            ["role" => "user", "content" => $message],
        ];

        $maxSteps = max(1, min(10, (int) $this->setting("ai_assistant_max_steps", "6")));
        $lastText = "";

        for ($step = 0; $step < $maxSteps; $step++) {
            $result = $this->ai->chatWithTools($messages, $definitions, $systemPrompt, 0.3, 3000);

            if (empty($result["tool_calls"])) {
                return (string) ($result["content"] ?? "");
            }

            if (!empty($result["content"])) {
                $lastText = $result["content"];
            }

            // The assistant turn that requested tools must be echoed back
            // verbatim, otherwise the next request has no matching tool ids.
            $messages[] = [
                "role"       => "assistant",
                "content"    => $result["content"] ?? "",
                "tool_calls" => $result["tool_calls"],
            ];

            foreach ($result["tool_calls"] as $call) {
                $name = $call["function"]["name"] ?? "";
                $argsRaw = $call["function"]["arguments"] ?? "{}";
                $args = json_decode((string) $argsRaw, true);
                if (!is_array($args)) {
                    $args = [];
                }

                $outcome = $tools->call($name, $args, "assistant");
                $payload = $outcome["ok"] ? $outcome["data"] : $outcome["error"];
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $messages[] = [
                    "role"         => "tool",
                    "tool_call_id" => $call["id"] ?? "",
                    "content"      => $this->truncateToolResult((string) $json),
                ];
            }
        }

        // Ran out of steps: still return something usable instead of an error.
        if ($lastText !== "") {
            return $lastText;
        }
        return "Не удалось завершить ответ за {$maxSteps} шагов. Уточните вопрос.";
    }

    /**
     * Compact system prompt for the tools mode. No documentation dump: the
     * model is told WHAT it can ask for, not given the whole library.
     */
    private function assistantToolPrompt($currentPage) {
        $siteTitle = $this->setting("site_title", "");
        $prompt = "Ты — AI-ассистент панели управления CMS apidcms (PHP + SQLite + Twig).\n"
            . "Отвечай по-русски, кратко и по делу, код — в markdown-блоках.\n\n"
            . "У тебя есть инструменты — используй их вместо догадок:\n"
            . "- schema.get — таблицы, колонки, допустимые значения, связи и карта страниц;\n"
            . "- records.list / records.get — реальные записи (не выдумывай данные);\n"
            . "- records.create / records.update / records.delete — изменения данных, с проверкой полей;\n"
            . "- navigation.get — какая таблица питает какой URL каким шаблоном;\n"
            . "- docs.search / docs.list / docs.get — документация apidcms.\n\n"
            . "Правила:\n"
            . "1. Вопрос про устройство сайта или данные — сначала посмотри инструментом, потом отвечай.\n"
            . "2. Вопрос про возможности CMS, настройки, типы страниц — docs.search, затем docs.get по нужной статье.\n"
            . "3. Никогда не выдумывай имена таблиц и колонок: проверяй через schema.get.\n"
            . "4. Перед изменением данных, если не уверен в результате, вызывай инструмент с dry_run=true.\n"
            . "5. Если данных не хватает — скажи, чего именно не хватает, и какой инструмент нужен.\n"
            . "6. Ссылки на документацию давай по slug статьи, а не выдуманным URL.";

        if ($siteTitle !== "") {
            $prompt .= "\n\nСайт: " . $siteTitle;
        }
        if ($currentPage !== "") {
            $prompt .= "\nПользователь сейчас в админке на странице: " . $currentPage;
        }

        $custom = $this->aiPrompts["assistant"] ?? "";
        if (!empty($custom)) {
            $prompt .= "\n\nДОПОЛНИТЕЛЬНЫЕ ИНСТРУКЦИИ:\n" . $custom;
        }
        return $prompt;
    }

    /** Keep one tool result from swallowing the whole context window. */
    private function truncateToolResult($json) {
        $limit = 16000;
        if (strlen($json) <= $limit) {
            return $json;
        }
        return substr($json, 0, $limit)
            . "\n… [результат обрезан: " . strlen($json) . " байт. Запросите меньше данных: "
            . "укажите tables, limit или конкретный slug.]";
    }

    /**
     * Legacy mode: full documentation and a table dump in the system prompt.
     * Kept for ai_assistant_tools=0 and as a fallback when tools are unavailable.
     */
    private function assistantWithDocs($message, $currentPage) {
        $tablesContext = $this->getTablesContext();

        // Load apidcms documentation as AI knowledge base
        $docsContext = "";
        try {
            $docsFetcher = new \Core\DocsFetcher();
            $docsFetcher->setLang($this->setting("admin_language", "ru") ?: "ru");
            $docsKb = $docsFetcher->getKnowledgeBase();
            if (!empty($docsKb)) {
                $docsContext = $docsKb;
            }
        } catch (\Throwable $e) {
            // Docs loading is optional - fallback to no documentation
        }

        return $this->ai->assistant($message, [
            "tables" => $tablesContext,
            "current_page" => $currentPage,
            "docs" => $docsContext,
            "css" => $this->getCssContext()
        ], $this->aiPrompts["assistant"] ?? "");
    }

    /**
     * Read one system setting, '' when unset.
     */
    private function setting($key, $default = "") {
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($row && $row["setting_value"] !== null) {
                return (string) $row["setting_value"];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $default;
    }

    /**
     * POST /ai/generate-template
     * Генерация/редактирование Twig-шаблона
     */
    public function generateTemplate() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $prompt = $input["prompt"] ?? "";
            $existingContent = $input["existing_content"] ?? "";
            $pageType = $input["page_type"] ?? "";

            if (empty($prompt)) {
                $this->jsonResponse(["error" => $this->lang->t("common.empty_request")], 400);
            }

            $tablesContext = $this->getTablesContext();

            // По pageType определяем, какая таблица наиболее релевантна
            $pageToTable = [
                "blog_list" => "posts",
                "single" => "posts",
                "blog" => "posts",
                "page" => "pages",
                "home" => "pages",
                "form" => null
            ];
            $sourceTable = $pageToTable[$pageType] ?? null;
            if ($sourceTable && $this->db->tableExists($sourceTable)) {
                $struct = $this->db->getTableStructure($sourceTable);
                $primaryCols = array_map(function($c) {
                    return $c["name"] . " (" . $c["type"] . ")";
                }, $struct);
                array_unshift($tablesContext, ["name" => $sourceTable, "columns" => $primaryCols]);
            }

            $result = $this->ai->generateTemplate($prompt, [
                "tables" => $tablesContext,
                "existing_content" => $existingContent,
                "page_type" => $pageType,
                "source_table" => $sourceTable,
                "css" => $this->getCssContext(),
                "templates" => $this->getTemplatesContext()
            ], $this->aiPrompts["template"] ?? "");

            $this->jsonResponse([
                "response" => $result,
                "template" => $result
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/generate-table
     * Генерация структуры таблицы
     */
    public function generateTable() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $prompt = $input["prompt"] ?? "";

            if (empty($prompt)) {
                $this->jsonResponse(["error" => $this->lang->t("common.empty_request")], 400);
            }

            $result = $this->ai->generateTableStructure($prompt, $this->aiPrompts["table"] ?? "");

            // Пробуем распарсить JSON из ответа
            $columns = json_decode($result, true);
            if ($columns === null) {
                // Если AI вернул невалидный JSON — возвращаем как текст
                $this->jsonResponse([
                    "response" => $result,
                    "columns" => null,
                    "raw" => true
                ]);
            }

            $this->jsonResponse([
                "response" => $result,
                "columns" => $columns
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/generate-content
     * Генерация записей для таблицы
     */
    public function generateContent() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $tableName = $input["table"] ?? "";
            $prompt = $input["prompt"] ?? "";
            $count = min((int)($input["count"] ?? 5), 20);

            if (empty($tableName) || empty($prompt)) {
                $this->jsonResponse(["error" => $this->lang->t("common.table_not_specified")], 400);
            }

            // Проверяем существование таблицы
            if (!$this->db->tableExists($tableName)) {
                $this->jsonResponse(["error" => $this->lang->t("common.table_not_found", ["name" => $tableName])], 404);
            }

            // Получаем структуру
            $structure = $this->db->getTableStructure($tableName);
            $columns = array_filter($structure, function($col) {
                // Исключаем системные поля
                return !in_array($col["name"], ["id", "created_at", "updated_at", "read_status"])
                    && $col["name"] !== "id";
            });
            $columns = array_values($columns);

            $result = $this->ai->generateContent($tableName, $columns, $prompt, $count, $this->aiPrompts["content"] ?? "");

            // Пробуем распарсить JSON
            $records = json_decode($result, true);

            $this->jsonResponse([
                "response" => $result,
                "records" => $records,
                "table" => $tableName,
                "count" => $count
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/insert-content
     * Вставить сгенерированный контент в таблицу
     */
    public function insertContent() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $tableName = $input["table"] ?? "";
            $records = $input["records"] ?? [];

            if (empty($tableName) || empty($records)) {
                $this->jsonResponse(["error" => $this->lang->t("common.records_not_specified")], 400);
            }

            if (!$this->db->tableExists($tableName)) {
                $this->jsonResponse(["error" => $this->lang->t("common.table_not_found", ["name" => $tableName])], 404);
            }

            $inserted = 0;
            $errors = [];

            foreach ($records as $record) {
                try {
                    // Убираем id если есть (автоинкремент)
                    unset($record["id"]);
                    $this->db->insert($tableName, $record);
                    $inserted++;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            $this->jsonResponse([
                "success" => true,
                "inserted" => $inserted,
                "total" => count($records),
                "errors" => $errors
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/fill-form
     * Sgenerit znachenia polei dlya formy sozdania zapisi
     */
    public function fillForm() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $tableName = $input["table"] ?? "";
            $prompt = $input["prompt"] ?? "";
            $structure = $input["structure"] ?? [];

            if (empty($tableName) || empty($prompt)) {
                $this->jsonResponse(["error" => "Ne ukazana tablica ili zapros"], 400);
            }

            // Существующие значения (итеративное заполнение)
            $existingValues = $input["existing_values"] ?? [];

            $fields = array_filter($structure, function($col) {
                return !in_array($col["name"], ["id", "created_at", "updated_at", "read_status"]);
            });
            $fields = array_values($fields);
            $fieldsJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $systemPrompt = "Ty — pomoshnik po zapolneniu form. Generiruy JSON s znacheniami polei.\n";
            $systemPrompt .= "PRAVILA: 1. Otvechai TOLKO JSON-obektom, bez markdown i poyasnenii.\n";
            $systemPrompt .= "2. Format: {\"field_name\": \"znachenie\"}\n";
            $systemPrompt .= "3. TEXT = tekst, INTEGER = chislo, REAL = drobnoe\n";
            $systemPrompt .= "4. DATETIME = Y-m-d H:i:s, email = validnyi email\n";
            $systemPrompt .= "5. Znachenia realisticnye i raznobraznye\n";
            $customPrompt = $this->aiPrompts["fill_form"] ?? "";
            if (!empty($customPrompt)) {
                $systemPrompt .= "\n\nDOPOLNITELNYE INSTRUKCII:\n" . $customPrompt;
            }

            $existingValuesJson = !empty($existingValues) ? json_encode($existingValues, JSON_UNESCAPED_UNICODE) : "net";
            $userMsg = "Tablica: {$tableName}\nPolia: {$fieldsJson}\n\nTEKUSHIE ZNACHENIA: {$existingValuesJson}\n\nNE PEREPISYVAI VSIO! Izmeni tolko to, chto nuzhno po zaprosu. Ostalnoe ostav kak est.\nZapros: {$prompt}\nGeneriruy JSON";

            $result = $this->ai->chat([["role" => "user", "content" => $userMsg]], $systemPrompt, 0.7, 4096);
            $values = json_decode($result, true);

            // Keep user-filled values, don't let AI overwrite them
            if (is_array($values) && !empty($existingValues)) {
                foreach ($existingValues as $key => $val) {
                    if (isset($values[$key]) && isset($val) && trim($val) !== '') {
                        $values[$key] = $val;
                    }
                }
            }

            $this->jsonResponse(["response" => $result, "values" => $values, "fields" => $fields]);
        } catch (\Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /ai/generate-css
     * Generate/edit CSS code
     */
    public function generateCss() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $prompt = $input["prompt"] ?? "";
            $existingContent = $input["existing_content"] ?? "";

            if (empty($prompt)) {
                $this->jsonResponse(["error" => $this->lang->t("common.empty_request")], 400);
            }

            $templatesCtx = $this->getTemplatesContext();
            $result = $this->ai->generateCSS($prompt, $existingContent, $templatesCtx, $this->aiPrompts["css"] ?? "");

            $this->jsonResponse([
                "response" => $result,
                "css" => $result
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }

}
