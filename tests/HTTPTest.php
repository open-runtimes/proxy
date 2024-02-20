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
            ->addHeader('authorization', 'Bearer proxy-secret-key');
        ;
    }

    public function testBalancer(): void
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

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping');
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        $this->assertEquals(200, $headers['status-code']);
        $this->assertEquals('pong', $body['ping']);
        $this->assertContains($body['server'], ['mockoon1', 'mockoon2']);
        $this->assertEquals('Bearer executor-secret-key', $body['secret']);

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping', [
            'authorization' => 'Bearer wrong-proxy-secret-key',
        ]);
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        // Ensure secret is nessessary
        $this->assertEquals(401, $headers['status-code']);
    }

    public function testAddressingMethods(): void
    {
        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping', [
            'x-opr-addressing-method' => 'anycast-efficient'
        ]);
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        // Ensure response as sent from Mockoon
        $this->assertEquals(200, $headers['status-code']);
        $this->assertEquals('pong', $body['ping']);
        $this->assertContains($body['server'], ['mockoon1', 'mockoon2']);
        // Ensure proper executor secret
        $this->assertEquals('Bearer executor-secret-key', $body['secret']);

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping', [
            'x-opr-addressing-method' => 'anycast-fast'
        ]);
        $headers = (array) $response['headers'];
        $body = (array) $response['body'];

        // Ensure response as sent from Mockoon
        $this->assertEquals(200, $headers['status-code']);
        $this->assertEquals('pong', $body['ping']);
        $this->assertContains($body['server'], ['mockoon1', 'mockoon2']);
        // Ensure proper executor secret
        $this->assertEquals('Bearer executor-secret-key', $body['secret']);

        $response = (array) $this->client->call(Client::METHOD_GET, '/v1/ping', [
            'x-opr-addressing-method' => 'broadcast'
        ]);
        $headers = (array) $response['headers'];
        $body = $response['body'];

        $this->assertEquals(204, $headers['status-code']);
        $this->assertEquals(0, $headers['content-length']);
        $this->assertEquals('', $body);
    }
}
