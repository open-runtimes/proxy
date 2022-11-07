<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\App;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Balancing\Algorithm;
use Utopia\Balancing\Algorithm\Random;
use Utopia\Balancing\Algorithm\RoundRobin;
use Utopia\Balancing\Balancing;
use Utopia\Balancing\Option;
use Utopia\CLI\Console;
use Utopia\Registry\Registry;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;

use function Swoole\Coroutine\run;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

App::setMode((string) App::getEnv('OPR_PROXY_ENV', App::MODE_TYPE_PRODUCTION));

// Setup Registry
$register = new Registry();

$register->set('state', function () {
    $count = \count(\explode(',', (string) App::getEnv('OPR_PROXY_EXECUTORS', '')));
    $state = new Table($count);
    $state->column('hostname', Swoole\Table::TYPE_STRING, 128); // Same as key of row
    $state->column('status', Swoole\Table::TYPE_STRING, 8); // 'online' or 'offline'
    $state->column('state', Swoole\Table::TYPE_STRING, 16384); // State as JSON
    $state->create();
    return $state;
});

$register->set('logger', function () {
    $providerName = App::getEnv('OPR_PROXY_LOGGING_PROVIDER', '');
    $providerConfig = App::getEnv('OPR_PROXY_LOGGING_CONFIG', '');
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
    $algoType = App::getEnv('OPR_PROXY_ALGORITHM', '');
    $algo = match ($algoType) {
        'round-robin' => new RoundRobin(0),
        'random' => new Random(),
        default => new Random()
    };

    return $algo;
});

// Setup Resources
App::setResource('state', fn () => $register->get('state'));
App::setResource('logger', fn () => $register->get('logger'));
App::setResource('algorithm', fn () => $register->get('algorithm'));

// Balancing must NOT be registry. This has to run on every request
App::setResource('balancing', function (Table $state, Algorithm $algorithm) {
    $balancing = new Balancing($algorithm);

    $balancing->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');

    foreach ($state as $stateItem) {
        /**
         * @var array<string,mixed> $stateItem
         */
        $balancing->addOption(new Option($stateItem));
    }

    return $balancing;
}, ['state', 'algorithm']);

function healthCheck(Registry $register, bool $forceShowError = false): void
{
    /**
     * @var Table $state
     */
    $state = $register->get('state');

    $executors = \explode(',', (string) App::getEnv('OPR_PROXY_EXECUTORS', ''));

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

        $oldState = $state->exists($hostname) ? $state->get($hostname) : null;
        $oldStatus = $oldState !== null ? ((array) $oldState)['status'] : null;
        if ($forceShowError === true || ($oldStatus !== null && $oldStatus !== $status)) {
            Console::success('Executor "' . $node->getHostname() . '" went ' . $status . '.');
        }

        $state->set($node->getHostname(), [
            'status' => $status,
            'hostname' => $hostname,
            'state' => \json_encode($node->getState())
        ]);
    }
}

function logError(Throwable $error, string $action, ?Logger $logger, Utopia\Route $route = null): void
{
    if ($logger) {
        $version = (string) App::getEnv('OPR_PROXY_VERSION', 'UNKNOWN');

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
        $log->setEnvironment(App::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Proxy log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

App::init()
    ->inject('request')
    ->action(function (Request $request) {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';

        if (empty($secretKey) || $secretKey !== App::getEnv('OPR_PROXY_SECRET', '')) {
            throw new Exception('Incorrect proxy key.', 401);
        }
    });

App::wildcard()
    ->inject('balancing')
    ->inject('state')
    ->inject('request')
    ->inject('response')
    ->action(function (Balancing $balancing, Table $state, Request $request, Response $response) {
        // TODO: @Meldiron Use RuntimeID (from body or header) to prefer executors with warm runtime
        /*
        $body = \json_decode($request->getRawPayload() ? $request->getRawPayload() : '{}', true);
        $runtimeId = $body['runtimeId'] ?? null;
        */

        $option = $balancing->run();

        if ($option === null) {
            throw new Exception('No online executor found', 404);
        }

        /**
         * @var string $hostname
         */
        $hostname = $option->getState('hostname') ?? '';

        // Optimistic update. Mark runtime up instantly to prevent race conditions
        // Next health check with confirm it started well, and update usage stats
        $runtimeId = $request->getHeader('x-opr-runtime-id', '');
        if (!empty($runtimeId)) {
            /**
             * @var array<string,mixed> $stateItem
             */
            $stateItem = $state->get($hostname);

            if ($stateItem['runtimes'] ?? null === null) {
                $stateItem['runtimes'] = [];
            }

            if ($stateItem['runtimes'][$runtimeId] ?? null === null) {
                /** @phpstan-ignore-next-line */
                $stateItem['runtimes'][$runtimeId] = [];
            }

            $stateItem['runtimes'][$runtimeId]['status'] = 'pass';
            $state->set($hostname, $stateItem);
        }


        $client = new Client($hostname, 80);
        $client->setMethod($request->getMethod());

        $headers = \array_merge($request->getHeaders(), [
            'authorization' => 'Bearer ' . App::getEnv('OPR_PROXY_EXECUTOR_SECRET', '')
        ]);

        // Header used for testing
        if (App::isDevelopment()) {
            $headers = \array_merge($headers, [
                'x-open-runtimes-executor-hostname' => $hostname
            ]);
        }

        $client->setHeaders($headers);
        $client->setData($request->getRawPayload());
        $client->execute($request->getURI());

        foreach ($client->headers as $header => $value) {
            $response->addHeader($header, $value);
        }

        $response
            ->setStatusCode(\intval($client->getStatusCode()))
            ->send(\strval($client->getBody()));
    });

App::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('logger')
    ->inject('request')
    ->inject('response')
    ->action(function (App $utopia, throwable $error, ?Logger $logger, Request $request, Response $response) {
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
            'version' => App::getEnv('OPR_PROXY_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode(\intval($code));

        $response->json($output);
    });

run(function () use ($register) {
    // If no health check, mark all as online
    if (App::getEnv('OPR_PROXY_HEALTHCHECK', 'enabled') === 'disabled') {
        /**
         * @var Table $state
         */
        $state = $register->get('state');
        $executors = \explode(',', (string) App::getEnv('OPR_PROXY_EXECUTORS', ''));

        foreach ($executors as $executor) {
            $state->set($executor, [
                'status' => 'online',
                'hostname' => $executor,
                'state' =>  \json_encode([])
            ]);
        }

        return;
    }

    // Initial health check + start timer
    healthCheck($register, true);

    $defaultInterval = '10000'; // 10 seconds
    Timer::tick(\intval(App::getEnv('OPR_PROXY_HEALTHCHECK_INTERVAL', $defaultInterval)), fn () => healthCheck($register, false));

    $server = new Server('0.0.0.0', 80, false);

    $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
        $request = new Request($swooleRequest);
        $response = new Response($swooleResponse);

        $app = new App('UTC');

        try {
            $app->run($request, $response);
        } catch (\Throwable $th) {
            $code = 500;

            /**
             * @var Logger $logger
             */
            $logger = $app->getResource('logger');
            logError($th, "serverError", $logger);
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
});
