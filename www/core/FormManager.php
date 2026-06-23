<?php
/**
 * FormManager — управление конфигурациями форм
 * 
 * Загружает конфиг формы из таблицы forms,
 * отдаёт структурированные данные в FormRenderer.
 */

namespace Core;

class FormManager {
    private $database;
    private $formCache = [];

    public function __construct($database) {
        $this->database = $database;
    }

    /**
     * Загрузить конфиг формы по имени
     */
    public function getForm(string $name): ?array {
        if (isset($this->formCache[$name])) {
            return $this->formCache[$name];
        }

        $result = $this->database->query(
            "SELECT * FROM forms WHERE name = ? AND status = 'active'",
            [$name]
        )->fetch();

        if (!$result) {
            return null;
        }

        // Парсим JSON-поля
        $form = $result;
        $form['fields'] = json_decode($form['fields'] ?? '{}', true) ?: [];
        $form['notifications'] = json_decode($form['notifications'] ?? '{}', true) ?: [];
        $form['design'] = json_decode($form['design'] ?? '{}', true) ?: [];

        // Загружаем структуру таблицы (для валидации колонок)
        $form['table_columns'] = $this->getTableColumns($form['source_table']);

        $this->formCache[$name] = $form;
        return $form;
    }

    /**
     * Получить все формы
     */
    public function getForms(): array {
        $rows = $this->database->query(
            "SELECT * FROM forms WHERE status = 'active' ORDER BY display_name ASC"
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $row['fields'] = json_decode($row['fields'] ?? '{}', true) ?: [];
            $row['notifications'] = json_decode($row['notifications'] ?? '{}', true) ?: [];
            $row['design'] = json_decode($row['design'] ?? '{}', true) ?: [];
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Получить поля формы, которые можно сохранить (есть в таблице)
     */
    public function getSavableFields(string $formName): array {
        $form = $this->getForm($formName);
        if (!$form) return [];

        $savable = [];
        foreach ($form['fields'] as $name => $config) {
            if (in_array($name, $form['table_columns'])) {
                $savable[$name] = $config;
            }
        }

        // Добавляем pd_consent если есть в таблице
        if (in_array('pd_consent', $form['table_columns'])) {
            $savable['pd_consent'] = ['type' => 'checkbox', 'label' => 'Согласие на ПД'];
        }

        return $savable;
    }

    /**
     * Проверить, что все обязательные поля формы заполнены
     */
    public function validateFormData(string $formName, array $data): array {
        $form = $this->getForm($formName);
        if (!$form) {
            return ['general' => 'Форма не найдена'];
        }

        $errors = [];
        foreach ($form['fields'] as $fieldName => $fieldConfig) {
            if (!empty($fieldConfig['required'])) {
                $value = $data[$fieldName] ?? '';
                if (empty(trim($value))) {
                    $label = $fieldConfig['label'] ?? $fieldName;
                    $errors[$fieldName] = "Поле '{$label}' обязательно для заполнения";
                }
            }

            // Валидация email
            if (($fieldConfig['type'] ?? '') === 'email' && !empty($data[$fieldName])) {
                if (!filter_var($data[$fieldName], FILTER_VALIDATE_EMAIL)) {
                    $label = $fieldConfig['label'] ?? $fieldName;
                    $errors[$fieldName] = "Поле '{$label}' должно содержать корректный email";
                }
            }
        }

        // Проверка pd_consent если есть в таблице
        $columns = $this->getTableColumns($form['source_table']);
        if (in_array('pd_consent', $columns)) {
            if (empty($data['pd_consent'])) {
                $errors['pd_consent'] = 'Необходимо дать согласие на обработку персональных данных';
            }
        }

        return $errors;
    }

    /**
     * Создать новую форму
     */
    public function createForm(array $data): int {
        $this->database->query(
            "INSERT INTO forms (name, display_name, source_table, fields, notifications, design, template, success_message, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                $data['display_name'],
                $data['source_table'],
                is_string($data['fields']) ? $data['fields'] : json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
                is_string($data['notifications']) ? $data['notifications'] : json_encode($data['notifications'] ?? [], JSON_UNESCAPED_UNICODE),
                is_string($data['design']) ? $data['design'] : json_encode($data['design'] ?? [], JSON_UNESCAPED_UNICODE),
                $data['template'] ?? 'default',
                $data['success_message'] ?? 'Спасибо! Форма успешно отправлена.',
                $data['status'] ?? 'active'
            ]
        );
        return $this->database->lastInsertId();
    }

    /**
     * Обновить форму
     */
    public function updateForm(int $id, array $data): void {
        $fields = [];
        $params = [];

        foreach (['name', 'display_name', 'source_table', 'fields', 'notifications', 'design', 'template', 'success_message', 'status'] as $key) {
            if (isset($data[$key])) {
                $value = $data[$key];
                if (in_array($key, ['fields', 'notifications', 'design']) && is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $fields[] = "$key = ?";
                $params[] = $value;
            }
        }

        if (!empty($fields)) {
            $params[] = date('Y-m-d H:i:s');
            $this->database->query(
                "UPDATE forms SET " . implode(', ', $fields) . ", updated_at = ? WHERE id = ?",
                array_merge($params, [$id])
            );
        }

        unset($this->formCache[$data['name'] ?? '']);
    }

    /**
     * Получить колонки таблицы
     */
    private function getTableColumns(string $tableName): array {
        if (!$this->database->tableExists($tableName)) {
            return [];
        }
        $structure = $this->database->getTableStructure($tableName);
        return array_map(function($col) { return $col['name']; }, $structure);
    }

    /**
     * Системные поля (не показываются в форме, не перезаписываются)
     */
    public static function getSystemFields(): array {
        return ['id', 'created_at', 'updated_at', 'status', 'read_status'];
    }
}
