<?php

namespace OpenRuntimes\Tests;

use PHPUnit\Framework\TestCase;
use OpenRuntimes\State\State;

abstract class Base extends TestCase
{
    /**
     * @var State
     */
    protected static $state = null;

    protected function tearDown(): void
    {
        self::$state->flush();
    }

    public function testSaveExecutor(): void
    {
        $hostname = uniqid('executor', true);
        $executor = self::$state->saveExecutor($hostname, 'online', 100);

        $this->assertEquals(true, $executor);

        $executor = self::$state->saveExecutor($hostname, 'offline', 0);
        $this->assertEquals(true, $executor);
    }

    public function testListExecutors(): void
    {
        $hostname = uniqid('executor', true);
        $executor = self::$state->saveExecutor($hostname, 'online', 100);

        $this->assertEquals(true, $executor);

        $executors = self::$state->listExecutors();

        $this->assertIsArray($executors);
        $expectedKey = 'executor.runtimes:' . $hostname;
        $this->assertArrayHasKey($expectedKey, $executors);
        $this->assertArrayHasKey('status', $executors[$expectedKey]);
        $this->assertArrayHasKey('usage', $executors[$expectedKey]);
        $this->assertEquals('online', $executors[$expectedKey]['status']);
        $this->assertEquals(100, $executors[$expectedKey]['usage']);
    }

    public function testSaveRuntime(): void
    {
        $hostname = uniqid('executor', true);
        $runtimeId = uniqid('runtime', true);

        $data = self::$state->saveRuntime($hostname, $runtimeId, 'online', 100);
        $this->assertEquals(true, $data);

        $data = self::$state->saveRuntime($hostname, $runtimeId, 'offline', 0);
        $this->assertEquals(true, $data);
    }

    public function testSaveRuntimes(): void
    {
        $hostname = uniqid('executor', true);

        $runtimeIdOne = uniqid('runtime', true);
        $runtimeIdTwo = uniqid('runtime', true);

        $data = self::$state->saveRuntimes($hostname, [
            $runtimeIdOne => [
                'status' => 'online',
                'usage' => 100,
            ],
            $runtimeIdTwo => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ]);
        $this->assertEquals(true, $data);

        $runtimes = self::$state->listRuntimes($hostname);

        $this->assertIsArray($runtimes);
        $this->assertCount(2, $runtimes);
        $this->assertArrayHasKey($runtimeIdOne, $runtimes);
        $this->assertArrayHasKey($runtimeIdTwo, $runtimes);
        $this->assertArrayHasKey('status', $runtimes[$runtimeIdOne]);
        $this->assertArrayHasKey('usage', $runtimes[$runtimeIdOne]);
        $this->assertEquals('online', $runtimes[$runtimeIdOne]['status']);
        $this->assertEquals(100, $runtimes[$runtimeIdOne]['usage']);
        $this->assertEquals('offline', $runtimes[$runtimeIdTwo]['status']);
        $this->assertEquals(0, $runtimes[$runtimeIdTwo]['usage']);
    }

    public function testListRuntimes(): void
    {
        $hostname = uniqid('executor', true);
        $runtimeId = uniqid('runtime', true);

        $data = self::$state->saveRuntime($hostname, $runtimeId, 'online', 100);
        $this->assertEquals(true, $data);

        $runtimes = self::$state->listRuntimes($hostname);

        $this->assertIsArray($runtimes);
        $this->assertCount(1, $runtimes);
        $this->assertArrayHasKey($runtimeId, $runtimes);
        $this->assertArrayHasKey('status', $runtimes[$runtimeId]);
        $this->assertArrayHasKey('usage', $runtimes[$runtimeId]);
        $this->assertEquals('online', $runtimes[$runtimeId]['status']);
        $this->assertEquals(100, $runtimes[$runtimeId]['usage']);
    }
}