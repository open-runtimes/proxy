<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenRuntimes\State\Adapter\Redis as RedisAdapter;
use OpenRuntimes\State\Adapter\RedisCluster as RedisClusterAdapter;
use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use OpenRuntimes\State\State;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Fetch\Client;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Http\Route;
use Utopia\Registry\Registry;

use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

const ADDRESSING_METHOD_ANYCAST_EFFICIENT = 'anycast-efficient';
const ADDRESSING_METHOD_ANYCAST_FAST = 'anycast-fast';
const ADDRESSING_METHOD_BROADCAST = 'broadcast';

const RESOURCE_EXECUTORS = '{executors}';
const RESOURCE_RUNTIMES = '{executors-runtimes}:';

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string) Http::getEnv('OPR_PROXY_ENV', Http::MODE_TYPE_PRODUCTION));

// Setup Registry
$register = new Registry();

function createState(string $dsn): State
{
    $dsn = new DSN($dsn);

    switch($dsn->getScheme()) {
        case 'redis':
            $redis = new Redis();
            $redis->connect($dsn->getHost(), intval($dsn->getPort()));
            return new State(new RedisAdapter($redis));
        case 'redis-cluster':
            $hosts = explode(';', str_replace(["[", "]"], "", $dsn->getHost()));
            $redisCluster = new \RedisCluster(null, $hosts, -1, -1, true, $dsn->getPassword());
            return new State(new RedisClusterAdapter($redisCluster));
        default:
            throw new Exception('Unsupported state connection: ' . $dsn->getScheme());
    }
}

/**
 * Create logger for cloud logging
 */
$register->set('logger', function () {
    $providerName = Http::getEnv('OPR_PROXY_LOGGING_PROVIDER', '');
    $providerConfig = Http::getEnv('OPR_PROXY_LOGGING_CONFIG', '');

    try {
        $loggingProvider = new DSN($providerConfig ?? '');

        $providerName = $loggingProvider->getScheme();
        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
            'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            default => ['key' => $loggingProvider->getHost()],
        };
    } catch (Throwable) {
        $configChunks = \explode(";", ($providerConfig ?? ''));

        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $configChunks[0], 'projectId' => $configChunks[1] ?? '', 'host' => '',],
            'logowl' => ['ticket' => $configChunks[0] ?? '', 'host' => ''],
            default => ['key' => $providerConfig],
        };
    }

    $logger = null;

    if (!empty($providerName) && is_array($providerConfig) && Logger::hasProvider($providerName)) {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig['projectId'] ?? '', $providerConfig['key'] ?? '', $providerConfig['host'] ?? ''),
            'logowl' => new LogOwl($providerConfig['ticket'] ?? '', $providerConfig['host'] ?? ''),
            'raygun' => new Raygun($providerConfig['key'] ?? ''),
            'appsignal' => new AppSignal($providerConfig['key'] ?? ''),
            default => throw new Exception('Provider "' . $providerName . '" not supported.')
        };

        $logger = new Logger($adapter);
    }

    return $logger;
});

$register->set('algorithm', function () {
    $algoType = Http::getEnv('OPR_PROXY_ALGORITHM', '');
    $algo = match ($algoType) {
        'round-robin' => new RoundRobin(0),
        'first' => new First(),
        'last' => new Last(),
        'random' => new Random(),
        default => new Random()
    };

    return $algo;
});

// Setup Resources
Http::setResource('logger', fn () => $register->get('logger'));
Http::setResource('algorithm', fn () => $register->get('algorithm'));
Http::setResource('state', fn () => createState(Http::getEnv('OPR_PROXY_CONNECTIONS_STATE', '') ?? ''));

// Balancer must NOT be registry. This has to run on every request
Http::setResource('balancer', function (Algorithm $algorithm, Request $request, State $state) {
    $runtimeId = $request->getHeader('x-opr-runtime-id', '');
    $method = $request->getHeader('x-opr-addressing-method', ADDRESSING_METHOD_ANYCAST_EFFICIENT);

    if ($method === ADDRESSING_METHOD_ANYCAST_FAST) {
        $algorithm = new Random();
    }

    $balancers = [];

    // Cold-started-only options
    $balancer1 = new Balancer($algorithm);

    // Only online executors
    $balancer1->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');

    if ($method === ADDRESSING_METHOD_ANYCAST_EFFICIENT) {
        // Executors with runtime cold-started
        if (!empty($runtimeId)) {
            $balancer = new Balancer($algorithm);
            $balancer->addFilter(function ($option) use ($runtimeId) {
                $runtimes = $option->getState('runtimes', []);
                $runtime = $runtimes[$runtimeId] ?? [];
                return ($runtime['usage'] ?? 100) < 80 &&
                       ($runtime['status'] ?? '') === 'pass';
            });
            $balancers[] = $balancer;
        }

        // Executors with low host-cpu usage
        $balancer = new Balancer($algorithm);
        $balancer->addFilter(fn ($option) => \intval($option->getState('usage', '100')) < 80);
        $balancers[] = $balancer;

        // Online executors
        $balancer = new Balancer($algorithm);
        $balancer->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');
        $balancers[] = $balancer;

        // Any executors
        $balancer = new Balancer($algorithm);
        $balancer->addFilter(fn () => true);
        $balancers[] = $balancer;
    } else {
        // Any executors that have the runtime in "pass" status if runtime ID is provided
        $balancer = new Balancer($algorithm);
        if (!empty($runtimeId)) {
            $balancer->addFilter(function ($option) use ($runtimeId) {
                $runtimes = $option->getState('runtimes', []);
                $runtime = $runtimes[$runtimeId] ?? [];
                return ($runtime['status'] ?? '') === 'pass';
            });
        } else {
            $balancer->addFilter(fn () => true);
        }
        $balancers[] = $balancer;
    }

    foreach ($state->list(RESOURCE_EXECUTORS) as $hostname => $executor) {
        $executor['runtimes'] = $state->list(RESOURCE_RUNTIMES . $hostname);
        $executor['hostname'] = $hostname;

        if (Http::isDevelopment()) {
            Console::log("Updated balancing option '" . $hostname . "' with ". \count($executor['runtimes'])." runtimes: " . \json_encode($executor));
        }

        foreach ($balancers as $balancer) {
            $balancer->addOption(new Option($executor));
        }
    }

    $group = new Group();
    foreach ($balancers as $balancer) {
        $group->add($balancer);
    }

    return $group;
}, ['algorithm', 'request', 'state']);

$healthCheck = function (State $state, bool $firstCheck = false) use ($register): void {
    $logger = $register->get('logger');
    $executors = $state->list(RESOURCE_EXECUTORS);

    $health = new Health();
    foreach (\explode(',', (string) Http::getEnv('OPR_PROXY_EXECUTORS', '')) as $hostname) {
        $health->addNode(new Node($hostname));
    }

    $healthy = true;

    foreach ($health->run()->getNodes() as $node) {
        try {
            $hostname = $node->getHostname();
            $executor = $executors[$hostname] ?? [];
            $newStatus = $node->isOnline() ? 'online' : 'offline';
            $healthy = $healthy && $node->isOnline();
            $shouldLog = $firstCheck || Http::isDevelopment() || $executor['status'] !== $newStatus;

            if ($node->isOnline() && $shouldLog) {
                Console::info('Executor "' . $hostname . '" is online');
            }

            if (!$node->isOnline() && $shouldLog) {
                $message = $node->getState()['message'] ?? 'Unexpected error.';
                $error = new Exception('Executor "' . $hostname . '" went offline: ' . $message, 500);
                logError($error, "healthCheckError", $logger, null);
            }

            $state->set(
                resource: RESOURCE_EXECUTORS,
                name: $hostname,
                status: $node->isOnline() ? 'online' : 'offline',
                usage: $node->getState()['usage'] ?? 0
            );

            if (Http::isDevelopment()) {
                Console::log('Executor "' . $hostname . '" healthcheck returned ' . \count($node->getState()['runtimes'] ?? []) . ' runtimes');
            }

            $runtimes = [];
            foreach ($node->getState()['runtimes'] ?? [] as $runtimeId => $runtime) {
                if (!\is_string($runtimeId) || !\is_array($runtime)) {
                    Console::warning('Invalid runtime data for ' . $hostname . ' runtime ' . $runtimeId);
                    continue;
                }

                $runtimes[$runtimeId] = [
                    'status' => $runtime['status'] ?? 'offline',
                    'usage' => $runtime['usage'] ?? 100,
                ];
            }

            $state->setAll(RESOURCE_RUNTIMES . $hostname, $runtimes);
        } catch (\Throwable $th) {
            try {
                $healthy = false;
                Console::warning('Health check failed for ' . $node->getHostname() . ': ' . $th->getMessage() . ' - removing from state');

                $state->set(RESOURCE_EXECUTORS, $node->getHostname(), 'offline', 100);
                $state->setAll(RESOURCE_RUNTIMES . $node->getHostname(), []);
            } catch (\Throwable $th) {
                Console::warning('Failed to remove executor from state: ' . $th->getMessage());
            }
        }
    }

    if (Http::getEnv('OPR_PROXY_HEALTHCHECK_URL', '') !== '' && $healthy) {
        try {
            Client::fetch(Http::getEnv('OPR_PROXY_HEALTHCHECK_URL') ?? '');
        } catch (\Throwable $th) {
            logError($th, 'healthCheckError', $logger, null);
        }
    }
};

function logError(Throwable $error, string $action, ?Logger $logger, Route $route = null): void
{
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

    if ($logger) {
        $version = (string) Http::getEnv('OPR_PROXY_VERSION') ?: 'UNKNOWN';

        $log = new Log();
        $log->setNamespace('proxy');
        $log->setServer(\gethostname() ? \gethostname() : 'unknown');
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));
        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        // TODO: @Meldiron Uncomment, was warning: Undefined array key "file" in Sentry.php on line 68
        // $log->addExtra('detailedTrace', $error->getTrace());
        $log->setAction($action);
        $log->setEnvironment(Http::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Proxy log pushed with status code: ' . $responseCode);
    }
}

Http::init()
    ->inject('request')
    ->action(function (Request $request) {
        if ($request->getURI() === '/v1/proxy/health') {
            return;
        }

        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';

        if (empty($secretKey) || $secretKey !== Http::getEnv('OPR_PROXY_SECRET', '')) {
            throw new Exception('Incorrect proxy key.', 401);
        }
    });

Http::get('/v1/proxy/health')
    ->inject('response')
    ->action(function (Response $response) {
        $response
            ->setStatusCode(200)
            ->send('OK');
    });

Http::get('/v1/proxy/stats') 
    ->inject('response')
    ->inject('state')
    ->action(function (Response $response, State $state) {
        $stats = [];
        foreach ($state->list(RESOURCE_EXECUTORS) as $hostname => $executor) {
            $stats[$hostname] = [
                'status' => $executor['status'],
                'usage' => $executor['usage'],
                'runtimes' => $state->list(RESOURCE_RUNTIMES . $hostname)
            ];
        }

        $response
            ->setStatusCode(200)
            ->json($stats);
    });

Http::wildcard()
    ->inject('balancer')
    ->inject('request')
    ->inject('response')
    ->inject('state')
    ->action(function (Group $balancer, Request $request, SwooleResponse $response, State $state) {
        $method = $request->getHeader('x-opr-addressing-method', ADDRESSING_METHOD_ANYCAST_EFFICIENT);

        $proxyRequest = function (string $hostname, ?SwooleResponse $response = null) use ($request, $state) {
            if (Http::isDevelopment()) {
                Console::info("Executing on " . $hostname);
            }

            // Optimistic update. Mark runtime up instantly to prevent race conditions
            // Next health check with confirm it started well, and update usage stats
            $runtimeId = $request->getHeader('x-opr-runtime-id', '');
            if (!empty($runtimeId)) {
                $state->set(RESOURCE_RUNTIMES . $hostname, $runtimeId, 'pass', 0);
            }

            $headers = \array_merge($request->getHeaders(), [
                'authorization' => 'Bearer ' . Http::getEnv('OPR_PROXY_EXECUTOR_SECRET', '')
            ]);

            // Header used for testing
            if (Http::isDevelopment()) {
                $headers['x-opr-executor-hostname'] = $hostname;
            }

            $body = $request->getRawPayload();

            $ch = \curl_init();

            $responseHeaders = [];

            \curl_setopt($ch, CURLOPT_URL, $hostname . $request->getURI());
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $key = strtolower(trim($header[0]));
                $responseHeaders[$key] = trim($header[1]);

                return $len;
            });
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_TIMEOUT, \intval(Http::getEnv('OPR_PROXY_MAX_TIMEOUT', '900')));

            // Chunked response support
            $isChunked = false;
            if ($response !== null) {
                $isBody = false;
                $callback = function ($data) use (&$isChunked, $response, &$isBody) {
                    $isChunked = true;

                    if ($isBody) {
                        $response->getSwooleResponse()->write($data);
                        return;
                    }

                    $lines = \explode("\n", $data);

                    $index = -1;
                    while (count($lines) > 0) {
                        $index++;

                        $line = \array_shift($lines);
                        $line = \trim($line, "\r");

                        if (empty($line)) {
                            if ($index === 0) {
                                $isBody = true;
                            }
                            break;
                        }

                        if (\str_starts_with($line, 'HTTP/')) {
                            $statusCode = \explode(' ', $line, 3)[1] ?? 0;
                            if (!empty($statusCode)) {
                                $response->getSwooleResponse()->status($statusCode);
                            }
                        } else {
                            [ $header, $headerValue ] = \explode(': ', $line, 2);
                            $response->getSwooleResponse()->header($header, $headerValue);
                        }
                    }

                    if (count($lines) > 0) {
                        $isBody = true;

                        $data = \implode("\n", $lines);
                        $data = \trim($data, "\r");
                        if (\strlen($data) > 0) {
                            $response->getSwooleResponse()->write($data);
                        }
                    }
                };
                \curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($callback) {
                    $callback($data);
                    return \strlen($data);
                });
                \curl_setopt($ch, CURLOPT_HEADER, 1);
            }

            $curlHeaders = [];
            foreach ($headers as $header => $value) {
                $curlHeaders[] = "{$header}: {$value}";
            }

            \curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            \curl_exec($ch);
            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = \curl_error($ch);
            $errNo = \curl_errno($ch);

            \curl_close($ch);

            if ($errNo !== 0) {
                if ($response !== null) {
                    $response->end();
                }

                // Critical executor errors that indicate the machine is unreachable
                if (in_array($errNo, [
                    CURLE_COULDNT_RESOLVE_HOST,  // DNS failure - the executor's hostname cannot be resolved, indicating either the executor is completely offline or DNS issues
                    CURLE_COULDNT_CONNECT,       // TCP connection failure - the executor process is not accepting connections, suggesting it's down or blocked by firewall
                    CURLE_OPERATION_TIMEDOUT     // Connection timeout - the executor is not responding at all, indicating it's frozen or network is completely blocked
                ])) {
                    $state->set(RESOURCE_EXECUTORS, $hostname, 'offline', 100);
                    $state->setAll(RESOURCE_RUNTIMES . $hostname, []);
                    Console::error("Executor '$hostname' appears to be down (Error $errNo: $error). Removed from state.");
                }
                // Runtime-specific errors that indicate issues with processing a specific request
                // These errors occur after a connection is established, suggesting the executor is up but the runtime is having issues
                elseif (!empty($runtimeId) && in_array($errNo, [
                    CURLE_RECV_ERROR,   // Failed to receive response - runtime might have crashed while processing
                    CURLE_SEND_ERROR,   // Failed to send request - runtime might be overloaded or in a bad state
                    CURLE_GOT_NOTHING   // Empty response - runtime likely crashed after accepting the connection
                ])) {
                    $state->set(RESOURCE_RUNTIMES . $hostname, $runtimeId, 'fail', 0);
                    Console::warning("Runtime '$runtimeId' on executor '$hostname' encountered an error (Error $errNo: $error). Removed from state.");
                }

                throw new Exception("Unexpected curl error between proxy and executor '$hostname' ($errNo): $error");
            }

            if ($response !== null) {
                foreach ($responseHeaders as $key => $value) {
                    $response->addHeader($key, $value);
                }

                $response->setStatusCode($statusCode);
                $response->end();
            }
        };

        if ($method === ADDRESSING_METHOD_BROADCAST) {
            foreach ($balancer->getOptions() as $option) {
                /**
                 * @var string $hostname
                 */
                $hostname = $option->getState('hostname') ?? '';
                $proxyRequest($hostname, null);
            }

            $response->noContent();
        } else {
            $option = $balancer->run();

            if (!isset($option)) {
                throw new Exception('No online executor found', 404);
            }

            /**
             * @var string $hostname
             */
            $hostname = $option->getState('hostname') ?? '';
            $proxyRequest($hostname, $response);
        }
    });

Http::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('logger')
    ->inject('request')
    ->inject('response')
    ->action(function (Http $utopia, throwable $error, ?Logger $logger, Request $request, Response $response) {
        $route = $utopia->match($request);
        try {
            logError($error, "httpError", $logger, $route);
        } catch (Throwable) {
            Console::warning('Unable to send log message');
        }


        $version = (string) Http::getEnv('OPR_PROXY_VERSION') ?: 'UNKNOWN';
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

        switch ($error->getCode()) {
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 406: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 425: // Error allowed publicly
            case 429: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                $code = $error->getCode();
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
        }

        $output = ((Http::isDevelopment())) ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => \json_encode($trace, JSON_UNESCAPED_UNICODE) === false ? [] : $trace, // check for failing encode
            'version' => $version
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });

run(function () use ($healthCheck) {
    $payloadSize = 22 * (1024 * 1024);

    $settings = [
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ];
    // Start HTTP server
    $http = new Http(new Server('0.0.0.0', Http::getEnv('PORT', '80'), $settings), 'UTC');

    $state = createState(Http::getEnv('OPR_PROXY_CONNECTIONS_STATE', '') ?? '');
    $healthCheck($state, true);

    $defaultInterval = '10000'; // 10 seconds
    Timer::tick(\intval(Http::getEnv('OPR_PROXY_HEALTHCHECK_INTERVAL', $defaultInterval)), fn () => $healthCheck($state, false));

    Console::success('Functions proxy is ready.');

    $http->start();
});
