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
    public function set(string $key, string $data, string $hash): bool;

    /**
     * @param  array<string,string>  $entries
     * @param  string  $hash
     * @return bool
     */
    public function setAll(array $entries, string $hash): bool;

    /**
     * @param  string  $hash
     * @return array<string, string>
     */
    public function getAll(string $hash): array;

    /**
     * @return bool
     */
    public function flush(): bool;
}
