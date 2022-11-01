<?php

namespace OpenRuntimes\Proxy\Health;

use Utopia\App;

class Health
{
    /**
     * @var Node[]
     */
    private array $nodes = [];

    public function addNode(Node $node): self
    {
        $this->nodes[] = $node;
        return $this;
    }

    public function run(): self
    {
        foreach ($this->nodes as $node) {
            go(function () use ($node) {
                try {
                    $endpoint = 'http://' . $node->getHostname() . '/v1/health';

                    $ch = \curl_init();

                    \curl_setopt($ch, CURLOPT_URL, $endpoint);
                    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'authorization: Bearer ' . App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET', '')
                    ]);

                    $executorResponse = \curl_exec($ch);
                    $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = \curl_error($ch);

                    \curl_close($ch);

                    if ($statusCode == 200 && !\is_bool($executorResponse)) {
                        $body = (array) \json_decode($executorResponse, true);

                        if ($body['status'] === 'pass') {
                            $node->setOnline(true);
                            $node->setState($body);
                        } else {
                            $message = 'Response does not include "pass" status.';
                            $node->setOnline(false);
                            $node->setState([ 'message' => $message ]);
                        }
                    } else {
                        $message = 'Code: ' . $statusCode . ' with response "' . $executorResponse .  '" and error error: ' . $error;
                        $node->setOnline(false);
                        $node->setState([ 'message' => $message ]);
                    }
                } catch (\Exception $err) {
                    throw $err;
                }
            });
        }

        return $this;
    }

    /**
     * @return Node[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }
}