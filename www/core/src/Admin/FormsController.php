<?php
/**
 * FormsController — manage forms in admin panel
 */

namespace Admin;

use Admin\Lang;

class FormsController extends BaseController {

    /**
     * List all forms
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
            'title' => $this->lang->t('forms.manage_title'),
            'forms' => $forms,
        ]);
    }

    /**
     * Edit form
     */
    public function edit($name) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render('error/404', ['message' => $this->lang->t('forms.not_found', ['name' => $name])]);
            return;
        }

        // Parse JSON
        $form['fields'] = json_decode($form['fields'] ?? '{}', true) ?: [];
        $form['notifications'] = json_decode($form['notifications'] ?? '{}', true) ?: [];
        $form['design'] = json_decode($form['design'] ?? '{}', true) ?: [];

        // Available tables list
        $tables = [];
        foreach ($this->db->getTables() as $t) {
            $tables[] = $t;
        }

        // Available form templates
        $templatesDir = $this->getFormTemplatesDir();
        $availableTemplates = [];
        if (is_dir($templatesDir)) {
            foreach (scandir($templatesDir) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (preg_match('/\.twig$/', $f) && $f !== '_base.html.twig' && $f !== 'messages.html.twig') {
                    $availableTemplates[] = $f;
                }
            }
            sort($availableTemplates);
        }

        $this->render('forms/edit', [
            'title' => $this->lang->t('forms.edit_form_title', ['name' => $form['display_name']]),
            'form' => $form,
            'tables' => $tables,
            'available_templates' => $availableTemplates,
        ]);
    }

    /**
     * Save form
     */
    public function save() {
        $name = $_POST['name'] ?? '';
        if (empty($name)) {
            $this->setFlash('error', $this->lang->t('forms.error_no_name'));
            $this->redirect('/forms');
            return;
        }

        // Collect data
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

        // Notifications
        $notifications = [
            'admin_notify' => !empty($_POST['notify_admin']),
            'admin_emails' => array_map('trim', explode(',', $_POST['admin_emails'] ?? '')),
            'admin_subject' => $_POST['admin_subject'] ?? $this->lang->t('forms.default_admin_subject'),
            'auto_reply' => !empty($_POST['auto_reply']),
            'auto_reply_subject' => $_POST['auto_reply_subject'] ?? $this->lang->t('forms.default_reply_subject'),
        ];
        // Clean empty emails
        $notifications['admin_emails'] = array_filter($notifications['admin_emails']);

        // Update
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
                    'submit_text' => $_POST['design_submit_text'] ?? $this->lang->t('forms.default_submit_text'),
                    'submit_class' => $_POST['design_submit_class'] ?? '',
                    'field_class' => $_POST['design_field_class'] ?? '',
                ], JSON_UNESCAPED_UNICODE),
                $_POST['template'] ?? 'default',
                $_POST['success_message'] ?? $this->lang->t('forms.default_success'),
                !empty($_POST['enable_csrf']) ? 1 : 0,
                !empty($_POST['status']) ? 'active' : 'inactive',
                $name,
            ]
        );

        $this->setFlash('success', $this->lang->t('forms.form_saved', ['name' => $name]));
        $this->redirect('/forms');
    }

    /**
     * Create new form
     */
    public function create() {
        $name = $_POST['new_name'] ?? '';
        if (empty($name)) {
            $this->setFlash('error', $this->lang->t('forms.error_enter_name'));
            $this->redirect('/forms');
            return;
        }

        // Check uniqueness
        $exists = $this->db->query("SELECT id FROM forms WHERE name = ?", [$name])->fetch();
        if ($exists) {
            $this->setFlash('error', $this->lang->t('forms.error_exists'));
            $this->redirect('/forms');
            return;
        }

        $this->db->query(
            "INSERT INTO forms (name, display_name, source_table, fields, template, status)
             VALUES (?, ?, ?, '{}', 'default', 'active')",
            [$name, $name, ($_POST['new_source_table'] ?? $name)]
        );

        $this->setFlash('success', $this->lang->t('forms.form_created', ['name' => $name]));
        $this->redirect('/forms/edit/' . $name);
    }

    /**
     * Delete form
     */
    public function delete($name) {
        $this->db->query("DELETE FROM forms WHERE name = ?", [$name]);
        $this->setFlash('success', $this->lang->t('forms.form_deleted', ['name' => $name]));
        $this->redirect('/forms');
    }

    /**
     * Toggle status (active/inactive)
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
     * Form templates list
     */
    public function templates($name) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render("error/404", ["message" => $this->lang->t('forms.not_found', ['name' => $name])]);
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
            "title" => $this->lang->t('forms.form_templates_title', ['name' => $form["display_name"]]),
            "form" => $form, "form_name" => $name,
            "templates" => $templates, "field_templates" => $fieldTemplates,
        ]);
    }

    /**
     * Edit form template
     */
    public function editTemplate($name, $file) {
        $form = $this->db->query("SELECT * FROM forms WHERE name = ?", [$name])->fetch();
        if (!$form) {
            $this->render("error/404", ["message" => $this->lang->t('forms.not_found', ['name' => $name])]);
            return;
        }
        $templatesDir = $this->getFormTemplatesDir();
        $filePath = $templatesDir . "/" . $file;
        $realFilePath = realpath($filePath) ?: "";
        $realTemplatesDir = realpath($templatesDir) ?: "___";
        if (strpos($realFilePath, $realTemplatesDir) !== 0) {
            $this->setFlash("error", $this->lang->t('forms.edit_tpl_error_path'));
            $this->redirect("/forms/{$name}/templates");
            return;
        }
        if (!file_exists($filePath)) {
            $this->setFlash("error", $this->lang->t('forms.edit_tpl_error_notfound'));
            $this->redirect("/forms/{$name}/templates");
            return;
        }
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $newContent = $_POST["content"] ?? "";
            if (file_put_contents($filePath, $newContent) !== false) {
                $this->setFlash("success", $this->lang->t('forms.edit_tpl_saved'));
                $this->redirect("/forms/{$name}/templates");
                return;
            } else {
                $this->setFlash("error", $this->lang->t('forms.edit_tpl_error_save'));
            }
        }
        $content = file_get_contents($filePath);
        $this->render("forms/edit_template", [
            "title" => $this->lang->t('forms.edit_template_title', ['file' => $file]),
            "form" => $form, "form_name" => $name,
            "file_name" => $file, "content" => $content, "file_path" => $filePath
        ]);
    }

    /**
     * Get form templates directory
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
        // Prefer theme's form templates directory
        $themeDir = THEMES_PATH . '/default/front/form';
        if (is_dir($themeDir)) {
            return realpath($themeDir);
        }
        // Fall back to core templates
        $coreFrontDir = CORE_PATH . '/views/front/form';
        if (is_dir($coreFrontDir)) {
            return realpath($coreFrontDir);
        }
        // Last resort: return theme path (for creating new templates)
        return $themeDir;
    }
}
