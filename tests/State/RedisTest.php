<?php

namespace OpenRuntimes\Tests;

use OpenRuntimes\State\State;
use Redis as Redis;
use OpenRuntimes\State\Adapter\Redis as RedisAdapter;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$state = new State(new RedisAdapter($redis));
    }

    public static function tearDownAfterClass(): void
    {
        // @phpstan-ignore-next-line
        self::$state = null;
    }
}
