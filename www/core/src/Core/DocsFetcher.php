<?php
/**
 * DocsFetcher - Fetches and caches documentation from GitHub
 *
 * Loads apidcms-docs manifest and individual doc files from GitHub,
 * caches them locally, and provides a formatted knowledge base
 * for the AI assistant.
 *
 * Supports multi-language via {lang} placeholder in URLs.
 *
 * @package Core
 */

namespace Core;

class DocsFetcher
{
    private $cacheDir;
    private $repoBase = 'https://raw.githubusercontent.com/Dezajnisto/apidcms-docs/master';
    private $manifestUrl;
    /**
     * Cache is the source of truth; the network is only used to fill it and to
     * refresh it on purpose (refreshManifest/refreshDoc/refreshAll).
     *
     * Before 2.6.0 every read hit GitHub when the cached copy was older than
     * the TTL: on a server where GitHub is unreachable (RPKI) that meant every
     * request waited 10 s for a timeout before falling back to the same file.
     * Docs must not depend on the network to be readable.
     */
    private $manifestTtl = 3600;
    private $docTtl = 21600;
    private $lang = 'ru';

    /**
     * @param string|null $cacheDir Path to cache directory
     */
    public function __construct($cacheDir = null)
    {
        if ($cacheDir === null) {
            // Site data must live outside the core: STORAGE_PATH/cache/docs.
            // Historically this was core/admin/storage/docs_cache, which made
            // the core write into itself and survive core updates as junk.
            $storage = defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 3) . '/storage';
            $this->cacheDir = $storage . '/cache/docs';
        } else {
            $this->cacheDir = rtrim($cacheDir, '/');
        }
        $this->manifestUrl = $this->repoBase . '/manifest.json';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Resolve a localised value (plain string or {"ru":"...", "en":"..."} object).
     * Backward-compatible: plain strings pass through unchanged.
     *
     * @param string|array $value
     * @return string
     */
    private function localeValue($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return $value[$this->lang] ?? $value['ru'] ?? reset($value) ?? '';
        }
        return '';
    }

    /**
     * Set current language for {lang} placeholder resolution.
     *
     * @param string $lang e.g. 'ru', 'en'
     */
    public function setLang($lang)
    {
        $this->lang = $lang;
    }

    /**
     * Resolve {lang} placeholder in a URL.
     *
     * @param string $url
     * @return string
     */
    private function resolveUrl($url)
    {
        return str_replace('{lang}', $this->lang, $url);
    }

    /**
     * Manifest as data: the cached copy when there is one, the network only
     * when the cache is empty (fresh install) or explicitly refreshed.
     * @return array|null
     */
    public function getManifest()
    {
        $cacheFile = $this->cacheDir . '/manifest.json';

        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data) {
                return $data;
            }
        }

        return $this->refreshManifest();
    }

    /**
     * Download the manifest, ignoring the cache. Returns the parsed data or
     * null when the network is unreachable — the old cache stays in place.
     *
     * @return array|null
     */
    public function refreshManifest()
    {
        $cacheFile = $this->cacheDir . '/manifest.json';
        $json = $this->download($this->manifestUrl);
        if ($json === null || $json === '') {
            $cached = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;
            return $cached ?: null;
        }

        $data = json_decode($json, true);
        if ($data) {
            file_put_contents($cacheFile, $json);
            return $data;
        }

        return null;
    }

    /**
     * Freshness of the cached manifest, for "is the documentation stale?"
     * answers without touching the network.
     *
     * @return array{cached: bool, updated_at: ?int, stale: bool}
     */
    public function manifestStatus()
    {
        $cacheFile = $this->cacheDir . '/manifest.json';
        if (!file_exists($cacheFile)) {
            return ['cached' => false, 'updated_at' => null, 'stale' => true];
        }
        $mtime = (int) filemtime($cacheFile);
        return [
            'cached'     => true,
            'updated_at' => $mtime,
            'stale'      => (time() - $mtime) >= $this->manifestTtl,
        ];
    }

    /**
     * One doc: cached copy first, network only when nothing is cached yet.
     *
     * @param string $url Full raw GitHub URL (may contain {lang})
     * @return string|null
     */
    public function getDocContent($url)
    {
        $cacheFile = $this->cacheFile($url);
        if (file_exists($cacheFile)) {
            return file_get_contents($cacheFile);
        }

        return $this->refreshDoc($url);
    }

    /**
     * Download one doc, ignoring the cache. The old cached copy is kept when
     * the download fails, so a refresh never blanks a working document.
     *
     * @param string $url Full raw GitHub URL (may contain {lang})
     * @return string|null
     */
    public function refreshDoc($url)
    {
        $cacheFile = $this->cacheFile($url);
        $content = $this->download($this->resolveUrl($url));
        if ($content === null) {
            return file_exists($cacheFile) ? file_get_contents($cacheFile) : null;
        }

        file_put_contents($cacheFile, $content);
        return $content;
    }

    /**
     * Refresh manifest and every document. With $force false only entries that
     * are older than their TTL are re-downloaded.
     *
     * $budgetSeconds keeps the call inside a web request: when the network is
     * unreachable every single download waits for its timeout, and 80 of them
     * would kill the request with a fatal timeout. The refresh stops early and
     * says so; calling it again continues from the same place.
     *
     * @return array{manifest: bool, refreshed: int, skipped: int, failed: int, failed_urls: string[], stopped_early: bool}
     */
    public function refreshAll($force = false, $budgetSeconds = 20)
    {
        $started = microtime(true);
        $manifest = $this->refreshManifest();
        $stats = ['manifest' => $manifest !== null, 'refreshed' => 0, 'skipped' => 0, 'failed' => 0,
                  'failed_urls' => [], 'stopped_early' => false];
        if (!$manifest || empty($manifest['docs'])) {
            return $stats;
        }

        foreach ($manifest['docs'] as $doc) {
            if ((microtime(true) - $started) > $budgetSeconds) {
                $stats['stopped_early'] = true;
                break;
            }
            $url = $doc['content_url'] ?? '';
            if ($url === '') {
                continue;
            }
            $cacheFile = $this->cacheFile($url);
            if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->docTtl) {
                $stats['skipped']++;
                continue;
            }
            $content = $this->download($this->resolveUrl($url));
            if ($content === null) {
                $stats['failed']++;
                $stats['failed_urls'][] = $this->resolveUrl($url);
                continue;
            }
            file_put_contents($cacheFile, $content);
            $stats['refreshed']++;
        }

        return $stats;
    }

    /** Cache file of one document: md5 of the resolved-for-language URL. */
    private function cacheFile($url)
    {
        return $this->cacheDir . '/' . md5($this->resolveUrl($url)) . '.md';
    }

    /** One HTTP GET with a short timeout; null means "no answer". */
    private function download($url)
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'user_agent' => 'apidcms-docs-fetcher/1.0'],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /**
     * Strip YAML front-matter from markdown
     *
     * @param string $content
     * @return string
     */
    private function stripFrontmatter($content)
    {
        if (preg_match('/^---\s*\n.*?\n---\s*\n/s', $content, $m)) {
            return substr($content, strlen($m[0]));
        }

        return $content;
    }

    /**
     * Get full knowledge base: all docs formatted for AI context
     *
     * Loads every doc listed in the manifest, grouped by section.
     * Falls back gracefully if GitHub is unreachable.
     *
     * @return string Formatted documentation text
     */
    public function getKnowledgeBase()
    {
        $manifest = $this->getManifest();
        if (!$manifest || empty($manifest['docs'])) {
            return "[Documentation unavailable - could not fetch manifest]\n";
        }

        $sections = $manifest['sections'] ?? [];
        $bySection = [];
        $loaded = 0;
        $failed = 0;

        foreach ($manifest['docs'] as $doc) {
            $section = $doc['section'] ?? 'other';
            $bySection[$section][] = $doc;
        }

        $kb = "=== APIDCMS DOCUMENTATION ===\n";
        $kb .= "Use this documentation as your primary knowledge source for apidcms questions.\n\n";

        foreach ($bySection as $section => $docs) {
            // Заголовки в манифесте локализованы ({"ru": ..., "en": ...}): без
            // localeValue() PHP превращает массив в строку и печатает
            // «Array to string conversion» — то есть ломает JSON-ответ.
            $sectionTitle = $this->localeValue($sections[$section]['title'] ?? $section);
            $kb .= "--- {$sectionTitle} ---\n\n";

            foreach ($docs as $doc) {
                $content = $this->getDocContent($doc['content_url']);
                if ($content === null) {
                    $failed++;
                    continue;
                }

                $content = $this->stripFrontmatter($content);
                $title = $this->localeValue($doc['title'] ?? '');
                $kb .= "## {$title}\n\n";
                $kb .= trim($content) . "\n\n";
                $loaded++;
            }
        }

        if ($failed > 0) {
            $kb .= "[Loaded {$loaded} docs, {$failed} failed]\n\n";
        }

        return $kb;
    }

    /**
     * Invalidate all cached docs (force re-fetch on next request)
     *
     * @return int Number of files removed
     */
    public function invalidateCache()
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep') {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }
}
