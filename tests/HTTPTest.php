<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class HTTPTest extends TestCase
{
    private ?Client $client;

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->client->setEndpoint("http://localhost/");
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    public function testBalancing(): void
    {
        $response = $this->client->call(Client::METHOD_GET, "/v1/ping", [
            'x-open-runtimes-proxy-secret' => 'proxy-secret-key'
        ]);

        // Ensure response as sent from Mockoon
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pong', $response['body']['ping']);
        $this->assertContains($response['body']['server'], ['mockoon1', 'mockoon2']);
        // Ensure proper executor secret
        $this->assertEquals('executor-secret-key', $response['body']['secret']);

        $server1 = $response['body']['server'];

        $response = $this->client->call(Client::METHOD_GET, "/v1/ping", [
            'x-open-runtimes-proxy-secret' => 'proxy-secret-key'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pong', $response['body']['ping']);
        $this->assertContains($response['body']['server'], ['mockoon1', 'mockoon2']);
        $this->assertEquals('executor-secret-key', $response['body']['secret']);

        $server2 = $response['body']['server'];

        // Ensure round-robin split traffic
        $this->assertNotEquals($server1, $server2); 

        $response = $this->client->call(Client::METHOD_GET, "/v1/ping", [
            'x-open-runtimes-proxy-secret' => 'wrong-secret-key'
        ]);

        // Ensure secret is nessessary
        $this->assertEquals(401, $response['headers']['status-code']);
    }
}
