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
     * @param  int     $usage
     *
     * @return bool
     */
    public function save(string $resource, string $name, string $status, int $usage): bool
    {
        $string = json_encode([
            'status' => $status,
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);

        $this->adapter->save(
            key: $name,
            data: json_encode($string, JSON_THROW_ON_ERROR),
            hash: $resource
        );

        return true;
    }

    /**
     * Get all executors status
     *
     * @param string  $resource
     * 
     * @return array<string, array<string, mixed>>
     */
    public function list($resource): array
    {
        $entries = $this->adapter->getAll($resource);

        $objects = [];
        foreach ($entries as $key => $value) {
            $json = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
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

        $this->adapter->saveAll(
            entries: $strings,
            hash: $resource
        );

        return true;
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
