<?php

namespace App\Models;

class UrlModel extends NocoDBModel
{
    public function __construct($apiUrl, $apiToken, $cache, $cacheTtl)
    {
        parent::__construct($apiUrl, $apiToken, $cache, $cacheTtl);
    }

    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    public function getApiToken()
    {
        return $this->apiToken;
    }

    public function getPageBySlug($slug, $tableId)
    {
        // Убираем косую черту из начала slug, если она есть
        $slug = ltrim($slug, '/');
        $filter = urlencode("(Slug,eq,{$slug})"); // Правильно кодируем фильтр
        $url = "{$this->apiUrl}/tables/{$tableId}/records?where={$filter}";
        $response = $this->sendRequest($url);

        if ($response === null) {
            return null;
        }

        if (is_array($response)) {
            $data = $response;
        } else {
            $data = json_decode($response, true);
        }

        if (!empty($data) && isset($data['list'][0])) {
            return $data['list'][0];
        }

        return null;
    }

    public function getRecordsByGroup($tableId, $group, $offset = 0, $limit = 10, $sort = '')
    {

        $filter = urlencode("(Group,eq,{$group})~and(Type,neq,List)");
        $url = "{$this->apiUrl}/tables/{$tableId}/records?where={$filter}&offset={$offset}&limit={$limit}";
        
        if (!empty($sort)) {
            $url .= "&sort=" . urlencode($sort);
        }
    
        $response = $this->sendRequest($url);
    
        if ($response === null) {
            return [];
        }
    
        if (is_array($response)) {
            $data = $response;
        } else {
            $data = json_decode($response, true);
        }
    
        if (!empty($data) && isset($data['list'])) {
            return $data['list'];
        }
    
        return [];
    }
    
    public function getTotalRecordsByGroup($tableId, $group)
    {
        $filter = urlencode("(Group,eq,{$group})~and(Type,neq,List)");
        $url = "{$this->apiUrl}/tables/{$tableId}/records/count?where={$filter}";
        $response = $this->sendRequest($url);
        
        if ($response === null) {
            return 0;
        }
    
        if (is_array($response)) {
            $data = $response;
        } else {
            $data = json_decode($response, true);
        }
    
        if (!empty($data) && isset($data['count'])) {
            return $data['count'];
        }
    
        return 0;
    }

    public function getPluginsByTemplate($tableId, $template)
    {
        $filter = urlencode("(Template,eq,{$template})");
        $url = "{$this->apiUrl}/tables/{$tableId}/records?where={$filter}";
        $response = $this->sendRequest($url);

        if ($response === null) {
            return [];
        }

        if (is_array($response)) {
            $data = $response;
        } else {
            $data = json_decode($response, true);
        }

        if (!empty($data) && isset($data['list'])) {
            return $data['list'];
        }

        return [];
    }

    public function getGlobalPlugins($tableId)
{
    $filter = urlencode("(Global,eq,true)");
    $url = "{$this->apiUrl}/tables/{$tableId}/records?where={$filter}";
    $response = $this->sendRequest($url);

    if ($response === null) {
        return [];
    }

    if (is_array($response)) {
        $data = $response;
    } else {
        $data = json_decode($response, true);
    }

    if (!empty($data) && isset($data['list'])) {
        return $data['list'];
    }

    return [];
}

    public function getRouteByPath($path, $tableId)
    {
        $filter = "(path,eq,'{$path}')";
        $response = $this->getRecordsByFilter($tableId, $filter);
        $data = json_decode($response, true);

        if (!empty($data) && isset($data['list'][0])) {
            return $data['list'][0];
        }

        return null;
    }

    public function getRouteByRecordId($recordId, $tableId)
    {
        $response = $this->getRecord($tableId, $recordId);
        $data = json_decode($response, true);

        if (!empty($data)) {
            return $data;
        }

        return null;
    }

    public function getAllRoutes($tableId)
    {
        $response = $this->getAllRecords($tableId);
        $data = json_decode($response, true);

        if (!empty($data) && isset($data['list'])) {
            return $data['list'];
        }

        echo "No routes found\n"; // Добавьте эту строку для отладки
        return [];
    }
}