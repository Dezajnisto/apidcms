<?php
/**
 * Api\ArticlesController — CRUD статей (таблица pages).
 *
 * Адаптивен к схеме: читает реальные колонки таблицы pages через PRAGMA.
 * Базовые поля (title, slug, status) — обязательны.
 * Остальные — опциональны, подхватываются из схемы.
 */

namespace Api;

class ArticlesController {

    /** @var \Core\Database */
    private $db;

    /** @var array|null кэш имён колонок pages */
    private $columns = null;

    /** Базовые поля, которые ОБЯЗАТЕЛЬНО есть в каждой pages (init_system_tables гарантирует) */
    private const CORE_FIELDS = ['id', 'title', 'slug', 'content', 'status', 'created_at', 'updated_at'];

    /** Поля, которые разрешено создавать/обновлять через API (если есть в схеме) */
    private const WRITABLE_FIELDS = ['title', 'slug', 'content', 'meta_title', 'meta_description',
        'featured_image', 'template', 'status'];

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /**
     * GET /api/v1/articles — список статей.
     */
    public function list(): void {
        $limit  = $this->intParam('limit', 20, 1, 100);
        $offset = $this->intParam('offset', 0, 0, PHP_INT_MAX);
        $status = $_GET['status'] ?? null;
        $order  = strtoupper($_GET['order'] ?? 'desc');
        $order  = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        $where = '';
        $params = [];
        if ($status !== null && $status !== '') {
            $where = ' WHERE status = ?';
            $params[] = $status;
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) c FROM pages" . $where, $params
        )->fetch()['c'];

        $selectCols = $this->selectColumns();
        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->query(
            "SELECT " . implode(', ', array_map([$this, 'q'], $selectCols)) .
            " FROM pages" . $where . " ORDER BY created_at " . $order . " LIMIT ? OFFSET ?",
            $params
        )->fetchAll();

        $this->ok(['articles' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    /**
     * GET /api/v1/articles/:slug — одна статья по slug.
     */
    public function get(string $slug): void {
        $selectCols = $this->selectColumns();
        $row = $this->db->query(
            "SELECT " . implode(', ', array_map([$this, 'q'], $selectCols)) .
            " FROM pages WHERE slug = ?", [$slug]
        )->fetch();

        if (!$row) {
            $this->notFound('Статья со slug "' . $slug . '" не найдена');
        }
        $this->ok(['article' => $row]);
    }

    /**
     * POST /api/v1/articles — создать статью.
     */
    public function create(): void {
        $body = $this->jsonBody();

        if (empty($body['title']) || empty($body['slug'])) {
            $this->err(422, 'validation_error', 'Обязательные поля: title, slug');
        }

        $title = trim($body['title']);
        $slug  = $this->normalizeSlug($body['slug']);

        $exists = $this->db->query("SELECT id FROM pages WHERE slug = ?", [$slug])->fetch();
        if ($exists) {
            $this->err(409, 'conflict', 'Статья со slug "' . $slug . '" уже существует');
        }

        // Собрать только те поля, что есть в схеме И разрешены к записи
        $schemaCols = $this->getColumns();
        $data = [];
        foreach (self::WRITABLE_FIELDS as $f) {
            if (($f === 'title' || $f === 'slug')) {
                continue; // обрабатываются отдельно
            }
            if (array_key_exists($f, $body) && in_array($f, $schemaCols, true)) {
                $data[$f] = $body[$f];
            }
        }

        $data['title'] = $title;
        $data['slug']  = $slug;

        $cols  = array_keys($data);
        $ph    = array_fill(0, count($cols), '?');
        $values = array_values($data);

        $this->db->query(
            "INSERT INTO pages (" . implode(', ', array_map([$this, 'q'], $cols)) . ")
             VALUES (" . implode(', ', $ph) . ")",
            $values
        );

        $id = (int) $this->db->query("SELECT last_insert_rowid() id")->fetch()['id'];
        http_response_code(201);
        $this->ok(['id' => $id, 'slug' => $slug], 201);
    }

    /**
     * PUT /api/v1/articles/:slug — частичное обновление по slug.
     */
    public function update(string $slug): void {
        $body = $this->jsonBody();

        $target = $this->db->query("SELECT id FROM pages WHERE slug = ?", [$slug])->fetch();
        if (!$target) {
            $this->notFound('Статья со slug "' . $slug . '" не найдена');
        }

        // Собрать данные
        $schemaCols = $this->getColumns();
        $data = [];
        foreach (self::WRITABLE_FIELDS as $f) {
            if ($f === $body['slug'] ?? null) {
                $data['slug'] = $this->normalizeSlug($body['slug']);
            } elseif ($f === 'title' && !empty($body['title'])) {
                $data['title'] = trim($body['title']);
            } elseif (array_key_exists($f, $body) && in_array($f, $schemaCols, true)) {
                $data[$f] = $body[$f];
            }
        }

        if (empty($data)) {
            $this->err(422, 'validation_error', 'Нет полей для обновления');
        }

        // Проверка уникальности при смене slug
        if (isset($data['slug']) && $data['slug'] !== $slug) {
            $dup = $this->db->query(
                "SELECT id FROM pages WHERE slug = ? AND id != ?",
                [$data['slug'], $target['id']]
            )->fetch();
            if ($dup) {
                $this->err(409, 'conflict', 'Статья со slug "' . $data['slug'] . '" уже существует');
            }
        }

        $setParts = [];
        $values   = [];
        foreach ($data as $col => $val) {
            $setParts[] = $this->q($col) . ' = ?';
            $values[]   = $val;
        }
        $values[] = $target['id'];

        $this->db->query(
            "UPDATE pages SET " . implode(', ', $setParts) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            $values
        );

        $this->ok(['id' => $target['id'], 'slug' => $data['slug'] ?? $slug, 'updated' => true]);
    }

    /**
     * DELETE /api/v1/articles/:slug — удалить статью.
     */
    public function delete(string $slug): void {
        $target = $this->db->query("SELECT id FROM pages WHERE slug = ?", [$slug])->fetch();
        if (!$target) {
            $this->notFound('Статья со slug "' . $slug . '" не найдена');
        }
        $this->db->query("DELETE FROM pages WHERE id = ?", [$target['id']]);
        http_response_code(204);
        exit;
    }

    // ── schema helpers ───────────────────────────────────

    /**
     * Получить все имена колонок таблицы pages (из PRAGMA, с кэшем).
     */
    private function getColumns(): array {
        if ($this->columns === null) {
            $rows = $this->db->getTableStructure('pages');
            $this->columns = array_column($rows, 'name');
        }
        return $this->columns;
    }

    /**
     * Список колонок для SELECT: все реальные колонки pages.
     */
    private function selectColumns(): array {
        return $this->getColumns();
    }

    // ── helpers ──────────────────────────────────────────

    private function normalizeSlug(string $slug): string {
        $slug = trim($slug);
        $slug = preg_replace('/[^a-zA-Z0-9а-яА-Я_-]+/u', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'page-' . time();
    }

    private function q(string $s): string {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    private function intParam(string $key, int $default, int $min, int $max): int {
        $v = isset($_GET[$key]) ? (int) $_GET[$key] : $default;
        return max($min, min($max, $v));
    }

    private function jsonBody(): array {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->err(400, 'bad_request', 'Некорректный JSON в теле запроса');
        }
        return $data;
    }

    private function ok(array $payload, int $http = 200): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function notFound(string $msg): void {
        $this->err(404, 'not_found', $msg);
    }

    private function err(int $http, string $code, string $msg): void {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => $code, 'message' => $msg]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}