<?php

namespace OpenRuntimes\Tests;

use RedisCluster as RedisCluster;
use OpenRuntimes\State\Adapter\RedisCluster as RedisAdapter;
use OpenRuntimes\State\State;

class RedisClusterTest extends Base
{
    protected static RedisCluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = new RedisCluster(null, ['redis-cluster-0:6379', 'redis-cluster-1:6379', 'redis-cluster-2:6379']);
        self::$state = new State(new RedisAdapter(self::$redis));
    }

    public static function tearDownAfterClass(): void
    {
        // @phpstan-ignore-next-line
        self::$state = null;
    }
}
