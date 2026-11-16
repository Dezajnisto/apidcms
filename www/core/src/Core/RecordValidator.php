<?php
/**
 * Core\RecordValidator — turns a table into a contract and checks records.
 *
 * Shape comes from PRAGMA (types, NOT NULL, defaults), limits from CHECK
 * constraints, meaning from table_meta/field_meta, links from foreign keys.
 * The result is (a) a JSON Schema an agent can read before writing and (b) a
 * machine-readable list of violations an agent can fix itself from:
 *
 *   {column, rule, got, expected}
 *
 * Rules are deliberately explicit instead of "500 + exception text": a wrong
 * type must never reach the database, because SQLite happily stores "1500 руб"
 * in an INTEGER column and nobody notices until the page is wrong.
 */

namespace Core;

class RecordValidator {

    /** @var Database */
    private $db;

    /** @var SchemaInspector */
    private $inspector;

    /** @var string */
    private $table;

    /** @var array lazily loaded tableSchema() */
    private $schema;

    public function __construct(Database $db, string $table) {
        $this->db = $db;
        $this->table = $table;
        $this->inspector = new SchemaInspector($db);
    }

    // ── schema ─────────────────────────────────────────

    private function schema(): array {
        if ($this->schema === null) {
            $this->schema = $this->inspector->tableSchema($this->table, false);
        }
        return $this->schema;
    }

    /** @return array<string, array> columns keyed by name */
    private function columns(): array {
        $out = [];
        foreach ($this->schema()['columns'] as $col) {
            $out[$col['name']] = $col;
        }
        return $out;
    }

    /**
     * JSON Schema (draft 2020-12) of one table, with apidcms extensions.
     *
     * An agent reads this before writing and gets types, enums, defaults,
     * references and human labels in one place.
     */
    public function jsonSchema(): array {
        $schema = $this->schema();
        $properties = [];
        $required = [];

        foreach ($schema['columns'] as $col) {
            $prop = ['type' => self::jsonType($col['type'])];
            if ($col['label']) {
                $prop['title'] = $col['label'];
            }
            if ($col['description']) {
                $prop['description'] = $col['description'];
            }
            if ($col['format']) {
                $prop['x-format'] = $col['format'];
            }
            if (!empty($col['enum'])) {
                $prop['enum'] = $col['enum'];
            }
            if ($col['example'] !== null && $col['example'] !== '') {
                $prop['examples'] = [$col['example']];
            }
            if ($col['default'] !== null) {
                $prop['default'] = $col['default'];
            }
            $prop['x-sqlite-type'] = $col['type'];
            if ($col['pk']) {
                $prop['readOnly'] = true;
            }
            if ($col['name'] === 'id') {
                $prop['readOnly'] = true;
            }
            if ($col['notnull'] && $col['default'] === null && !$col['pk']) {
                $required[] = $col['name'];
            }
            $properties[$col['name']] = $prop;
        }

        foreach ($schema['foreign_keys'] as $fk) {
            if (isset($properties[$fk['column']])) {
                $properties[$fk['column']]['x-references'] = [
                    'table'  => $fk['references_table'],
                    'column' => $fk['references_column'],
                ];
            }
        }

        return [
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'title'                => $schema['label'] ?: $this->table,
            'description'          => $schema['description'] ?: ($schema['purpose'] ?: null),
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
            'x-apidcms'            => [
                'table'        => $this->table,
                'rows'         => $schema['rows'],
                'relations'    => $schema['relations'],
                'foreign_keys' => $schema['foreign_keys'],
                'endpoint'     => '/api/v1/tables/' . $this->table,
            ],
        ];
    }

    // ── validation ─────────────────────────────────────

    /**
     * Validate a payload against the table.
     *
     * @param array $payload     decoded JSON body
     * @param bool  $isUpdate    partial row (PUT) instead of full (POST)
     * @param array $existing    current row, used for "unchanged" checks
     * @return array<int, array{column: string, rule: string, got: mixed, expected: string}>
     *         empty when the payload is valid
     */
    public function validate(array $payload, bool $isUpdate = false, array $existing = []): array {
        $columns = $this->columns();
        $errors = [];

        foreach ($payload as $field => $value) {
            // `id` is echoed back by clients that just fetched a row; the
            // primary key is never written, so it is ignored silently.
            if ($field === 'id') {
                continue;
            }
            if (!isset($columns[$field])) {
                $errors[] = [
                    'column'   => (string) $field,
                    'rule'     => 'unknown_column',
                    'got'      => $value,
                    'expected' => 'one of: ' . implode(', ', array_keys($columns)),
                ];
                continue;
            }
            $error = $this->validateColumn($columns[$field], $value);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // Required columns: only for a full row (POST), and only when the
        // column has no default to fall back on.
        if (!$isUpdate) {
            foreach ($columns as $name => $col) {
                if ($col['pk'] || $name === 'id') {
                    continue;
                }
                if (!$col['notnull'] || $col['default'] !== null) {
                    continue;
                }
                if (array_key_exists($name, $payload)) {
                    continue;
                }
                $errors[] = [
                    'column'   => $name,
                    'rule'     => 'required',
                    'got'      => null,
                    'expected' => 'a value is required (' . $col['type'] . ($col['label'] ? ', ' . $col['label'] : '') . ')',
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate one column value.
     *
     * @return array|null error entry or null when valid
     */
    private function validateColumn(array $col, $value): ?array {
        $name = $col['name'];
        $isNull = ($value === null);

        if ($isNull) {
            if ($col['notnull']) {
                return $this->error($name, 'notnull', $value, 'a value is required, NULL is not allowed');
            }
            return null;
        }

        // Empty string is normalized to NULL later by Database::normalizeEmptyValues().
        if ($value === '') {
            if ($col['notnull'] && self::isNumeric($col['type'])) {
                return $this->error($name, 'notnull', $value, 'an empty string is not a number (' . $col['type'] . ')');
            }
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return $this->error($name, 'type', $value, self::jsonType($col['type']) . ' (' . $col['type'] . ')');
        }

        $affinity = self::affinity($col['type']);

        if (!self::matchesAffinity($affinity, $value)) {
            return $this->error($name, 'type', $value, self::jsonType($col['type']) . ' (' . $col['type'] . ')');
        }

        if (!empty($col['enum'])) {
            $allowed = array_map('strval', $col['enum']);
            if (!in_array((string) $value, $allowed, true)) {
                return $this->error($name, 'enum', $value, 'one of: ' . implode(', ', $allowed));
            }
        }

        if ($col['format'] !== null) {
            $formatError = $this->validateFormat($col['format'], $value);
            if ($formatError !== null) {
                return $this->error($name, 'format', $value, $formatError);
            }
        }

        $maxLength = self::maxLength($col['type']);
        if ($maxLength !== null && is_string($value) && self::length($value) > $maxLength) {
            return $this->error($name, 'max_length', self::shorten($value, 30), "at most $maxLength characters");
        }

        foreach ($this->schema()['foreign_keys'] as $fk) {
            if ($fk['column'] !== $name) {
                continue;
            }
            if (!$this->referencedRowExists($fk, $value)) {
                return $this->error(
                    $name,
                    'foreign_key',
                    $value,
                    "an existing id in {$fk['references_table']}.{$fk['references_column']}"
                );
            }
        }

        return null;
    }

    /**
     * Human- and machine-readable format check (field_meta.format).
     */
    private function validateFormat(string $format, $value): ?string {
        switch (strtolower($format)) {
            case 'integer':
                return preg_match('/^-?\d+$/', (string) $value) ? null : 'an integer';
            case 'money':
            case 'number':
            case 'decimal':
                return is_numeric((string) $value) ? null : 'a number';
            case 'boolean':
            case 'bool':
                return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'yes', 'no'], true)
                    ? null : 'a boolean (true/false, 1/0)';
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? null : 'a date as YYYY-MM-DD';
            case 'datetime':
                $ts = strtotime((string) $value);
                return $ts !== false ? null : 'a date and time (YYYY-MM-DD HH:MM:SS)';
            case 'url':
            case 'uri':
                return filter_var((string) $value, FILTER_VALIDATE_URL) !== false ? null : 'a full URL';
            case 'email':
                return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false ? null : 'an email address';
            case 'slug':
                return preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/i', (string) $value) ? null : 'a slug (letters, digits, - and _)';
            default:
                return null; // unknown format label — do not invent a rule
        }
    }

    private function referencedRowExists(array $fk, $value): bool {
        $table = $fk['references_table'];
        // An empty value means "no link", already handled above.
        if ($value === null || $value === '') {
            return true;
        }
        try {
            if (!$this->db->tableExists($table)) {
                return true; // dangling reference declaration — do not block writes
            }
            $row = $this->db->query(
                'SELECT 1 FROM ' . $this->db->quoteIdentifier($table) .
                ' WHERE ' . $this->db->quoteIdentifier($fk['references_column']) . ' = ? LIMIT 1',
                [$value]
            )->fetch();
            return (bool) $row;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Cast validated values to what SQLite expects: numeric strings become
     * ints/floats, booleans become 0/1. Unknown keys are dropped.
     */
    public function cast(array $payload): array {
        $columns = $this->columns();
        $out = [];
        foreach ($payload as $field => $value) {
            if ($field === 'id' || !isset($columns[$field])) {
                continue;
            }
            $affinity = self::affinity($columns[$field]['type']);
            if ($value === '') {
                $out[$field] = $value; // normalization happens in Database
                continue;
            }
            if ($affinity === 'integer' && is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $value = (int) $value;
            } elseif ($affinity === 'number' && is_string($value) && is_numeric($value)) {
                $value = (float) $value;
            } elseif ($affinity === 'integer' && is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $out[$field] = $value;
        }
        return $out;
    }

    // ── type helpers ───────────────────────────────────

    /**
     * SQLite type affinity — the four buckets that actually matter when
     * writing a value.
     */
    public static function affinity(string $sqliteType): string {
        $t = strtoupper($sqliteType);
        if (strpos($t, 'INT') !== false || strpos($t, 'BOOL') !== false) {
            return 'integer';
        }
        if (strpos($t, 'REAL') !== false || strpos($t, 'FLOA') !== false || strpos($t, 'DOUB') !== false
            || strpos($t, 'NUM') !== false || strpos($t, 'DEC') !== false) {
            return 'number';
        }
        if (strpos($t, 'BLOB') !== false) {
            return 'blob';
        }
        return 'text';
    }

    public static function isNumeric(string $sqliteType): bool {
        $affinity = self::affinity($sqliteType);
        return $affinity === 'integer' || $affinity === 'number';
    }

    /** JSON Schema type for a SQLite type. */
    public static function jsonType(string $sqliteType): string {
        switch (self::affinity($sqliteType)) {
            case 'integer':
                return 'integer';
            case 'number':
                return 'number';
            default:
                return 'string';
        }
    }

    private static function matchesAffinity(string $affinity, $value): bool {
        switch ($affinity) {
            case 'integer':
                if (is_int($value)) {
                    return true;
                }
                if (is_bool($value)) {
                    return true;
                }
                if (is_float($value)) {
                    return floor($value) == $value;
                }
                return is_string($value) && preg_match('/^-?\d+$/', $value) === 1;
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return true;
                }
                return is_string($value) && is_numeric($value);
            case 'blob':
                return is_string($value);
            default:
                return is_string($value) || is_int($value) || is_float($value);
        }
    }

    /** VARCHAR(120) → 120; no explicit limit → null. */
    private static function maxLength(string $sqliteType): ?int {
        if (preg_match('/\(\s*(\d+)\s*\)/', $sqliteType, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** Character count, without requiring mbstring. */
    private static function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** Truncate a rejected value before putting it into an error payload. */
    private static function shorten(string $value, int $limit): string {
        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
        return $cut . (self::length($value) > $limit ? '…' : '');
    }

    private function error(string $column, string $rule, $got, string $expected): array {
        return [
            'column'   => $column,
            'rule'     => $rule,
            'got'      => $got,
            'expected' => $expected,
        ];
    }
}
