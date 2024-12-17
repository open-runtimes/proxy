<?php

namespace Tests;

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

class HealthTest extends TestCase
{
    public function testHealth(): void
    {
        run(function () {
            $health = new Health();

            $nodes = $health
                ->addNode(new Node('server1'))
                ->addNode(new Node('mockoon1'))
                ->run()
                ->getNodes();

            $this->assertIsArray($nodes);
            $this->assertCount(2, $nodes);

            $serverNode = $nodes[0];
            $mockoonNode = $nodes[1];

            $this->assertFalse($serverNode->isOnline());
            $this->assertArrayHasKey('message', $serverNode->getState());

            $this->assertTrue($mockoonNode->isOnline());
            $this->assertArrayHasKey('status', $mockoonNode->getState());
        });
    }
}
