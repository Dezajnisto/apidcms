<?php
/**
 * Core\AgentTools — the actions a model is allowed to take on this site.
 *
 * One catalogue, three consumers:
 *   - /mcp (Model Context Protocol) exposes it to external agents;
 *   - the built-in assistant calls it through function calling instead of
 *     carrying 58k tokens of documentation in every prompt;
 *   - /api/v1 keeps its own JSON endpoints, but they share the same Core
 *     services (SchemaInspector, RecordValidator, Audit), so nothing drifts.
 *
 * A tool either succeeds with data or fails with a machine-readable error:
 *   ['ok' => true,  'data' => mixed]
 *   ['ok' => false, 'error' => array]
 *
 * Definitions use the JSON Schema subset that both MCP and OpenAI-compatible
 * function calling understand.
 */

namespace Core;

class AgentTools {

    /** @var Database */
    private $db;

    /** @var SchemaInspector|null */
    private $inspector;

    /** @var DocIndex|null */
    private $docs;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Tool catalogue. Descriptions are written for a model: when to use a
     * tool, what it costs, what comes back.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     */
    public function definitions(): array {
        return [
            [
                'name'        => 'schema.get',
                'description' => 'The whole project in one call: tables, columns (type, required, default, allowed values, label, format), foreign keys, many-to-many relations and the page graph (which table feeds which URL with which template). Start here.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tables'     => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Optional: limit to these tables.',
                        ],
                        'rows'       => [
                            'type'        => 'boolean',
                            'description' => 'Include row counts (one COUNT(*) per table). Default true.',
                        ],
                        'navigation' => [
                            'type'        => 'boolean',
                            'description' => 'Include the page graph. Default true.',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'records.list',
                'description' => 'Read rows of one table with pagination, sorting and substring search across text columns.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table'  => ['type' => 'string', 'description' => 'Table name.'],
                        'limit'  => ['type' => 'integer', 'description' => 'Max rows, 1..200, default 20.'],
                        'offset' => ['type' => 'integer', 'description' => 'Skip this many rows.'],
                        'sort'   => ['type' => 'string', 'description' => 'Column to sort by, default id.'],
                        'order'  => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'search' => ['type' => 'string'],
                    ],
                    'required' => ['table'],
                ],
            ],
            [
                'name'        => 'records.get',
                'description' => 'One row by id.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table' => ['type' => 'string'],
                        'id'    => ['type' => 'integer'],
                    ],
                    'required' => ['table', 'id'],
                ],
            ],
            [
                'name'        => 'records.create',
                'description' => 'Insert a row. The payload is validated against the table (types, NOT NULL, enums, foreign keys, formats); on rejection the error lists column, rule, got and expected. Use dry_run=true first to see what would change and which URLs are affected.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table'   => ['type' => 'string'],
                        'data'    => ['type' => 'object', 'description' => 'Column → value.'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                    'required' => ['table', 'data'],
                ],
            ],
            [
                'name'        => 'records.update',
                'description' => 'Update the given columns of one row. Same validation as create. Returns which columns changed.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table'   => ['type' => 'string'],
                        'id'      => ['type' => 'integer'],
                        'data'    => ['type' => 'object'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                    'required' => ['table', 'id', 'data'],
                ],
            ],
            [
                'name'        => 'records.delete',
                'description' => 'Delete one row. Use dry_run=true to see the row and the pages that would lose it.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table'   => ['type' => 'string'],
                        'id'      => ['type' => 'integer'],
                        'dry_run' => ['type' => 'boolean'],
                    ],
                    'required' => ['table', 'id'],
                ],
            ],
            [
                'name'        => 'navigation.get',
                'description' => 'The page map: for every page its slug, URL, type, template, source table, filters and per-page count. The answer to "which table feeds this URL?".',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'relations.list',
                'description' => 'Many-to-many relations stored in entity_relations, optionally for one table.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['table' => ['type' => 'string']],
                ],
            ],
            [
                'name'        => 'audit.list',
                'description' => 'Recent writes: method, path, table, row, IP and the diff. Use it to check what has already been done.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => '1..500, default 20.'],
                        'table' => ['type' => 'string'],
                        'id'    => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name'        => 'docs.list',
                'description' => 'Table of contents of the CMS documentation (slug, title, section, description). Read this before docs.get — it is cheap.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'docs.get',
                'description' => 'One documentation article as Markdown, by slug from docs.list.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['slug' => ['type' => 'string']],
                    'required'   => ['slug'],
                ],
            ],
            [
                'name'        => 'docs.search',
                'description' => 'Find documentation articles by words from the question. Falls back to full-text search when titles do not match.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'description' => 'default 5'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Same catalogue in the OpenAI/DeepSeek function-calling shape.
     */
    public function functionDefinitions(): array {
        $out = [];
        foreach ($this->definitions() as $tool) {
            $out[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => str_replace('.', '_', $tool['name']),
                    'description' => $tool['description'],
                    'parameters'  => $tool['inputSchema'],
                ],
            ];
        }
        return $out;
    }

    /**
     * Run one tool. Never throws: a failure is data the model can read.
     *
     * @param string $name dot name ("schema.get") or function name ("schema_get")
     * @return array{ok: bool, data?: mixed, error?: array}
     */
    public function call(string $name, array $args = [], string $actor = 'agent'): array {
        $name = $this->canonicalName($name);
        try {
            switch ($name) {
                case 'schema.get':
                    return $this->okResult($this->toolSchema($args));
                case 'records.list':
                    return $this->okResult($this->toolList($args));
                case 'records.get':
                    return $this->okResult($this->toolGet($args));
                case 'records.create':
                    return $this->toolCreate($args, $actor);
                case 'records.update':
                    return $this->toolUpdate($args, $actor);
                case 'records.delete':
                    return $this->toolDelete($args, $actor);
                case 'navigation.get':
                    return $this->okResult($this->inspector()->navigationGraph());
                case 'relations.list':
                    return $this->okResult($this->inspector()->relations($args['table'] ?? null));
                case 'audit.list':
                    return $this->okResult($this->toolAudit($args));
                case 'docs.list':
                    return $this->okResult($this->docIndex()->toc());
                case 'docs.get':
                    return $this->okResult($this->toolDoc($args));
                case 'docs.search':
                    return $this->okResult(
                        $this->docIndex()->searchDeep((string) ($args['query'] ?? ''), (int) ($args['limit'] ?? 5))
                    );
                default:
                    return $this->failResult('unknown_tool', "Unknown tool \"$name\". Available tools are returned by tools/list.");
            }
        } catch (\Throwable $e) {
            return $this->failResult('tool_failed', $e->getMessage());
        }
    }

    public function setLang(string $lang): void {
        $this->docIndex()->setLang($lang);
    }

    /** Pages that show this table — the "why is it not visible" answer. */
    public function affectedUrls(string $table): array {
        $out = [];
        foreach ($this->inspector()->navigationGraph() as $page) {
            if ($page['source_table'] === $table) {
                $out[] = [
                    'slug'     => $page['slug'],
                    'url'      => $page['url'],
                    'title'    => $page['title'],
                    'template' => $page['template'],
                ];
            }
        }
        return $out;
    }

    // ── tools ──────────────────────────────────────────

    private function toolSchema(array $args): array {
        $only = isset($args['tables']) && is_array($args['tables']) && $args['tables'] ? $args['tables'] : null;
        $withRows = !array_key_exists('rows', $args) || $args['rows'] !== false;
        $withNav = !array_key_exists('navigation', $args) || $args['navigation'] !== false;

        $tables = [];
        foreach ($this->inspector()->userTables() as $table) {
            if ($only !== null && !in_array($table, $only, true)) {
                continue;
            }
            $tables[] = $this->inspector()->tableSchema($table, $withRows);
        }

        return [
            'cms'          => 'apidcms',
            'version'      => $this->coreVersion(),
            'generated_at' => date('c'),
            'tables'       => $tables,
            'navigation'   => $withNav ? $this->inspector()->navigationGraph() : [],
        ];
    }

    private function toolList(array $args): array {
        $table = $this->requireTable($args['table'] ?? '');
        $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $sort = (string) ($args['sort'] ?? 'id');
        $order = strtolower((string) ($args['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $search = trim((string) ($args['search'] ?? ''));

        $structure = $this->db->getTableStructure($table);
        $columns = array_column($structure, 'name');
        if (!in_array($sort, $columns, true)) {
            $sort = in_array('id', $columns, true) ? 'id' : $columns[0];
        }

        $where = '';
        $params = [];
        if ($search !== '') {
            $conds = [];
            foreach ($structure as $col) {
                if (RecordValidator::affinity($col['type']) === 'text') {
                    $conds[] = $this->db->quoteIdentifier($col['name']) . ' LIKE ?';
                    $params[] = '%' . $search . '%';
                }
            }
            if ($conds) {
                $where = ' WHERE ' . implode(' OR ', $conds);
            }
        }

        $total = (int) $this->db->query(
            'SELECT COUNT(*) c FROM ' . $this->db->quoteIdentifier($table) . $where,
            $params
        )->fetch()['c'];

        $rows = $this->db->query(
            'SELECT * FROM ' . $this->db->quoteIdentifier($table) . $where .
            ' ORDER BY ' . $this->db->quoteIdentifier($sort) . ' ' . $order .
            ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        )->fetchAll();

        return ['table' => $table, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    private function toolGet(array $args): array {
        $table = $this->requireTable($args['table'] ?? '');
        $id = (int) ($args['id'] ?? 0);
        $row = $this->db->getById($table, $id);
        if (!$row) {
            throw new \RuntimeException("No row id=$id in table \"$table\"");
        }
        return ['table' => $table, 'row' => $row];
    }

    private function toolCreate(array $args, string $actor): array {
        $table = $this->requireTable($args['table'] ?? '');
        $data = is_array($args['data'] ?? null) ? $args['data'] : null;
        if ($data === null || $data === []) {
            throw new \RuntimeException('data must be a non-empty object of columns');
        }

        $validator = new RecordValidator($this->db, $table);
        $errors = $validator->validate($data, false);
        if ($errors) {
            return $this->failResult('validation_failed', 'Проверьте поля', [
                'table'  => $table,
                'action' => 'insert',
                'fields' => $errors,
            ]);
        }
        $payload = $this->db->normalizeEmptyValues($table, $validator->cast($data));

        if (!empty($args['dry_run'])) {
            return $this->okResult([
                'dry_run'       => true,
                'table'         => $table,
                'action'        => 'insert',
                'would_change'  => true,
                'changes'       => $this->describeChanges([], $payload),
                'affected_urls' => $this->affectedUrls($table),
            ]);
        }

        $id = (int) $this->db->insert($table, $payload);
        $row = $this->db->getById($table, $id) ?: $payload;
        $this->audit()->record('POST', $table, $id, ['after' => $row], $actor);
        return $this->okResult(['created' => true, 'table' => $table, 'id' => $id, 'row' => $row]);
    }

    private function toolUpdate(array $args, string $actor): array {
        $table = $this->requireTable($args['table'] ?? '');
        $id = (int) ($args['id'] ?? 0);
        $data = is_array($args['data'] ?? null) ? $args['data'] : null;
        if ($data === null || $data === []) {
            throw new \RuntimeException('data must be a non-empty object of columns');
        }
        $before = $this->db->getById($table, $id);
        if (!$before) {
            throw new \RuntimeException("No row id=$id in table \"$table\"");
        }

        $validator = new RecordValidator($this->db, $table);
        $errors = $validator->validate($data, true, $before);
        if ($errors) {
            return $this->failResult('validation_failed', 'Проверьте поля', [
                'table'  => $table,
                'action' => 'update',
                'id'     => $id,
                'fields' => $errors,
            ]);
        }
        $payload = $this->db->normalizeEmptyValues($table, $validator->cast($data));
        if (!$payload) {
            throw new \RuntimeException('Nothing to update after validation');
        }

        $after = $before;
        foreach ($payload as $col => $val) {
            $after[$col] = $val;
        }
        $changed = Audit::changedFields($before, $after);

        if (!empty($args['dry_run'])) {
            return $this->okResult([
                'dry_run'       => true,
                'table'         => $table,
                'id'            => $id,
                'action'        => 'update',
                'would_change'  => true,
                'changes'       => $changed,
                'affected_urls' => $this->affectedUrls($table),
            ]);
        }

        $this->db->update($table, $id, $payload);
        $this->audit()->record('PUT', $table, $id, [
            'before'  => $before,
            'after'   => $after,
            'changed' => $changed,
        ], $actor);
        return $this->okResult(['updated' => true, 'table' => $table, 'id' => $id, 'changed' => $changed]);
    }

    private function toolDelete(array $args, string $actor): array {
        $table = $this->requireTable($args['table'] ?? '');
        $id = (int) ($args['id'] ?? 0);
        $row = $this->db->getById($table, $id);
        if (!$row) {
            throw new \RuntimeException("No row id=$id in table \"$table\"");
        }

        if (!empty($args['dry_run'])) {
            return $this->okResult([
                'dry_run'       => true,
                'table'         => $table,
                'id'            => $id,
                'action'        => 'delete',
                'would_change'  => true,
                'row'           => $row,
                'affected_urls' => $this->affectedUrls($table),
            ]);
        }

        try {
            $this->db->delete($table, $id);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
                return $this->failResult('foreign_key_violation', 'Строка используется другой таблицей', [
                    'table' => $table,
                    'id'    => $id,
                    'hint'  => 'Remove the dependent rows first.',
                ]);
            }
            throw $e;
        }

        $this->audit()->record('DELETE', $table, $id, ['before' => $row], $actor);
        return $this->okResult(['deleted' => true, 'table' => $table, 'id' => $id]);
    }

    private function toolAudit(array $args): array {
        $limit = (int) ($args['limit'] ?? 20);
        $table = isset($args['table']) && $args['table'] !== '' ? (string) $args['table'] : null;
        $entries = $this->audit()->recent($limit, $table);
        if (isset($args['id'])) {
            $rowId = (int) $args['id'];
            $entries = array_values(array_filter(
                $entries,
                fn($e) => (int) ($e['row_id'] ?? 0) === $rowId
            ));
        }
        return $entries;
    }

    private function toolDoc(array $args): array {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw new \RuntimeException('slug is required; take one from docs.list');
        }
        $doc = $this->docIndex()->get($slug);
        if ($doc === null) {
            throw new \RuntimeException("No document with slug \"$slug\"; call docs.list");
        }
        return $doc;
    }

    // ── plumbing ───────────────────────────────────────

    private function inspector(): SchemaInspector {
        if ($this->inspector === null) {
            $this->inspector = new SchemaInspector($this->db);
        }
        return $this->inspector;
    }

    private function docIndex(): DocIndex {
        if ($this->docs === null) {
            $this->docs = new DocIndex();
        }
        return $this->docs;
    }

    private function audit(): Audit {
        return new Audit($this->db);
    }

    private function requireTable($name): string {
        $name = (string) $name;
        if ($name === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \RuntimeException('A valid table name is required');
        }
        if (SchemaInspector::isSystemTable($name)) {
            throw new \RuntimeException("Table \"$name\" is internal and not exposed");
        }
        if (!$this->db->tableExists($name)) {
            throw new \RuntimeException("Table \"$name\" does not exist");
        }
        return $name;
    }

    private function describeChanges(array $before, array $after): array {
        $out = [];
        foreach ($after as $col => $val) {
            $out[$col] = ['from' => $before[$col] ?? null, 'to' => $val];
        }
        return $out;
    }

    private function canonicalName(string $name): string {
        if (strpos($name, '.') !== false) {
            return $name;
        }
        // OpenAI function names cannot contain dots: schema_get → schema.get
        foreach ($this->definitions() as $tool) {
            if (str_replace('.', '_', $tool['name']) === $name) {
                return $tool['name'];
            }
        }
        return $name;
    }

    private function okResult($data): array {
        return ['ok' => true, 'data' => $data];
    }

    private function failResult(string $code, string $message, array $extra = []): array {
        return [
            'ok'    => false,
            'error' => array_merge(['error' => $code, 'message' => $message], $extra),
        ];
    }

    private function coreVersion(): string {
        $file = CORE_PATH . '/VERSION';
        return file_exists($file) ? trim(file_get_contents($file)) : '0.0.0';
    }
}
