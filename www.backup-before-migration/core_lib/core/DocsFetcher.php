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
    private $manifestTtl = 3600;
    private $docTtl = 21600;
    private $lang = 'ru';

    /**
     * @param string|null $cacheDir Path to cache directory
     */
    public function __construct($cacheDir = null)
    {
        if ($cacheDir === null) {
            $this->cacheDir = dirname(__DIR__) . '/admin/storage/docs_cache';
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
     * Fetch manifest.json from GitHub (with local cache)
     * @return array|null
     */
    public function getManifest()
    {
        $cacheFile = $this->cacheDir . '/manifest.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->manifestTtl) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data) {
                return $data;
            }
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'user_agent' => 'apidcms-docs-fetcher/1.0'],
        ]);
        $json = @file_get_contents($this->manifestUrl, false, $ctx);
        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if ($data) {
            file_put_contents($cacheFile, $json);
        }

        return $data;
    }

    /**
     * Fetch a single doc from GitHub (with local cache)
     *
     * @param string $url Full raw GitHub URL (may contain {lang})
     * @return string|null
     */
    public function getDocContent($url)
    {
        $url = $this->resolveUrl($url);
        $cacheKey = md5($url);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.md';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->docTtl) {
            return file_get_contents($cacheFile);
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'user_agent' => 'apidcms-docs-fetcher/1.0'],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) {
            // Try stale cache as fallback
            if (file_exists($cacheFile)) {
                return file_get_contents($cacheFile);
            }
            return null;
        }

        file_put_contents($cacheFile, $content);

        return $content;
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
            $sectionTitle = $sections[$section]['title'] ?? mb_convert_case($section, MB_CASE_TITLE, 'UTF-8');
            $kb .= "--- {$sectionTitle} ---\n\n";

            foreach ($docs as $doc) {
                $content = $this->getDocContent($doc['content_url']);
                if ($content === null) {
                    $failed++;
                    continue;
                }

                $content = $this->stripFrontmatter($content);
                $title = $doc['title'] ?? '';
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
