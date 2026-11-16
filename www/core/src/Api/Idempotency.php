<?php
/**
 * Api\Idempotency — the same POST twice must not create two rows.
 *
 * Agents retry: a timeout, a 502 from a proxy, a client that repeats a request
 * after a crash. Without this, "publish this news item" reliably produces
 * three of them.
 *
 * The client sends `Idempotency-Key: <uuid>`. The first response is stored
 * together with the key for 24 hours; a repeat returns the stored response
 * (with `Idempotent-Replay: true`) instead of writing again. Only successful
 * writes (2xx) are stored — a failed attempt must stay retryable.
 */

namespace Api;

class Idempotency {

    /** How long a key is remembered. */
    private const TTL_HOURS = 24;

    /** @var \Core\Database */
    private $db;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS api_idempotency (
            key TEXT PRIMARY KEY,
            method TEXT NOT NULL,
            path TEXT,
            status INTEGER NOT NULL,
            response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_idem_created ON api_idempotency(created_at)');
        $done = true;
    }

    /**
     * The key from the request header, or null when the client sent none.
     */
    public static function keyFromRequest(): ?string {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        // A key is an opaque token, not free text: bound its size.
        return substr($key, 0, 200);
    }

    /**
     * Replay a stored response for this key, if any.
     *
     * @return array{status: int, response: array}|null
     */
    public function find(string $key, string $method, string $path): ?array {
        try {
            $row = $this->db->query(
                'SELECT status, response FROM api_idempotency
                  WHERE key = ? AND method = ? AND path = ?
                    AND created_at > datetime("now", ?)',
                [$key, strtoupper($method), $path, '-' . self::TTL_HOURS . ' hours']
            )->fetch();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) $row['response'], true);
        return [
            'status'   => (int) $row['status'],
            'response' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * Remember a successful response for this key.
     */
    public function store(string $key, string $method, string $path, int $status, array $response): void {
        try {
            $this->prune();
            $this->db->query(
                'INSERT OR REPLACE INTO api_idempotency (key, method, path, status, response, created_at)
                 VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
                [
                    $key,
                    strtoupper($method),
                    $path,
                    $status,
                    json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable $e) {
            // Never fail a write because the replay cache is unavailable.
        }
    }

    /**
     * Drop keys older than the TTL. Cheap: the table stays tiny.
     */
    public function prune(): void {
        try {
            $this->db->query(
                'DELETE FROM api_idempotency WHERE created_at <= datetime("now", ?)',
                ['-' . self::TTL_HOURS . ' hours']
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
