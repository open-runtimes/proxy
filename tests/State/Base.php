<?php

namespace Tests\State;

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

    public function testSave(): void
    {
        // test saving
        $hostname = uniqid();
        $executor = self::$state->save('executor', $hostname, 'online', 100);

        $this->assertEquals(true, $executor);

        $this->assertEquals([
            $hostname => [
                'status' => 'online',
                'usage' => 100,
            ],
        ], self::$state->list('executor'));

        // test updating
        $executor = self::$state->save('executor', $hostname, 'offline', 0);
        $this->assertEquals(true, $executor);

        $this->assertEquals([
            $hostname => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ], self::$state->list('executor'));

        // test adding
        $hostnameTwo = uniqid('executor', true);
        $executor = self::$state->save('executor', $hostnameTwo, 'online', 95);

        $this->assertEquals(true, $executor);

        $this->assertEquals([
            $hostname => [
                'status' => 'offline',
                'usage' => 100,
            ],
            $hostnameTwo => [
                'status' => 'online',
                'usage' => 95,
            ],
        ], self::$state->list('executor'));
    }

    public function testSaveAll(): void 
    {
        // test saving
        $hostnameOne = uniqid();
        $hostnameTwo = uniqid();

        $entries = [
            $hostnameOne => [
                'status' => 'online',
                'usage' => 100,
            ],
            $hostnameTwo => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ];

        $data = self::$state->saveAll('executor', $entries);
        $this->assertEquals(true, $data);

        $this->assertEquals($entries, self::$state->list('executor'));

        // test updating
        $entries[$hostnameOne]['status'] = 'offline';

        $data = self::$state->saveAll('executor', $entries);
        $this->assertEquals(true, $data);

        $this->assertEquals($entries, self::$state->list('executor'));
    }

    public function testNestedResource(): void
    {
        // test saving
        $hostname = uniqid();
        $runtimeId = uniqid();

        $data = self::$state->save('executor:' . $hostname, $runtimeId, 'online', 100);
        $this->assertEquals(true, $data);

        $this->assertEquals([
            $runtimeId => [
                'status' => 'online',
                'usage' => 100,
            ],
        ], self::$state->list('executor:' . $hostname));

        // test updating
        $data = self::$state->save('executor:' . $hostname, $runtimeId, 'offline', 0);
        $this->assertEquals(true, $data);

        $this->assertEquals([
            $runtimeId => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ], self::$state->list('executor:' . $hostname));

        $runtimeIdTwo = uniqid();

        $data = self::$state->save('executor:' . $hostname, $runtimeIdTwo, 'online', 95);

        $this->assertEquals(true, $data);

        $this->assertEquals([
            $runtimeId => [
                'status' => 'offline',
                'usage' => 0,
            ],
            $runtimeIdTwo => [
                'status' => 'online',
                'usage' => 95,
            ],
        ], self::$state->list('executor:' . $hostname));
    }
}
