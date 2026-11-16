<?php
/**
 * Api\WriteSupport — shared plumbing of the write endpoints.
 *
 * POST/PUT/DELETE in every API controller answer the same questions: is this
 * payload acceptable, what would change, did the client already send it, and
 * who has to be able to see it afterwards. The answers live here so both
 * TablesController and ArticlesController behave identically.
 *
 * Requires: $this->db (Core\Database).
 */

namespace Api;

trait WriteSupport {

    /** ?dry_run=1 (or true/yes) — validate and report, write nothing. */
    private function dryRun(): bool {
        if (!isset($_GET['dry_run'])) {
            return false;
        }
        return in_array(strtolower((string) $_GET['dry_run']), ['1', 'true', 'yes', 'on'], true);
    }

    private function requestPath(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (string) parse_url($uri, PHP_URL_PATH);
    }

    /**
     * 422 with a machine-readable field list: an agent fixes itself from this
     * instead of guessing what a 500 meant.
     */
    private function validationFailed(string $table, string $action, array $fields): void {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code'    => 'validation_failed',
                'message' => 'Запись отклонена: проверьте поля',
                'table'   => $table,
                'action'  => $action,
                'fields'  => $fields,
                'hint'    => 'Rules come from the table schema: GET /api/v1/tables/' . $table . '/json-schema',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * A constraint the database refused after validation — still machine-
     * readable instead of a bare 500 with an exception text.
     */
    private function databaseError(string $table, \PDOException $e): void {
        $message = $e->getMessage();
        if (strpos($message, 'UNIQUE constraint failed') !== false) {
            $code = 'conflict';
            $http = 409;
            $hint = 'A record with these values already exists. Use PUT to update it.';
        } elseif (strpos($message, 'FOREIGN KEY constraint failed') !== false) {
            $code = 'foreign_key_violation';
            $http = 409;
            $hint = 'A referenced record does not exist (or is still referenced by another table).';
        } elseif (strpos($message, 'NOT NULL constraint failed') !== false) {
            $code = 'notnull_violation';
            $http = 422;
            $hint = 'A required value is missing.';
        } else {
            $code = 'database_error';
            $http = 422;
            $hint = 'The database rejected the row.';
        }
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'table'   => $table,
                'hint'    => $hint,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Pages that show this table: the answer to "I wrote it, why is nothing
     * visible?". Source: navigation.page_config.source_table (or the column of
     * the same name on older records).
     */
    private function affectedUrls(string $table): array {
        try {
            $pages = (new \Core\SchemaInspector($this->db))->navigationGraph();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($pages as $page) {
            if ($page['source_table'] !== $table) {
                continue;
            }
            $out[] = [
                'slug'     => $page['slug'],
                'url'      => $page['url'],
                'title'    => $page['title'],
                'template' => $page['template'],
                'filters'  => $page['filters'],
            ];
        }
        return $out;
    }

    /** Differences between two rows in the {column: {from, to}} shape. */
    private function describeChanges(array $before, array $after): array {
        $out = [];
        foreach ($after as $col => $val) {
            $out[$col] = ['from' => $before[$col] ?? null, 'to' => $val];
        }
        return $out;
    }

    /** Write one audit entry; never fails the write it describes. */
    private function auditWrite(string $method, string $table, ?int $rowId, array $diff): void {
        // The token that made the change is part of the trail: two agents may
        // share one site, and "who did it" must not be a guess.
        (new \Core\Audit($this->db))->record($method, $table, $rowId, $diff, 'api', Auth::currentTokenId());
    }

    /**
     * Stored response for this Idempotency-Key, if the client sent one and the
     * same request already succeeded.
     *
     * @return array{key: string, status: int, response: array}|null
     */
    private function idempotentReplay(string $method): ?array {
        $key = Idempotency::keyFromRequest();
        if ($key === null) {
            return null;
        }
        $replay = (new Idempotency($this->db))->find($key, $method, $this->requestPath());
        if ($replay === null) {
            return null;
        }
        header('Idempotent-Replay: true');
        return ['key' => $key, 'status' => $replay['status'], 'response' => $replay['response']];
    }

    /** Remember a successful write so a retry does not repeat it. */
    private function idempotentStore(string $method, int $status, array $response): void {
        $key = Idempotency::keyFromRequest();
        if ($key === null) {
            return;
        }
        (new Idempotency($this->db))->store($key, $method, $this->requestPath(), $status, $response);
    }
}
