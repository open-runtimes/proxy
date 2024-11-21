<?php

namespace OpenRuntimes\Proxy\Health;

use Utopia\Http\Http;

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

                    \curl_close($ch);

                    if ($statusCode == 200 && !\is_bool($executorResponse)) {
                        $node->setOnline(true);
                        $node->setState(\json_decode($executorResponse, true) ?: []);
                    } else {
                        $message = 'Code: ' . $statusCode . ' with response "' . $executorResponse .  '" and error error: ' . $error;
                        $node->setOnline(false);
                        $node->setState([ 'message' => $message ]);
                    }
                } catch (\Exception $err) {
                    throw $err;
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
