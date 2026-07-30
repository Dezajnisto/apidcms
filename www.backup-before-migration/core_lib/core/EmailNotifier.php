<?php
/**
 * Класс для отправки email уведомлений форм
 * Использует универсальный EmailSender
 */

namespace Core;

class EmailNotifier {
    private $config;
    private $emailSender;
    
    /**
     * Конструктор
     */
    public function __construct($config = []) {
        $this->config = array_merge([
            'from_email' => 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'from_name' => 'Site Notification',
            'charset' => 'UTF-8'
        ], $config);
        
        // Инициализируем отправитель
        $this->emailSender = new EmailSender($config);
    }
    
    /**
     * Отправить email администратору о новой заявке
     */
    public function sendAdminNotification($formData, $notificationConfig, $formTitle) {
        if (empty($notificationConfig['admin_emails']) || !$notificationConfig['admin_notify']) {
            return false;
        }
        
        $subject = $notificationConfig['admin_subject'] ?? 'Новая заявка с сайта';
        $emails = is_array($notificationConfig['admin_emails']) ? 
                 $notificationConfig['admin_emails'] : 
                 [$notificationConfig['admin_emails']];
        
        // Формируем тело письма
        $message = $this->buildAdminEmailTemplate($formData, $formTitle);
        
        $results = [];
        foreach ($emails as $email) {
            $cleanEmail = trim($email);
            if (filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                $results[$cleanEmail] = $this->emailSender->send(
                    $cleanEmail,
                    $subject,
                    $message,
                    true // HTML формат
                );

            } else {
                error_log("Неверный email администратора: " . $cleanEmail);
                $results[$cleanEmail] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Отправить автоответ пользователю
     */
    public function sendAutoReply($formData, $notificationConfig, $formTitle) {
        if (!$notificationConfig['auto_reply'] || empty($notificationConfig['auto_reply_field'])) {
            return false;
        }
        
        $userEmail = $formData[$notificationConfig['auto_reply_field']] ?? '';
        if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("Неверный email пользователя для автоответа: " . $userEmail);
            return false;
        }
        
        $subject = $notificationConfig['auto_reply_subject'] ?? 'Мы получили ваше сообщение';
        $message = $this->buildAutoReplyTemplate($formData, $formTitle);
        
        $result = $this->emailSender->send(
            $userEmail,
            $subject,
            $message,
            true
        );
        
        error_log("Не удалось отправить автоответ {$userEmail}");
        
        return $result;
    }
    
    /**
     * Шаблон письма администратору
     */
    private function buildAdminEmailTemplate($formData, $formTitle) {
        // ... существующий код без изменений ...
        // Оставляем ваш текущий шаблон
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"{$this->config['charset']}\">
            <title>Новая заявка</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .field { margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 3px; }
                .field-label { font-weight: bold; color: #495057; }
                .field-value { margin-top: 5px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"header\">
                    <h1>📧 Новая заявка: {$formTitle}</h1>
                    <p>Время: " . date('d.m.Y H:i:s') . "</p>
                    <p>IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'неизвестен') . "</p>
                </div>
        ";
        
        foreach ($formData as $field => $value) {
            if (!empty($value)) {
                $fieldLabel = $this->generateFieldLabel($field);
                $html .= "
                <div class=\"field\">
                    <div class=\"field-label\">{$fieldLabel}:</div>
                    <div class=\"field-value\">" . nl2br(htmlspecialchars($value)) . "</div>
                </div>
                ";
            }
        }
        
        $html .= "
                <div class=\"footer\">
                    <p>Это письмо отправлено автоматически с сайта " . ($_SERVER['HTTP_HOST'] ?? '') . "</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $html;
    }
    
    /**
     * Шаблон автоответа
     */
    private function buildAutoReplyTemplate($formData, $formTitle) {
        // ... существующий код без изменений ...
        $userName = $formData['name'] ?? 'клиент';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"{$this->config['charset']}\">
            <title>Подтверждение получения</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding: 20px; }
                .content { padding: 20px; background: #f8f9fa; border-radius: 5px; }
                .footer { margin-top: 30px; text-align: center; color: #6c757d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"header\">
                    <h1>Спасибо за ваше сообщение!</h1>
                </div>
                <div class=\"content\">
                    <p>Уважаемый(ая) <strong>{$userName}</strong>,</p>
                    <p>Мы получили ваше сообщение через форму <strong>{$formTitle}</strong> и свяжемся с вами в ближайшее время.</p>
                    <p><strong>Краткая информация о вашей заявке:</strong></p>
                    <ul>
        ";
        
        foreach ($formData as $field => $value) {
            if (!empty($value) && !in_array($field, ['id', 'created_at'])) {
                $fieldLabel = $this->generateFieldLabel($field);
                $html .= "<li><strong>{$fieldLabel}:</strong> " . htmlspecialchars($value) . "</li>";
            }
        }
        
        $html .= "
                    </ul>
                    <p>Если у вас есть срочный вопрос, вы можете связаться с нами по телефону.</p>
                </div>
                <div class=\"footer\">
                    <p>С уважением,<br>Команда сайта " . ($_SERVER['HTTP_HOST'] ?? '') . "</p>
                    <p>Это письмо отправлено автоматически, пожалуйста, не отвечайте на него.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Генерация читаемого названия поля
     */
    private function generateFieldLabel($fieldName) {
        $labels = [
            'name' => 'Имя',
            'email' => 'Email',
            'phone' => 'Телефон',
            'message' => 'Сообщение',
            'subject' => 'Тема'
        ];
        
        return $labels[$fieldName] ?? ucfirst(str_replace('_', ' ', $fieldName));
    }
}
?>