<?php
/**
 * Api\Auth — проверка API-ключа через Bearer-токен.
 *
 * Ключ хранится в system_settings -> api_key.
 * Если ключ не задан — API выключен (403).
 */

namespace Api;

class Auth {

    /** @var \Core\Database */
    private $db;

    /** @var string|null кэшированный api_key */
    private $cachedKey = null;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /**
     * Проверить заголовок Authorization: Bearer <key>.
     * Возвращает true если ключ верный.
     * Кидает 401/403 и завершает скрипт иначе.
     */
    public function require(): void {
        $storedKey = $this->getStoredKey();

        if ($storedKey === null || $storedKey === '') {
            $this->jsonError(403, 'api_disabled', 'API key is not set in system_settings');
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strpos($header, 'Bearer ') === 0) {
            $provided = substr($header, 7);
        } else {
            $provided = '';
        }

        if (!hash_equals($storedKey, $provided)) {
            $this->jsonError(401, 'unauthorized', 'Invalid or missing API key');
        }
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