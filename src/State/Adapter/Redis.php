<?php

namespace OpenRuntimes\State\Adapter;

use OpenRuntimes\State\State;
use Redis as Client;

class Redis implements State
{
    /**
     * @var Client
     */
    private $redis;

    /**
     * @param Client $redis
     */
    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    public function save(string $resource, string $name, string $status, float $usage): bool
    {
        $string = json_encode([
            'status' => $status,
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);

        return $this->redis->hSet($resource, $name, $string) !== false;
    }

    public function list(string $resource): array
    {
        $entries = $this->redis->hGetAll($resource) ?: [];
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
        $pipeline = $this->redis->multi();

        foreach ($entries as $key => $value) {
            if (!isset($value['status'], $value['usage'])) {
                continue;
            }
            $string = json_encode([
                'status' => $value['status'],
                'usage' => $value['usage'],
            ], JSON_THROW_ON_ERROR);
            $pipeline->hSet($resource, $key, $string);
        }
        $results = $pipeline->exec();

        return $results !== false;
    }

    public function remove(string $resource, string $name): bool
    {
        return (bool)$this->redis->hDel($resource, $name);
    }

    public function flush(): bool
    {
        return $this->redis->flushAll();
    }
}
