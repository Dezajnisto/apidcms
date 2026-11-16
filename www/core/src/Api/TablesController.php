<?php
/**
 * Api\TablesController — read + write ЛЮБОЙ таблицы в БД (динамическая схема).
 *
 * GET: list, get, getById, schema
 * POST: insert (write to any user table)
 * Системные таблицы (system_*, plugin_*, sqlite_*) исключаются.
 */

namespace Api;

class TablesController {

    use WriteSupport;

    /** @var \Core\Database */
    private $db;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /**
     * GET /api/v1/tables — список таблиц со схемой.
     */
    public function list(): void {
        $allTables = $this->db->getTables();

        $result = [];
        foreach ($allTables as $name) {
            if ($this->isSystem($name)) {
                continue;
            }
            $cols = $this->db->getTableStructure($name);
            $colNames = array_column($cols, 'name');
            $rowCount = (int) $this->db->query(
                'SELECT COUNT(*) c FROM ' . $this->q($name)
            )->fetch()['c'];

            $meta = $this->db->getTableMeta($name);
            $result[] = [
                'name'    => $name,
                'label'   => $meta['label'] ?? null,
                'rows'    => $rowCount,
                'columns' => $colNames,
            ];
        }

        usort($result, fn($a, $b) => $a['name'] <=> $b['name']);
        $this->ok(['tables' => $result]);
    }

    /**
     * GET /api/v1/tables/:table — записи таблицы.
     * ?limit=50&offset=0&sort=id&order=desc&search=...
     */
    public function get(string $tableName): void {
        $this->validateTable($tableName);

        $limit  = $this->intParam('limit', 50, 1, 200);
        $offset = $this->intParam('offset', 0, 0, PHP_INT_MAX);
        $sort   = $_GET['sort'] ?? 'id';
        $order  = strtoupper($_GET['order'] ?? 'desc');
        $order  = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
        $search = trim($_GET['search'] ?? '');

        // Проверить, что sort-колонка существует
        $cols = $this->db->getTableStructure($tableName);
        $colNames = array_column($cols, 'name');
        if (!in_array($sort, $colNames, true)) {
            $sort = 'id';
        }

        // Поиск: все текстовые поля
        $where = '';
        $params = [];
        if ($search !== '') {
            $conditions = [];
            foreach ($cols as $col) {
                if ($this->isTextType($col['type'])) {
                    $conditions[] = $this->q($col['name']) . ' LIKE ?';
                    $params[] = '%' . $search . '%';
                }
            }
            if (!empty($conditions)) {
                $where = ' WHERE ' . implode(' OR ', $conditions);
            }
        }

        $total = (int) $this->db->query(
            'SELECT COUNT(*) c FROM ' . $this->q($tableName) . $where,
            $params
        )->fetch()['c'];

        $params[] = $limit;
        $params[] = $offset;
        $rows = $this->db->query(
            'SELECT * FROM ' . $this->q($tableName) . $where .
            ' ORDER BY ' . $this->q($sort) . ' ' . $order . ' LIMIT ? OFFSET ?',
            $params
        )->fetchAll();

        $this->ok([
            'table'   => $tableName,
            'rows'    => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }

    /**
     * GET /api/v1/tables/:table/:id — одна запись.
     */
    public function getById(string $tableName, string $id): void {
        $this->validateTable($tableName);
        $row = $this->db->getById($tableName, (int) $id);
        if (!$row) {
            $this->err(404, 'not_found', "Запись id=$id в таблице \"$tableName\" не найдена");
        }
        $this->ok(['table' => $tableName, 'row' => $row]);
    }

    /**
     * POST /api/v1/tables/:table — create a record.
     *
     * Closed loop for an agent: the body is validated against the table
     * (types, NOT NULL, defaults, enums, foreign keys, formats) and a bad
     * value gets 422 with a field list instead of a 500 or, worse, a silent
     * write of "1500 руб" into an INTEGER column.
     *
     * ?dry_run=1 — validate and report what would change, write nothing.
     * Idempotency-Key — a retry returns the first response instead of
     * creating a second row.
     */
    public function insert(string $tableName): void {
        $this->validateTable($tableName);

        $body = $this->jsonBody();
        if (!is_array($body) || empty($body)) {
            $this->err(400, 'bad_request', 'Тело должно быть JSON-объектом с полями таблицы');
        }

        $validator = new \Core\RecordValidator($this->db, $tableName);
        $errors = $validator->validate($body, false);
        if ($errors) {
            // A dry run is a question, not a write: answer with the violations
            // instead of a 422, so one call tells the whole story.
            if ($this->dryRun()) {
                $this->ok([
                    'dry_run'      => true,
                    'table'        => $tableName,
                    'action'       => 'insert',
                    'would_change' => false,
                    'validation'   => $errors,
                ]);
            }
            $this->validationFailed($tableName, 'insert', $errors);
        }
        $data = $this->db->normalizeEmptyValues($tableName, $validator->cast($body));

        if ($this->dryRun()) {
            $this->ok([
                'dry_run'       => true,
                'table'         => $tableName,
                'action'        => 'insert',
                'would_change'  => true,
                'validation'    => [],
                'changes'       => $this->describeChanges([], $data),
                'affected_urls' => $this->affectedUrls($tableName),
            ]);
        }

        // Reply from the first attempt instead of writing a second row.
        $replay = $this->idempotentReplay('POST');
        if ($replay !== null) {
            $this->ok($replay['response'], $replay['status']);
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $id = (int) $this->db->insert($tableName, $data);
            $row = $this->db->getById($tableName, $id) ?: $data;
            $this->auditWrite('POST', $tableName, $id, ['after' => $row]);
            $response = ['table' => $tableName, 'id' => $id, 'row' => $row];
            $this->idempotentStore('POST', 201, $response);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $this->databaseError($tableName, $e);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->err(500, 'internal_error', $e->getMessage());
        }

        $this->ok($response, 201);
    }

    /**
     * PUT /api/v1/tables/:table/:id — update a record (partial).
     *
     * Same validation as POST, but only the fields that were sent are checked;
     * required fields that are already set stay valid.
     *
     * ?dry_run=1 — show the resulting row and the diff, write nothing.
     */
    public function update(string $tableName, string $id): void {
        $this->validateTable($tableName);
        $idInt = (int) $id;

        $before = $this->db->getById($tableName, $idInt);
        if (!$before) {
            $this->err(404, 'not_found', "Запись id=$id в таблице \"$tableName\" не найдена");
        }

        $body = $this->jsonBody();
        if (!is_array($body) || empty($body)) {
            $this->err(400, 'bad_request', 'Тело должно быть JSON-объектом для обновления');
        }

        $validator = new \Core\RecordValidator($this->db, $tableName);
        $errors = $validator->validate($body, true, $before);
        if ($errors) {
            if ($this->dryRun()) {
                $this->ok([
                    'dry_run'      => true,
                    'table'        => $tableName,
                    'id'           => $idInt,
                    'action'       => 'update',
                    'would_change' => false,
                    'validation'   => $errors,
                ]);
            }
            $this->validationFailed($tableName, 'update', $errors);
        }
        $data = $this->db->normalizeEmptyValues($tableName, $validator->cast($body));

        if (empty($data)) {
            $this->err(422, 'validation_failed', 'Нет полей для обновления');
        }

        $after = $before;
        foreach ($data as $col => $val) {
            $after[$col] = $val;
        }

        if ($this->dryRun()) {
            $this->ok([
                'dry_run'       => true,
                'table'         => $tableName,
                'id'            => $idInt,
                'action'        => 'update',
                'would_change'  => true,
                'validation'    => [],
                'changes'       => \Core\Audit::changedFields($before, $after),
                'row'           => $after,
                'affected_urls' => $this->affectedUrls($tableName),
            ]);
        }

        $replay = $this->idempotentReplay('PUT');
        if ($replay !== null) {
            $this->ok($replay['response'], $replay['status']);
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $this->db->update($tableName, $idInt, $data);
            $changes = \Core\Audit::changedFields($before, $after);
            $this->auditWrite('PUT', $tableName, $idInt, [
                'before'  => $before,
                'after'   => $after,
                'changed' => $changes,
            ]);
            $response = [
                'table'   => $tableName,
                'id'      => $idInt,
                'updated' => true,
                'changed' => $changes,
            ];
            $this->idempotentStore('PUT', 200, $response);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $this->databaseError($tableName, $e);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->err(500, 'internal_error', $e->getMessage());
        }

        $this->ok($response);
    }

    /**
     * DELETE /api/v1/tables/:table/:id — delete a record.
     *
     * ?dry_run=1 — show which pages would lose this row, delete nothing. That
     * is the answer to "I deleted it, so why is the page still the same?".
     */
    public function delete(string $tableName, string $id): void {
        $this->validateTable($tableName);
        $idInt = (int) $id;

        $row = $this->db->getById($tableName, $idInt);
        if (!$row) {
            $this->err(404, 'not_found', "Запись id=$id в таблице \"$tableName\" не найдена");
        }

        if ($this->dryRun()) {
            $this->ok([
                'dry_run'       => true,
                'table'         => $tableName,
                'id'            => $idInt,
                'action'        => 'delete',
                'would_change'  => true,
                'validation'    => [],
                'row'           => $row,
                'affected_urls' => $this->affectedUrls($tableName),
            ]);
        }

        $replay = $this->idempotentReplay('DELETE');
        if ($replay !== null) {
            $this->ok($replay['response'], $replay['status']);
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $this->db->delete($tableName, $idInt);
            $this->auditWrite('DELETE', $tableName, $idInt, ['before' => $row]);
            $response = ['table' => $tableName, 'id' => $idInt, 'deleted' => true];
            $this->idempotentStore('DELETE', 200, $response);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $this->databaseError($tableName, $e);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->err(500, 'internal_error', $e->getMessage());
        }

        $this->ok($response);
    }

    /**
     * GET /api/v1/tables/:table/schema — structure of one table.
     *
     * Shape comes from PRAGMA, meaning from table_meta/field_meta, limits from
     * CHECK constraints, links from PRAGMA foreign_key_list + entity_relations.
     */
    public function schema(string $tableName): void {
        $this->validateTable($tableName);
        $inspector = new \Core\SchemaInspector($this->db);
        $this->ok($inspector->tableSchema($tableName));
    }

    /**
     * GET /api/v1/tables/:table/json-schema — JSON Schema of one table.
     *
     * The same rules the write endpoints enforce, expressed as JSON Schema
     * (draft 2020-12) with apidcms extensions: labels, formats, references,
     * enum values and relations. An agent can validate locally before POSTing.
     */
    public function jsonSchema(string $tableName): void {
        $this->validateTable($tableName);
        $validator = new \Core\RecordValidator($this->db, $tableName);
        $this->ok($validator->jsonSchema());
    }

    // ── helpers ────────────────────────────────────────

    private function validateTable(string $name): void {
        if ($name === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $this->err(400, 'bad_request', 'Некорректное имя таблицы');
        }
        if (!$this->db->tableExists($name)) {
            $this->err(404, 'not_found', "Таблица \"$name\" не существует");
        }
        if ($this->isSystem($name)) {
            $this->err(403, 'forbidden', "Таблица \"$name\" недоступна через API");
        }
    }

    private function isSystem(string $name): bool {
        return \Core\SchemaInspector::isSystemTable($name);
    }

    private function isTextType(string $type): bool {
        $t = strtolower($type);
        return in_array($t, ['text', 'varchar', 'char', 'string', 'clob', 'nvarchar', 'nchar'], true)
            || strpos($t, 'char') !== false
            || strpos($t, 'text') !== false;
    }

    private function q(string $s): string {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    private function intParam(string $key, int $default, int $min, int $max): int {
        $v = isset($_GET[$key]) ? (int) $_GET[$key] : $default;
        return max($min, min($max, $v));
    }

    private function jsonBody(): array {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->err(400, 'bad_request', 'Некорректный JSON в теле запроса');
        }
        return $data;
    }

    private function ok(array $payload, int $http = 200): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function err(int $http, string $code, string $msg): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => $code, 'message' => $msg]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}