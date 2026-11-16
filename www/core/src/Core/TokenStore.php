<?php
/**
 * Core\TokenStore — API tokens that can be created and revoked in the admin.
 *
 * Until now the API had exactly one key: system_settings.api_key, written by
 * hand into the database, giving access to everything and impossible to
 * revoke from the panel. A token is a key WITH rights: it has a name (so a
 * human can tell two agents apart), a scope (read / content / full), a
 * creation date, a last-used date and a revocation date.
 *
 * Storage rules:
 *   - only sha256(token) is stored; the token itself is shown once;
 *   - the legacy api_key keeps working (full scope) until it is cleared, so
 *     existing integrations do not break on upgrade;
 *   - the table is created lazily, like table_meta, so no manual migration.
 *
 * The table is named api_tokens, NOT user_tokens: on some sites user_tokens
 * already exists as the "remember me" storage of an older login flow
 * (user_id, token, type, expires_at). Same name, different meaning — a
 * collision would silently break logins on those sites.
 */

namespace Core;

class TokenStore {

    /** Scopes, weakest first. */
    public const SCOPES = ['read', 'content', 'full'];

    /** Token prefix: makes a leaked token recognisable in a log or a chat. */
    private const PREFIX = 'apc_';

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
        $this->db->exec('CREATE TABLE IF NOT EXISTS api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT \'\',
            token_hash TEXT NOT NULL UNIQUE,
            scope TEXT NOT NULL DEFAULT \'full\',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME,
            revoked_at DATETIME
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash)');
        $done = true;
    }

    /**
     * Create a token. The plaintext value is returned once and never stored.
     *
     * @return array{id: int, token: string, name: string, scope: string}
     */
    public function create(string $name, string $scope = 'full'): array {
        $this->ensureTable();
        $scope = $this->normalizeScope($scope);
        $token = self::PREFIX . bin2hex(random_bytes(20));
        $this->db->query(
            'INSERT INTO api_tokens (name, token_hash, scope) VALUES (?, ?, ?)',
            [trim($name), hash('sha256', $token), $scope]
        );
        return [
            'id'    => (int) $this->db->getConnection()->lastInsertId(),
            'token' => $token,
            'name' => trim($name),
            'scope' => $scope,
        ];
    }

    /**
     * All tokens, newest first. No hashes: the panel never needs them.
     */
    public function all(): array {
        $this->ensureTable();
        $rows = $this->db->query(
            'SELECT id, name, scope, created_at, last_used_at, revoked_at
               FROM api_tokens ORDER BY id DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'scope'        => $this->normalizeScope((string) $row['scope']),
                'created_at'   => $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'revoked_at'   => $row['revoked_at'],
                'active'       => $row['revoked_at'] === null || $row['revoked_at'] === '',
            ];
        }
        return $out;
    }

    public function countActive(): int {
        $this->ensureTable();
        $row = $this->db->query(
            'SELECT COUNT(*) c FROM api_tokens WHERE revoked_at IS NULL'
        )->fetch();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Revoke a token: the row stays, so the audit trail keeps its meaning.
     */
    public function revoke(int $id): bool {
        $this->ensureTable();
        $this->db->query(
            'UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP
              WHERE id = ? AND revoked_at IS NULL',
            [$id]
        );
        return true;
    }

    /**
     * Resolve a presented token to {id, name, scope}, or null.
     */
    public function authenticate(string $token): ?array {
        $this->ensureTable();
        $row = $this->db->query(
            'SELECT id, name, scope FROM api_tokens
              WHERE token_hash = ? AND revoked_at IS NULL',
            [hash('sha256', $token)]
        )->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'    => (int) $row['id'],
            'name'  => (string) $row['name'],
            'scope' => $this->normalizeScope((string) $row['scope']),
        ];
    }

    /**
     * Remember that a token was used. Throttled: one write per token per
     * 5 minutes, so a busy agent does not turn every read into an UPDATE.
     */
    public function touch(int $id): void {
        try {
            $this->db->query(
                'UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP
                  WHERE id = ?
                    AND (last_used_at IS NULL
                         OR last_used_at < datetime(\'now\', \'-5 minutes\'))',
                [$id]
            );
        } catch (\Throwable $e) {
            // Usage statistics must never fail the request they describe.
        }
    }

    /** Rank of a scope, for "is this token allowed to do that?" comparisons. */
    public static function rank(string $scope): int {
        $i = array_search($scope, self::SCOPES, true);
        return $i === false ? 1 : $i + 1;
    }

    /** Unknown or empty scope becomes the safest one, never 'full'. */
    private function normalizeScope(string $scope): string {
        $scope = strtolower(trim($scope));
        return in_array($scope, self::SCOPES, true) ? $scope : 'read';
    }
}
