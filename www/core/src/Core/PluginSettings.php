<?php
/**
 * Core\PluginSettings — plugin settings that must not live in plugin.json.
 *
 * A plugin manifest (plugins/<name>/plugin.json) is code: it is committed,
 * reviewed and deployed. Settings are not code — they are per-site values, and
 * some of them are secrets: a YooKassa live key, an Ed25519 signing key. While
 * they lived in the manifest, a live payment key travelled through git, through
 * every deploy and into the server-side code backups. The workaround was a
 * .gitignore line for one file, which left fresh clones and stands without a
 * manifest at all — and PluginManager silently skipped those plugin folders.
 *
 * So the manifest keeps the SCHEMA of the settings (key, type, label,
 * description, secret flag) and the DEFAULT value; the actual value lives here,
 * in the site database. PluginManager overlays the two, therefore plugin code
 * keeps reading $pm->getPlugin('name')['settings'][i]['value'] and never learns
 * where the value came from.
 *
 * The table is created lazily, like api_tokens / table_meta: a plain core
 * update needs no manual migration on 16 sites.
 *
 * Table name is plugin_settings, not system_settings: system_settings is
 * embedded in the admin settings screen, in the API schema and in the schema
 * the AI assistant reads, and secrets do not belong there.
 */

namespace Core;

class PluginSettings
{
    /** @var Database */
    private $db;

    /** Lazy CREATE TABLE runs at most once per request. */
    private static $tableReady = false;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS plugin_settings (
            plugin TEXT NOT NULL,
            setting_key TEXT NOT NULL,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (plugin, setting_key)
        )');
        self::$tableReady = true;
    }

    /**
     * All stored values of one plugin.
     *
     * @return array<string, string> setting_key => setting_value
     */
    public function all(string $plugin): array
    {
        $this->ensureTable();
        $rows = $this->db->query(
            'SELECT setting_key, setting_value FROM plugin_settings WHERE plugin = ?',
            [$plugin]
        )->fetchAll(\PDO::FETCH_ASSOC);

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $values;
    }

    public function has(string $plugin, string $key): bool
    {
        $this->ensureTable();
        $row = $this->db->query(
            'SELECT 1 FROM plugin_settings WHERE plugin = ? AND setting_key = ?',
            [$plugin, $key]
        )->fetch();
        return (bool) $row;
    }

    public function get(string $plugin, string $key, ?string $default = null): ?string
    {
        $values = $this->all($plugin);
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * Store a value. An empty string is a real value ("the owner cleared it"),
     * it is not the same as "never set" — see applyTo().
     */
    public function set(string $plugin, string $key, ?string $value): void
    {
        $this->ensureTable();
        $this->db->query(
            'INSERT INTO plugin_settings (plugin, setting_key, setting_value, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(plugin, setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = CURRENT_TIMESTAMP',
            [$plugin, $key, $value]
        );
    }

    /**
     * Forget a value: the plugin falls back to the default from its manifest.
     */
    public function delete(string $plugin, string $key): void
    {
        $this->ensureTable();
        $this->db->query(
            'DELETE FROM plugin_settings WHERE plugin = ? AND setting_key = ?',
            [$plugin, $key]
        );
    }

    /**
     * Overlay stored values on a manifest settings list.
     *
     * Semantics:
     *   - no row in the table  → keep the default from the manifest;
     *   - row present, empty   → the owner cleared it, the value is empty.
     * A row can therefore not be "absent and empty" at the same time, which is
     * exactly the difference between "not configured yet" and "cleared".
     *
     * For every setting the manifest default is preserved in 'manifest_value'
     * (so the manifest can be written back unchanged) and 'from_db' tells the
     * admin screen which value is actually in use.
     *
     * Adoption: a secret left in the manifest by an older plugin version is
     * copied into the database once and reported through $adopted, so the
     * caller can blank it in the file. Idempotent — after the file is blanked
     * there is nothing left to adopt.
     *
     * Stale secrets: a secret sitting in the manifest while the database already
     * has a value for it is ignored (the database wins) but reported through
     * $stale — otherwise a hand-edited plugin.json would keep a live key in the
     * file forever while the site happily used the one from the database.
     *
     * @param array<int, array> $settings manifest settings
     * @param string[]|null     $adopted  out: keys moved from manifest to the DB
     * @param string[]|null     $stale    out: keys still in the file, ignored
     * @return array<int, array>
     */
    public function applyTo(string $plugin, array $settings, ?array &$adopted = null, ?array &$stale = null): array
    {
        $adopted = [];
        $stale = [];
        if (!$settings) {
            return $settings;
        }

        $stored = $this->all($plugin);
        $type = 'text';

        foreach ($settings as $index => $setting) {
            if (!is_array($setting) || !isset($setting['key'])) {
                continue;
            }

            $key = (string) $setting['key'];
            $type = (string) ($setting['type'] ?? 'text');

            // The manifest value, not the current one: applyTo() must be
            // idempotent, otherwise a second overlay would take the value from
            // the database as "the default from the file" and write the secret
            // back into plugin.json on the next activate/deactivate.
            $manifestValue = array_key_exists('manifest_value', $setting)
                ? $setting['manifest_value']
                : ($setting['value'] ?? null);

            $setting['manifest_value'] = $manifestValue;

            if (array_key_exists($key, $stored)) {
                $setting['value'] = self::cast($stored[$key], $type);
                $setting['from_db'] = true;
                if (!empty($setting['secret']) && is_string($manifestValue) && $manifestValue !== '') {
                    $stale[] = $key;
                }
            } elseif (!empty($setting['secret']) && is_string($manifestValue) && $manifestValue !== '') {
                // Migration path for sites upgrading from a manifest that still
                // carries the secret. Written to the DB before the file is
                // blanked, so the plugin never sees an empty key in between.
                $this->set($plugin, $key, $manifestValue);
                $setting['value'] = self::cast($manifestValue, $type);
                $setting['from_db'] = true;
                $adopted[] = $key;
            } else {
                $setting['value'] = self::cast($manifestValue, $type);
                $setting['from_db'] = false;
            }

            $settings[$index] = $setting;
        }

        return $settings;
    }

    /**
     * Settings are stored as TEXT; restore the type the admin form expects
     * (a checkbox posts "1", a number must stay a number for comparisons).
     */
    private static function cast($value, string $type)
    {
        if ($value === null) {
            return null;
        }
        switch ($type) {
            case 'checkbox':
                return !in_array((string) $value, ['', '0', 'false'], true);
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                $raw = trim((string) $value);
                if ($raw === '') {
                    return null;
                }
                return (preg_match('/^-?\d+$/', $raw) ? (int) $raw : (float) $raw);
            default:
                return (string) $value;
        }
    }
}
