<?php
/**
 * Core\SchemaInspector — the description layer of a project.
 *
 * Turns the database into something an agent (or a human) can understand:
 *   - shape:  PRAGMA table_info, types, defaults, NOT NULL, primary keys
 *   - limits: enum values from CHECK constraints in sqlite_master.sql
 *   - meaning: table_meta / field_meta (label, purpose, format, example)
 *   - links:  foreign keys and entity_relations (many-to-many pivots)
 *   - map:    navigation rows, resolved into a page graph
 *
 * Used by the HTTP API (/api/v1/schema, /navigation, /relations) and by the
 * admin table constructor, so both always tell the same story.
 */

namespace Core;

class SchemaInspector {

    /** Tables the API and the AI never touch: internal state of the CMS. */
    private const SYSTEM_PREFIXES = ['system_', 'plugin_', 'sqlite_'];

    /**
     * Tables the API and the AI never touch: internal state of the CMS.
     * entity_relations, visit_stats and the meta tables are machinery: an
     * agent reads relations through /api/v1/relations and meanings through
     * /api/v1/schema, it must not edit them as data.
     */
    private const SYSTEM_TABLES = [
        'users', 'user_tokens',
        'entity_relations', 'visit_stats', 'table_meta', 'field_meta',
        'api_idempotency',
        'sqlite_master', 'sqlite_sequence',
    ];

    /** @var \Core\Database */
    private $db;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->db->ensureMetaTables();
    }

    // ── table filters ──────────────────────────────────

    public static function isSystemTable(string $name): bool {
        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return in_array($name, self::SYSTEM_TABLES, true);
    }

    /**
     * User tables (the ones an agent may read and write), sorted by name.
     *
     * @return string[]
     */
    public function userTables(): array {
        $tables = array_values(array_filter($this->db->getTables(), function ($t) {
            return !self::isSystemTable($t);
        }));
        sort($tables);
        return $tables;
    }

    // ── single table ───────────────────────────────────

    /**
     * Full description of one table.
     *
     * @param bool $withRows include the row count (one COUNT(*) per table)
     * @return array{name,label,description,purpose,rows,columns,foreign_keys,relations}
     */
    public function tableSchema(string $table, bool $withRows = true): array {
        $meta    = $this->db->getTableMeta($table) ?? [];
        $fieldMeta = $this->db->getFieldMeta($table);
        $columns = [];

        foreach ($this->db->getTableStructure($table) as $col) {
            $name = $col['name'];
            $fm   = $fieldMeta[$name] ?? [];
            $enum = $fm['enum'] ?? null;
            if ($enum === null) {
                $enum = $this->enumFromCheck($table, $name);
            }
            $columns[] = [
                'name'        => $name,
                'type'        => $col['type'],
                'pk'          => (bool) $col['pk'],
                'notnull'     => (bool) $col['notnull'],
                'default'     => Database::normalizeDefault($col['dflt_value']),
                'enum'        => $enum,
                'label'       => $fm['label'] ?? null,
                'description' => $fm['description'] ?? null,
                'format'      => $fm['format'] ?? null,
                'example'     => $fm['example'] ?? null,
            ];
        }

        return [
            'name'         => $table,
            'label'        => $meta['label'] ?? null,
            'description'  => $meta['description'] ?? null,
            'purpose'      => $meta['purpose'] ?? null,
            'rows'         => $withRows ? $this->rowCount($table) : null,
            'columns'      => $columns,
            'foreign_keys' => $this->foreignKeys($table),
            'relations'    => $this->relations($table),
        ];
    }

    /**
     * Whole project in one payload: every user table plus the page graph.
     * This is the answer to "what is this site?" — built to be cheap to read.
     */
    public function projectSchema(): array {
        $tables = [];
        foreach ($this->userTables() as $table) {
            $tables[] = $this->tableSchema($table);
        }

        return [
            'cms'          => 'apidcms',
            'status'       => 'preview',
            'generated_at' => date('c'),
            'tables'       => $tables,
            'navigation'   => $this->navigationGraph(),
            'notes'        => [
                'System tables (system_*, plugin_*, users, user_tokens) are hidden from this API.',
                'null semantics: a column value of null may mean "not described yet", it is never invented.',
            ],
        ];
    }

    public function rowCount(string $table): int {
        try {
            $row = $this->db->query('SELECT COUNT(*) c FROM ' . $this->db->quoteIdentifier($table))->fetch();
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ── constraints ────────────────────────────────────

    /**
     * Enum values declared as CHECK ("col" IN ('a','b')) in the table DDL.
     *
     * The table constructor does not create CHECK constraints, so this is
     * mainly for tables created with raw SQL — the usual apidcms way.
     *
     * @return string[]|null
     */
    public function enumFromCheck(string $table, string $column): ?array {
        $row = $this->db->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        )->fetch();
        if (!$row || empty($row['sql'])) {
            return null;
        }
        $quoted = preg_quote($column, '/');
        $pattern = '/CHECK\s*\(\s*["`\[]?' . $quoted . '["`\]]?\s+IN\s*\(\s*([^)]*)\)/i';
        if (!preg_match($pattern, $row['sql'], $m)) {
            return null;
        }
        if (!preg_match_all("/'((?:[^']|'')*)'/", $m[1], $values)) {
            return null;
        }
        $out = array_map(fn($v) => str_replace("''", "'", $v), $values[1]);
        return $out ?: null;
    }

    /**
     * Foreign keys of a table: [{column, references_table, references_column, on_delete}]
     */
    public function foreignKeys(string $table): array {
        try {
            $rows = $this->db->query('PRAGMA foreign_key_list(' . $this->db->quoteIdentifier($table) . ')')->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'column'            => $r['from'],
                'references_table'  => $r['table'],
                'references_column' => $r['to'],
                'on_delete'         => $r['on_delete'] ?? null,
            ];
        }
        return $result;
    }

    // ── entity_relations (many-to-many) ────────────────

    /**
     * Pivot relations of a table (or of the whole project when $table is null).
     *
     * Shape: [{relation_name, source_table, target_table, count}]
     * target_table is not stored on the pivot, so it stays null unless a
     * relation_name matches a table name — we never guess it.
     */
    public function relations(?string $table = null): array {
        $where = '';
        $params = [];
        if ($table !== null) {
            $where = ' WHERE source_table = ?';
            $params[] = $table;
        }
        try {
            $rows = $this->db->query(
                'SELECT relation_name, source_table, COUNT(*) c
                   FROM entity_relations' . $where . '
                  GROUP BY relation_name, source_table
                  ORDER BY source_table, relation_name',
                $params
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }

        $tables = $this->userTables();
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'relation_name' => $r['relation_name'],
                'source_table'  => $r['source_table'],
                'target_table'  => in_array($r['relation_name'], $tables, true) ? $r['relation_name'] : null,
                'count'         => (int) $r['c'],
            ];
        }
        return $result;
    }

    // ── navigation: the page graph ─────────────────────

    /**
     * Resolved page graph. page_config is decoded and merged with the columns
     * it overrides, so an agent sees "which table feeds which URL with which
     * template" instead of guessing at a JSON string.
     *
     * @return array[]
     */
    public function navigationGraph(): array {
        $rows = $this->db->query(
            'SELECT * FROM navigation ORDER BY menu_order, id'
        )->fetchAll();

        $pages = [];
        foreach ($rows as $row) {
            $config = $this->decodeJson($row['page_config'] ?? null);
            $filters = $row['filters'] ?? null;
            $pageConfigError = null;
            if ($filters === null || $filters === '') {
                $filters = $config['filters'] ?? [];
            } elseif (is_string($filters)) {
                $decoded = json_decode($filters, true);
                if (is_array($decoded)) {
                    $filters = $decoded;
                } else {
                    $filters = [];
                    $pageConfigError = 'filters is not valid JSON';
                }
            }
            if (($row['page_config'] ?? '') !== null && ($row['page_config'] ?? '') !== '' && empty($config) && $pageConfigError === null) {
                $pageConfigError = 'page_config is not valid JSON';
            }

            $type = $config['type'] ?? ($row['page_type'] ?? null);
            $sourceTable = $config['source_table'] ?? ($row['source_table'] ?? null);
            if ($sourceTable === '') {
                $sourceTable = null;
            }
            if (!$type) {
                $type = $sourceTable ? 'dynamic' : null;
            }

            $pages[] = [
                'id'             => (int) $row['id'],
                'slug'           => $row['url'],
                'url'            => in_array($type, ['raw', 'external'], true)
                                    ? $row['url']
                                    : '/' . ltrim((string) $row['url'], '/'),
                'title'          => $row['title'],
                'type'           => $type,
                'template'       => $config['template'] ?? ($row['template'] ?? null),
                'source_table'   => $sourceTable,
                'filters'        => $filters,
                'get_filters'    => $config['get_filters'] ?? null,
                'items_per_page' => $config['items_per_page'] ?? ($row['items_per_page'] ?? null),
                'sort'           => $config['sort'] ?? null,
                'page_id'        => $row['page_id'] !== null ? (int) $row['page_id'] : null,
                'parent_id'      => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
                'location'       => $row['location'] ?? null,
                'status'         => $row['status'] ?? null,
                'page_config_error' => $pageConfigError,
            ];
        }
        return $pages;
    }

    // ── helpers ────────────────────────────────────────

    /**
     * Decode a JSON column tolerant to nulls and garbage.
     */
    private function decodeJson($raw): array {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
