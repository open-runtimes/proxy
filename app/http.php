<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Swoole\Atomic;
use Swoole\Coroutine\Http\Client;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Swoole\Coroutine\Http\Server;
use function Swoole\Coroutine\run;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\App;
use Utopia\Balancing\Algorithm\Random;
use Utopia\Balancing\Algorithm\RoundRobin;
use Utopia\CLI\Console;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

$executorStates = new Table(1024);
$executorStates->column('status', Swoole\Table::TYPE_STRING, 8); // 'online' or 'offline'
$executorStates->column('state', Swoole\Table::TYPE_STRING, 16384); // State as JSON
$executorStates->create();

function markOffline(Table $executorStates, string $executorHostname, string $error, bool $forceShowError = false)
{
    $oldState = $executorStates->exists($executorHostname) ? $executorStates->get($executorHostname) : [];
    
    $state['status'] = 'offline';
    $state['state'] = \json_encode([]);
    $executorStates->set($executorHostname, $state);

    if (!$oldState || ($oldState['status'] ?? '') === 'online' || $forceShowError) {
        Console::warning('Executor "' . $executorHostname . '" went down! Message:');
        Console::warning($error);
    }
}

function markOnline(Table $executorStates, string $executorHostname, array $state, bool $forceShowError = false)
{
    $oldState = $executorStates->exists($executorHostname) ? $executorStates->get($executorHostname) : [];
    
    $state['status'] = 'offline';
    $state['state'] = \json_encode($state);
    $executorStates->set($executorHostname, $state);

    if (!$oldState || ($oldState['status'] ?? '') === 'online' || $forceShowError) {
        Console::success('Executor "' . $executorHostname . '" went online.');
    }
}

function fetchExecutorsState(Table $executorStates, bool $forceShowError = false)
{
    $executors = \explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($executor, $forceShowError, $executorStates) {
            try {
                $endpoint = 'http://' . $executor . ':3000/v1/health';

                $ch = \curl_init();

                \curl_setopt($ch, CURLOPT_URL, $endpoint);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'x-appwrite-executor-key: ' . App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET', '')
                ]);

                $executorResponse = \curl_exec($ch);
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = \curl_error($ch);

                \curl_close($ch);

                if ($statusCode === 200) {
                    markOnline($executorStates, $executor, \json_decode($executorResponse, true), $forceShowError);
                } else {
                    $message = 'Code: ' . $statusCode . ' with response "' . $executorResponse .  '" and error error: ' . $error;
                    markOffline($executorStates, $executor, $message, $forceShowError);
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
    $adapter = new $classname($providerConfig);
    $logger = new Logger($adapter);
}

function logError(Throwable $error, string $action, Utopia\Route $route = null)
{
    global $logger;

    if ($logger) {
        $version = App::getEnv('OPEN_RUNTIMES_PROXY_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("executor");
        $log->setServer(\gethostname());
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
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

Console::success("Waiting for executors to start...");

\sleep(5);

fetchExecutorsState($executorStates, true);

Console::log("State of executors at startup:");

go(function () use($executorStates) {
    $executors = \explode(',', App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTORS', ''));

    foreach ($executors as $executor) {
        $state = $executorStates->exists($executor) ? $executorStates->get($executor) : null;

        if($state === null) {
            Console::warning("Executor" . $executor . ' has unknown state.');
        } else {
            Console::log('Executor ' . $executor . ' is ' . ($state['status'] ?? 'unknown') . '.');
        }
    }
});

Swoole\Event::wait();

$run = function (SwooleRequest $request, SwooleResponse $response) use ($adapter) {
    $secretKey = $request->header['x-appwrite-executor-key'] ?? '';

    if (empty($secretKey)) {
        throw new Exception('Missing proxy key');
    }
    if ($secretKey !== App::getEnv('OPEN_RUNTIMES_PROXY_SECRET', '')) {
        throw new Exception('Missing proxy key');
    }

    // TODO: @Meldiron Support more algos

    $adapterType = App::getEnv('OPEN_RUNTIMES_PROXY_ALGORITHM', '');
    $adapter = match ($adapterType) {
        'round-robin' => new RoundRobin(),
        'random' => new Random(),
        default => new Random()
    };

    if($adapter->getName() === 'RoundRobin') {
        $roundRobinAtomic = new Atomic(-1);
    }

    $body = \json_decode($request->getContent(), true);
    $runtimeId = $body['runtimeId'] ?? null;
    $executor = $adapter->getNextExecutor($runtimeId);

    Console::success("Executing on " . $executor['hostname']);

    $client = new Client($executor['hostname'], 80);
    $client->setMethod($request->server['request_method'] ?? 'GET');
    $client->setHeaders(\array_merge($request->header, [
        'x-appwrite-executor-key' => App::getEnv('OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET', '')
    ]));
    $client->setData($request->getContent());

    $status = $client->execute($request->server['request_uri'] ?? '/');

    $response->setStatusCode($client->getStatusCode());
    $response->header('content-type', 'application/json; charset=UTF-8');
    $response->write($client->getBody());
    $response->end();
};

run(function () use ($executorStates, $run) {
    // TODO: @Meldiron Allow scaling. Only do this on one machine, or only worry about executors on my host machine

    // Keep updating executors state
    Timer::tick((int) App::getEnv('OPEN_RUNTIMES_PROXY_PING_INTERVAL', 10000), function (int $timerId) use ($executorStates) {
        fetchExecutorsState($executorStates, false);
    });

    $server = new Server('0.0.0.0', 80, false);

    $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($run) {
        try {
            call_user_func($run, $swooleRequest, $swooleResponse);
        } catch (\Throwable $th) {
            logError($th, "serverError");

            $output = [
                'message' => 'Error: ' . $th->getMessage(),
                'code' => 500,
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTrace()
            ];

            $swooleResponse->setStatusCode(500);
            $swooleResponse->header('content-type', 'application/json; charset=UTF-8');
            $swooleResponse->write(\json_encode($output));
            $swooleResponse->end();
        }
    });

    Console::success("Functions proxy is ready.");

    $server->start();
});
