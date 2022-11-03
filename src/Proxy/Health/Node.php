<?php

namespace OpenRuntimes\Proxy\Health;

class Node
{
    /**
     * @var string
     */
    private string $hostname;

    /**
     * @var bool
     */
    private bool $online;

    /**
     * @var array<string,mixed>
     */
    private array $state;

    /**
     * @param string $hostname
     * @param bool $online
     * @param array<string,mixed> $state
     */
    public function __construct(string $hostname, bool $online = false, array $state = [])
    {
        $this->hostname = $hostname;
        $this->online = $online;
        $this->state = $state;
    }

    /**
     * Get Node hostname.
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * Set Node availability status.
     *
     * @param bool $online
     * @return self
     */
    public function setOnline(bool $online): self
    {
        $this->online = $online;
        return $this;
    }

    /**
     * Get Node availability status.
     *
     * @return bool
     */
    public function isOnline(): bool
    {
        return $this->online;
    }

    /**
     * Set Node state.
     *
     * @param array<string,mixed> $state
     * @return self
     */
    public function setState(array $state): self
    {
        $this->state = $state;
        return $this;
    }

    /**
     * Get Node state.
     *
     * @return array<string,mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }
}
