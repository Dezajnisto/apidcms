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
        ];
    }

    private $aiPrompts = [];

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
     * Универсальный AI-ассистент
     */
    public function assistant() {
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $message = $input["message"] ?? "";
            $currentPage = $input["current_page"] ?? "";

            if (empty($message)) {
                $this->jsonResponse(["error" => "Пустой запрос"], 400);
            }

            $tablesContext = $this->getTablesContext();
            $response = $this->ai->assistant($message, [
                "tables" => $tablesContext,
                "current_page" => $currentPage
            ], $this->aiPrompts["assistant"] ?? "");

            $this->jsonResponse(["response" => $response]);
        } catch (Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
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
                $this->jsonResponse(["error" => "Пустой запрос"], 400);
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
                "source_table" => $sourceTable
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
                $this->jsonResponse(["error" => "Пустой запрос"], 400);
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
                $this->jsonResponse(["error" => "Не указана таблица или запрос"], 400);
            }

            // Проверяем существование таблицы
            if (!$this->db->tableExists($tableName)) {
                $this->jsonResponse(["error" => "Таблица {} не найдена"], 404);
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
                $this->jsonResponse(["error" => "Не указана таблица или записи"], 400);
            }

            if (!$this->db->tableExists($tableName)) {
                $this->jsonResponse(["error" => "Таблица {} не найдена"], 404);
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

            $this->jsonResponse(["response" => $result, "values" => $values, "fields" => $fields]);
        } catch (\Exception $e) {
            $this->jsonResponse(["error" => $e->getMessage()], 500);
        }
    }
}
