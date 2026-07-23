<?php
/**
 * ExternalPageLoader - fetch and cache data from external JSON API/URL
 *
 * Part of apidcms core. Used by FrontController for page_type=external pages.
 *
 * Features:
 *   - HTTP GET/POST to external URLs with custom headers
 *   - JSON path extraction (dot notation: "data.plugins")
 *   - File-based cache with configurable TTL
 *   - Query parameter forwarding (for live search/filter)
 */

namespace Core;

class ExternalPageLoader
{
    private string $url;
    private string $jsonPath;
    private int $cacheTtl;
    private string $method;
    private array $headers;
    private string $cacheDir;

    /**
     * @param array $config  Page config from navigation.page_config (JSON decoded)
     *                        Required: source_url
     *                        Optional: json_path, cache_ttl, method, headers
     * @param string|null $cacheDir  Override cache directory
     */
    public function __construct(array $config, ?string $cacheDir = null)
    {
        $this->url = $config['source_url'] ?? '';
        $this->jsonPath = $config['json_path'] ?? '';
        $this->cacheTtl = (int)($config['cache_ttl'] ?? 0);
        $this->method = strtoupper($config['method'] ?? 'GET');
        $this->headers = $config['headers'] ?? [];

        if ($cacheDir !== null) {
            $this->cacheDir = rtrim($cacheDir, '/');
        } else {
            $this->cacheDir = defined('PROJECT_ROOT')
                ? PROJECT_ROOT . '/admin/views/cache'
                : __DIR__ . '/../../admin/views/cache';
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Fetch data from external source, with optional cache.
     *
     * @param array $queryParams  Current request query parameters (forwarded in live mode)
     * @return array  ['items' => [...], 'raw' => [...], 'from_cache' => bool]
     * @throws \RuntimeException
     */
    public function fetch(array $queryParams = []): array
    {
        if ($this->cacheTtl > 0) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $url = $this->buildUrl($queryParams);
        $rawData = $this->httpRequest($url);

        $result = [
            'raw' => $rawData,
            'items' => $this->extractItems($rawData),
            'from_cache' => false,
        ];

        if ($this->cacheTtl > 0) {
            $this->saveToCache($result);
        }

        return $result;
    }

    /**
     * Build full URL, forwarding query params for live mode.
     */
    private function buildUrl(array $queryParams): string
    {
        $url = $this->url;
        $cleanParams = $queryParams;
        // Strip CMS-internal params
        unset($cleanParams['page'], $cleanParams['sort']);
        if (!empty($cleanParams)) {
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $separator . http_build_query($cleanParams);
        }
        return $url;
    }

    /**
     * Execute HTTP request via stream context.
     */
    private function httpRequest(string $url): array
    {
        $opts = [
            'http' => [
                'method' => $this->method,
                'header' => $this->buildHeaderLines(),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ];

        if (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = $this->headers['_body'] ?? '';
            $opts['http']['content'] = $body;
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException(
                'ExternalPageLoader: failed to fetch ' . $url .
                ($error ? ' - ' . $error['message'] : '')
            );
        }

        // Check HTTP status
        if (isset($http_response_header) && !empty($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $statusLine, $m)) {
                $statusCode = (int)$m[1];
                if ($statusCode >= 400) {
                    throw new \RuntimeException(
                        "ExternalPageLoader: HTTP {$statusCode} from {$url}"
                    );
                }
            }
        }

        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'ExternalPageLoader: invalid JSON from ' . $url .
                ' - ' . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Build HTTP headers string for stream context.
     */
    private function buildHeaderLines(): string
    {
        $lines = [
            'Accept: application/json',
            'User-Agent: apidcms/ExternalPageLoader',
        ];
        foreach ($this->headers as $key => $value) {
            if ($key === '_body') continue;
            $lines[] = "{$key}: {$value}";
        }
        return implode("\r\n", $lines);
    }

    /**
     * Extract target items using dot-notation json_path.
     * "data.plugins" -> $data['data']['plugins']
     * "" -> whole response
     */
    private function extractItems(array $data): array
    {
        if (empty($this->jsonPath)) {
            return $data;
        }

        $parts = explode('.', $this->jsonPath);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return [];
            }
            $current = $current[$part];
        }

        return is_array($current) ? $current : [$current];
    }

    /**
     * Check if valid cache exists.
     */
    private function cacheHit(): bool
    {
        $file = $this->cacheFilePath();
        if (!file_exists($file)) {
            return false;
        }
        $mtime = filemtime($file);
        return ($mtime + $this->cacheTtl) > time();
    }

    /**
     * Read from cache file.
     */
    private function getFromCache(): ?array
    {
        if (!$this->cacheHit()) {
            return null;
        }
        $content = @file_get_contents($this->cacheFilePath());
        if ($content === false) {
            return null;
        }
        $envelope = json_decode($content, true);
        if (!is_array($envelope) || !isset($envelope['ts'])) {
            return null;
        }
        return $envelope['data'] ?? null;
    }

    /**
     * Save to cache file.
     */
    private function saveToCache(array $data): void
    {
        $envelope = [
            'ts' => time(),
            'url' => $this->url,
            'data' => $data,
        ];
        @file_put_contents(
            $this->cacheFilePath(),
            json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Cache file path based on URL + json_path hash.
     */
    private function cacheFilePath(): string
    {
        $hash = md5($this->url . '_' . $this->jsonPath);
        return $this->cacheDir . '/external_' . $hash . '.json';
    }

    /**
     * Fetch raw text content from a URL (e.g. README.md for detail pages).
     * Returns null on 404, throws on other errors.
     *
     * @param string $url
     * @return string|null
     * @throws \RuntimeException
     */
    public function fetchContent(string $url): ?string
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Accept: text/plain,text/markdown,text/html\r\nUser-Agent: apidcms/ExternalPageLoader",
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException(
                'ExternalPageLoader::fetchContent failed: ' . $url .
                ($error ? ' - ' . $error['message'] : '')
            );
        }

        // 404 = no content, not an error
        if (isset($http_response_header) && !empty($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $statusLine, $m)) {
                $statusCode = (int)$m[1];
                if ($statusCode === 404) {
                    return null;
                }
                if ($statusCode >= 400) {
                    throw new \RuntimeException(
                        "ExternalPageLoader::fetchContent HTTP {$statusCode} from {$url}"
                    );
                }
            }
        }

        return $response;
    }

    /**
     * Clear this loader's cache.
     */
    public function clearCache(): void
    {
        $file = $this->cacheFilePath();
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clear ALL external page caches.
     *
     * @param string|null $cacheDir
     * @return int  Number of files removed
     */
    public static function clearAllCache(?string $cacheDir = null): int
    {
        $dir = $cacheDir ?? (defined('PROJECT_ROOT')
            ? PROJECT_ROOT . '/admin/views/cache'
            : __DIR__ . '/../../admin/views/cache');
        $count = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        foreach (glob($dir . '/external_*.json') as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }
}
