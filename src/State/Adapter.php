<?php

namespace OpenRuntimes\State;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  string  $data
     * @param  string  $hash
     * @return bool
     */
    public function save(string $key, string $data, string $hash): bool;

    /**
     * @param  array<string,string>  $entries
     * @param  string  $hash
     * @return bool
     */
    public function saveAll(array $entries, string $hash): bool;

    /**
     * @param  string  $hash
     * @return array<string, string>
     */
    public function getAll(string $hash): array;

    /**
     * @param  string  $key
     * @param  string  $hash
     * @return bool
     */
    public function remove(string $key, string $hash): bool;

    /**
     * @param  string  $hash
     * @return bool
     */
    public function removeAll(string $hash): bool;

    /**
     * @return bool
     */
    public function flush(): bool;
}
