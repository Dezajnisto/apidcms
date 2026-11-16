<?php
/**
 * Core\Relations — many-to-many links as data, for the API and the assistant.
 *
 * Links live in the entity_relations pivot, which the API deliberately hides
 * as a table (it is machinery, not content). Hiding it without offering a way
 * to write links would mean an external agent can read "product → categories"
 * but can never create one. This class is that way:
 *
 *   POST   /api/v1/relations  {source_table, source_id, relation_name, target_ids[]}
 *   DELETE /api/v1/relations  same body (empty target_ids = unlink all)
 *
 * The target table of a relation is NOT guessed from the relation name when
 * the project says otherwise: it comes from navigation.page_config.relations
 * (the same place the admin form reads), with "relation name is a table" as a
 * fallback for projects that never configured the column.
 */

namespace Core;

class Relations {

    /** @var Database */
    private $db;

    /** @var SchemaInspector */
    private $inspector;

    /** @var array|null relation_name => target_table, cached per request */
    private $map = null;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->inspector = new SchemaInspector($db);
    }

    // ── resolution ─────────────────────────────────────

    /**
     * relation_name => target_table, from page_config (authoritative) and
     * from "relation name equals table name" (fallback).
     *
     * @return array<string, string>
     */
    public function map(): array {
        if ($this->map !== null) {
            return $this->map;
        }
        $map = [];
        $userTables = $this->inspector->userTables();

        try {
            $rows = $this->db->query(
                "SELECT source_table, page_config FROM navigation
                  WHERE page_type = 'dynamic' AND status = 'active'"
            )->fetchAll();
            foreach ($rows as $row) {
                if (empty($row['page_config'])) {
                    continue;
                }
                $config = json_decode($row['page_config'], true);
                if (!is_array($config) || empty($config['relations']) || !is_array($config['relations'])) {
                    continue;
                }
                foreach ($config['relations'] as $column => $rel) {
                    if (!is_array($rel) || empty($rel['table'])) {
                        continue;
                    }
                    if (isset($map[$column])) {
                        continue; // first definition wins, as in the admin form
                    }
                    if (!in_array($rel['table'], $userTables, true)) {
                        continue;
                    }
                    $map[$column] = $rel['table'];
                }
            }
        } catch (\Throwable $e) {
            // A broken page_config must not take relations down with it.
        }

        foreach ($userTables as $table) {
            if (!isset($map[$table])) {
                $map[$table] = $table;
            }
        }

        $this->map = $map;
        return $map;
    }

    /** Target table of a relation, or null when nothing is known about it. */
    public function targetTable(string $relationName): ?string {
        $map = $this->map();
        return $map[$relationName] ?? null;
    }

    /**
     * Relations that make sense for a table: those already used by its rows
     * plus those its page_config declares. Used in the "unknown relation"
     * error message, so an agent does not have to guess names.
     */
    public function knownFor(string $sourceTable): array {
        $known = [];
        try {
            $rows = $this->db->query(
                'SELECT DISTINCT relation_name FROM entity_relations WHERE source_table = ?',
                [$sourceTable]
            )->fetchAll();
            foreach ($rows as $row) {
                $known[] = (string) $row['relation_name'];
            }
        } catch (\Throwable $e) {
            // Pivot missing is not an error here.
        }
        try {
            $rows = $this->db->query(
                "SELECT page_config FROM navigation
                  WHERE source_table = ? AND page_type = 'dynamic'",
                [$sourceTable]
            )->fetchAll();
            foreach ($rows as $row) {
                $config = json_decode((string) ($row['page_config'] ?? ''), true);
                if (!is_array($config) || empty($config['relations']) || !is_array($config['relations'])) {
                    continue;
                }
                foreach (array_keys($config['relations']) as $column) {
                    $known[] = (string) $column;
                }
            }
        } catch (\Throwable $e) {
            // Same: navigation is optional.
        }
        $known = array_values(array_unique(array_filter($known, fn($n) => $n !== '')));
        sort($known);
        return $known;
    }

    // ── reading ────────────────────────────────────────

    /** Target ids linked to one source row through one relation. */
    public function links(string $sourceTable, int $sourceId, string $relationName): array {
        $rows = $this->db->query(
            'SELECT target_id FROM entity_relations
              WHERE source_table = ? AND source_id = ? AND relation_name = ?
              ORDER BY target_id',
            [$sourceTable, $sourceId, $relationName]
        )->fetchAll();
        return array_map(fn($r) => (int) $r['target_id'], $rows);
    }

    // ── validation ─────────────────────────────────────

    /**
     * Check the request before touching the pivot.
     *
     * @return array{errors: array, target_table: ?string, source_table: ?string,
     *               source_id: ?int, relation_name: string, target_ids: int[]}
     */
    public function check(array $payload): array {
        $errors = [];
        $sourceTable = trim((string) ($payload['source_table'] ?? ''));
        $relationName = trim((string) ($payload['relation_name'] ?? ''));
        $sourceId = isset($payload['source_id']) ? (int) $payload['source_id'] : 0;

        if ($sourceTable === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sourceTable)) {
            $errors[] = ['column' => 'source_table', 'rule' => 'invalid_name', 'got' => $sourceTable,
                         'expected' => 'a table name from GET /api/v1/tables'];
        } elseif (!$this->db->tableExists($sourceTable) || SchemaInspector::isSystemTable($sourceTable)) {
            $errors[] = ['column' => 'source_table', 'rule' => 'unknown_table', 'got' => $sourceTable,
                         'expected' => 'a user table: ' . implode(', ', $this->inspector->userTables())];
        }

        if ($sourceId <= 0) {
            $errors[] = ['column' => 'source_id', 'rule' => 'invalid_id', 'got' => $payload['source_id'] ?? null,
                         'expected' => 'a positive integer id of an existing row'];
        } elseif (empty($errors) && !$this->db->getById($sourceTable, $sourceId)) {
            $errors[] = ['column' => 'source_id', 'rule' => 'not_found', 'got' => $sourceId,
                         'expected' => "an existing id in table \"$sourceTable\""];
        }

        if ($relationName === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $relationName)) {
            $errors[] = ['column' => 'relation_name', 'rule' => 'invalid_name', 'got' => $relationName,
                         'expected' => 'a relation name such as "categories"'];
        }

        $targetTable = $relationName !== '' ? $this->targetTable($relationName) : null;
        if ($relationName !== '' && $targetTable === null) {
            $known = $this->knownFor($sourceTable);
            $errors[] = ['column' => 'relation_name', 'rule' => 'unknown_relation', 'got' => $relationName,
                         'expected' => $known ? 'one of: ' . implode(', ', $known)
                                              : 'a relation declared in navigation.page_config.relations'];
        }

        $targetIds = [];
        $raw = $payload['target_ids'] ?? null;
        if ($raw === null || $raw === []) {
            // Empty list is meaningful: DELETE unlinks everything, POST is a no-op.
            $raw = [];
        } elseif (!is_array($raw)) {
            $errors[] = ['column' => 'target_ids', 'rule' => 'invalid_type', 'got' => $raw,
                         'expected' => 'an array of integers'];
            $raw = [];
        }
        foreach ($raw as $id) {
            $int = (int) $id;
            if ((string) $int !== (string) $id && !is_int($id)) {
                $errors[] = ['column' => 'target_ids', 'rule' => 'invalid_id', 'got' => $id,
                             'expected' => 'integer ids'];
                continue;
            }
            if ($int <= 0) {
                $errors[] = ['column' => 'target_ids', 'rule' => 'invalid_id', 'got' => $id,
                             'expected' => 'positive integer ids'];
                continue;
            }
            $targetIds[] = $int;
        }
        $targetIds = array_values(array_unique($targetIds));

        if ($targetIds && $targetTable !== null) {
            $missing = [];
            foreach ($targetIds as $id) {
                if (!$this->db->getById($targetTable, $id)) {
                    $missing[] = $id;
                }
            }
            if ($missing) {
                $errors[] = ['column' => 'target_ids', 'rule' => 'not_found', 'got' => $missing,
                             'expected' => "existing ids in table \"$targetTable\""];
            }
        }

        return [
            'errors'        => $errors,
            'target_table'  => $targetTable,
            'source_table'  => $sourceTable,
            'source_id'     => $sourceId,
            'relation_name' => $relationName,
            'target_ids'    => $targetIds,
        ];
    }

    // ── writing ────────────────────────────────────────

    /**
     * Link the given targets. Already linked ids are reported, not duplicated:
     * the pivot has no unique index, so a plain INSERT would double rows.
     *
     * @return array{added: int[], already: int[]}
     */
    public function add(string $sourceTable, int $sourceId, string $relationName, array $targetIds): array {
        $existing = $this->links($sourceTable, $sourceId, $relationName);
        $added = [];
        $already = [];
        foreach ($targetIds as $id) {
            if (in_array($id, $existing, true)) {
                $already[] = $id;
                continue;
            }
            $this->db->query(
                'INSERT INTO entity_relations (source_table, source_id, relation_name, target_id)
                 VALUES (?, ?, ?, ?)',
                [$sourceTable, $sourceId, $relationName, $id]
            );
            $added[] = $id;
        }
        return ['added' => $added, 'already' => $already];
    }

    /**
     * Unlink the given targets; with an empty list — unlink everything for
     * this source row and relation.
     *
     * @return array{removed: int[], missing: int[], cleared: bool}
     */
    public function remove(string $sourceTable, int $sourceId, string $relationName, array $targetIds): array {
        if (!$targetIds) {
            $before = $this->links($sourceTable, $sourceId, $relationName);
            $this->db->query(
                'DELETE FROM entity_relations
                  WHERE source_table = ? AND source_id = ? AND relation_name = ?',
                [$sourceTable, $sourceId, $relationName]
            );
            return ['removed' => $before, 'missing' => [], 'cleared' => true];
        }

        $existing = $this->links($sourceTable, $sourceId, $relationName);
        $removed = [];
        $missing = [];
        foreach ($targetIds as $id) {
            if (!in_array($id, $existing, true)) {
                $missing[] = $id;
                continue;
            }
            $this->db->query(
                'DELETE FROM entity_relations
                  WHERE source_table = ? AND source_id = ? AND relation_name = ? AND target_id = ?',
                [$sourceTable, $sourceId, $relationName, $id]
            );
            $removed[] = $id;
        }
        return ['removed' => $removed, 'missing' => $missing, 'cleared' => false];
    }
}
