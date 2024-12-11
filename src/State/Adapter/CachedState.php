<?php

namespace OpenRuntimes\State\Adapter;

use OpenRuntimes\State\Cache;
use OpenRuntimes\State\State;

class CachedState implements State
{
    private State $state;
    private Cache $cache;
    private int $ttl;

    public function __construct(State $state, Cache $cache, int $ttl = 10)
    {
        $this->state = $state;
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function save(string $resource, string $name, string $status, float $usage): bool
    {
        $success = $this->state->save($resource, $name, $status, $usage);
        if ($success) {
            $this->cache->set($resource.':'.$name, ['status' => $status, 'usage' => $usage], $this->ttl);
        }
        return $success;
    }

    public function list(string $resource): array
    {
        $entries = $this->cache->getAll($resource);

        if (empty($entries)) {
            // Refresh from state
            $entries = $this->state->list($resource);
            if (!empty($entries)) {
                $this->cache->setAll($resource, $entries, $this->ttl);
            }
        }

        return $entries;
    }

    public function saveAll(string $resource, array $entries): bool
    {
        $success = $this->state->saveAll($resource, $entries);
        if ($success) {
            $this->cache->setAll($resource, $entries, $this->ttl);
        }
        return $success;
    }

    public function remove(string $resource, string $name): bool
    {
        $success = $this->state->remove($resource, $name);
        if ($success) {
            // Remove from cache
            $this->cache->delete($resource . ':' . $name);
        }
        return $success;
    }

    public function flush(): bool
    {
        $success = $this->state->flush();
        if ($success) {
            $this->cache->clear();
        }
        return $success;
    }
}