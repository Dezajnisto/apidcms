<?php
/**
 * Api\DocsController — the documentation as an API resource.
 *
 * DocsFetcher used to spend its life on raw.githubusercontent.com: on a server
 * where GitHub is unreachable (RPKI) every assistant question waited for a
 * timeout, and an external agent had no way to find the docs at all.
 *
 * Now the API serves the local cache:
 *
 *   GET  /api/v1/docs            table of contents (+ ?q= full-text search)
 *   GET  /api/v1/docs/{slug}     one article as Markdown (?lang=ru|en)
 *   POST /api/v1/docs/refresh    re-download the cache from GitHub (the only
 *                                endpoint that needs the network)
 *
 * The same documents are MCP resources (apidcms://docs/{slug}), so an agent
 * reading either transport sees identical text.
 */

namespace Api;

use Core\DocIndex;

class DocsController {

    /** @var \Core\Database */
    private $db;

    /** @var DocIndex|null */
    private $index;

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /** GET /api/v1/docs[?q=...&limit=...&lang=ru|en] */
    public function list(): void {
        $index = $this->index();

        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query !== '') {
            $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
            $results = $index->searchDeep($query, $limit);
            $this->ok([
                'query'   => $query,
                'results' => $results,
                'total'   => count($results),
                'status'  => $index->status(),
            ]);
        }

        $docs = $index->toc();
        $this->ok([
            'docs'   => $docs,
            'total'  => count($docs),
            'status' => $index->status(),
            'lang'   => $this->lang(),
            'usage'  => [
                'GET /api/v1/docs/{slug}?lang=ru|en returns the article as Markdown.',
                'GET /api/v1/docs?q=words searches titles, descriptions and bodies.',
                'POST /api/v1/docs/refresh re-downloads the cache; reads never touch the network.',
                'The same articles are MCP resources: apidcms://docs/{slug}.',
            ],
        ]);
    }

    /** GET /api/v1/docs/{slug}[?lang=ru|en] */
    public function get(string $slug): void {
        $index = $this->index();
        $doc = $index->get($slug);
        if ($doc === null) {
            $this->err(404, 'not_found',
                "Документация \"$slug\" не найдена. Список — GET /api/v1/docs");
        }
        $doc['lang'] = $this->lang();
        $doc['source'] = 'local-cache';
        $this->ok($doc);
    }

    /** POST /api/v1/docs/refresh[?force=1] */
    public function refresh(): void {
        $force = in_array(strtolower((string) ($_GET['force'] ?? '0')), ['1', 'true', 'yes'], true);
        // A refresh downloads up to two languages worth of articles. Keep the
        // request inside a sane wall clock even when the network hangs.
        @set_time_limit(60);
        $index = $this->index();
        $stats = $index->refresh($force, 25);
        $this->ok([
            'refreshed' => $stats,
            'status'    => $index->status(),
            'hint'      => $stats['stopped_early']
                ? 'Time budget reached, call refresh again to continue. Reads keep working from the cache meanwhile.'
                : 'Without the network the previous cache stays in place; status.stale tells whether it is old.',
        ]);
    }

    // ── helpers ────────────────────────────────────────

    private function index(): DocIndex {
        if ($this->index === null) {
            $this->index = new DocIndex();
            $this->index->setLang($this->lang());
        }
        return $this->index;
    }

    /** Documentation language: ?lang= wins, then the site language. */
    private function lang(): string {
        $lang = strtolower(trim((string) ($_GET['lang'] ?? '')));
        if (in_array($lang, ['ru', 'en'], true)) {
            return $lang;
        }
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_language'"
            )->fetch();
            $siteLang = strtolower(trim((string) ($row['setting_value'] ?? '')));
            if (in_array($siteLang, ['ru', 'en'], true)) {
                return $siteLang;
            }
        } catch (\Throwable $e) {
            // No settings table yet — ru is the safe default.
        }
        return 'ru';
    }

    private function ok(array $payload, int $http = 200): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function err(int $http, string $code, string $msg): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => $code, 'message' => $msg]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
