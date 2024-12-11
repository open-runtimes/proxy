<?php

namespace OpenRuntimes\State;

use Swoole\Table as Table;

class Cache
{
    /**
     * @var Table
     */
    private $table;

    public function __construct(Table $table)
    {
        $this->table = $table;
    }

    public function get(string $key): ?array
    {
        $row = $this->table->get($key);
        if (!$row) {
            return null;
        }

        // Check TTL
        $now = time();
        if ($row['expires_at'] < $now) {
            $this->table->del($key);
            return null;
        }

        return [
            'status' => $row['status'],
            'usage' => $row['usage'],
        ];
    }

    public function getAll(string $resource): array
    {
        $now = time();
        $results = [];
        foreach ($this->table as $key => $row) {
            if (str_starts_with($key, $resource.':')) {
                if ($row['expires_at'] >= $now) {
                    $hostname = substr($key, strlen($resource) + 1);
                    $results[$hostname] = [
                        'status' => $row['status'],
                        'usage' => $row['usage'],
                    ];
                } else {
                    $this->table->del($key);
                }
            }
        }

        return $results;
    }

    public function set(string $key, array $value, int $ttl): bool
    {
        $expiresAt = time() + $ttl;
        return $this->table->set($key, [
            'status' => $value['status'],
            'usage' => (float) $value['usage'],
            'expires_at' => $expiresAt,
        ]);
    }

    public function setAll(string $resource, array $entries, int $ttl): bool
    {
        $expiresAt = time() + $ttl;
        foreach ($entries as $hostname => $value) {
            $key = $resource . ':' . $hostname;
            $this->table->set($key, [
                'status' => $value['status'],
                'usage' => (float) $value['usage'],
                'expires_at' => $expiresAt,
            ]);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        return $this->table->del($key);
    }

    public function clear(): bool
    {
        foreach ($this->table as $key => $row) {
            $this->table->del($key);
        }
        return true;
    }
}
