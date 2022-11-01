<?php

namespace OpenRuntimes\Proxy\Health;

class Node
{
    private string $hostname;

    private bool $online;

    /**
     * @var array<string,mixed>
     */
    private array $state;

    /**
     * @param array<string,mixed> $state
     */
    public function __construct(string $hostname, bool $online = false, array $state = [])
    {
        $this->hostname = $hostname;
        $this->online = $online;
        $this->state = $state;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setOnline(bool $online): self
    {
        $this->online = $online;
        return $this;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function setState(array $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function isOnline(): bool
    {
        return $this->online;
    }

    /**
     * @return array<string,mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }
}
