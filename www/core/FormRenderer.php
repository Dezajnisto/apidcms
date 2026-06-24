<?php
/**
 * FormRenderer — рендеринг и обработка форм
 *
 * Новая версия: использует Twig-шаблоны вместо генерации HTML строками.
 * Конфигурация загружается через FormManager из таблицы forms.
 */

namespace Core;

class FormRenderer {
    private $database;
    private $twig;
    private $formManager;
    private $config;

    public function __construct($database, $twig = null, $config = []) {
        $this->database = $database;
        $this->twig = $twig;
        $this->config = $config;
        $this->formManager = new FormManager($database);
    }

    /**
     * Задать Twig (если не был передан в конструктор)
     */
    public function setTwig($twig): void {
        $this->twig = $twig;
    }

    /**
     * Получить FormManager
     */
    public function getFormManager(): FormManager {
        return $this->formManager;
    }

    /**
     * Рендеринг формы по имени
     *
     * @param string $formName Имя формы из таблицы forms
     * @param array $options   Опции переопределения:
     *                         - template    : шаблон формы
     *                         - submit_text : текст кнопки
     *                         - submit_class: CSS класс кнопки
     *                         - field_class : CSS класс полей
     *                         - form_class  : CSS класс формы
     *                         - action      : URL обработчика
     * @return string HTML формы
     */
    public function renderForm(string $formName, array $options = []): string {
        if (!$this->twig) {
            return '<!-- FormRenderer: Twig not initialized -->';
        }

        $form = $this->formManager->getForm($formName);
        if (!$form) {
            return '<!-- Form "' . htmlspecialchars($formName) . '" not found -->';
        }

        // Определяем шаблон
        $templateName = $options['template'] ?? $form['template'] ?? 'default';

        // Проверяем pd_consent
        $showConsent = in_array('pd_consent', $form['table_columns']);

        // CSRF
        $enableCsrf = $form['enable_csrf'] ?? 1;
        $csrfToken = '';
        if ($enableCsrf) {
            $csrfToken = $this->generateCsrfToken();
        }

        // Собираем данные для шаблона
        $data = [
            'form_name'      => $formName,
            'form_hash'      => $options['form_hash'] ?? '',
            'fields'         => $form['fields'],
            'show_consent'   => $showConsent,
            'enable_csrf'    => $enableCsrf,
            'csrf_token'     => $csrfToken,
            'action'         => $options['action'] ?? '/form-handler',
            'submit_text'    => $options['submit_text'] ?? $form['design']['submit_text'] ?? 'Отправить',
            'submit_class'   => $options['submit_class'] ?? $form['design']['submit_class'] ?? '',
            'field_class'    => $options['field_class'] ?? $form['design']['field_class'] ?? '',
            'label_class'    => $options['label_class'] ?? $form['design']['label_class'] ?? '',
            'form_class'     => $options['form_class'] ?? $form['design']['form_class'] ?? '',
            'success_message'=> $options['success_message'] ?? $form['success_message'] ?? 'Форма успешно отправлена!',
            'source_table'   => $form['source_table'],
            'hidden_fields'  => $options['hidden_fields'] ?? [],
            'form_attrs'     => $options['form_attrs'] ?? '',
            'field_defaults' => $options['field_defaults'] ?? [],
        ];

        try {
            $html = $this->twig->render('form/' . $templateName . '.html.twig', $data);
        } catch (\Twig\Error\LoaderError $e) {
            // Если шаблон не найден — используем default
            $html = $this->twig->render('form/default.html.twig', $data);
        }

        return $html;
    }

    /**
     * Обработка отправки формы
     *
     * @return array ['success' => bool, 'message' => string, 'errors' => array, 'id' => int|null]
     */
    public function processSubmission(): array {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => 'Метод не поддерживается'];
        }

        $formName = $_POST['form_name'] ?? '';
        if (empty($formName)) {
            return ['success' => false, 'message' => 'Имя формы не указано'];
        }

        $form = $this->formManager->getForm($formName);
        if (!$form) {
            return ['success' => false, 'message' => 'Форма не найдена'];
        }

        // Проверка CSRF
        $enableCsrf = $form['enable_csrf'] ?? 1;
        if ($enableCsrf && !$this->validateCsrfToken()) {
            return ['success' => false, 'message' => 'Недействительный CSRF токен'];
        }

        // Валидация полей
        $errors = $this->formManager->validateFormData($formName, $_POST);
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Проверьте правильность заполнения формы', 'errors' => $errors];
        }

        // Подготавливаем данные только для полей, которые есть в таблице
        $savableFields = $this->formManager->getSavableFields($formName);
        $formData = [];

        foreach ($savableFields as $fieldName => $fieldConfig) {
            if ($fieldName === 'pd_consent') {
                $formData[$fieldName] = !empty($_POST[$fieldName]) ? 1 : 0;
            } elseif (isset($_POST[$fieldName])) {
                $formData[$fieldName] = trim($_POST[$fieldName]);
            }
        }

        // Если в таблице есть read_status — устанавливаем "unread"
        if (in_array('read_status', $form['table_columns']) && empty($formData['read_status'])) {
            $formData['read_status'] = 'unread';
        }

        // Вставляем данные
        try {
            $newId = $this->database->insert($form['source_table'], $formData);

            // Логируем согласие на ПД
            if (!empty($_POST['pd_consent'])) {
                $this->logPdConsent($form['source_table'], $newId);
            }

            // Отправляем уведомления
            $this->sendNotifications($form, $formData, $newId);

            $responseMessage = $_POST['_success_message'] ?? $form['success_message'] ?? 'Форма успешно отправлена!';

            return [
                'success' => true,
                'message' => $responseMessage,
                'id' => $newId
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Ошибка сохранения: ' . $e->getMessage()];
        }
    }

    /**
     * Отправка уведомлений (админу + автоответ)
     */
    private function sendNotifications(array $form, array $formData, int $recordId): void {
        $notifications = $form['notifications'] ?? [];

        if (empty($notifications)) {
            return;
        }

        try {
            $settings = new \Core\Settings($this->database);
            $emailConfig = $settings->getEmailConfig();
            $emailNotifier = new EmailNotifier($emailConfig);

            $formTitle = $form['display_name'] ?? $form['name'];

            // Уведомление админу
            if (!empty($notifications['admin_notify'])) {
                $emailNotifier->sendAdminNotification($formData, $notifications, $formTitle);
            }

            // Автоответ пользователю
            if (!empty($notifications['auto_reply'])) {
                $emailNotifier->sendAutoReply($formData, $notifications, $formTitle);
            }
        } catch (\Exception $e) {
            error_log("Form notification error: " . $e->getMessage());
        }
    }

    /**
     * Логирование согласия на обработку ПД
     */
    private function logPdConsent(string $tableName, int $recordId): void {
        $logDir = dirname(dirname(__FILE__)) . '/admin/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/pd_consent.log';
        $line = date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' | '
              . $tableName . ' | id=' . $recordId . ' | '
              . substr(($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 100) . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Генерация CSRF токена
     */
    private function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Валидация CSRF токена
     */
    private function validateCsrfToken(): bool {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($sessionToken)) {
            return false;
        }
        if (!hash_equals($sessionToken, $token)) {
            return false;
        }
        // Токен можно использовать повторно в рамках сессии (удобно для AJAX)
        return true;
    }
}
