<?php

namespace Tests;

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use PHPUnit\Framework\TestCase;

class HealthTest extends TestCase
{
    public function testNode(): void
    {
        $node = new Node('server1');

        $this->assertEquals('server1', $node->getHostname());
        $this->assertFalse($node->isOnline());
        $this->assertIsArray($node->getState());
        $this->assertCount(0, $node->getState());


        $node = new Node('server2', true, [ 'cpu' => 50, 'memory' => 256 ]);

        $this->assertEquals('server2', $node->getHostname());
        $this->assertTrue($node->isOnline());
        $this->assertIsArray($node->getState());
        $this->assertCount(2, $node->getState());
        $this->assertEquals(50, $node->getState()['cpu']);
        $this->assertEquals(256, $node->getState()['memory']);

        $node->setOnline(false);
        $this->assertFalse($node->isOnline());
        $node->setOnline(true);
        $this->assertTrue($node->isOnline());

        $node->setState([ 'threads' => 3 ]);
        $this->assertIsArray($node->getState());
        $this->assertCount(1, $node->getState());
        $this->assertEquals(3, $node->getState()['threads']);
        $this->assertArrayNotHasKey('cpu', $node->getState());
        $this->assertArrayNotHasKey('memory', $node->getState());
    }

    public function testHealth(): void
    {
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
    }
}
