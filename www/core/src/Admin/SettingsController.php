<?php
/**
 * Контроллер для управления настройками сайта
 * Все настройки хранятся в system_settings
 */

namespace Admin;

use Core\Settings;
use Core\TokenStore;
use Exception;

class SettingsController extends BaseController {

    /**
     * Настройки → API: named tokens with a scope, created and revoked here.
     *
     * The plaintext token is shown once: it is kept in the session across the
     * redirect (never in the URL, where it would end up in logs and history).
     */
    public function api() {
        try {
            $store = new TokenStore($this->db);
            $tokens = $store->all();

            $newToken = null;
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!empty($_SESSION['new_api_token'])) {
                $newToken = $_SESSION['new_api_token'];
                unset($_SESSION['new_api_token']);
            }

            $legacyKey = '';
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'api_key'"
            )->fetch();
            if ($row) {
                $legacyKey = (string) $row['setting_value'];
            }

            $this->render("settings/api", [
                "title" => $this->lang->t('settings.api_title'),
                "tokens" => $tokens,
                "new_token" => $newToken,
                "legacy_key_set" => $legacyKey !== '',
                "api_enabled" => $legacyKey !== '' || count(array_filter($tokens, fn($t) => $t['active'])) > 0,
                "scopes" => TokenStore::SCOPES,
                "host" => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
                    . ($_SERVER['HTTP_HOST'] ?? 'example.com'),
            ]);
        } catch (Exception $e) {
            $this->render("error/404", [
                "message" => $this->lang->t('settings.api_load_error') . ' ' . $e->getMessage()
            ]);
        }
    }

    /**
     * POST /settings/api/create — create a token and show it exactly once.
     */
    public function createToken() {
        try {
            $name = trim((string) ($_POST['name'] ?? ''));
            $scope = (string) ($_POST['scope'] ?? 'full');
            if ($name === '') {
                $name = $this->lang->t('settings.api_default_name');
            }

            $store = new TokenStore($this->db);
            $created = $store->create($name, $scope);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['new_api_token'] = $created;

            $this->redirect('/settings/api?created=1');
        } catch (Exception $e) {
            $this->redirect('/settings/api?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * POST /settings/api/revoke — the token stops working, the row stays.
     */
    public function revokeToken() {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception($this->lang->t('settings.api_token_not_found'));
            }
            (new TokenStore($this->db))->revoke($id);
            $this->redirect('/settings/api?revoked=1');
        } catch (Exception $e) {
            $this->redirect('/settings/api?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Главная страница настроек
     */
    public function index() {
        try {
            $systemSettings = $this->getSystemSettings();

            // Таблицы для выбора публичных (AI-контекст)
            $allTables = $this->db->getTables();
            $blacklistedTables = ["sqlite_sequence", "system_settings", "users", "user_tokens",
                "entity_relations", "visit_stats", "table_meta", "field_meta", "api_idempotency", "api_tokens"];
            $selectableTables = [];
            foreach ($allTables as $t) {
                if (!in_array($t, $blacklistedTables)) {
                    $cnt = $this->db->query("SELECT COUNT(*) as c FROM \"{$t}\"")->fetch()["c"];
                    $selectableTables[] = ["name" => $t, "row_count" => $cnt];
                }
            }

            // Сколько контекста реально уходит в AI: объём промпта не должен
            // быть невидимым. Лимиты читаем теми же правилами, что AIController.
            $aiContext = null;
            try {
                $flat = [];
                foreach ($systemSettings as $row) {
                    $flat[(string) $row["key"]] = $row["value"];
                }
                $limits = [
                    "template_max_size" => $this->limitValue($flat, "ai_template_max_size", \Core\AIContext::DEFAULT_TEMPLATE_MAX),
                    "template_total_max" => $this->limitValue($flat, "ai_template_total_max", \Core\AIContext::DEFAULT_TEMPLATE_TOTAL),
                    "css_max_size" => $this->limitValue($flat, "ai_css_max_size", \Core\AIContext::DEFAULT_CSS_MAX),
                ];
                $aiContext = \Core\AIContext::summary(
                    $limits["template_max_size"],
                    $limits["template_total_max"],
                    $limits["css_max_size"]
                );
                $aiContext["limits"] = $limits;
            } catch (\Throwable $e) {
                $aiContext = null;
            }

            $this->render("settings/index", [
                "title" => $this->lang->t('settings.page_title'),
                "system_settings" => $systemSettings,
                "all_tables" => $selectableTables,
                "blacklisted_tables" => $blacklistedTables,
                "ai_context" => $aiContext
            ]);
        } catch (Exception $e) {
            $this->render("error/404", [
                "message" => $this->lang->t('settings.load_error') . ' ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Значение лимита из списка настроек: пусто или 0 — встроенный лимит,
     * -1 — без ограничения. Те же правила, что в Admin\AIController.
     *
     * @param array $settings строки system_settings (ключ => значение)
     */
    private function limitValue(array $settings, string $key, int $default): int
    {
        $raw = trim((string) ($settings[$key] ?? ""));
        if ($raw === "") {
            return $default;
        }
        $value = (int) $raw;
        if ($value === -1) {
            return 0;
        }
        return $value > 0 ? $value : $default;
    }

    /**
     * Сохранить настройку (system_settings)
     */
        public function saveSetting() {
        try {
            $key = $_POST["setting_key"] ?? "";
            $value = $_POST["setting_value"] ?? "";
            $type = $_POST["setting_type"] ?? "string";

            if (empty($key)) {
                throw new Exception($this->lang->t('settings.key_not_specified'));
            }

            $settings = new Settings($this->db);
            $settings->set($key, $value, $type);

            $this->redirect("/settings?saved=1");
        } catch (Exception $e) {
            $this->redirect("/settings?error=" . urlencode($e->getMessage()));
        }
    }

    /**
     * \u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043d\u0435\u0441\u043a\u043e\u043b\u044c\u043a\u043e \u043d\u0430\u0441\u0442\u0440\u043e\u0435\u043a \u0441\u0440\u0430\u0437\u0443
     * POST: settings[\u043a\u043b\u044e\u0447]=\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u0435, types[\u043a\u043b\u044e\u0447]=\u0442\u0438\u043f
     */
    public function saveSettings() {
        try {
            $settingsData = $_POST["settings"] ?? [];
            // Checkbox fields: unchecked = 0
            $checkboxKeys = ['maintenance_mode', 'ai_frontend_use_system', 'stats_enabled'];
            foreach ($checkboxKeys as $ck) {
                if (!isset($settingsData[$ck])) {
                    $settingsData[$ck] = '0';
                }
            }

            if (empty($settingsData)) {
                throw new Exception($this->lang->t('settings.no_data_to_save'));
            }

            $settings = new Settings($this->db);
            foreach ($settingsData as $key => $value) {
                $type = $_POST["types"][$key] ?? "string";
                if (!empty($key)) {
                    $settings->set($key, $value, $type);
                }
            }

            $this->redirect("/settings?saved=1");
        } catch (Exception $e) {
            $this->redirect("/settings?error=" . urlencode($e->getMessage()));
        }
    }
    /**
     * Удалить настройку
     */
    public function deleteSetting() {
        try {
            $key = $_POST["key"] ?? "";

            if (empty($key)) {
                throw new Exception($this->lang->t('settings.key_not_specified'));
            }

            $this->db->query(
                "DELETE FROM system_settings WHERE setting_key = ?",
                [$key]
            );

            $this->redirect("/settings?deleted=1");
        } catch (Exception $e) {
            $this->redirect("/settings?error=" . urlencode($e->getMessage()));
        }
    }

    /**
     * Получить все системные настройки
     */
    private function getSystemSettings() {
        $rows = $this->db->query(
            "SELECT * FROM system_settings ORDER BY setting_key"
        )->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[] = [
                "key" => $row["setting_key"],
                "value" => $row["setting_value"],
                "type" => $row["setting_type"] ?? "string",
                "created" => $row["created_at"],
                "updated" => $row["updated_at"]
            ];
        }
        return $settings;
    }
}
