# external — External JSON API Page Type

**Added:** v1.3.4
**Type:** `external`

The `external` page type fetches data from an external JSON API/URL instead of a local database table.
Use it for catalogs, changelogs, or any data that lives outside your CMS.

## How it works

1. Page request arrives → FrontController detects `page_type = external`
2. `ExternalPageLoader` checks cache (if `cache_ttl > 0`)
3. If cache is stale/missing → HTTP request to `source_url`
4. Response is parsed as JSON, `json_path` extracts the target array
5. Data is passed to Twig template as `items`

## Page config (JSON in navigation.page_config)

```json
{
  "source_url": "https://api.example.com/data.json",
  "json_path": "results",
  "cache_ttl": 3600,
  "method": "GET",
  "headers": {
    "Authorization": "Bearer {{ token }}"
  },
  "template": "catalog"
}
```

### Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `source_url` | string | — | **Required.** External JSON URL |
| `json_path` | string | `""` | Dot-notation path: `"data.items"` → `$data['data']['items']`. Empty = entire response |
| `cache_ttl` | int | `0` | Cache lifetime in seconds. `0` = live mode (no cache). `3600` = 1 hour |
| `method` | string | `"GET"` | HTTP method: GET, POST, PUT, PATCH |
| `headers` | object | `{}` | Extra HTTP headers. Supports `{{ token }}` and `{{ setting.KEY }}` |
| `template` | string | `"default"` | Twig template name (without `.html.twig`). `"default"` uses `external.html.twig` |

### Presets

| Preset | cache_ttl | Use case |
|--------|-----------|----------|
| 🗄️ Cached catalog | 3600 | Rarely changing data: catalogs, docs, changelogs |
| ⚡ Live API | 0 | Interactive data: search, filters, Notion/NocoDB |

## Cache

- Cache files: `/admin/views/cache/external_{md5(url+path)}.json`
- Format: `{"ts": 1690000000, "url": "...", "data": {...}}`
- Auto-expires based on `cache_ttl` (file mtime comparison)
- Cleared via Admin → Clear cache → External, or programmatically:
  ```php
  \Core\ExternalPageLoader::clearAllCache();
  ```

## Placeholders in headers

- `{{ token }}` → system setting `external_default_token`
- `{{ setting.KEY }}` → any system setting by key

Example for Notion API:
```json
{
  "headers": {
    "Authorization": "Bearer {{ setting.notion_api_key }}",
    "Notion-Version": "2022-06-28"
  }
}
```

## Twig template variables

| Variable | Type | Description |
|----------|------|-------------|
| `items` | array | Data extracted via `json_path` (or full response) |
| `raw` | array | Full JSON response (unfiltered) |
| `from_cache` | bool | `true` if served from cache |
| `page_config` | object | Full page config from navigation |
| `source_url` | string | The source URL |
| `nav_item` | object | Navigation item (same as other page types) |

## Live mode query forwarding

When `cache_ttl = 0`, query parameters from the page URL are forwarded to the API:

- `?q=search&category=5` → appended to `source_url`
- CMS-internal params (`page`, `sort`) are stripped

## Error handling

If the external API is unreachable or returns an error:
- HTTP 4xx/5xx → `external_error.html.twig` is rendered
- Invalid JSON → `external_error.html.twig` is rendered
- Error is logged to PHP error log
- The site does NOT crash

## Examples

### Plugin catalog (cached)
```json
{
  "source_url": "https://raw.githubusercontent.com/Dezajnisto/apidcms-plugins/main/plugins.json",
  "json_path": "plugins",
  "cache_ttl": 3600,
  "template": "plugins"
}
```

### Changelog from GitHub Releases (cached)
```json
{
  "source_url": "https://api.github.com/repos/Dezajnisto/apidcms/releases",
  "cache_ttl": 3600,
  "headers": {"Accept": "application/vnd.github.v3+json"},
  "template": "changelog"
}
```

### NocoDB catalog (live)
```json
{
  "source_url": "https://app.nocodb.com/api/v2/tables/mxzy0/records",
  "json_path": "list",
  "cache_ttl": 0,
  "headers": {"xc-token": "{{ setting.nocodb_api_key }}"},
  "template": "catalog"
}
```
