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
        // Заголовки
        header_remove('X-Powered-By');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');

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

        // Auth — на всё, кроме OPTIONS и GET system/info
        $resource = $this->segments[0];
        $needsAuth = !($resource === 'system' && $_SERVER['REQUEST_METHOD'] === 'GET');
        if ($needsAuth) {
            (new Auth($this->db))->require();
        }

        // Диспетчеризация
        match ($resource) {
            'system'   => $this->systemInfo(),
            'articles' => $this->articles(),
            'tables'   => $this->tables(),
            ''         => $this->systemInfo(),
            default    => $this->err(404, 'not_found', "Ресурс '$resource' не найден"),
        };
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

            if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $name && $id && $id !== 'schema') {
                $ctrl->update($name, $id);
                exit;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $name && $id && $id !== 'schema') {
                $ctrl->delete($name, $id);
                exit;
            }

            if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'PUT', 'DELETE'])) {
                $this->err(405, 'method_not_allowed', 'Метод не поддерживается');
            }

            // GET: остались только GET-запросы (POST/PUT/DELETE уже вышли выше)
            if ($id === 'schema' && $name) {
                $ctrl->schema($name);
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