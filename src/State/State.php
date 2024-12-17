<?php

namespace OpenRuntimes\State;

class State
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Save executor status
     *
     * @param  string  $resource
     * @param  string  $name
     * @param  string  $status
     * @param  float   $usage
     *
     * @return bool
     */
    public function save(string $resource, string $name, string $status, float $usage): bool
    {
        $string = json_encode([
            'status' => $status,
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);

        return $this->adapter->save(
            key: $name,
            data: $string,
            hash: $resource
        );
    }

    /**
     * Get all executors status
     *
     * @param string  $resource
     *
     * @return array<string, array<string, mixed>>
     */
    public function list(string $resource): array
    {
        $entries = $this->adapter->getAll($resource);

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

    /**
     * Save multiple entries
     *
     * @param  string  $resource
     * @param  array<string, array<string, mixed>>  $entries
     *
     * @return bool
     */
    public function saveAll(string $resource, array $entries): bool
    {
        $strings = [];
        foreach ($entries as $key => $value) {
            if (!isset($value['status'], $value['usage'])) {
                continue;
            }

            $strings[$key] = json_encode([
                'status' => $value['status'],
                'usage' => $value['usage'],
            ], JSON_THROW_ON_ERROR);
        }

        return $this->adapter->saveAll(
            entries: $strings,
            hash: $resource
        );
    }

    public function remove(string $resource, string $name): bool
    {
        return $this->adapter->remove(
            key: $name,
            hash: $resource
        );
    }

    public function removeAll(string $resource): bool
    {
        return $this->adapter->removeAll($resource);
    }

    /**
     * Purge executors
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->adapter->flush();
    }
}
