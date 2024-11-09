<?php

namespace OpenRuntimes\State;

interface Adapter
{
    /**
     * @param  string  $key
     * @param  string  $data
     * @param  string  $hash
     * @return bool|string
     */
    public function save(string $key, string $data, string $hash): bool|string;

    /**
     * @param  array<string,string>  $entries
     * @param  string  $hash
     * @return bool|array<string,string>
     */
    public function saveAll(array $entries, string $hash): bool|array;

    /**
     * @param  string  $key
     * @return array<string, string>
     */
    public function getAll(string $hash): array;

    /**
     * @return bool
     */
    public function flush(): bool;
}