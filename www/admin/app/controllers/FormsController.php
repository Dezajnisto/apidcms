<?php
/**
 * FormsController — управление формами в админке
 */

namespace Admin;

class FormsController extends BaseController {

    /**
     * Список всех форм
     */
    public function index() {
        $rows = $this->db->query("SELECT * FROM forms ORDER BY display_name ASC")->fetchAll();

        $forms = [];
        foreach ($rows as $row) {
            $fields = json_decode($row['fields'] ?? '{}', true);
            $row['field_count'] = is_array($fields) ? count($fields) : 0;
            $forms[] = $row;
        }

        $this->render('forms/index', [
            'title' => 'Управление формами',
            'forms' => $forms,
        ]);
    }

    /**
     * Редактирование формы
     */
    public function edit($name) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render('error/404', ['message' => "Форма '{$name}' не найдена"]);
            return;
        }

        // Парсим JSON
        $form['fields'] = json_decode($form['fields'] ?? '{}', true) ?: [];
        $form['notifications'] = json_decode($form['notifications'] ?? '{}', true) ?: [];
        $form['design'] = json_decode($form['design'] ?? '{}', true) ?: [];

        // Список доступных таблиц
        $tables = [];
        foreach ($this->db->getTables() as $t) {
            $tables[] = $t;
        }

        $this->render('forms/edit', [
            'title' => 'Редактирование формы: ' . $form['display_name'],
            'form' => $form,
            'tables' => $tables,
        ]);
    }

    /**
     * Сохранение формы
     */
    public function save() {
        $name = $_POST['name'] ?? '';
        if (empty($name)) {
            $this->setFlash('error', 'Имя формы не указано');
            $this->redirect('/forms');
            return;
        }

        // Собираем данные
        $fields = [];
        $fieldNames = $_POST['field_name'] ?? [];
        $fieldLabels = $_POST['field_label'] ?? [];
        $fieldTypes = $_POST['field_type'] ?? [];
        $fieldRequired = $_POST['field_required'] ?? [];
        $fieldPlaceholders = $_POST['field_placeholder'] ?? [];
        $fieldHelp = $_POST['field_help'] ?? [];
        $fieldRows = $_POST['field_rows'] ?? [];
        $fieldOptions = $_POST['field_options'] ?? [];

        foreach ($fieldNames as $i => $fname) {
            $fname = trim($fname);
            if (empty($fname)) continue;

            $field = [
                'label' => $fieldLabels[$i] ?? $fname,
                'type' => $fieldTypes[$i] ?? 'text',
                'placeholder' => $fieldPlaceholders[$i] ?? '',
                'required' => !empty($fieldRequired[$i]),
                'help_text' => $fieldHelp[$i] ?? '',
            ];

            if (($field['type'] ?? '') === 'textarea' && !empty($fieldRows[$i])) {
                $field['rows'] = (int)$fieldRows[$i];
            }

            if (in_array($field['type'] ?? '', ['select', 'radio']) && !empty($fieldOptions[$i])) {
                $options = [];
                foreach (explode("\n", $fieldOptions[$i]) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    if (strpos($line, '=') !== false) {
                        [$val, $label] = explode('=', $line, 2);
                        $options[trim($val)] = trim($label);
                    } else {
                        $options[$line] = $line;
                    }
                }
                $field['options'] = $options;
            }

            $fields[$fname] = $field;
        }

        // Уведомления
        $notifications = [
            'admin_notify' => !empty($_POST['notify_admin']),
            'admin_emails' => array_map('trim', explode(',', $_POST['admin_emails'] ?? '')),
            'admin_subject' => $_POST['admin_subject'] ?? 'Новая заявка с сайта',
            'auto_reply' => !empty($_POST['auto_reply']),
            'auto_reply_subject' => $_POST['auto_reply_subject'] ?? 'Мы получили ваше сообщение',
            'auto_reply_field' => $_POST['auto_reply_field'] ?? 'email',
        ];
        // Очищаем пустые email
        $notifications['admin_emails'] = array_filter($notifications['admin_emails']);

        // Обновляем
        $this->db->query(
            "UPDATE forms SET display_name = ?, source_table = ?, fields = ?, notifications = ?,
             design = ?, template = ?, success_message = ?, enable_csrf = ?, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE name = ?",
            [
                $_POST['display_name'] ?? $name,
                $_POST['source_table'] ?? '',
                json_encode($fields, JSON_UNESCAPED_UNICODE),
                json_encode($notifications, JSON_UNESCAPED_UNICODE),
                json_encode([
                    'submit_text' => $_POST['design_submit_text'] ?? 'Отправить',
                    'submit_class' => $_POST['design_submit_class'] ?? '',
                    'field_class' => $_POST['design_field_class'] ?? '',
                ], JSON_UNESCAPED_UNICODE),
                $_POST['template'] ?? 'default',
                $_POST['success_message'] ?? 'Спасибо! Форма успешно отправлена.',
                !empty($_POST['enable_csrf']) ? 1 : 0,
                !empty($_POST['status']) ? 'active' : 'inactive',
                $name,
            ]
        );

        $this->setFlash('success', "Форма '{$name}' сохранена");
        $this->redirect('/forms');
    }

    /**
     * Создание новой формы
     */
    public function create() {
        $name = $_POST['new_name'] ?? '';
        if (empty($name)) {
            $this->setFlash('error', 'Укажите имя формы');
            $this->redirect('/forms');
            return;
        }

        // Проверка уникальности
        $exists = $this->db->query("SELECT id FROM forms WHERE name = ?", [$name])->fetch();
        if ($exists) {
            $this->setFlash('error', "Форма с именем '{$name}' уже существует");
            $this->redirect('/forms');
            return;
        }

        $this->db->query(
            "INSERT INTO forms (name, display_name, source_table, fields, template, status)
             VALUES (?, ?, ?, '{}', 'default', 'active')",
            [$name, $name, ($_POST['new_source_table'] ?? $name)]
        );

        $this->setFlash('success', "Форма '{$name}' создана");
        $this->redirect('/forms/edit/' . $name);
    }

    /**
     * Удаление формы
     */
    public function delete($name) {
        $this->db->query("DELETE FROM forms WHERE name = ?", [$name]);
        $this->setFlash('success', "Форма '{$name}' удалена");
        $this->redirect('/forms');
    }

    /**
     * Переключение статуса (active/inactive)
     */
    public function toggle($name) {
        $form = $this->db->query("SELECT status FROM forms WHERE name = ?", [$name])->fetch();
        if ($form) {
            $newStatus = $form['status'] === 'active' ? 'inactive' : 'active';
            $this->db->query("UPDATE forms SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE name = ?", [$newStatus, $name]);
        }
        $this->redirect('/forms');
    }
}
