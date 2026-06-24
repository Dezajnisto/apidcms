<?php
/**
 * Контроллер для управления настройками сайта
 * Все настройки хранятся в system_settings
 */

namespace Admin;

use Core\Settings;
use Exception;

class SettingsController extends BaseController {

    /**
     * Главная страница настроек
     */
    public function index() {
        try {
            $systemSettings = $this->getSystemSettings();

            // Таблицы для выбора публичных (AI-контекст)
            $allTables = $this->db->getTables();
            $blacklistedTables = ["sqlite_sequence", "system_settings", "users", "user_tokens"];
            $selectableTables = [];
            foreach ($allTables as $t) {
                if (!in_array($t, $blacklistedTables)) {
                    $cnt = $this->db->query("SELECT COUNT(*) as c FROM \"{$t}\"")->fetch()["c"];
                    $selectableTables[] = ["name" => $t, "row_count" => $cnt];
                }
            }

            $this->render("settings/index", [
                "title" => "Настройки сайта",
                "system_settings" => $systemSettings,
                "all_tables" => $selectableTables,
                "blacklisted_tables" => $blacklistedTables
            ]);
        } catch (Exception $e) {
            $this->render("error/404", [
                "message" => "Ошибка при загрузке настроек: " . $e->getMessage()
            ]);
        }
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
                throw new Exception("\u041d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d \u043a\u043b\u044e\u0447 \u043d\u0430\u0441\u0442\u0440\u043e\u0439\u043a\u0438");
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
            foreach ([maintenance_mode, ai_frontend_use_system, stats_enabled] as $ck) {
                if (!isset($settingsData[$ck])) {
                    $settingsData[$ck] = 0;
                }
            }
            if (empty($settingsData)) {
                throw new Exception("\u041d\u0435\u0442 \u0434\u0430\u043d\u043d\u044b\u0445 \u0434\u043b\u044f \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u044f");
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
                throw new Exception("Не указан ключ настройки");
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
