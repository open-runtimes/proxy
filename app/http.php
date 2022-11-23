<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenRuntimes\Proxy\Health\Health;
use OpenRuntimes\Proxy\Health\Node;
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
use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;
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
        'first' => new First(),
        'last' => new Last(),
        'random' => new Random(),
        default => new Random()
    };

    return $algo;
});

// Setup Resources
App::setResource('state', fn () => $register->get('state'));
App::setResource('logger', fn () => $register->get('logger'));
App::setResource('algorithm', fn () => $register->get('algorithm'));

// Balancer must NOT be registry. This has to run on every request
App::setResource('balancer', function (Table $state, Algorithm $algorithm, Request $request) {
    $runtimeId = $request->getHeader('x-opr-runtime-id', '');

    $group = new Group();

    // Cold-started-only options
    $balancer1 = new Balancer($algorithm);

    // Only online executors
    $balancer1->addFilter(fn ($option) => $option->getState('status', 'offline') === 'online');

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

    foreach ($state as $stateItem) {
        if (App::isDevelopment()) {
            Console::log("Adding balancing option: " . \json_encode($stateItem));
        }

        /**
         * @var array<string,mixed> $stateItem
         */
        $balancer1->addOption(new Option($stateItem));
        $balancer2->addOption(new Option($stateItem));
    }

    $group
        ->add($balancer1)
        ->add($balancer2);

    return $group;
}, ['state', 'algorithm', 'request']);

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
        $oldStatus = isset($oldState) ? ((array) $oldState)['status'] : null;
        if ($forceShowError === true || (isset($oldStatus) && $oldStatus !== $status)) {
            if ($status === 'online') {
                Console::success('Executor "' . $node->getHostname() . '" went online.');
            } else {
                Console::error('Executor "' . $node->getHostname() . '" went offline.');
            }
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
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

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
        // TODO: @Meldiron Uncomment, was warning: Undefined array key "file" in Sentry.php on line 68
        // $log->addExtra('detailedTrace', $error->getTrace());
        $log->setAction($action);
        $log->setEnvironment(App::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Proxy log pushed with status code: ' . $responseCode);
    }
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
    ->inject('balancer')
    ->inject('state')
    ->inject('request')
    ->inject('response')
    ->action(function (Group $balancer, Table $state, Request $request, Response $response) {
        \var_dump("Action start");
        $option = $balancer->run();

        if (!isset($option)) {
            throw new Exception('No online executor found', 404);
        }

        /**
         * @var string $hostname
         */
        $hostname = $option->getState('hostname') ?? '';

        if (App::isDevelopment()) {
            Console::info("Executing on " . $hostname);
        }

        // Optimistic update. Mark runtime up instantly to prevent race conditions
        // Next health check with confirm it started well, and update usage stats
        $runtimeId = $request->getHeader('x-opr-runtime-id', '');
        if (!empty($runtimeId)) {
            $record = $state->get($hostname);

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

            $state->set($hostname, $record);
        }

        $headers = \array_merge($request->getHeaders(), [
            'authorization' => 'Bearer ' . App::getEnv('OPR_PROXY_EXECUTOR_SECRET', '')
        ]);

        // Header used for testing
        if (App::isDevelopment()) {
            $headers = \array_merge($headers, [
                'x-opr-executor-hostname' => $hostname
            ]);
        }

        $body = $request->getRawPayload();

        $ch = \curl_init();

        \curl_setopt($ch, CURLOPT_URL, $hostname . $request->getURI());
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HEADER, true);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $curlHeaders = [];
        foreach ($headers as $header => $value) {
            $curlHeaders[] = "{$header}: {$value}";
        }

        \curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        \var_dump("Action exc start");

        $executorResponse = \curl_exec($ch);

        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $error = \curl_error($ch);

        $errNo = \curl_errno($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr(\strval($executorResponse), 0, $header_size);
        $body = substr(\strval($executorResponse), $header_size);

        \curl_close($ch);

        \var_dump("Action exc end");

        \var_dump($header);
        \var_dump($body);
        \var_dump($statusCode);
        \var_dump($error);
        \var_dump($errNo);

        if ($errNo !== 0) {
            throw new Exception('Unexpected curl error between proxy and executor: ' . $error);
        }

        if (!empty($headers)) {
            $headers = preg_split("/\r\n|\n|\r/", $headers);
            if ($headers) {
                foreach ($headers as $header) {
                    if (\str_contains($header, ':')) {
                        [ $key, $value ] = \explode(':', $header, 2);

                        $response->addHeader($key, $value);
                    }
                }
            }
        }

        $response
            ->setStatusCode($statusCode)
            ->send($body);

        \var_dump("Action end 2");
    });

App::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('logger')
    ->inject('request')
    ->inject('response')
    ->action(function (App $utopia, throwable $error, ?Logger $logger, Request $request, Response $response) {
        \var_dump("Action error 0");
        \var_dump($error);
        \var_dump("Action error 1");
        $route = $utopia->match($request);
        logError($error, "httpError", $logger, $route);
        \var_dump("Action error 2");

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
        \var_dump("Action error 3");

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode(\intval($code));

        $response->json($output);
        \var_dump("Action error 4");
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
    }

    // Initial health check + start timer
    healthCheck($register, true);

    $defaultInterval = '10000'; // 10 seconds
    Timer::tick(\intval(App::getEnv('OPR_PROXY_HEALTHCHECK_INTERVAL', $defaultInterval)), fn () => healthCheck($register, false));

    $server = new Server('0.0.0.0', 80, false);

    $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
        \var_dump("Hadnle start");
        $request = new Request($swooleRequest);
        $response = new Response($swooleResponse);
        \var_dump("Hadnle start 2");

        $app = new App('UTC');
        \var_dump("Hadnle start 3");

        try {
            $app->run($request, $response);
            \var_dump("Hadnle end");
        } catch (\Throwable $th) {
            \var_dump("Handle error 1");
            \var_dump($th);
            \var_dump("Handle error 2");

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

            \var_dump("Handle error 3");
            $swooleResponse->end(\json_encode($output));
            \var_dump("Handle error 4");
        }
    });

    Console::success('Functions proxy is ready.');

    $server->start();
});
