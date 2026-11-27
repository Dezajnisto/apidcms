<?php
/**
 * Core\DocIndex — the documentation as data, not as a prompt dump.
 *
 * The old assistant loaded every doc and pasted it into the prompt (~74k
 * tokens per message): it paid for the whole library on every question. That
 * path is gone — DocsFetcher::getKnowledgeBase() and the "docs" assistant mode
 * were removed in 2.8.2, so nothing sends the library any more.
 *
 * This class exposes the library as an index: a cheap table of contents, plus
 * one document on demand. MCP resources and the assistant's tools both talk to
 * it, so a question costs one or two documents instead of forty.
 */

namespace Core;

class DocIndex {

    /** @var DocsFetcher */
    private $fetcher;

    /** @var array|null cached manifest */
    private $manifest;

    /** @var string current documentation language */
    private $lang = 'ru';

    public function __construct(?DocsFetcher $fetcher = null) {
        $this->fetcher = $fetcher ?: new DocsFetcher();
    }

    /**
     * Table of contents: one entry per document, no content.
     *
     * @return array<int, array{slug, title, section, section_title, description}>
     */
    public function toc(): array {
        $manifest = $this->manifest();
        if (empty($manifest['docs'])) {
            return [];
        }
        $sections = $manifest['sections'] ?? [];
        $out = [];
        foreach ($manifest['docs'] as $doc) {
            $section = $doc['section'] ?? 'other';
            $out[] = [
                'slug'          => $doc['slug'] ?? '',
                'title'         => $this->locale($doc['title'] ?? ''),
                'section'       => $section,
                'section_title' => $this->locale($sections[$section]['title'] ?? $section),
                'description'   => $this->locale($doc['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * One document, markdown with front-matter stripped.
     */
    public function get(string $slug): ?array {
        foreach ($this->manifest()['docs'] ?? [] as $doc) {
            if (($doc['slug'] ?? '') !== $slug) {
                continue;
            }
            $content = $this->fetcher->getDocContent($doc['content_url'] ?? '');
            if ($content === null) {
                return null;
            }
            return [
                'slug'          => $slug,
                'title'         => $this->locale($doc['title'] ?? ''),
                'section'       => $doc['section'] ?? 'other',
                'description'   => $this->locale($doc['description'] ?? ''),
                'content'       => $this->stripFrontmatter($content),
            ];
        }
        return null;
    }

    /**
     * Search by title, description and body. Bodies are only fetched for docs
     * that match on title/description, so a search stays cheap.
     *
     * @return array<int, array{slug, title, section, description, matches}>
     */
    public function search(string $query, int $limit = 5): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $terms = $this->terms($query);
        if (!$terms) {
            return [];
        }

        $results = [];
        foreach ($this->toc() as $doc) {
            $title = $this->normalize($doc['title']);
            $description = $this->normalize($doc['description']);
            $section = $this->normalize($doc['section_title'] . ' ' . $doc['section']);

            $score = 0;
            foreach ($terms as $term) {
                if (mb_strpos($title, $term) !== false) {
                    $score += 4;
                } elseif (mb_strpos($description, $term) !== false) {
                    $score += 2;
                } elseif (mb_strpos($section, $term) !== false) {
                    $score += 1;
                }
            }
            if ($score === 0) {
                continue;
            }
            $results[] = $doc + ['score' => $score, 'matches' => 'title/description'];
        }

        // More matched terms beats more partial hits on one term.
        usort($results, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['slug'], $b['slug']));
        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * Search inside document bodies too — slower (fetches docs), used when the
     * title search found nothing.
     */
    public function searchDeep(string $query, int $limit = 5): array {
        $shallow = $this->search($query, $limit);
        if ($shallow) {
            return $shallow;
        }
        $terms = $this->terms($query);
        if (!$terms) {
            return [];
        }
        $results = [];
        foreach ($this->toc() as $doc) {
            $body = $this->get($doc['slug']);
            if (!$body) {
                continue;
            }
            $text = $this->normalize($body['content']);
            $hits = 0;
            foreach ($terms as $term) {
                $hits += substr_count($text, $term);
            }
            if ($hits > 0) {
                $results[] = $doc + ['score' => $hits, 'matches' => 'body'];
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * Query terms: lowercase, punctuation and separators flattened so that
     * "page_config", "page-config" and "page config" are the same word.
     *
     * @return string[]
     */
    private function terms(string $query): array {
        $normalized = $this->normalize($query);
        $stop = ['как', 'что', 'это', 'для', 'или', 'the', 'and', 'for', 'with', 'how', 'what'];
        $terms = array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            fn($t) => mb_strlen($t) > 2 && !in_array($t, $stop, true)
        );
        return array_values(array_unique($terms));
    }

    /** Lowercase and flatten separators so word forms of the same name match. */
    private function normalize(string $text): string {
        $text = mb_strtolower($text);
        return (string) preg_replace('/[_\-\/\\.]+/u', ' ', $text);
    }

    /**
     * Language of the documentation (ru|en), follows the site language.
     */
    public function setLang(string $lang): void {
        $this->lang = $lang;
        $this->fetcher->setLang($lang);
        $this->manifest = null;
    }

    /**
     * State of the local documentation cache: is it there, when was it
     * refreshed, does it need a refresh.
     *
     * @return array{cached: bool, updated_at: ?int, stale: bool, docs: int, lang: string}
     */
    public function status(): array {
        $status = $this->fetcher->manifestStatus();
        $status['docs'] = count($this->toc());
        $status['lang'] = $this->lang;
        return $status;
    }

    /**
     * Refresh the cache from GitHub — the only operation that needs the
     * network. Both languages are refreshed: ?lang= must not depend on who
     * looked at the site last.
     *
     * The total time is budgeted so that an unreachable network cannot turn
     * the call into a fatal timeout; the caller may simply call it again.
     *
     * @return array{manifest: bool, refreshed: int, skipped: int, failed: int, failed_urls: string[], stopped_early: bool}
     */
    public function refresh(bool $force = false, int $budgetSeconds = 20): array {
        $started = microtime(true);
        $original = $this->lang;
        $total = ['manifest' => false, 'refreshed' => 0, 'skipped' => 0, 'failed' => 0,
                  'failed_urls' => [], 'stopped_early' => false];
        foreach (['ru', 'en'] as $lang) {
            $left = (int) max(1, $budgetSeconds - (microtime(true) - $started));
            $this->setLang($lang);
            $stats = $this->fetcher->refreshAll($force, $left);
            $total['manifest'] = $total['manifest'] || $stats['manifest'];
            $total['refreshed'] += $stats['refreshed'];
            $total['skipped'] += $stats['skipped'];
            $total['failed'] += $stats['failed'];
            $total['failed_urls'] = array_merge($total['failed_urls'], $stats['failed_urls'] ?? []);
            if (!empty($stats['stopped_early'])) {
                $total['stopped_early'] = true;
                break;
            }
        }
        $this->setLang($original);
        return $total;
    }

    private function manifest(): array {
        if ($this->manifest === null) {
            $this->manifest = $this->fetcher->getManifest() ?: [];
        }
        return $this->manifest;
    }

    private function locale($value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            // Manifest titles and descriptions are localised objects: honour
            // the requested language, fall back to ru, then to the first key.
            return (string) ($value[$this->lang] ?? $value['ru'] ?? reset($value) ?? '');
        }
        return '';
    }

    private function stripFrontmatter(string $content): string {
        if (preg_match('/^---\s*\n.*?\n---\s*\n/s', $content, $m)) {
            return ltrim(substr($content, strlen($m[0])));
        }
        return $content;
    }
}
