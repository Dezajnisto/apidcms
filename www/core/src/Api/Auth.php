<?php
/**
 * Api\Auth — Bearer token check and scope enforcement.
 *
 * Two kinds of credentials are accepted:
 *   1. a token from the admin screen (api_tokens): named, scoped, revocable,
 *      with a last-used date;
 *   2. the legacy system_settings.api_key: one key for everything, no name, no
 *      revocation. It keeps working with the full scope until it is cleared,
 *      so existing integrations do not break on upgrade.
 *
 * The API is enabled when at least one live token or the legacy key exists.
 * Otherwise every authenticated endpoint answers 403 api_disabled.
 */

namespace Api;

use Core\TokenStore;

class Auth {

    /** Scope of the current request, set by require(). */
    private const SCOPE_READ    = 'read';
    private const SCOPE_CONTENT = 'content';
    private const SCOPE_FULL    = 'full';

    /** @var \Core\Database */
    private $db;

    /** @var string|null кэшированный api_key */
    private $cachedKey = null;

    /** @var array|null resolved credential: {id, name, scope} */
    private $token = null;

    /** @var array|null last resolved credential, for the audit trail */
    private static $current = null;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /**
     * Authenticate the request and refuse it when the token is not allowed to
     * do what it asks for. Exits on failure.
     */
    public function require(): void {
        $provided = $this->bearer();

        $store = new TokenStore($this->db);
        $token = $provided !== '' ? $store->authenticate($provided) : null;

        if ($token !== null) {
            $this->token = $token;
            $store->touch($token['id']);
        } else {
            $this->requireLegacyKey($provided, $store);
        }

        self::$current = $this->token;
        header('X-apidcms-scope: ' . $this->scope());
        $this->enforceScope();
    }

    /** Scope of the authenticated credential. */
    public function scope(): string {
        return $this->token['scope'] ?? self::SCOPE_FULL;
    }

    /** Token id of the last authenticated request, null when unknown. */
    public static function currentTokenId(): ?int {
        return isset(self::$current['id']) ? (int) self::$current['id'] : null;
    }

    // ── internals ──────────────────────────────────────

    private function bearer(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strpos($header, 'Bearer ') === 0) {
            return substr($header, 7);
        }
        return '';
    }

    /**
     * Fallback to the legacy api_key. When neither a token nor the key is
     * configured, the API is disabled rather than "open".
     */
    private function requireLegacyKey(string $provided, TokenStore $store): void {
        $storedKey = $this->getStoredKey();

        if ($storedKey === null || $storedKey === '') {
            if ($store->countActive() === 0) {
                $this->jsonError(403, 'api_disabled',
                    'API is not configured: create a token in the admin (Settings → API)');
            }
            $this->jsonError(401, 'unauthorized', 'Invalid or missing API token');
        }

        if ($provided === '' || !hash_equals($storedKey, $provided)) {
            $this->jsonError(401, 'unauthorized', 'Invalid or missing API token');
        }

        $this->token = ['id' => null, 'name' => 'legacy', 'scope' => self::SCOPE_FULL];
    }

    /**
     * A read token must not write. The check is per request method and
     * resource: GET is always read, everything else is a write, and future
     * core-owned resources (templates) need the full scope.
     */
    private function enforceScope(): void {
        $required = $this->requiredScope();
        if (TokenStore::rank($this->scope()) >= TokenStore::rank($required)) {
            return;
        }
        $this->jsonError(
            403,
            'insufficient_scope',
            'This token is "' . $this->scope() . '" but the request needs "' . $required . '"'
                . '. Create a token with a wider scope in the admin (Settings → API).'
        );
    }

    private function requiredScope(): string {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Core-owned resources that are never handed to a read token.
        if (preg_match('#/api/v1/templates(/|$)#', $uri)) {
            return self::SCOPE_FULL;
        }
        // The audit log holds client IPs and diffs of other people's writes: it
        // is operator data, not site content, so a read token must not see it
        // (a published demo token would otherwise leak visitor IPs).
        if (preg_match('#/api/v1/audit(/|$)#', $uri)) {
            return self::SCOPE_CONTENT;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return self::SCOPE_READ;
        }
        // /mcp is POST for every call, including read-only tools: its own
        // per-tool check in Core\AgentTools decides what a token may do.
        if ($uri === '/mcp' || strpos($uri, '/mcp/') === 0) {
            return self::SCOPE_READ;
        }
        return self::SCOPE_CONTENT;
    }

    /**
     * Получить сохранённый ключ из system_settings.
     */
    private function getStoredKey(): ?string {
        if ($this->cachedKey !== null) {
            return $this->cachedKey;
        }
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                ['api_key']
            )->fetch();
            $this->cachedKey = $row ? $row['setting_value'] : null;
        } catch (\Exception $e) {
            $this->cachedKey = null;
        }
        return $this->cachedKey;
    }

    /**
     * Отдать JSON-ошибку и завершить скрипт.
     */
    private function jsonError(int $httpCode, string $code, string $message): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code'    => $code,
                'message' => $message,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
