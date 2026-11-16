<?php
/**
 * Api\Router — диспетчер API-запросов.
 *
 * Маршрутизация: /api/v1/{resource}/{id}/{subresource}
 * Вызывается из router.php после проверки ADMIN_ACCESS.
 */

namespace Api;

class Router {

    /** @var \Core\Database */
    private $db;

    /** @var array распарсенный путь: [resource, id, subresource] */
    private $segments = [];

    public function __construct(\Core\Database $db) {
        $this->db = $db;
    }

    /**
     * Входная точка. Разбирает URI и вызывает нужный контроллер.
     */
    public function dispatch(): void {
        // Headers
        header_remove('X-Powered-By');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        // Never let a caller mistake this for a stable contract: v1 is preview.
        header('X-apidcms-api: v1-preview');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Извлечь путь: /api/v1/articles/slug → ['articles', 'slug']
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = trim($uri, '/');

        // Отрезаем 'api/v1'
        $path = '';
        if (strpos($uri, 'api/v1/') === 0) {
            $path = substr($uri, strlen('api/v1/'));
        } elseif (strpos($uri, 'api/v1') === 0) {
            $path = '';
        } else {
            $this->err(404, 'not_found', 'Путь должен начинаться с /api/v1/');
        }

        $parts = $path !== '' ? explode('/', $path) : [];
        $this->segments = array_pad($parts, 3, null);

        // Auth — everything except OPTIONS and the public discovery endpoints
        // (GET /api/v1 and GET /api/v1/system/*).
        $resource = $this->segments[0] ?? '';
        if ($resource === null) {
            $resource = '';
        }
        $needsAuth = !(
            ($resource === '' || $resource === null || $resource === 'system')
            && $_SERVER['REQUEST_METHOD'] === 'GET'
        );
        if ($needsAuth) {
            (new Auth($this->db))->require();
        }

        // Dispatch
        match ($resource) {
            'system'     => $this->systemInfo(),
            'schema'     => $this->schema(),
            'navigation' => $this->navigation(),
            'relations'  => $this->relations(),
            'docs'       => $this->docs(),
            'articles'   => $this->articles(),
            'tables'     => $this->tables(),
            'audit'      => $this->audit(),
            'templates'  => $this->notImplemented('templates', 'GET/PUT /api/v1/templates/{name} is planned, see the roadmap step 5'),
            'mcp'        => $this->notImplemented('mcp', '/mcp is served outside /api/v1 and is not implemented yet (roadmap step 4)'),
            ''           => $this->index(),
            default      => $this->err(404, 'not_found', "Ресурс '$resource' не найден"),
        };
    }

    // ── /api/v1 — discovery ────────────────────────────

    /**
     * Public index: what this CMS is, what the API can do, what is not ready.
     *
     * An agent that knows only the domain must be able to start here and walk
     * to /api/v1/schema in one more call.
     */
    private function index(): void {
        $version = $this->readVersion();
        $title = $this->setting('site_title');

        $this->ok([
            'cms'          => 'apidcms',
            'version'      => $version,
            'api_version'  => 'v1',
            'status'       => 'preview',
            'site'         => [
                'title'    => $title,
                'language' => $this->setting('site_language'),
            ],
            'generated_at' => date('c'),
            'capabilities' => $this->capabilities(),
            'notes'        => [
                'status "preview" means the contract may change; responses are never complete by default.',
                'start with GET /api/v1/schema for the whole project in one call.',
                'auth: Authorization: Bearer <token> from the admin (Settings → API). Scope read = GET only, content = writes to user tables, full = everything. The response header X-apidcms-scope tells which scope was used.',
                'relations are read with GET /api/v1/relations[/{table}/{id}] and written with POST/DELETE /api/v1/relations.',
                'documentation is served from the local cache: GET /api/v1/docs, GET /api/v1/docs/{slug}?lang=ru|en.',
                'POST /mcp speaks Model Context Protocol: the same data as agent tools (schema.get, records.*, relations.*, docs.*).',
                'endpoints marked "planned" answer 501 not_implemented, not 404.',
            ],
        ]);
    }

    /**
     * Machine-readable feature list: what works now, what is planned.
     * "planned" paths answer 501 so an agent does not look for a typo.
     */
    private function capabilities(): array {
        return [
            ['name' => 'system.info',     'methods' => ['GET'], 'path' => '/api/v1/system/info',   'status' => 'ready'],
            ['name' => 'schema',          'methods' => ['GET'], 'path' => '/api/v1/schema',        'status' => 'ready'],
            ['name' => 'navigation',      'methods' => ['GET'], 'path' => '/api/v1/navigation',    'status' => 'ready'],
            ['name' => 'relations',       'methods' => ['GET'], 'path' => '/api/v1/relations',     'status' => 'ready'],
            ['name' => 'relations.write', 'methods' => ['GET', 'POST', 'DELETE'], 'path' => '/api/v1/relations[/{table}/{id}]', 'status' => 'ready'],
            ['name' => 'tables.list',     'methods' => ['GET'], 'path' => '/api/v1/tables',        'status' => 'ready'],
            ['name' => 'tables.records',  'methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'path' => '/api/v1/tables/{table}[/{id}]', 'status' => 'ready'],
            ['name' => 'tables.schema',   'methods' => ['GET'], 'path' => '/api/v1/tables/{table}/schema', 'status' => 'ready'],
            ['name' => 'tables.json_schema', 'methods' => ['GET'], 'path' => '/api/v1/tables/{table}/json-schema', 'status' => 'ready'],
            ['name' => 'articles',        'methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'path' => '/api/v1/articles[/{slug}]', 'status' => 'ready'],
            ['name' => 'write.validation', 'methods' => [], 'path' => null,                   'status' => 'ready'],
            ['name' => 'write.dry_run',   'methods' => [], 'path' => null,                   'status' => 'ready'],
            ['name' => 'write.idempotency', 'methods' => [], 'path' => null,                 'status' => 'ready'],
            ['name' => 'audit',           'methods' => ['GET'], 'path' => '/api/v1/audit',       'status' => 'ready'],
            ['name' => 'docs',            'methods' => ['GET', 'POST'], 'path' => '/api/v1/docs[/{slug}]', 'status' => 'ready'],
            ['name' => 'assistant.tools', 'methods' => [], 'path' => null,                   'status' => 'ready'],
            ['name' => 'tokens.scopes',   'methods' => [], 'path' => null,                   'status' => 'ready'],
            ['name' => 'templates',       'methods' => ['GET', 'PUT'], 'path' => '/api/v1/templates/{name}', 'status' => 'planned'],
            ['name' => 'mcp',             'methods' => ['POST'], 'path' => '/mcp',                'status' => 'ready'],
        ];
    }

    // ── /api/v1/schema, /navigation, /relations ────────

    private function schema(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->err(405, 'method_not_allowed', 'Schema is read-only');
        }
        $inspector = new \Core\SchemaInspector($this->db);
        $schema = $inspector->projectSchema();
        $schema['version'] = $this->readVersion();
        $schema['site'] = [
            'title'    => $this->setting('site_title'),
            'language' => $this->setting('site_language'),
        ];
        $this->ok($schema);
    }

    private function navigation(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->err(405, 'method_not_allowed', 'Navigation is read-only');
        }
        $inspector = new \Core\SchemaInspector($this->db);
        $pages = $inspector->navigationGraph();
        $this->ok(['pages' => $pages, 'total' => count($pages)]);
    }

    private function relations(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $table = $this->segments[1];
        $rowId = $this->segments[2];

        if ($method === 'GET') {
            if ($table !== null && $rowId !== null) {
                (new RelationsController($this->db))->forRow($table, $rowId);
            }
            $inspector = new \Core\SchemaInspector($this->db);
            if ($table !== null && !\Core\SchemaInspector::isSystemTable($table) && !$this->db->tableExists($table)) {
                $this->err(404, 'not_found', "Таблица \"$table\" не существует");
            }
            $rows = $inspector->relations($table);
            $this->ok([
                'relations' => $rows,
                'total'     => count($rows),
                'notes'     => [
                    'Read links of one row: GET /api/v1/relations/{table}/{id}.',
                    'Write them: POST /api/v1/relations {source_table, source_id, relation_name, target_ids[]}; DELETE removes them.',
                ],
            ]);
        }

        if ($method === 'POST') {
            (new RelationsController($this->db))->add();
        }
        if ($method === 'DELETE') {
            (new RelationsController($this->db))->remove();
        }

        $this->err(405, 'method_not_allowed', 'Разрешены GET, POST и DELETE');
    }

    // ── /api/v1/docs ───────────────────────────────────

    /**
     * Documentation: local cache, network only for an explicit refresh.
     */
    private function docs(): void {
        $ctrl = new DocsController($this->db);
        $slug = $this->segments[1];
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            if ($slug === null || $slug === '') {
                $ctrl->list();
            }
            $ctrl->get($slug);
        }
        if ($method === 'POST' && $slug === 'refresh') {
            $ctrl->refresh();
        }
        $this->err(405, 'method_not_allowed', 'Разрешены GET и POST /api/v1/docs/refresh');
    }

    // ── /api/v1/audit ──────────────────────────────────

    private function audit(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->err(405, 'method_not_allowed', 'Audit log is read-only');
        }
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $table = isset($_GET['table']) ? trim((string) $_GET['table']) : null;
        $rowId = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $audit = new \Core\Audit($this->db);
        $entries = $audit->recent($limit, $table);
        if ($rowId !== null) {
            $entries = array_values(array_filter($entries, function ($entry) use ($rowId) {
                return (int) ($entry['row_id'] ?? 0) === $rowId;
            }));
        }
        $this->ok([
            'entries' => $entries,
            'total'   => $audit->count($table),
            'limit'   => max(1, min(500, $limit)),
            'table'   => $table,
        ]);
    }

    // ── /api/v1/system/info ────────────────────────────

    private function systemInfo(): void {
        $version = $this->readVersion();
        $title = $this->db->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = ?", ['site_title']
        )->fetch();

        $this->ok([
            'cms'        => 'apidcms',
            'version'    => $version,
            'status'     => 'preview',
            'php'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'site_title' => $title ? $title['setting_value'] : '',
            'time'       => date('c'),
        ]);
    }

    // ── /api/v1/articles[/:slug] ────────────────────────

    private function articles(): void {
        $ctrl = new ArticlesController($this->db);
        $slug = $this->segments[1];

        try {
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $slug ? $ctrl->get($slug) : $ctrl->list();
                    break;
                case 'POST':
                    $ctrl->create();
                    break;
                case 'PUT':
                    $slug ? $ctrl->update($slug) : $this->err(400, 'bad_request', 'Укажите slug в URL');
                    break;
                case 'DELETE':
                    $slug ? $ctrl->delete($slug) : $this->err(400, 'bad_request', 'Укажите slug в URL');
                    break;
                default:
                    $this->err(405, 'method_not_allowed', 'Метод не поддерживается');
            }
        } catch (\Throwable $e) {
            $this->err(500, 'internal_error', $e->getMessage());
        }
    }

    // ── /api/v1/tables[/:name[/:id[/schema]]] ───────────

    private function tables(): void {
        $ctrl = new TablesController($this->db);
        $name = $this->segments[1];
        $id   = $this->segments[2];

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if ($name) {
                    $ctrl->insert($name);
                } else {
                    $this->err(400, 'bad_request', 'Укажите имя таблицы в URL');
                }
                exit;
            }

            $readOnlyIds = ['schema', 'json-schema'];

            if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $name && $id && !in_array($id, $readOnlyIds, true)) {
                $ctrl->update($name, $id);
                exit;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $name && $id && !in_array($id, $readOnlyIds, true)) {
                $ctrl->delete($name, $id);
                exit;
            }

            if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'PUT', 'DELETE'])) {
                $this->err(405, 'method_not_allowed', 'Метод не поддерживается');
            }

            // GET: остались только GET-запросы (POST/PUT/DELETE уже вышли выше)
            if ($id === 'schema' && $name) {
                $ctrl->schema($name);
            } elseif ($id === 'json-schema' && $name) {
                $ctrl->jsonSchema($name);
            } elseif ($id && $name) {
                $ctrl->getById($name, $id);
            } elseif ($name) {
                $ctrl->get($name);
            } else {
                $ctrl->list();
            }
        } catch (\Throwable $e) {
            $this->err(500, 'internal_error', $e->getMessage());
        }
    }

    // ── helpers ────────────────────────────────────────

    private function readVersion(): string {
        $f = CORE_PATH . '/VERSION';
        if (file_exists($f)) {
            return trim(file_get_contents($f));
        }
        return '0.0.0';
    }

    /** Read one system_settings value, '' when unset. */
    private function setting(string $key): string {
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]
            )->fetch();
            return $row ? (string) $row['setting_value'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 501 for endpoints that exist in the roadmap but not in code yet.
     * Deliberately NOT 404: an agent must not go looking for a typo in a path
     * that will appear later.
     */
    private function notImplemented(string $what, string $hint = ''): void {
        http_response_code(501);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code'    => 'not_implemented',
                'message' => "$what is not implemented in this version",
                'hint'    => $hint,
            ],
            'status' => 'preview',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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