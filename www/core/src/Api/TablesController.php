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

    /** @var \Core\Database */
    private $db;

    private const SKIP_PREFIXES = ['system_', 'plugin_', 'sqlite_'];

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

            $result[] = [
                'name'    => $name,
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
     * POST /api/v1/tables/:table — вставить запись.
     * Тело: {поле: значение, ...} — подхватываются только реальные колонки.
     */
    public function insert(string $tableName): void {
        $this->validateTable($tableName);

        $body = $this->jsonBody();
        if (!is_array($body) || empty($body)) {
            $this->err(400, 'bad_request', 'Тело должно быть JSON-объектом с полями таблицы');
        }

        $cols = $this->db->getTableStructure($tableName);
        $schemaMap = [];
        foreach ($cols as $c) {
            $schemaMap[$c['name']] = $c;
        }

        $data = [];
        foreach ($body as $field => $value) {
            if ($field === 'id') continue;  // автоинкремент
            if (!isset($schemaMap[$field])) continue;  // нет в схеме
            $data[$field] = $value;
        }

        if (empty($data)) {
            $this->err(422, 'validation_error', 'Нет полей для вставки (все поля отфильтрованы по схеме)');
        }

        $colNames = array_keys($data);
        $placeholders = array_fill(0, count($colNames), '?');
        $values = array_values($data);

        $this->db->query(
            'INSERT INTO ' . $this->q($tableName) .
            ' (' . implode(', ', array_map([$this, 'q'], $colNames)) . ')' .
            ' VALUES (' . implode(', ', $placeholders) . ')',
            $values
        );

        $id = (int) $this->db->query('SELECT last_insert_rowid() id')->fetch()['id'];
        http_response_code(201);
        $this->ok(['table' => $tableName, 'id' => $id], 201);
    }

    /**
     * PUT /api/v1/tables/:table/:id — обновить запись (частично).
     * Тело: {поле: значение, ...} — только реальные колонки.
     */
    public function update(string $tableName, string $id): void {
        $this->validateTable($tableName);
        $idInt = (int) $id;

        $exists = $this->db->query(
            'SELECT id FROM ' . $this->q($tableName) . ' WHERE id = ?', [$idInt]
        )->fetch();
        if (!$exists) {
            $this->err(404, 'not_found', "Запись id=$id в таблице \"$tableName\" не найдена");
        }

        $body = $this->jsonBody();
        if (!is_array($body) || empty($body)) {
            $this->err(400, 'bad_request', 'Тело должно быть JSON-объектом для обновления');
        }

        $cols = $this->db->getTableStructure($tableName);
        $schemaMap = [];
        foreach ($cols as $c) {
            $schemaMap[$c['name']] = $c;
        }

        $data = [];
        foreach ($body as $field => $value) {
            if ($field === 'id') continue;  // PK не обновляем
            if (!isset($schemaMap[$field])) continue;  // нет в схеме
            $data[$field] = $value;
        }

        if (empty($data)) {
            $this->err(422, 'validation_error', 'Нет полей для обновления (все поля отфильтрованы по схеме)');
        }

        $setParts = [];
        $values = [];
        foreach ($data as $col => $val) {
            $setParts[] = $this->q($col) . ' = ?';
            $values[] = $val;
        }
        $values[] = $idInt;

        // updated_at обновляем только если колонка существует
        if (isset($schemaMap['updated_at'])) {
            $setParts[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        $this->db->query(
            'UPDATE ' . $this->q($tableName) . ' SET ' . implode(', ', $setParts) . ' WHERE id = ?',
            $values
        );

        $this->ok(['table' => $tableName, 'id' => $idInt, 'updated' => true]);
    }

    /**
     * DELETE /api/v1/tables/:table/:id — удалить запись.
     */
    public function delete(string $tableName, string $id): void {
        $this->validateTable($tableName);
        $idInt = (int) $id;

        $exists = $this->db->query(
            'SELECT id FROM ' . $this->q($tableName) . ' WHERE id = ?', [$idInt]
        )->fetch();
        if (!$exists) {
            $this->err(404, 'not_found', "Запись id=$id в таблице \"$tableName\" не найдена");
        }

        $this->db->query('DELETE FROM ' . $this->q($tableName) . ' WHERE id = ?', [$idInt]);
        http_response_code(204);
        exit;
    }

    /**
     * GET /api/v1/tables/:table/schema — структура таблицы.
     */
    public function schema(string $tableName): void {
        $this->validateTable($tableName);
        $cols = $this->db->getTableStructure($tableName);
        // Привести к читаемому виду
        $columns = array_map(function($c) {
            return [
                'name'    => $c['name'],
                'type'    => $c['type'],
                'pk'      => (bool) $c['pk'],
                'notnull' => (bool) $c['notnull'],
                'default' => $c['dflt_value'],
            ];
        }, $cols);
        $this->ok(['table' => $tableName, 'columns' => $columns]);
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
        foreach (self::SKIP_PREFIXES as $pfx) {
            if (strpos($name, $pfx) === 0) {
                return true;
            }
        }
        return in_array($name, ['sqlite_master', 'sqlite_sequence'], true);
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