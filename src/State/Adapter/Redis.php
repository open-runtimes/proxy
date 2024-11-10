<?php

namespace OpenRuntimes\State\Adapter;

use Redis as Client;
use Throwable;
use OpenRuntimes\State\Adapter;

class Redis implements Adapter
{
    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * Redis constructor.
     *
     * @param  Client  $redis
     */
    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    /**
     * @param  string  $key
     * @param  string  $data
     * @param  string  $hash
     * @return bool|string
     */
    public function save(string $key, string $data, string $hash): bool|string
    {
        if (empty($key) || empty($data)) {
            return false;
        }

        try {
            $this->redis->hSet($key, $data, $hash);

            return $data;
        } catch (Throwable $th) {
            return false;
        }
    }

    /**
     * @param  array<string,string>  $entries
     * @param  string  $hash
     *
     * @return bool|array<string,string>
     */
    public function saveAll(array $entries, string $hash): bool|array
    {
        if (empty($hash) || empty($entries)) {
            return false;
        }

        try {
            $this->redis->hMSet($hash, $entries);

            return $entries;
        } catch (Throwable $th) {
            return false;
        }
    }

    /**
     * @param  string  $hash
     * @return array<string, string>
     */
    public function getAll(string $hash): array
    {
        $keys = $this->redis->hGetAll($hash);

        if (empty($keys)) {
            return [];
        }

        return $keys;
    }

    public function flush(): bool
    {
        return $this->redis->flushAll();
    }
}
