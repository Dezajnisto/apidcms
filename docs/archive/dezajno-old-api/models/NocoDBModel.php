<?php

namespace App\Models;

class NocoDBModel
{
    protected $apiUrl;
    protected $apiToken;
    protected $cache;
    protected $cacheTtl;

    public function __construct($apiUrl, $apiToken, $cache, $cacheTtl = null)
    {
        $this->apiUrl = $apiUrl;
        $this->apiToken = $apiToken;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    protected function sendRequest($url, $method = 'GET', $data = [])
    {
        
        // Генерация ключа на основе URL и данных запроса
        $cacheKey = md5($url . json_encode($data));
        
        $cachedResponse = $this->cache->get($cacheKey);

        if ($cachedResponse) {
            return is_array($cachedResponse) ? $cachedResponse : json_decode($cachedResponse, true);
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                "xc-token: {$this->apiToken}"
            ],
        ]);

        if ($method === 'POST' || $method === 'PATCH') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return json_encode(['error' => "cURL Error #: {$err}"]);
        } else {
            $decodedResponse = json_decode($response, true);
            $this->cache->set($cacheKey, $decodedResponse, $this->cacheTtl); // Используем срок жизни кэша
            return $decodedResponse; // Возвращаем декодированный массив
        }
    }

    public function getRecord($tableId, $recordId)
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records/{$recordId}";
        $cacheKey = "record_{$tableId}_{$recordId}"; // Используем ключ на основе идентификатора таблицы и идентификатора записи
        $cachedResponse = $this->cache->get($cacheKey);

        if ($cachedResponse) {
            return is_array($cachedResponse) ? $cachedResponse : json_decode($cachedResponse, true);
        }

        $response = $this->sendRequest($url);
        $this->cache->set($cacheKey, $response, $this->cacheTtl); // Кэшируем ответ с ключом на основе идентификатора таблицы и идентификатора записи
        return $response;
    }

    public function createRecord($tableId, $data)
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records";
        return $this->sendRequest($url, 'POST', $data);
    }

    public function updateRecord($tableId, $recordId, $data)
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records/{$recordId}";
        return $this->sendRequest($url, 'PATCH', $data);
    }

    public function deleteRecord($tableId, $recordId)
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records/{$recordId}";
        return $this->sendRequest($url, 'DELETE');
    }

    public function getAllRecords($tableId)
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records";
        return $this->sendRequest($url);
    }

    public function getRecordsByFilter($tableId, $filter, $sort = [], $limit = [])
    {
        $url = "{$this->apiUrl}/tables/{$tableId}/records?where={$filter}";
        if (!empty($sort)) {
            $url .= "&sort=" . implode(",", $sort);
        }
        if (!empty($limit)) {
            $url .= "&limit=" . implode(",", $limit);
        }
        return $this->sendRequest($url);
    }
}