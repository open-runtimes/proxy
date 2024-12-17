<?php

namespace OpenRuntimes\Proxy\Health;

use Utopia\Http\Http;
use Utopia\CLI\Console;

use function Swoole\Coroutine\batch;

class Health
{
    /**
     * @var Node[]
     */
    private array $nodes = [];

    /**
     * Add node to ping during health check.
     *
     * @param Node $node
     * @return self
     */
    public function addNode(Node $node): self
    {
        $this->nodes[] = $node;
        return $this;
    }

    /**
     * Run health checks on nodes, sending HTTP requests.
     * Each node is checked independently and failures are handled gracefully.
     *
     * @return self
     */
    public function run(): self
    {
        $callables = [];

        foreach ($this->nodes as $node) {
            $callables[] = function () use ($node) {
                try {
                    $endpoint = 'http://' . $node->getHostname() . '/v1/health';

                    $ch = \curl_init();

                    \curl_setopt($ch, CURLOPT_URL, $endpoint);
                    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'authorization: Bearer ' . Http::getEnv('OPR_PROXY_EXECUTOR_SECRET', '')
                    ]);

                    $executorResponse = \curl_exec($ch);
                    $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = \curl_error($ch);
                    $errNo = \curl_errno($ch);

                    \curl_close($ch);

                    // Any connection error means the executor is not healthy
                    if ($errNo !== 0) {
                        $message = "Connection error ($errNo): $error";
                        $node->setOnline(false);
                        $node->setState(['message' => $message]);
                        
                        if (Http::isDevelopment()) {
                            Console::error("Health check failed for {$node->getHostname()}: $message");
                        }
                        return;
                    }

                    // Only consider 200 responses with valid JSON and 'pass' status as healthy
                    if ($statusCode === 200 && !\is_bool($executorResponse)) {
                        try {
                            $body = \json_decode($executorResponse, true);
                            
                            if (!is_array($body)) {
                                throw new \Exception('Invalid JSON response');
                            }

                            if (($body['status'] ?? '') === 'pass') {
                                $node->setOnline(true);
                                $node->setState($body);
                                return;
                            }
                            
                            $message = 'Response does not include "pass" status: ' . $executorResponse;
                            $node->setOnline(false);
                            $node->setState(['message' => $message]);
                        } catch (\Throwable $e) {
                            $message = 'Failed to parse executor response: ' . $e->getMessage();
                            $node->setOnline(false);
                            $node->setState(['message' => $message]);
                        }
                    } else {
                        $message = 'Invalid status code: ' . $statusCode . ' with response "' . $executorResponse .  '"';
                        if ($error) {
                            $message .= ' and error: ' . $error;
                        }
                        $node->setOnline(false);
                        $node->setState(['message' => $message]);
                    }

                    if (Http::isDevelopment()) {
                        Console::error("Health check failed for {$node->getHostname()}: " . ($message ?? 'Unknown error'));
                    }
                } catch (\Throwable $err) {
                    // Catch all other unexpected errors
                    $message = 'Unexpected error during health check: ' . $err->getMessage();
                    $node->setOnline(false);
                    $node->setState(['message' => $message]);
                    
                    if (Http::isDevelopment()) {
                        Console::error("Health check error for {$node->getHostname()}: " . $err->getMessage());
                    }
                }
            };
        }
        batch($callables);

        return $this;
    }

    /**
     * Get nodes. Useful after running health check to get status.
     *
     * @return Node[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }
}