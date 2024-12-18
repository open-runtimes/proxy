<?php

namespace OpenRuntimes\State\Adapter;

use OpenRuntimes\State\State;
use RedisCluster as Client;

class RedisCluster implements State
{
    /**
     * @var Client
     */
    private $redisCluster;

    /**
     * @param Client $redisCluster
     */
    public function __construct(Client $redisCluster)
    {
        $this->redisCluster = $redisCluster;
    }

    public function save(string $resource, string $name, string $status, float $usage): bool
    {
        $data = json_encode([
            'status' => $status,
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);

        return $this->redisCluster->hSet($resource, $name, $data) !== false;
    }

    public function list(string $resource): array
    {
        $entries = $this->redisCluster->hGetAll($resource) ?: [];

        $objects = [];
        foreach ($entries as $key => $value) {
            $json = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            $objects[$key] = [
                'status' => $json['status'] ?? null,
                'usage' => $json['usage'] ?? 0,
            ];
        }

        return $objects;
    }

    public function saveAll(string $resource, array $entries): bool
    {
        // RedisCluster doesn't support pipeline in the same way single Redis does
        foreach ($entries as $key => $value) {
            if (!isset($value['status'], $value['usage'])) {
                continue;
            }
            $data = json_encode([
                'status' => $value['status'],
                'usage' => $value['usage'],
            ], JSON_THROW_ON_ERROR);

            $result = $this->redisCluster->hSet($resource, $key, $data);
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    public function remove(string $resource, string $name): bool
    {
        return (bool)$this->redisCluster->hDel($resource, $name);
    }

    public function flush(): bool
    {
        foreach ($this->redisCluster->_masters() as $master) {
            $result = $master->flushAll();
            if ($result === false) {
                return false;
            }
        }
        return true;
    }
}
