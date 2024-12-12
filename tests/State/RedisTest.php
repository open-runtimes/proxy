<?php

namespace Tests\State;

use OpenRuntimes\State\State;
use Redis as Redis;
use OpenRuntimes\State\Adapter\Redis as RedisState;

class RedisTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        self::$state = new RedisState($redis);
    }

    public static function tearDownAfterClass(): void
    {
        // @phpstan-ignore-next-line
        self::$state = null;
    }
}
