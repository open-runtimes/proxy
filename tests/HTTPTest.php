<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class HTTPTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = new Client();
        
        $this->client
            ->setEndpoint("http://localhost/")
            ->addHeader('Authorization', 'Bearer proxy-secret-key');
        ;
    }

    public function testBalancing(): void
    {
        $response = $this->client->call(Client::METHOD_GET, "/v1/ping");

        // Ensure response as sent from Mockoon
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pong', $response['body']['ping']);
        $this->assertContains($response['body']['server'], ['mockoon1', 'mockoon2']);
        // Ensure proper executor secret
        $this->assertEquals('Bearer executor-secret-key', $response['body']['secret']);

        $server1 = $response['body']['server'];

        $response = $this->client->call(Client::METHOD_GET, "/v1/ping");

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('pong', $response['body']['ping']);
        $this->assertContains($response['body']['server'], ['mockoon1', 'mockoon2']);
        $this->assertEquals('Bearer executor-secret-key', $response['body']['secret']);

        $server2 = $response['body']['server'];

        // Ensure round-robin split traffic
        $this->assertNotEquals($server1, $server2);

        $response = $this->client->call(Client::METHOD_GET, "/v1/ping", [
            'Authorization' => 'Bearer wrong-proxy-secret-key',
        ]);

        // Ensure secret is nessessary
        $this->assertEquals(401, $response['headers']['status-code']);
    }
}
