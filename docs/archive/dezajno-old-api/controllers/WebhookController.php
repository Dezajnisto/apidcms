<?php

class WebhookController
{
    private $redisCache;
    private $urlModel;

    public function __construct($redisCache, $urlModel)
    {
        $this->redisCache = $redisCache;
        $this->urlModel = $urlModel;
    }

    public function handleWebhook()
    {
        // Получаем данные от webhook
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'No data received']);
            return;
        }

        // Проверяем, что данные содержат необходимые поля
        if (!isset($data['type']) || !isset($data['data']['table_id']) || !isset($data['data']['rows'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data format']);
            return;
        }

        $type = $data['type'];
        $tableId = $data['data']['table_id'];
        $rows = $data['data']['rows'];

        // Обрабатываем действие в зависимости от типа
        switch ($type) {
            case 'records.after.update':
                $this->handleUpdate($tableId, $rows);
                break;
            case 'records.after.create':
                $this->handleCreate($tableId, $rows);
                break;
            case 'records.after.delete':
                $this->handleDelete($tableId, $rows);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
                return;
        }

        // Возвращаем успешный ответ
        http_response_code(200);
        echo json_encode(['success' => true]);
    }

    private function handleCreate($tableId, $rows)
    {
        foreach ($rows as $row) {
            $recordId = $row['Id'];
            // Кэшируем данные в Redis
            $cacheKey = "record_{$tableId}_{$recordId}";
            $this->redisCache->set($cacheKey, $row);
        }
    }

    private function handleUpdate($tableId, $rows)
    {
        foreach ($rows as $row) {
            $recordId = $row['Id'];
            // Обновляем данные в Redis
            $cacheKey = "record_{$tableId}_{$recordId}";
            $this->redisCache->set($cacheKey, $row);
        }
    }

    private function handleDelete($tableId, $rows)
    {
        foreach ($rows as $row) {
            $recordId = $row['Id'];
            // Удаляем данные из Redis
            $cacheKey = "record_{$tableId}_{$recordId}";
            $this->redisCache->delete($cacheKey);
        }
    }
}