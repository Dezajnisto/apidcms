<?php

namespace App\Models;

use Predis\Client;

class RedisCache
{
    private $client;

    public function __construct($config)
    {
        $this->client = new Client([
            'scheme' => 'tcp',
            'host'   => $config['redis']['host'],
            'port'   => $config['redis']['port'],
        ]);
    }

    public function get($key)
    {
        $value = $this->client->get($key);
        return $value ? json_decode($value, true) : null;
    }

    public function set($key, $value, $ttl = null)
    {
        $serializedValue = is_array($value) ? json_encode($value) : $value;
        if ($ttl) {
            $this->client->setex($key, $ttl, $serializedValue);
        } else {
            $this->client->set($key, $serializedValue);
        }
    }

    public function delete($key)
    {
        $this->client->del([$key]);
    }

    public function flushAll()
    {
        $this->client->flushdb();
    }
}