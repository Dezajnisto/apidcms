<?php
/**
 * Класс для работы с системными настройками
 */

namespace Core;

class Settings {
    private $database;
    
    public function __construct($database) {
        $this->database = $database;
    }
    
    /**
     * Получить значение настройки
     */
    public function get($key, $default = null) {
        $result = $this->database->query(
            "SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?",
            [$key]
        )->fetch();
        
        if (!$result) {
            return $default;
        }
        
        return $this->castValue($result['setting_value'], $result['setting_type']);
    }
    
    /**
     * Установить значение настройки
     */
    public function set($key, $value, $type = 'string') {
        $exists = $this->database->query(
            "SELECT id FROM system_settings WHERE setting_key = ?",
            [$key]
        )->fetch();
        
        if ($exists) {
            return $this->database->query(
                "UPDATE system_settings SET setting_value = ?, setting_type = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?",
                [$value, $type, $key]
            );
        } else {
            return $this->database->query(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)",
                [$key, $value, $type]
            );
        }
    }
    
    /**
     * Получить все email настройки
     */
    public function getEmailConfig() {
        return [
            'driver' => $this->get('email_driver', 'api'),
            'api' => [
                'provider' => $this->get('email_api_provider', ''),
                'key' => $this->get('email_api_key', ''),
                'endpoint' => $this->get('email_api_endpoint', '')
            ],
            'smtp' => [
                'host' => $this->get('email_smtp_host', ''),
                'port' => $this->get('email_smtp_port', '587'),
                'username' => $this->get('email_smtp_username', ''),
                'password' => $this->get('email_smtp_password', ''),
                'encryption' => $this->get('email_smtp_encryption', 'tls')
            ],
            'from' => [
                'email' => $this->get('email_from_email', ''),
                'name' => $this->get('email_from_name', '')
            ]
        ];
    }
    
    /**
     * Получить все настройки для админки
     */
    public function getAllSettings() {
        $result = $this->database->query(
            "SELECT setting_key, setting_value, setting_type FROM system_settings ORDER BY setting_key"
        )->fetchAll();
        
        $settings = [];
        foreach ($result as $row) {
            $settings[$row['setting_key']] = [
                'value' => $this->castValue($row['setting_value'], $row['setting_type']),
                'type' => $row['setting_type']
            ];
        }
        
        return $settings;
    }
    
    /**
     * Приведение типа значения
     */
    private function castValue($value, $type) {
        if ($value === null || $value === '') {
            return $value;
        }
        
        switch ($type) {
            case 'boolean':
            case 'bool':
                return (bool)$value;
            case 'integer':
            case 'int':
                return (int)$value;
            case 'float':
            case 'double':
                return (float)$value;
            case 'array':
                return json_decode($value, true) ?: [];
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }
}
?>