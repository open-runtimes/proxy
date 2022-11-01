<?php

require_once __DIR__ . '/../vendor/autoload.php';

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

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

$executorsCount = \count(\explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', '')));
$executorStates = new Table($executorsCount);
$executorStates->column('hostname', Swoole\Table::TYPE_STRING, 128); // Same as key of row
$executorStates->column('status', Swoole\Table::TYPE_STRING, 8); // 'online' or 'offline'
$executorStates->column('state', Swoole\Table::TYPE_STRING, 16384); // State as JSON
$executorStates->create();

$roundRobinAtomic = new Atomic(0); // Only used if round-robin algo is used

function markOffline(string $executorHostname, string $error, bool $forceShowError = false): void
{
    global $executorStates;

    $oldState = $executorStates->exists($executorHostname) ? $executorStates->get($executorHostname) : [];

    $tableState = [
        'status' => 'offline',
        'hostname' => $executorHostname,
        'state' => \json_encode([])
    ];

    $executorStates->set($executorHostname, $tableState);

    if (!$oldState || ($oldState['status'] ?? '') === 'online' || $forceShowError) {
        Console::warning('Executor "' . $executorHostname . '" went down! Message:');
        Console::warning($error);
    }
}

/**
 * @param array<string, mixed> $state
 */
function markOnline(string $executorHostname, array $state, bool $forceShowError = false): void
{
    global $executorStates;

    $oldState = $executorStates->exists($executorHostname) ? $executorStates->get($executorHostname) : [];

    $tableState = [
        'status' => 'online',
        'hostname' => $executorHostname,
        'state' => \json_encode($state)
    ];

    $executorStates->set($executorHostname, $tableState);

    if (!$oldState || ($oldState['status'] ?? '') === 'offline' || $forceShowError) {
        Console::success('Executor "' . $executorHostname . '" went online.');
    }
}

function fetchExecutorsState(bool $forceShowError = false): void
{
    $executors = \explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($executor, $forceShowError) {
            try {
                $endpoint = 'http://' . $executor . '/v1/health';

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
                        markOnline($executor, $body, $forceShowError);
                    } else {
                        $message = 'Response does not include "pass" status.';
                        markOffline($executor, $message, $forceShowError);
                    }
                } else {
                    $message = 'Code: ' . $statusCode . ' with response "' . $executorResponse .  '" and error error: ' . $error;
                    markOffline($executor, $message, $forceShowError);
                }
            } catch (\Exception $err) {
                throw $err;
            }
        });
    }
}

/**
 * Create logger instance
 */
$providerName = App::getEnv('OPEN_RUNTIMES_PROXY_LOGGING_PROVIDER', '');
$providerConfig = App::getEnv('OPEN_RUNTIMES_PROXY_LOGGING_CONFIG', '');
$logger = null;

if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);

    /**
     * @var Adapter $adapter
     */
    $adapter = new $classname($providerConfig);
    $logger = new Logger($adapter);
}

function logError(Throwable $error, string $action, Utopia\Route $route = null): void
{
    global $logger;

    if ($logger) {
        $version = App::getEnv('OPEN_RUNTIMES_PROXY_VERSION', 'UNKNOWN');

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

Console::success("Waiting for executors to start...");

$startupDelay = (int) App::getEnv('OPEN_RUNTIMES_PROXY_STARTUP_DELAY', 0);
if ($startupDelay > 0) {
    \sleep((int) ($startupDelay / 1000));
}

if (App::getEnv('OPEN_RUNTIMES_PROXY_OPTIONS_HEALTHCHECK', 'enabled') === 'enabled') {
    fetchExecutorsState(true);
} else {
    // If no health check, mark all as online
    $executors = \explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        $executorStates->set($executor, [
            'status' => 'online',
            'hostname' => $executor,
            'state' =>  \json_encode([])
        ]);
    }
}

Console::log("State of executors at startup:");

go(function () use ($executorStates) {
    $executors = \explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

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

$run = function (SwooleRequest $request, SwooleResponse $response) {
    global $executorStates;
    global $roundRobinAtomic;

    $secretKey = \explode(' ', $request->header['authorization'] ?? '')[1] ?? '';

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

    $body = \json_decode($request->getContent() ? $request->getContent() : '{}', true);
    $runtimeId = $body['runtimeId'] ?? null;
    // TODO: @Meldiron Use RuntimeID in CPU-based adapter

    $option = $balancing->run();

    if ($option === null) {
        throw new Exception("No online executor found", 404);
    }

    if ($algo instanceof RoundRobin) {
        $roundRobinAtomic->cmpset($roundRobinIndex, $algo->getIndex() + 1); // +1 because of -1 earlier above
    }

    $executor = [];

    foreach ($option->getStateKeys() as $key) {
        $executor[$key] = $option->getState($key);
    }

    Console::success("Executing on " . $executor['hostname']);

    $client = new Client($executor['hostname'], 80);
    $client->setMethod($request->server['request_method'] ?? 'GET');

    $headers = \array_merge($request->header, [
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
    $client->setData($request->getContent());

    $status = $client->execute($request->server['request_uri'] ?? '/');

    $response->setStatusCode($client->getStatusCode());
    $response->header('content-type', 'application/json; charset=UTF-8');
    $response->write($client->getBody());
    $response->end();
};

/** @phpstan-ignore-next-line */
Co\run(
    function () use ($run) {
        // Keep updating executors state
        if (App::getEnv('OPEN_RUNTIMES_PROXY_OPTIONS_HEALTHCHECK', 'enabled') === 'enabled') {
            Timer::tick((int) App::getEnv('OPEN_RUNTIMES_PROXY_PING_INTERVAL', 10000), function (int $timerId) {
                fetchExecutorsState(false);
            });
        }
        $server = new Server('0.0.0.0', 80, false);

        $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($run) {
            try {
                call_user_func($run, $swooleRequest, $swooleResponse);
            } catch (\Throwable $th) {
                $code = $th->getCode();
                $code = $code === 0 ? 500 : $code;
                logError($th, "serverError");

                $output = [
                    'message' => 'Error: ' . $th->getMessage(),
                    'code' => $code,
                    'file' => $th->getFile(),
                    'line' => $th->getLine(),
                    'trace' => $th->getTrace()
                ];

                $swooleResponse->setStatusCode($code);
                $swooleResponse->header('content-type', 'application/json; charset=UTF-8');
                $swooleResponse->write(\json_encode($output));
                $swooleResponse->end();
            }
        });

        Console::success("Functions proxy is ready.");

        $server->start();
    }
);
