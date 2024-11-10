<?php

namespace OpenRuntimes\State;

class State
{
    public const HASH_KEY_EXECUTOR = 'executor';
    public const HASH_KEY_EXECUTOR_RUNTIMES = 'executor-runtimes';

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @param  Adapter  $cache
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Save executor status
     *
     * @param  string  $hostname
     * @param  array   $executor
     *
     * @return bool
     */
    public function saveExecutor(string $executorHostname, string $status, int $usage): bool
    {
        $this->adapter->save(
            key: $executorHostname,
            data: json_encode([
                'status' => $status,
                'usage' => $usage,
            ]),
            hash: State::HASH_KEY_EXECUTOR
        );

        return true;
    }

    /**
     * Get all executors status
     *
     * @return array
     */
    public function listExecutors(): array
    {
        $runtimes = $this->adapter->getAll(State::HASH_KEY_EXECUTOR);

        $objects = [];
        foreach ($runtimes as $key => $value) {
            $objects[$key] = json_decode($value, true);
        }

        return $objects;
    }

    /**
     * Get all runtimes by executor instance
     *
     * @param  string  $executorHostname
     *
     * @return array
     */
    public function listRuntimes(string $executorHostname): array
    {
        $runtimes = $this->adapter->getAll(State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $executorHostname);

        $objects = [];
        foreach ($runtimes as $key => $value) {
            $objects[$key] = json_decode($value, true);
        }

        return $objects;
    }

    /**
     * Save runtime status
     *
     * @param  string  $executorHostname
     * @param  string  $runtimeId
     * @param  string  $status
     * @param  int     $usage
     *
     * @return bool
     */
    public function saveRuntime(string $executorHostname, string $runtimeId, string $status, int $usage): bool
    {
        $this->adapter->save(
            key: $runtimeId,
            data: json_encode([
                'status' => $status,
                'usage' => $usage
            ]),
            hash: State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $executorHostname
        );

        return true;
    }

    /**
     * Save multiple runtimes
     *
     * @param  string  $executorHostname
     * @param  array   $runtimes
     *
     * @return bool
     */
    public function saveRuntimes(string $executorHostname, array $runtimes): bool
    {
        $this->adapter->saveAll(
            entries: $runtimes,
            hash: State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $executorHostname
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
