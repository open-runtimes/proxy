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
     * Set resource entry
     *
     * @param  string  $resource
     * @param  string  $name
     * @param  string  $status
     * @param  float   $usage
     *
     * @return bool
     */
    public function set(string $resource, string $name, string $status, float $usage): bool
    {
        $string = json_encode([
            'status' => $status,
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);

        return $this->adapter->set(
            key: $name,
            data: $string,
            hash: $resource
        );
    }

    /**
     * Get all resource entries
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
     * Save multiple resource entries
     *
     * @param  string  $resource
     * @param  array<string, array<string, mixed>>  $entries
     *
     * @return bool
     */
    public function setAll(string $resource, array $entries): bool
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

        return $this->adapter->setAll(
            entries: $strings,
            hash: $resource
        );
    }

    /**
     * Purge resources
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->adapter->flush();
    }
}
