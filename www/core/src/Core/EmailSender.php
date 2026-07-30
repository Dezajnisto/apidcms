<?php
/**
 * Универсальный класс для отправки email через API или SMTP
 * Без привязки к конкретным провайдерам
 */

namespace Core;

class EmailSender {
    private $config;
    
    /**
     * Конструктор
     */
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Основной метод отправки email
     */
    public function send($to, $subject, $message, $isHtml = true) {
        $primaryDriver = $this->config['driver'] ?? 'api';
        
        // Порядок fallback
        $fallbackOrder = $this->getFallbackOrder($primaryDriver);
        
        foreach ($fallbackOrder as $driver) {
            try {
                    
                $result = $this->sendWithDriver($driver, $to, $subject, $message, $isHtml);
                
                if ($result) {
                    return true;
                }
                
            } catch (\Exception $e) {
                error_log("Ошибка отправки через {$driver}: " . $e->getMessage());
                continue;
            }
        }
        
        error_log("Все методы отправки email не удались");
        return false;
    }
    
    /**
     * Определяет порядок fallback методов
     */
    private function getFallbackOrder($primaryDriver) {
        $allDrivers = ['api', 'smtp', 'mail'];
        
        // Ставим основной драйвер первым
        $order = [$primaryDriver];
        
        // Добавляем остальные драйверы
        foreach ($allDrivers as $driver) {
            if ($driver !== $primaryDriver) {
                $order[] = $driver;
            }
        }
        
        return $order;
    }
    
    /**
     * Отправка через конкретный драйвер
     */
    private function sendWithDriver($driver, $to, $subject, $message, $isHtml) {
        switch ($driver) {
            case 'api':
                return $this->sendViaApi($to, $subject, $message, $isHtml);
                
            case 'smtp':
                return $this->sendViaSmtp($to, $subject, $message, $isHtml);
                
            case 'mail':
                return $this->sendViaMail($to, $subject, $message, $isHtml);
                
            default:
                throw new \Exception("Неизвестный драйвер: " . $driver);
        }
    }
    
    /**
     * Отправка через API провайдера (универсальная)
     */
    private function sendViaApi($to, $subject, $message, $isHtml) {
        $apiConfig = $this->config['api'] ?? [];
        
        if (empty($apiConfig['key']) || empty($apiConfig['endpoint'])) {
            throw new \Exception('API настройки неполные: нужны ключ и endpoint');
        }
        
        // Детальное логирование
        
        // Формат данных для smtp.bz API
        $postData = [
            'from' => $this->config['from']['email'] ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'name' => $this->config['from']['name'] ?: 'Site Notification',
            'to' => $to,
            'subject' => $subject,
            'html' => $isHtml ? $message : '',
            'text' => $isHtml ? $this->htmlToText($message) : $message
        ];
        
        // Убираем полностью пустые значения
        $postData = array_filter($postData, function($value) {
            return $value !== null && $value !== '';
        });
        
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiConfig['endpoint'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $apiConfig['key'],
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false, // На время отладки
            CURLOPT_SSL_VERIFYHOST => false  // На время отладки
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Детальное логирование ответа
        
        curl_close($ch);
        
        if ($error) {
            throw new \Exception('Ошибка API запроса: ' . $error);
        }
        
        // Парсим ответ для получения деталей ошибки
        $responseData = json_decode($response, true);
        
        // Универсальная проверка - любой HTTP 200-299 считается успехом
        if ($httpCode >= 200 && $httpCode < 300) {
            // Дополнительная проверка для smtp.bz
            if (isset($responseData['success']) && $responseData['success'] === true) {
                return true;
            } elseif (!isset($responseData['success'])) {
                // Если нет поля success, считаем успехом по HTTP коду
                return true;
            }
        }
        
        // Детальный анализ ошибки
        $errorMsg = 'Неизвестная ошибка API';
        if (is_array($responseData)) {
            $errorMsg = $responseData['message'] ?? $responseData['error'] ?? $errorMsg;
            if (isset($responseData['errors'])) {
                $errorMsg .= " | Errors: " . json_encode($responseData['errors']);
            }
        } else {
            $errorMsg = $response;
        }
        
        throw new \Exception("API вернул HTTP {$httpCode}: {$errorMsg}");
    }
    
    /**
     * Отправка через SMTP (упрощенная реализация)
     */
    private function sendViaSmtp($to, $subject, $message, $isHtml) {
        $smtpConfig = $this->config['smtp'] ?? [];
        
        // Проверяем наличие необходимых настроек
        if (empty($smtpConfig['host']) || empty($smtpConfig['username']) || empty($smtpConfig['password'])) {
            throw new \Exception('SMTP настройки неполные: нужны хост, логин и пароль');
        }
        
        // Временно используем стандартную функцию mail()
        // В production можно добавить PHPMailer или другую SMTP библиотеку

        return $this->sendViaMail($to, $subject, $message, $isHtml);
    }
    
    /**
     * Стандартная отправка через mail()
     */
    private function sendViaMail($to, $subject, $message, $isHtml) {
        $fromEmail = $this->config['from']['email'];
        $fromName = $this->config['from']['name'];
        
        // Если from email не указан, генерируем из домена
        if (empty($fromEmail)) {
            $fromEmail = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        
        // Если from name не указан, используем домен
        if (empty($fromName)) {
            $fromName = 'Site Notification';
        }
        
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'Return-Path: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: ' . ($isHtml ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8'),
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3'
        ];
        
        // Для HTML писем добавляем plain text версию
        if ($isHtml) {
            $boundary = uniqid('np');
            
            $headers = [
                'From: ' . $fromName . ' <' . $fromEmail . '>',
                'Reply-To: ' . $fromEmail,
                'Return-Path: ' . $fromEmail,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'X-Mailer: PHP/' . phpversion()
            ];
            
            $textVersion = $this->htmlToText($message);
            
            $body = "--" . $boundary . "\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $textVersion . "\r\n\r\n" .
                   "--" . $boundary . "\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $message . "\r\n\r\n" .
                   "--" . $boundary . "--";
            
            return mail($to, $subject, $body, implode("\r\n", $headers));
        }
        
        return mail($to, $subject, $message, implode("\r\n", $headers));
    }
    
    /**
     * Конвертирует HTML в plain text
     */
    private function htmlToText($html) {
        // Простая конвертация - убираем теги и нормализуем пробелы
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
}
?>