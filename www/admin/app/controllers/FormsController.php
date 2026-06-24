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

    /**
     * Список шаблонов формы
     */
    public function templates($name) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render("error/404", ["message" => "Форма '{$name}' не найдена"]);
            return;
        }
        $templatesDir = $this->getFormTemplatesDir();
        $templates = [];
        if (is_dir($templatesDir)) {
            $files = scandir($templatesDir);
            foreach ($files as $f) {
                if ($f === "." || $f === "..") continue;
                if (preg_match("/\.twig$/", $f)) {
                    $fp = $templatesDir . "/" . $f;
                    $templates[] = ["name" => $f, "size" => filesize($fp), "modified" => filemtime($fp)];
                }
            }
            usort($templates, fn($a, $b) => strcmp($a["name"], $b["name"]));
        }
        $fieldsDir = $templatesDir . "/fields";
        $fieldTemplates = [];
        if (is_dir($fieldsDir)) {
            $files = scandir($fieldsDir);
            foreach ($files as $f) {
                if ($f === "." || $f === "..") continue;
                if (preg_match("/\.twig$/", $f)) {
                    $fp = $fieldsDir . "/" . $f;
                    $fieldTemplates[] = ["name" => "fields/" . $f, "size" => filesize($fp), "modified" => filemtime($fp)];
                }
            }
            usort($fieldTemplates, fn($a, $b) => strcmp($a["name"], $b["name"]));
        }
        $this->render("forms/templates", [
            "title" => "Шаблоны формы: {$form["display_name"]}",
            "form" => $form, "form_name" => $name,
            "templates" => $templates, "field_templates" => $fieldTemplates,
        ]);
    }

    /**
     * Редактирование шаблона формы
     */
    public function editTemplate($name, $file) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render("error/404", ["message" => "Форма '{$name}' не найдена"]);
            return;
        }
        $templatesDir = $this->getFormTemplatesDir();
        $filePath = $templatesDir . "/" . $file;
        $realFilePath = realpath($filePath) ?: "";
        $realTemplatesDir = realpath($templatesDir) ?: "___";
        if (strpos($realFilePath, $realTemplatesDir) !== 0) {
            $this->setFlash("error", "Недопустимый путь к шаблону");
            $this->redirect("/forms/{$name}/templates");
            return;
        }
        if (!file_exists($filePath)) {
            $this->setFlash("error", "Шаблон '{$file}' не найден");
            $this->redirect("/forms/{$name}/templates");
            return;
        }
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $newContent = $_POST["content"] ?? "";
            if (file_put_contents($filePath, $newContent) !== false) {
                $this->setFlash("success", "Шаблон '{$file}' сохранён");
                $this->redirect("/forms/{$name}/templates");
                return;
            } else {
                $this->setFlash("error", "Не удалось сохранить шаблон");
            }
        }
        $content = file_get_contents($filePath);
        $this->render("forms/edit_template", [
            "title" => "Редактирование: {$file}",
            "form" => $form, "form_name" => $name,
            "file_name" => $file, "content" => $content, "file_path" => $filePath
        ]);
    }

    /**
     * Получить директорию шаблонов формы
     */
    public function editFieldTemplate($name, $file) {
        return $this->editTemplate($name, "fields/" . $file);
    }

    /**
     * Create a new form or field template
     */
    public function createTemplate($name) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->setFlash("error", "Form '{$name}' not found");
            $this->redirect("/forms");
            return;
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            $this->redirect("/forms/{$name}/templates");
            return;
        }

        $fileName = trim($_POST["template_name"] ?? "");
        $templateType = $_POST["template_type"] ?? "form";

        if (empty($fileName)) {
            $this->setFlash("error", "Template name is required");
            $this->redirect("/forms/{$name}/templates");
            return;
        }

        // Validate: only .html.twig extension, letters/numbers/hyphens/underscores
        if (!preg_match('/^[a-zA-Z0-9_-]+\.html\.twig$/', $fileName)) {
            $this->setFlash("error", "Name must be like: my-template.html.twig (letters, numbers, hyphens, underscores)");
            $this->redirect("/forms/{$name}/templates");
            return;
        }

        $templatesDir = $this->getFormTemplatesDir();

        if ($templateType === "field") {
            $templatesDir .= "/fields";
            if (!is_dir($templatesDir)) {
                mkdir($templatesDir, 0755, true);
            }
        }

        $filePath = $templatesDir . "/" . $fileName;

        if (file_exists($filePath)) {
            $this->setFlash("error", "Template '{$fileName}' already exists");
            $this->redirect("/forms/{$name}/templates");
            return;
        }

        // Generate skeleton based on type
        if ($templateType === "field") {
            $skeleton = <<<'TWIG'
{# Field: CHANGE_ME — CHANGE_ME input #}
{% set input_id = 'field_' ~ field.name %}
<div class="form-group{{ field.required ? ' required' : '' }}">
    {% set has_label = field.label is defined and field.label is not empty %}
    {% if has_label %}
    <label for="{{ input_id }}" class="form-label">{{ field.label }}{% if field.required %} <span class="required-star">*</span>{% endif %}</label>
    {% endif %}
    <input type="{{ field.type }}" name="{{ field.name }}" id="{{ input_id }}"
           value="{{ form_data[field.name]|default('') }}"
           class="form-control {{ design.field_class|default('') }}"
           {% if field.placeholder %}placeholder="{{ field.placeholder }}"{% endif %}
           {% if field.required %}required{% endif %}>
    {% if field.help_text %}<small class="form-help">{{ field.help_text }}</small>{% endif %}
</div>
TWIG;
        } else {
            $skeleton = <<<'TWIG'
{# CHANGE_ME — describe this template #}
{% extends "form/_base.html.twig" %}

{% block form_content %}
    {% for field_name, field in form.fields %}
        {% set field = field|merge({name: field_name}) %}
        <div class="mb-3">
            {{ include('form/fields/' ~ field.type ~ '.html.twig', {field: field, form_data: form_data, design: design}, ignore_missing = true) }}
        </div>
    {% endfor %}

    <button type="submit" class="{{ design.submit_class|default('btn btn-primary') }}">
        {{ design.submit_text|default('Send') }}
    </button>
{% endblock %}
TWIG;
        }

        if (file_put_contents($filePath, $skeleton) === false) {
            $this->setFlash("error", "Failed to create template '{$fileName}'");
        } else {
            $this->setFlash("success", "Template '{$fileName}' created");
        }

        $this->redirect("/forms/{$name}/templates");
    }

    private function getFormTemplatesDir(): string {
        // Prefer project's own form templates directory
        $projectFrontDir = PROJECT_ROOT . '/front/app/views/form';
        if (is_dir($projectFrontDir)) {
            return realpath($projectFrontDir);
        }
        // Fall back to core templates
        // __DIR__ is .../core/www/admin/app/controllers/ → go up 3 to reach www/
        $coreDir = dirname(dirname(dirname(__DIR__)));
        $coreFrontDir = $coreDir . '/front/app/views/form';
        if (is_dir($coreFrontDir)) {
            return realpath($coreFrontDir);
        }
        // Last resort: return project path (for creating new templates)
        return $projectFrontDir;
    }
}
