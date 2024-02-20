<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use Swoole\Runtime;
use Swoole\Table;
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
use Utopia\Fetch\Client;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Route;
use Utopia\Registry\Registry;

use function Swoole\Coroutine\run;

const ADDRESSING_METHOD_ANYCAST_EFFICIENT = 'anycast-efficient';
const ADDRESSING_METHOD_ANYCAST_FAST = 'anycast-fast';
const ADDRESSING_METHOD_BROADCAST = 'broadcast';

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string) Http::getEnv('OPR_PROXY_ENV', Http::MODE_TYPE_PRODUCTION));

// Setup Registry
$register = new Registry();

$register->set('containers', function () {
    $count = \count(\explode(',', (string) Http::getEnv('OPR_PROXY_EXECUTORS', '')));
    $state = new Table($count);
    $state->column('hostname', Swoole\Table::TYPE_STRING, 128); // Same as key of row
    $state->column('status', Swoole\Table::TYPE_STRING, 8); // 'online' or 'offline'
    $state->column('state', Swoole\Table::TYPE_STRING, 16384); // State as JSON
    $state->create();
    return $state;
});

$register->set('logger', function () {
    $providerName = Http::getEnv('OPR_PROXY_LOGGING_PROVIDER', '');
    $providerConfig = Http::getEnv('OPR_PROXY_LOGGING_CONFIG', '');
    $logger = null;

    if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig),
            'raygun' => new Raygun($providerConfig),
            'logowl' => new LogOwl($providerConfig),
            'appsignal' => new AppSignal($providerConfig),
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
Http::setResource('containers', fn () => $register->get('containers'));

// Balancer must NOT be registry. This has to run on every request
Http::setResource('balancer', function (Algorithm $algorithm, Request $request, Table $containers) {
    $runtimeId = $request->getHeader('x-opr-runtime-id', '');
    $method = $request->getHeader('x-opr-addressing-method', ADDRESSING_METHOD_ANYCAST_EFFICIENT);

    $group = new Group();

    if ($method === ADDRESSING_METHOD_ANYCAST_FAST) {
        $algorithm = new Random();
    }

    // Cold-started-only options
    $balancer1 = new Balancer($algorithm);

    // Only online executors
    $balancer1->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');

    if ($method === ADDRESSING_METHOD_ANYCAST_EFFICIENT) {
        // Only low host-cpu usage
        $balancer1->addFilter(function ($option) {
            /**
             * @var array<string,mixed> $state
             */
            $state = \json_decode($option->getState('state', '{}'), true);
            return ($state['usage'] ?? 100) < 80;
        });

        // Only low runtime-cpu usage
        if (!empty($runtimeId)) {
            $balancer1->addFilter(function ($option) use ($runtimeId) {
                /**
                 * @var array<string,mixed> $state
                 */
                $state = \json_decode($option->getState('state', '{}'), true);

                /**
                 * @var array<string,mixed> $runtimes
                 */
                $runtimes = $state['runtimes'];

                /**
                 * @var array<string,mixed> $runtime
                 */
                $runtime = $runtimes[$runtimeId] ?? [];

                return ($runtime['usage'] ?? 100) < 80;
            });
        }

        // Any options
        $balancer2 = new Balancer($algorithm);
        $balancer2->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');
    }

    foreach ($containers as $stateItem) {
        if (Http::isDevelopment()) {
            Console::log("Adding balancing option: " . \json_encode($stateItem));
        }

        /**
         * @var array<string,mixed> $stateItem
         */
        $balancer1->addOption(new Option($stateItem));

        if (isset($balancer2)) {
            $balancer2->addOption(new Option($stateItem));
        }
    }

    $group->add($balancer1);

    if (isset($balancer2)) {
        $group->add($balancer2);
    }

    return $group;
}, ['algorithm', 'request', 'containers']);

function healthCheck(bool $forceShowError = false): void
{
    /**
     * @var Registry $register
     */
    global $register;

    $containers = $register->get('containers');

    $executors = \explode(',', (string) Http::getEnv('OPR_PROXY_EXECUTORS', ''));

    $health = new Health();

    foreach ($executors as $executor) {
        $health->addNode(new Node($executor));
    }

    $nodes = $health
        ->run()
        ->getNodes();

    $healthy = true;

    foreach ($nodes as $node) {
        $status = $node->isOnline() ? 'online' : 'offline';
        $hostname = $node->getHostname();

        $oldState = $containers->exists($hostname) ? $containers->get($hostname) : null;
        $oldStatus = isset($oldState) ? ((array) $oldState)['status'] : null;
        if ($forceShowError === true || (isset($oldStatus) && $oldStatus !== $status)) {
            if ($status === 'online') {
                Console::success('Executor "' . $node->getHostname() . '" went online.');
            } else {
                Console::error('Executor "' . $node->getHostname() . '" went offline.');
            }
        }

        if ($status === 'offline') {
            $healthy = false;
        }

        $containers->set($node->getHostname(), [
            'status' => $status,
            'hostname' => $hostname,
            'state' => \json_encode($node->getState())
        ]);
    }

    if (Http::getEnv('OPR_PROXY_HEALTHCHECK_URL', '') !== '' && $healthy) {
        Client::fetch(Http::getEnv('OPR_PROXY_HEALTHCHECK_URL', '') ?? '');
    }
}

function logError(Throwable $error, string $action, ?Logger $logger, Route $route = null): void
{
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

    if ($logger) {
        $version = (string) Http::getEnv('OPR_PROXY_VERSION', 'UNKNOWN');

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
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';

        if (empty($secretKey) || $secretKey !== Http::getEnv('OPR_PROXY_SECRET', '')) {
            throw new Exception('Incorrect proxy key.', 401);
        }
    });

Http::wildcard()
    ->inject('balancer')
    ->inject('request')
    ->inject('response')
    ->inject('containers')
    ->action(function (Group $balancer, Request $request, Response $response, Table $containers) {
        $method = $request->getHeader('x-opr-addressing-method', ADDRESSING_METHOD_ANYCAST_EFFICIENT);

        $proxyRequest = function (string $hostname) use ($request, $containers) {
            if (Http::isDevelopment()) {
                Console::info("Executing on " . $hostname);
            }

            // Optimistic update. Mark runtime up instantly to prevent race conditions
            // Next health check with confirm it started well, and update usage stats
            $runtimeId = $request->getHeader('x-opr-runtime-id', '');
            if (!empty($runtimeId)) {
                $record = $containers->get($hostname);

                $stateItem = \json_decode($record['state'] ?? '{}', true);

                if (!isset($stateItem['runtimes'])) {
                    $stateItem['runtimes'] = [];
                }

                if (!isset($stateItem['runtimes'][$runtimeId])) {
                    $stateItem['runtimes'][$runtimeId] = [];
                }

                $stateItem['runtimes'][$runtimeId]['status'] = 'pass';
                $stateItem['runtimes'][$runtimeId]['usage'] = 0;

                $record['state'] = \json_encode($stateItem);

                $containers->set($hostname, $record);
            }

            $headers = \array_merge($request->getHeaders(), [
                'authorization' => 'Bearer ' . Http::getEnv('OPR_PROXY_EXECUTOR_SECRET', '')
            ]);

            // Header used for testing
            if (Http::isDevelopment()) {
                $headers = \array_merge($headers, [
                    'x-opr-executor-hostname' => $hostname
                ]);
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

            $curlHeaders = [];
            foreach ($headers as $header => $value) {
                $curlHeaders[] = "{$header}: {$value}";
            }

            \curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            $body = \curl_exec($ch);
            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = \curl_error($ch);
            $errNo = \curl_errno($ch);

            \curl_close($ch);

            if ($errNo !== 0 || \is_bool($body)) {
                throw new Exception('Unexpected curl error between proxy and executor ID ' . $hostname . ' (' . $errNo .  '): ' . $error);
            }

            return [
                'statusCode' => $statusCode,
                'body' => $body,
                'headers' => $responseHeaders
            ];
        };

        if ($method === ADDRESSING_METHOD_BROADCAST) {
            foreach ($balancer->getOptions() as $option) {
                /**
                 * @var string $hostname
                 */
                $hostname = $option->getState('hostname') ?? '';

                $proxyRequest($hostname);
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

            $result = $proxyRequest($hostname);
            $headers = $result['headers'];

            foreach ($headers as $key => $value) {
                $response->addHeader($key, $value);
            }

            $response
                ->setStatusCode($result['statusCode'])
                ->send($result['body']);
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
        logError($error, "httpError", $logger, $route);

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

        $output = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTrace(),
            'version' => Http::getEnv('OPR_PROXY_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode(\intval($code));

        $response->json($output);
    });

// If no health check, mark all as online
if (Http::getEnv('OPR_PROXY_HEALTHCHECK', 'enabled') === 'disabled') {
    $executors = \explode(',', (string) Http::getEnv('OPR_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        $containers = $register->get('containers');
        $containers->set($executor, [
            'status' => 'online',
            'hostname' => $executor,
            'state' =>  \json_encode([])
        ]);
    }
}

run(function () {
    // Initial health check + start timer
    healthCheck(true);

    $defaultInterval = '10000'; // 10 seconds
    Timer::tick(\intval(Http::getEnv('OPR_PROXY_HEALTHCHECK_INTERVAL', $defaultInterval)), fn () => healthCheck(false));

    // Start HTTP server
    $http = new Http(new Server('0.0.0.0', Http::getEnv('PORT', '80')), 'UTC');

    Console::success('Functions proxy is ready.');

    $http->start();
});
