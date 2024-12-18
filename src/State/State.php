<?php

namespace OpenRuntimes\State;

interface State
{
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
    public function save(string $resource, string $name, string $status, float $usage): bool;

    /**
     * Get all executors status
     *
     * @param string  $resource
     *
     * @return array<string, array<string, mixed>>
     */
    public function list(string $resource): array;

    /**
     * Save multiple entries
     *
     * @param  string  $resource
     * @param  array<string, array<string, mixed>>  $entries
     *
     * @return bool
     */
    public function saveAll(string $resource, array $entries): bool;

    /**
     * Remove resource
     *
     * @param  string  $resource
     * @param  string  $name
     *
     * @return bool
     */
    public function remove(string $resource, string $name): bool;

    /**
     * Purge executors
     *
     * @return bool
     */
    public function flush(): bool;
}
