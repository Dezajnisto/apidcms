<?php
/**
 * Сервис для рендеринга форм в любом месте сайта
 */

namespace Core;

class FormRenderer {
    private $database;
    private $emailNotifier;
    
    public function __construct($database, $emailConfig = []) {
        $this->database = $database;
        $this->emailNotifier = new EmailNotifier($emailConfig);
    }
    
    /**
     * Рендерит форму по названию таблицы
     */
    public function renderForm($tableName, $formConfig = []) {
        // Проверяем существование таблицы
        if (!$this->database->tableExists($tableName)) {
            return '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">Форма не найдена</div>';
        }
        
        // Получаем структуру таблицы
        $structure = $this->database->getTableStructure($tableName);
        
        // Генерируем HTML формы
        return $this->generateFormHtml($tableName, $structure, $formConfig);
    }
    
    /**
     * Генерация HTML формы
     */
    private function generateFormHtml($tableName, $structure, $formConfig) {
        $action = $formConfig['action'] ?? '/form-handler';
        $method = $formConfig['method'] ?? 'post';
        $formClass = $formConfig['form_class'] ?? 'space-y-6';
        $submitText = $formConfig['submit_text'] ?? 'Отправить';
        
        $html = '<form method="' . $method . '" action="' . $action . '" class="' . $formClass . '" data-table="' . $tableName . '">';
        
        // Добавляем скрытое поле с названием таблицы
        $html .= '<input type="hidden" name="form_table" value="' . $tableName . '">';
        
        // CSRF защита
        $html .= $this->generateCsrfField();
        
        foreach ($structure as $field) {
            if ($this->isSystemField($field['name'])) {
                continue;
            }
            
            $fieldConfig = $formConfig['fields'][$field['name']] ?? [];
            
            // Пропускаем скрытые поля
            if (isset($fieldConfig['hidden']) && $fieldConfig['hidden']) {
                continue;
            }
            
            $html .= $this->renderFormField($field, $fieldConfig);
        }
        
        // Согласие на обработку ПД (если включено в form_config или есть колонка в таблице)
        $hasConsentColumn = false;
        foreach ($structure as $col) {
            if ($col['name'] === 'pd_consent') { $hasConsentColumn = true; break; }
        }
        $consentEnabled = $formConfig['consent_enabled'] ?? $hasConsentColumn;
        $consentRequired = $formConfig['consent_required'] ?? true;
        if ($consentEnabled) {
            $reqAttr = $consentRequired ? ' required' : '';
            $html .= '
            <div class="form-field consent-field bg-gray-50 p-4 rounded-lg border border-gray-200">
                <label class="flex items-start space-x-3 cursor-pointer">
                    <input type="checkbox" name="pd_consent" value="1"' . $reqAttr . '
                           class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-700 leading-relaxed">
                        Я даю согласие на обработку моих персональных данных в соответствии с
                        <a href="/confidential" target="_blank" class="text-blue-600 hover:text-blue-800 underline">Политикой конфиденциальности</a>
                        ' . ($consentRequired ? '<span class="text-red-500">*</span>' : '') . '
                    </span>
                </label>
            </div>
            ';
        }

        // Кнопка отправки
        $html .= '
            <div class="form-submit">
                <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition font-medium">
                    ' . htmlspecialchars($submitText) . '
                </button>
            </div>
        ';
        
        $html .= '</form>';
        
        return $html;
    }
    
    /**
     * Рендер поля формы
     */
    private function renderFormField($field, $fieldConfig) {
        $name = $field['name'];
        $label = $fieldConfig['label'] ?? $this->generateLabel($name);
        $required = $fieldConfig['required'] ?? ($field['notnull'] ? true : false);
        $placeholder = $fieldConfig['placeholder'] ?? '';
        $helpText = $fieldConfig['help_text'] ?? '';
        
        $fieldType = $fieldConfig['type'] ?? $this->determineFieldType($field, $fieldConfig);
        
        $html = '<div class="form-field">';
        
        // Label
        if ($fieldType !== 'checkbox') {
            $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">' . htmlspecialchars($label);
            if ($required) {
                $html .= ' <span class="text-red-500">*</span>';
            }
            $html .= '</label>';
        }
        
        // Field
        switch ($fieldType) {
            case 'textarea':
                $rows = $fieldConfig['rows'] ?? 4;
                $html .= '<textarea name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'rows="' . $rows . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '></textarea>';
                break;
                
            case 'select':
                $options = $fieldConfig['options'] ?? [];
                $html .= '<select name="' . $name . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
                foreach ($options as $value => $optionLabel) {
                    $html .= '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($optionLabel) . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'checkbox':
                $html .= '<label class="flex items-center">';
                $html .= '<input type="checkbox" name="' . $name . '" ';
                $html .= 'class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                $html .= '<span class="text-sm font-medium text-gray-700">' . htmlspecialchars($label) . '</span>';
                if ($required) {
                    $html .= ' <span class="text-red-500">*</span>';
                }
                $html .= '</label>';
                break;
                
            case 'email':
                $html .= '<input type="email" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            case 'tel':
                $html .= '<input type="tel" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
                break;
                
            default:
                $html .= '<input type="text" name="' . $name . '" ';
                $html .= 'placeholder="' . htmlspecialchars($placeholder) . '" ';
                $html .= 'class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" ';
                $html .= ($required ? 'required' : '') . '>';
        }
        
        // Help text
        if (!empty($helpText)) {
            $html .= '<p class="mt-1 text-sm text-gray-500">' . htmlspecialchars($helpText) . '</p>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Обработка отправки формы
     */
    public function processFormSubmission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        
        // Проверяем CSRF токен
        if (!$this->validateCsrfToken()) {
            error_log("CSRF validation failed");
            return false;
        }
        
        $tableName = $_POST['form_table'] ?? '';
        if (empty($tableName) || !$this->database->tableExists($tableName)) {
            error_log("Table not found: " . $tableName);
            return false;
        }
        
        try {
            // Получаем структуру таблицы
            $structure = $this->database->getTableStructure($tableName);
            
            // Подготавливаем данные
            $formData = [];
            foreach ($structure as $field) {
                $fieldName = $field['name'];
                
                // Пропускаем системные поля
                if ($this->isSystemField($fieldName)) {
                    continue;
                }
                
                if (isset($_POST[$fieldName])) {
                    $formData[$fieldName] = trim($_POST[$fieldName]);
                }
            }
            
            // Сохраняем pd_consent если есть колонка в таблице
            $hasPdConsent = false;
            foreach ($structure as $col) { if ($col['name'] === 'pd_consent') { $hasPdConsent = true; break; } }
            if ($hasPdConsent) {
                $formData['pd_consent'] = !empty($_POST['pd_consent']) ? 1 : 0;
            }

            // Вставляем данные
            $newId = $this->database->insert($tableName, $formData);

            // Логируем согласие на обработку ПД (если была галочка)
            if (!empty($_POST['pd_consent'])) {
                $logDir = dirname(dirname(__FILE__)) . '/admin/storage/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                $logFile = $logDir . '/pd_consent.log';
                $line = date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' | ' . $tableName . ' | id=' . $newId . ' | ' . substr(($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 100) . PHP_EOL;
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            }
            


            // 🔥 ДОБАВЛЯЕМ ОТПРАВКУ УВЕДОМЛЕНИЙ ДЛЯ ПРОИЗВОЛЬНЫХ ФОРМ (дублирующая логика на удаление)
            //  $this->sendNotificationsForForm($tableName, $formData, $newId);

            return true;
            
        } catch (\Exception $e) {
            error_log("Ошибка обработки формы: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отправка уведомлений для произвольной формы
     */
    private function sendNotificationsForForm($tableName, $formData, $recordId) {
        try {
            
            // Ищем конфигурацию формы в таблице navigation
            $navItem = $this->database->query(
                "SELECT * FROM navigation WHERE source_table = ? AND page_type = 'form' AND status = 'active' LIMIT 1",
                [$tableName]
            )->fetch();

            if (!$navItem) {
                return;
            }


            // Парсим конфигурацию формы
            $formConfig = [];
            if (!empty($navItem['form_config'])) {
                $formConfig = json_decode($navItem['form_config'], true);
            } else {
                return;
            }

            $notificationsConfig = $formConfig['notifications'] ?? [];

            if (empty($notificationsConfig)) {
                return;
            }


            // Уведомление администраторам
            if (!empty($notificationsConfig['admin_notify'])) {
                error_log("👨‍💼 Отправка уведомления администраторам");
                $success = $this->emailNotifier->sendAdminNotification($formData, $notificationsConfig, $navItem['title']);
                if ($success) {
                } else {
                }
            }

            // Автоответ пользователю
            if (!empty($notificationsConfig['auto_reply'])) {
                $success = $this->emailNotifier->sendAutoReply($formData, $notificationsConfig, $navItem['title']);
                if ($success) {
                } else {
                }
            }

        } catch (\Exception $e) {
        }
    }

    /**
     * Валидация CSRF токена
     */
    private function validateCsrfToken() {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        if (empty($token) || empty($sessionToken)) {
            return false;
        }
        
        if (!hash_equals($sessionToken, $token)) {
            return false;
        }
        
        // Удаляем использованный токен
        unset($_SESSION['csrf_token']);
        return true;
    }
    
    /**
     * Проверка системных полей
     */
    private function isSystemField($fieldName) {
        $systemFields = ['id', 'created_at', 'updated_at', 'status', 'read_status', 'pd_consent'];
        return in_array($fieldName, $systemFields);
    }
    
    /**
     * Генерация человеко-читаемого label
     */
    private function generateLabel($fieldName) {
        $label = str_replace(['_', '-'], ' ', $fieldName);
        $label = ucfirst($label);
        return $label;
    }
    
    /**
     * Определение типа поля
     */
    private function determineFieldType($field, $fieldConfig) {
        $name = strtolower($field['name']);
        
        if (strpos($name, 'email') !== false) return 'email';
        if (strpos($name, 'phone') !== false || strpos($name, 'tel') !== false) return 'tel';
        if (strpos($name, 'message') !== false || strpos($name, 'content') !== false) return 'textarea';
        if (strpos($name, 'description') !== false) return 'textarea';
        
        return 'text';
    }
    
    /**
     * Генерация CSRF поля
     */
    private function generateCsrfField() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
    }
}
?>