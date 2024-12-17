<?php

namespace Tests;

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

class HealthTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Ensure we're in test environment
        putenv('OPR_PROXY_ENV=test');
    }

    public function testHealthCheck(): void
    {
        run(function () {
            $health = new Health();

            // Test both mockoon instances and an invalid server
            $nodes = $health
                ->addNode(new Node('mockoon1'))    // Should be healthy
                ->addNode(new Node('mockoon2'))    // Should be healthy
                ->addNode(new Node('invalid-server')) // Should fail
                ->run()
                ->getNodes();

            $this->assertIsArray($nodes);
            $this->assertCount(3, $nodes);

            // Check mockoon1
            $mockoon1Node = $nodes[0];
            $this->assertTrue($mockoon1Node->isOnline(), 'Mockoon1 should be online');
            $this->assertArrayHasKey('status', $mockoon1Node->getState());
            $this->assertEquals('pass', $mockoon1Node->getState()['status']);

            // Check mockoon2
            $mockoon2Node = $nodes[1];
            $this->assertTrue($mockoon2Node->isOnline(), 'Mockoon2 should be online');
            $this->assertArrayHasKey('status', $mockoon2Node->getState());
            $this->assertEquals('pass', $mockoon2Node->getState()['status']);

            // Check invalid server
            $invalidNode = $nodes[2];
            $this->assertFalse($invalidNode->isOnline(), 'Invalid server should be offline');
            $this->assertArrayHasKey('message', $invalidNode->getState());
            $this->assertStringContainsString('Connection error', $invalidNode->getState()['message']);
        });
    }

    public function testPartialFailure(): void
    {
        run(function () {
            $health = new Health();

            // Test scenario where one mockoon is down but other is up
            $nodes = $health
                ->addNode(new Node('mockoon1'))
                ->addNode(new Node('mockoon2:1234')) // Wrong port, should fail
                ->run()
                ->getNodes();

            $this->assertCount(2, $nodes);

            // First node should still be healthy
            $this->assertTrue($nodes[0]->isOnline(), 'Mockoon1 should remain online despite mockoon2 failure');
            
            // Second node should be offline but not affect first node
            $this->assertFalse($nodes[1]->isOnline(), 'Mockoon2 with wrong port should be offline');
            $this->assertArrayHasKey('message', $nodes[1]->getState());
        });
    }

    public function testFailedResponse(): void
    {
        run(function () {
            $health = new Health();

            // Test error responses from mockoon
            $nodes = $health
                ->addNode(new Node('mockoon1/v1/error')) // Should return error response
                ->run()
                ->getNodes();

            $this->assertCount(1, $nodes);
            $node = $nodes[0];
            
            $this->assertFalse($node->isOnline());
            $this->assertArrayHasKey('message', $node->getState());
            $this->assertStringContainsString('Invalid status code', $node->getState()['message']);
        });
    }

    public function testConcurrentChecks(): void
    {
        run(function () {
            $health = new Health();

            // Add multiple nodes to test concurrent checking
            $nodes = $health
                ->addNode(new Node('mockoon1'))         // Should succeed
                ->addNode(new Node('invalid-server1'))  // Should fail
                ->addNode(new Node('mockoon2'))         // Should succeed
                ->addNode(new Node('invalid-server2'))  // Should fail
                ->run()
                ->getNodes();

            $this->assertCount(4, $nodes);

            // Count online nodes (should be exactly 2 - the mockoon instances)
            $onlineNodes = array_filter($nodes, fn($node) => $node->isOnline());
            $this->assertCount(2, $onlineNodes);

            // Verify all offline nodes have error messages
            $offlineNodes = array_filter($nodes, fn($node) => !$node->isOnline());
            $this->assertCount(2, $offlineNodes);
            foreach ($offlineNodes as $node) {
                $this->assertArrayHasKey('message', $node->getState());
                $this->assertNotEmpty($node->getState()['message']);
            }
        });
    }
}