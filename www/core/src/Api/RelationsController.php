<?php
/**
 * Api\RelationsController — writing many-to-many links through the API.
 *
 * entity_relations is hidden from /tables on purpose (it is machinery, not
 * content), so this is the only way to link a row to its categories, tags or
 * whatever the project declared in navigation.page_config.relations.
 *
 *   POST   /api/v1/relations      link target_ids to a source row
 *   DELETE /api/v1/relations      unlink (empty target_ids = unlink everything)
 *   GET    /api/v1/relations/{table}/{id}  links of one row, grouped
 *
 * Validation, dry_run, Idempotency-Key and the audit trail work exactly like
 * they do for /tables: one failure mode, one way to see what changed.
 */

namespace Api;

use Core\Relations;

class RelationsController {

    use WriteSupport;

    /** @var \Core\Database */
    private $db;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /** GET /api/v1/relations/{table}/{id} — links of one row. */
    public function forRow(string $table, string $id): void {
        if (!$this->db->tableExists($table) || \Core\SchemaInspector::isSystemTable($table)) {
            $this->err(404, 'not_found', "Таблица \"$table\" не существует или недоступна через API");
        }
        $rowId = (int) $id;
        $row = $this->db->getById($table, $rowId);
        if (!$row) {
            $this->err(404, 'not_found', "Запись id=$rowId в таблице \"$table\" не найдена");
        }

        $relations = new Relations($this->db);
        $links = [];
        foreach ($relations->knownFor($table) as $name) {
            $ids = $relations->links($table, $rowId, $name);
            $links[] = [
                'relation_name' => $name,
                'target_table'  => $relations->targetTable($name),
                'target_ids'    => $ids,
                'count'         => count($ids),
            ];
        }

        $this->ok([
            'source_table' => $table,
            'source_id'    => $rowId,
            'relations'    => $links,
            'total'        => count($links),
        ]);
    }

    /** POST /api/v1/relations — link targets to a source row. */
    public function add(): void {
        $this->handle('POST');
    }

    /** DELETE /api/v1/relations — unlink targets from a source row. */
    public function remove(): void {
        $this->handle('DELETE');
    }

    // ── shared ─────────────────────────────────────────

    private function handle(string $method): void {
        $body = $this->jsonBody();
        $relations = new Relations($this->db);
        $checked = $relations->check($body);

        if ($checked['errors']) {
            if ($this->dryRun()) {
                $this->ok([
                    'dry_run'    => true,
                    'action'     => $method === 'POST' ? 'link' : 'unlink',
                    'validation' => $checked['errors'],
                ]);
            }
            $this->validationFailed((string) ($checked['source_table'] ?? 'relations'),
                $method === 'POST' ? 'link' : 'unlink', $checked['errors']);
        }

        $sourceTable  = (string) $checked['source_table'];
        $sourceId     = (int) $checked['source_id'];
        $relationName = (string) $checked['relation_name'];
        $targetIds    = (array) $checked['target_ids'];
        $targetTable  = $checked['target_table'];

        $before = $relations->links($sourceTable, $sourceId, $relationName);

        if ($this->dryRun()) {
            $after = $method === 'POST'
                ? array_values(array_unique(array_merge($before, $targetIds)))
                : array_values(array_diff($before, $targetIds));
            sort($after);
            $this->ok([
                'dry_run'       => true,
                'action'        => $method === 'POST' ? 'link' : 'unlink',
                'source_table'  => $sourceTable,
                'source_id'     => $sourceId,
                'relation_name' => $relationName,
                'target_table'  => $targetTable,
                'validation'    => [],
                'would_change'  => $after !== $before,
                'before'        => $before,
                'after'         => $after,
                'affected_urls' => $this->affectedUrls($sourceTable),
            ]);
        }

        $replay = $this->idempotentReplay($method);
        if ($replay !== null) {
            $this->ok($replay['response'], $replay['status']);
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            if ($method === 'POST') {
                $result = $relations->add($sourceTable, $sourceId, $relationName, $targetIds);
                $changed = ['added' => $result['added'], 'already' => $result['already']];
            } else {
                $result = $relations->remove($sourceTable, $sourceId, $relationName, $targetIds);
                $changed = ['removed' => $result['removed'], 'missing' => $result['missing'],
                            'cleared' => $result['cleared']];
            }
            $after = $relations->links($sourceTable, $sourceId, $relationName);

            $this->auditWrite($method, $sourceTable, $sourceId, [
                'relation_name' => $relationName,
                'target_table'  => $targetTable,
                'before'        => $before,
                'after'         => $after,
                'changed'       => $changed,
            ]);

            $response = [
                'source_table'  => $sourceTable,
                'source_id'     => $sourceId,
                'relation_name' => $relationName,
                'target_table'  => $targetTable,
                'target_ids'    => $after,
                'count'         => count($after),
                'changes'       => $changed,
            ];
            $status = $method === 'POST' ? 201 : 200;
            $this->idempotentStore($method, $status, $response);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $this->databaseError($sourceTable, $e);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->err(500, 'internal_error', $e->getMessage());
        }

        $this->ok($response, $status);
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
