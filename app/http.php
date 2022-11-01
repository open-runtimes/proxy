<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use Swoole\Atomic;
use Swoole\Coroutine\Http\Client;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\App;
use Utopia\Balancing\Algorithm\Random;
use Utopia\Balancing\Algorithm\RoundRobin;
use Utopia\Balancing\Balancing;
use Utopia\Balancing\Option;
use Utopia\CLI\Console;
use Utopia\Logger\Adapter;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Registry\Registry;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

$register = new Registry();

$register->set('executorStates', function () {
    $executorsCount = \count(\explode(',', (string) App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', '')));
    $executorStates = new Table($executorsCount);
    $executorStates->column('hostname', Swoole\Table::TYPE_STRING, 128); // Same as key of row
    $executorStates->column('status', Swoole\Table::TYPE_STRING, 8); // 'online' or 'offline'
    $executorStates->column('state', Swoole\Table::TYPE_STRING, 16384); // State as JSON
    $executorStates->create();
    return $executorStates;
});

// Only used if round-robin algo is used
$register->set('roundRobinAtomic', fn () => new Atomic(0));

function fetchExecutorsState(bool $forceShowError = false): void
{
    global $register;

    $executorStates = $register->get('executorStates');

    $executors = \explode(',', (string) App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    $health = new Health();

    foreach ($executors as $executor) {
        $health->addNode(new Node($executor));
    }

    $nodes = $health
        ->run()
        ->getNodes();

    foreach ($nodes as $node) {
        $status = $node->isOnline() ? 'online' : 'offline';
        $hostname = $node->getHostname();

        $oldStatus = $executorStates->exists($hostname) ? $executorStates->get($hostname)['status'] ?? null : null;
        if ($forceShowError === true || ($oldStatus !== null && $oldStatus !== $status)) {
            Console::success('Executor "' . $node->getHostname() . '" went ' . $status . '.');
        }

        $executorStates->set($node->getHostname(), [
            'status' => $status,
            'hostname' => $hostname,
            'state' => \json_encode($node->getState())
        ]);
    }
}

/**
 * Create logger instance
 */
$providerName = App::getEnv('OPEN_RUNTIMES_PROXY_LOGGING_PROVIDER', '');
$providerConfig = App::getEnv('OPEN_RUNTIMES_PROXY_LOGGING_CONFIG', '');
$logger = null;

if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
    $adapter = match ($providerName) {
        'sentry' => new Sentry($providerConfig),
        'raygun' => new Raygun($providerConfig),
        'logown' => new LogOwl($providerConfig),
        'appsignal' => new AppSignal($providerConfig),
        default => throw new Exception('Provider "' . $providerName . '" not supported.')
    };

    $logger = new Logger($adapter);
}

function logError(Throwable $error, string $action, Utopia\Route $route = null): void
{
    global $logger;

    if ($logger) {
        $version = (string) App::getEnv('OPEN_RUNTIMES_PROXY_VERSION', 'UNKNOWN');

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
        $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $isProduction = App::getEnv('OPEN_RUNTIMES_PROXY_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Proxy log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

Console::success('Waiting for executors to start...');

$startupDelay = \intval(App::getEnv('OPEN_RUNTIMES_PROXY_STARTUP_DELAY', '0'));
if ($startupDelay > 0) {
    \sleep((int) ($startupDelay / 1000));
}

\go(function () use ($register) {
    if (App::getEnv('OPEN_RUNTIMES_PROXY_HEALTHCHECK', 'enabled') === 'enabled') {
        fetchExecutorsState(true);
    } else {
        $executorStates = $register->get('executorStates');

        // If no health check, mark all as online
        $executors = \explode(',', (string) App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

        foreach ($executors as $executor) {
            $executorStates->set($executor, [
                'status' => 'online',
                'hostname' => $executor,
                'state' =>  \json_encode([])
            ]);
        }
    }
});

Console::log('State of executors at startup:');

go(function () use ($register) {
    $executorStates = $register->get('executorStates');

    $executors = \explode(',', (string) App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        $state = $executorStates->exists($executor) ? $executorStates->get($executor) : null;

        if ($state === null) {
            Console::warning('Executor ' . $executor . ' has unknown state.');
        } else {
            Console::log('Executor ' . $executor . ' is ' . ($state['status'] ?? 'unknown') . '.');
        }
    }
});

Swoole\Event::wait();

// Wildcard action
App::error()
    ->inject('request')
    ->inject('response')
    ->inject('roundRobinAtomic')
    ->inject('executorStates')
    ->action(function (Request $request, Response $response, Atomic $roundRobinAtomic, Table $executorStates) {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';

        if (empty($secretKey)) {
            throw new Exception('Incorrect proxy key.', 401);
        }
        if ($secretKey !== App::getEnv('OPEN_RUNTIMES_PROXY_SECRET', '')) {
            throw new Exception('Incorrect proxy key.', 401);
        }

        $roundRobinIndex = $roundRobinAtomic->get();

        $algoType = App::getEnv('OPEN_RUNTIMES_PROXY_ALGORITHM', '');
        $algo = match ($algoType) {
            'round-robin' => new RoundRobin($roundRobinIndex - 1), // Atomic indexes from 1. Balancing library indexes from 0. That's why -1
            'random' => new Random(),
            default => new Random()
        };

        $balancing = new Balancing($algo);

        $balancing->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');

        foreach ($executorStates as $state) {
            $balancing->addOption(new Option($state));
        }

        $body = \json_decode($request->getRawPayload() ? $request->getRawPayload() : '{}', true);
        $runtimeId = $body['runtimeId'] ?? null;
        // TODO: @Meldiron Use RuntimeID in CPU-based adapter

        $option = $balancing->run();

        if ($option === null) {
            throw new Exception('No online executor found', 404);
        }

        if ($algo instanceof RoundRobin) {
            $roundRobinAtomic->cmpset($roundRobinIndex, $algo->getIndex() + 1); // +1 because of -1 earlier above
        }

        $executor = [];

        foreach ($option->getStateKeys() as $key) {
            $executor[$key] = $option->getState($key);
        }

        Console::success('Executing on ' . $executor['hostname']);

        $client = new Client($executor['hostname'], 80);
        $client->setMethod($request->getMethod());

        $headers = \array_merge($request->getHeaders(), [
            'authorization' => 'Bearer ' . App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET', '')
        ]);

        // Header used for testing
        $isProduction = App::getEnv('OPEN_RUNTIMES_PROXY_ENV', 'development') === 'production';
        if (!$isProduction) {
            $headers = \array_merge($headers, [
                'x-open-runtimes-executor-hostname' => $executor['hostname']
            ]);
        }

        $client->setHeaders($headers);
        $client->setData($request->getRawPayload());
        $client->execute($request->getURI());

        $response
            ->setStatusCode($client->getStatusCode())
            ->setContentType(Response::CONTENT_TYPE_JSON, self::CHARSET_UTF8)
            ->send($client->getBody(), true);
    });

// TODO: @Meldiron Uncomment once utopia framework supports wildcard
/*
App::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('request')
    ->inject('response')
    ->action(function (App $utopia, throwable $error, Request $request, Response $response) {
        $route = $utopia->match($request);
        logError($error, "httpError", $route);

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
            'version' => App::getEnv('OPEN_RUNTIMES_PROXY_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });
*/

/** @phpstan-ignore-next-line */
Co\run(
    function () use ($register) {
        // Keep updating executors state
        if (App::getEnv('OPEN_RUNTIMES_PROXY_HEALTHCHECK', 'enabled') === 'enabled') {
            Timer::tick(\intval(App::getEnv('OPEN_RUNTIMES_PROXY_PING_INTERVAL', '10000')), function (int $timerId) {
                \go(function () {
                    fetchExecutorsState(false);
                });
            });
        }

        App::setMode(App::MODE_TYPE_PRODUCTION);

        $server = new Server('0.0.0.0', 80, false);

        App::setResource('executorStates', fn () => $register->get('executorStates'));
        App::setResource('roundRobinAtomic', fn () => $register->get('roundRobinAtomic'));

        $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
            $request = new Request($swooleRequest);
            $response = new Response($swooleResponse);

            $app = new App('UTC');

            try {
                $app->run($request, $response);
            } catch (\Throwable $th) {
                $code = $th->getCode() === 0 ? 500 : $th->getCode(); // TODO: @Meldiron Set to 500 once proper utopia framework error handler is done
                logError($th, "serverError");
                $swooleResponse->setStatusCode($code);
                $output = [
                    'message' => 'Error: ' . $th->getMessage(),
                    'code' => $code,
                    'file' => $th->getFile(),
                    'line' => $th->getLine(),
                    'trace' => $th->getTrace()
                ];
                $swooleResponse->end(\json_encode($output));
            }
        });

        Console::success('Functions proxy is ready.');

        $server->start();
    }
);
