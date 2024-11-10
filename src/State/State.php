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
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Save executor status
     *
     * @param  string  $hostname
     * @param  string  $status
     * @param  int     $usage
     *
     * @return bool
     */
    public function saveExecutor(string $hostname, string $status, int $usage): bool
    {
        $this->adapter->save(
            key: $hostname,
            data: json_encode([
                'status' => $status,
                'usage' => $usage,
            ], JSON_THROW_ON_ERROR),
            hash: State::HASH_KEY_EXECUTOR
        );

        return true;
    }

    /**
     * Get all executors status
     *
     * @return array<string, mixed>
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
     * @param  string  $hostname
     *
     * @return array<string, mixed>
     */
    public function listRuntimes(string $hostname): array
    {
        $runtimes = $this->adapter->getAll(State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $hostname);

        $objects = [];
        foreach ($runtimes as $key => $value) {
            $objects[$key] = json_decode($value, true);
        }

        return $objects;
    }

    /**
     * Save runtime status
     *
     * @param  string  $hostname
     * @param  string  $runtimeId
     * @param  string  $status
     * @param  int     $usage
     *
     * @return bool
     */
    public function saveRuntime(string $hostname, string $runtimeId, string $status, int $usage): bool
    {
        $this->adapter->save(
            key: $runtimeId,
            data: json_encode([
                'status' => $status,
                'usage' => $usage
            ], JSON_THROW_ON_ERROR),
            hash: State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $hostname
        );

        return true;
    }

    /**
     * Save multiple runtimes
     *
     * @param  string  $hostname
     * @param  array<string, array<string, mixed>>  $runtimes
     *
     * @return bool
     */
    public function saveRuntimes(string $hostname, array $runtimes): bool
    {
        $strings = [];
        foreach ($runtimes as $key => $value) {
            $strings[$key] = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $this->adapter->saveAll(
            entries: $strings,
            hash: State::HASH_KEY_EXECUTOR_RUNTIMES . ':' . $hostname
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
