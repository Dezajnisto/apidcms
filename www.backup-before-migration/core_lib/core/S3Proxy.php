<?php
/**
 * Простой прокси для S3 файлов
 * Решает проблемы CORS и временных токенов
 */

namespace Core;

class S3Proxy {
    
    /**
     * Базовый URL S3 хранилища
     */
    private $s3BaseUrl = 'https://hb.ru-msk.vkcloud-storage.ru';
    
    /**
     * Проксирует запрос к S3 и возвращает файл
     */
    public function proxyFile($s3Path) {
        // Формируем полный URL к S3
        $s3Url = $this->s3BaseUrl . '/' . ltrim($s3Path, '/');
        
        // Инициализируем cURL
        $ch = curl_init();
        
        // Настраиваем cURL
        curl_setopt_array($ch, [
            CURLOPT_URL => $s3Url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        // Выполняем запрос
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        curl_close($ch);
        
        // Если файл получен успешно
        if ($httpCode === 200 && $fileContent) {
            // Устанавливаем правильный Content-Type
            if ($contentType) {
                header('Content-Type: ' . $contentType);
            }
            
            // Кэшируем в браузере (1 день)
            header('Cache-Control: public, max-age=86400');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
            
            // Отдаем содержимое файла
            echo $fileContent;
            exit;
        }
        
        // Если ошибка
        http_response_code($httpCode ?: 404);
        echo 'File not found';
        exit;
    }
    
    /**
     * Генерирует URL для прокси
     */
    public function getProxyUrl($s3Path) {
        return '/s3-proxy/' . ltrim($s3Path, '/');
    }
}