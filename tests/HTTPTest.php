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
            ->setEndpoint('http://openruntimes-proxy/')
            ->addHeader('Authorization', 'Bearer proxy-secret-key');
        ;
    }

    public function testBalancing(): void
    {
        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping');
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        // Ensure response as sent from Mockoon
        $this->assertEquals(200, $headers['status-code']);
        $this->assertEquals('pong', $body['ping']);
        $this->assertContains($body['server'], ['mockoon1', 'mockoon2']);
        // Ensure proper executor secret
        $this->assertEquals('Bearer executor-secret-key', $body['secret']);

        $server1 = $body['server'];

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping');
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        $this->assertEquals(200, $headers['status-code']);
        $this->assertEquals('pong', $body['ping']);
        $this->assertContains($body['server'], ['mockoon1', 'mockoon2']);
        $this->assertEquals('Bearer executor-secret-key', $body['secret']);

        $server2 = $body['server'];

        // Ensure round-robin split traffic
        $this->assertNotEquals($server1, $server2);

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping', [
            'Authorization' => 'Bearer wrong-proxy-secret-key',
        ]);
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        // Ensure secret is nessessary
        $this->assertEquals(401, $headers['status-code']);
    }
}
