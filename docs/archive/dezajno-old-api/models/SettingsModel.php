<?php

namespace App\Models;

class SettingsModel extends NocoDBModel
{
    private $tableId;

    public function __construct($apiUrl, $apiToken, $tableId, $cache, $cacheTtl)
    {
        parent::__construct($apiUrl, $apiToken, $cache, $cacheTtl);
        $this->tableId = $tableId;
    }

    public function getSettings()
    {
        $url = "{$this->apiUrl}/tables/{$this->tableId}/records";
        return $this->sendRequest($url);
    }

    public function getSettingByKey($key)
    {
        $url = "{$this->apiUrl}/tables/{$this->tableId}/records?where=(key,eq,'{$key}')";
        $response = $this->sendRequest($url);
        $data = json_decode($response, true);

        if (!empty($data) && isset($data[0])) {
            return $data[0];
        }

        return null;
    }

    public function getSettingByRecordId($recordId)
    {
        $url = "{$this->apiUrl}/tables/{$this->tableId}/records/{$recordId}";
        $response = $this->sendRequest($url);

        if (is_array($response)) {
            $data = $response;
        } else {
            $data = json_decode($response, true);
        }

        if (!empty($data)) {
            return $data;
        }

        return null;
    }
}