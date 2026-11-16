<?php
/**
 * Core\Audit — who changed what, when, from where.
 *
 * Agents retry, and a human has to be able to see what an agent did. Every
 * write through /api/v1 lands in system_audit with the diff, so the answer to
 * "why is this row like this?" is one query away.
 *
 * The table is created on first use (like table_meta), so upgrading a site
 * needs no manual migration.
 */

namespace Core;

class Audit {

    /** Diff size limit: an audit row must not become a copy of the database. */
    private const MAX_DIFF_BYTES = 8000;

    /** How long entries are kept; pruned lazily on write. */
    private const RETENTION_DAYS = 90;

    /** @var Database */
    private $db;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    public function ensureTable(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS system_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            actor TEXT,
            token_id INTEGER,
            method TEXT NOT NULL,
            path TEXT,
            table_name TEXT,
            row_id INTEGER,
            ip TEXT,
            diff TEXT
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON system_audit(created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_table ON system_audit(table_name, row_id)');
        $done = true;
    }

    /**
     * Record one write.
     *
     * @param array $diff ['before' => array|null, 'after' => array|null, 'changed' => array]
     */
    public function record(
        string $method,
        string $table,
        ?int $rowId,
        array $diff = [],
        string $actor = 'api',
        ?int $tokenId = null
    ): void {
        try {
            $this->db->query(
                'INSERT INTO system_audit (actor, token_id, method, path, table_name, row_id, ip, diff)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $actor,
                    $tokenId,
                    strtoupper($method),
                    $this->currentPath(),
                    $table,
                    $rowId,
                    $this->clientIp(),
                    $this->encodeDiff($diff),
                ]
            );
            $this->prune();
        } catch (\Throwable $e) {
            // Auditing must never break the write it describes.
        }
    }

    /**
     * Drop entries older than RETENTION_DAYS. Runs on ~1% of writes, so the
     * table is bounded without a cron job.
     */
    private function prune(): void {
        if (random_int(1, 100) !== 1) {
            return;
        }
        try {
            $this->db->query(
                'DELETE FROM system_audit WHERE created_at <= datetime("now", ?)',
                ['-' . self::RETENTION_DAYS . ' days']
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Recent entries, newest first.
     *
     * @param int      $limit max 500
     * @param string|null $table only entries of one table
     */
    public function recent(int $limit = 50, ?string $table = null): array {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, created_at, actor, token_id, method, path, table_name, row_id, ip, diff
                  FROM system_audit';
        $params = [];
        if ($table !== null && $table !== '') {
            $sql .= ' WHERE table_name = ?';
            $params[] = $table;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $rows = $this->db->query($sql, $params)->fetchAll();
        foreach ($rows as &$row) {
            $row['diff'] = $row['diff'] !== null ? json_decode($row['diff'], true) : null;
        }
        return $rows;
    }

    public function count(?string $table = null): int {
        $sql = 'SELECT COUNT(*) c FROM system_audit';
        $params = [];
        if ($table !== null && $table !== '') {
            $sql .= ' WHERE table_name = ?';
            $params[] = $table;
        }
        return (int) $this->db->query($sql, $params)->fetch()['c'];
    }

    /**
     * Which columns differ between the old and the new row.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changedFields(array $before, array $after): array {
        $changed = [];
        foreach ($after as $name => $value) {
            $old = $before[$name] ?? null;
            if ((string) $old !== (string) $value || ($old === null) !== ($value === null)) {
                $changed[$name] = ['from' => $old, 'to' => $value];
            }
        }
        return $changed;
    }

    private function encodeDiff(array $diff): ?string {
        if (empty($diff)) {
            return null;
        }
        $json = json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }
        if (strlen($json) > self::MAX_DIFF_BYTES) {
            // Keep the shape, drop the bulk: the audit says WHAT changed even
            // when the values themselves are too big to store.
            $json = json_encode([
                'truncated' => true,
                'keys'      => array_keys($diff),
            ], JSON_UNESCAPED_UNICODE);
        }
        return $json;
    }

    private function clientIp(): ?string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip !== null ? (string) $ip : null;
    }

    private function currentPath(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (string) parse_url($uri, PHP_URL_PATH);
    }
}
