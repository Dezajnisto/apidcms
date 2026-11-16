<?php
/**
 * Api\Mcp — Model Context Protocol server on top of Core\AgentTools.
 *
 * MCP is how an external agent ([CC], Cursor, any client) discovers and calls
 * tools. This is not a second API: the tool catalogue and the execution live in
 * Core\AgentTools, which the built-in assistant uses too. One implementation,
 * three transports.
 *
 * Transport: Streamable HTTP, JSON-RPC 2.0, POST /mcp, same Bearer token as the
 * API. Stateless — no session id, nothing kept between calls.
 *
 * Methods: initialize, ping, tools/list, tools/call, resources/list,
 * resources/read, prompts/list. Notifications are accepted and ignored.
 */

namespace Api;

use Core\AgentTools;
use Core\DocIndex;

class Mcp {

    /** Protocol revisions this server understands; the newest is preferred. */
    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    private const PROTOCOL_VERSION = '2025-06-18';

    /** @var \Core\Database */
    private $db;

    /** @var AgentTools */
    private $tools;

    /** @var string scope of the authenticated token */
    private $scope = 'full';

    public function __construct(\Core\Database $db) {
        $this->db = $db;
        $this->tools = new AgentTools($db);
    }

    // ── entry point ────────────────────────────────────

    public function run(): void {
        header_remove('X-Powered-By');
        header('X-apidcms-api: v1-preview');
        header('X-apidcms-mcp: 1');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // No server-initiated stream: this implementation is stateless.
            http_response_code(405);
            header('Allow: POST, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'jsonrpc' => '2.0',
                'error'   => ['code' => -32000, 'message' => 'This MCP server is stateless: use POST'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST, OPTIONS');
            exit;
        }

        $auth = new Auth($this->db);
        $auth->require();
        $this->scope = $auth->scope();

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->write($this->error(null, -32700, 'Parse error'));
            return;
        }

        // Batch support (array of requests). Notifications inside a batch
        // produce no response entries.
        if (array_is_list($payload) && $payload !== []) {
            $responses = [];
            foreach ($payload as $request) {
                $response = $this->handle(is_array($request) ? $request : []);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }
            $this->write($responses === [] ? null : $responses);
            return;
        }

        $this->write($this->handle($payload));
    }

    // ── JSON-RPC ───────────────────────────────────────

    private function handle(array $request): ?array {
        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $isNotification = !array_key_exists('id', $request);

        // Notifications: initialized, cancelled, progress — nothing to answer.
        if ($isNotification) {
            return null;
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->ok($id, $this->initialize($params));
                case 'ping':
                    return $this->ok($id, new \stdClass());
                case 'tools/list':
                    return $this->ok($id, ['tools' => $this->tools->definitions()]);
                case 'tools/call':
                    return $this->ok($id, $this->callTool($params));
                case 'resources/list':
                    return $this->ok($id, ['resources' => $this->resourceList()]);
                case 'resources/read':
                    return $this->ok($id, $this->readResource($params));
                case 'prompts/list':
                    return $this->ok($id, ['prompts' => []]);
                default:
                    return $this->error($id, -32601, "Method not found: $method");
            }
        } catch (\Throwable $e) {
            return $this->error($id, -32603, $e->getMessage());
        }
    }

    private function initialize(array $params): array {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true)
            ? $requested
            : self::PROTOCOL_VERSION;

        return [
            'protocolVersion' => $version,
            'capabilities'    => [
                'tools'     => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo'      => [
                'name'    => 'apidcms',
                'title'   => 'apidcms — site data as agent tools',
                'version' => $this->coreVersion(),
            ],
            'instructions'    => $this->instructions(),
        ];
    }

    private function instructions(): string {
        return implode("\n", [
            'This server exposes one website built on apidcms: its data tables, the page map and the documentation.',
            'Start with schema.get — it returns every table, column, allowed value, relation and page in one call.',
            'Records can be written: records.create / records.update / records.delete. Every write is validated against the table (types, NOT NULL, enums, foreign keys) and leaves an audit entry.',
            'Many-to-many links are written with relations.add / relations.remove — entity_relations is never touched as a table.',
            'Pass dry_run=true to see what would change — including which URLs would be affected — without writing.',
            'If a write is rejected, the error lists column, rule, got and expected: fix those fields and retry.',
            'Docs live as resources (apidcms://docs/{slug}); read apidcms://docs/toc first instead of fetching everything.',
        ]);
    }

    private function ok($id, $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message, $data = null): array {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function write($payload): void {
        header('Content-Type: application/json; charset=utf-8');
        if ($payload === null) {
            http_response_code(202);
            exit;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── tools ──────────────────────────────────────────

    private function callTool(array $params): array {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $result = $this->tools->call($name, $args, 'mcp', $this->scope);
        $payload = $result['ok'] ? $result['data'] : $result['error'];

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ]],
            'isError' => !$result['ok'],
        ];
    }

    // ── resources ──────────────────────────────────────

    private function resourceList(): array {
        $resources = [
            [
                'uri'         => 'apidcms://schema',
                'name'        => 'Project schema',
                'description' => 'Tables, columns, allowed values, relations and the page map.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'apidcms://navigation',
                'name'        => 'Page map',
                'description' => 'Resolved navigation: which table feeds which URL with which template.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'apidcms://docs/toc',
                'name'        => 'Documentation contents',
                'description' => 'Every documentation article with its slug, title and section.',
                'mimeType'    => 'application/json',
            ],
        ];

        try {
            foreach ((new DocIndex())->toc() as $doc) {
                $resources[] = [
                    'uri'         => 'apidcms://docs/' . $doc['slug'],
                    'name'        => $doc['title'] !== '' ? $doc['title'] : $doc['slug'],
                    'description' => $doc['description'],
                    'mimeType'    => 'text/markdown',
                ];
            }
        } catch (\Throwable $e) {
            // Documentation is optional: the data tools still work offline.
        }

        return $resources;
    }

    private function readResource(array $params): array {
        $uri = (string) ($params['uri'] ?? '');

        if ($uri === 'apidcms://schema') {
            $schema = $this->tools->call('schema.get', []);
            return $this->jsonContents($uri, $schema['data'] ?? $schema['error']);
        }
        if ($uri === 'apidcms://navigation') {
            $nav = $this->tools->call('navigation.get', []);
            return $this->jsonContents($uri, $nav['data'] ?? $nav['error']);
        }
        if ($uri === 'apidcms://docs/toc') {
            $toc = $this->tools->call('docs.list', []);
            return $this->jsonContents($uri, $toc['data'] ?? $toc['error']);
        }
        if (strpos($uri, 'apidcms://docs/') === 0) {
            $slug = substr($uri, strlen('apidcms://docs/'));
            $doc = $this->tools->call('docs.get', ['slug' => $slug]);
            if (!$doc['ok']) {
                throw new \RuntimeException($doc['error']['message'] ?? "No document with slug \"$slug\"");
            }
            return ['contents' => [[
                'uri'      => $uri,
                'mimeType' => 'text/markdown',
                'text'     => $doc['data']['content'],
            ]]];
        }

        throw new \RuntimeException("Unknown resource: $uri");
    }

    private function jsonContents(string $uri, $data): array {
        return ['contents' => [[
            'uri'      => $uri,
            'mimeType' => 'application/json',
            'text'     => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]]];
    }

    private function coreVersion(): string {
        $file = CORE_PATH . '/VERSION';
        return file_exists($file) ? trim(file_get_contents($file)) : '0.0.0';
    }
}
