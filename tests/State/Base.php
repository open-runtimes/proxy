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

    protected function setUp(): void
    {
        self::$state->flush();
    }

    public function testSave(): void
    {
        $resource = uniqid();

        // test saving
        $name = uniqid();
        $result = self::$state->save($resource, $name, 'online', 100);

        $this->assertEquals(true, $result);
        $this->assertEquals([
            $name => [
                'status' => 'online',
                'usage' => 100,
            ],
        ], self::$state->list($resource));

        // test updating
        $result = self::$state->save($resource, $name, 'offline', 0);
        $this->assertEquals(true, $result);

        $this->assertEquals([
            $name => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ], self::$state->list($resource));

        // test adding
        $nameTwo = uniqid($resource, true);
        $result = self::$state->save($resource, $nameTwo, 'online', 95);

        $this->assertEquals(true, $result);

        $this->assertEquals([
            $name => [
                'status' => 'offline',
                'usage' => 0,
            ],
            $nameTwo => [
                'status' => 'online',
                'usage' => 95,
            ],
        ], self::$state->list($resource));
    }

    public function testSaveAll(): void 
    {
        $resource = uniqid();

        // test saving
        $nameOne = uniqid();
        $nameTwo = uniqid();

        $entries = [
            $nameOne => [
                'status' => 'online',
                'usage' => 100,
            ],
            $nameTwo => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ];

        $data = self::$state->saveAll($resource, $entries);
        $this->assertEquals(true, $data);

        $this->assertEquals($entries, self::$state->list($resource));

        // test updating
        $entries[$nameOne]['status'] = 'offline';

        $data = self::$state->saveAll($resource, $entries);
        $this->assertEquals(true, $data);

        $this->assertEquals($entries, self::$state->list($resource));
    }

    public function testNestedResource(): void
    {
        $resourceNested = uniqid();

        // test saving
        $resourceId = uniqid();
        $name = uniqid();

        $data = self::$state->save($resourceNested . $resourceId, $name, 'online', 100);
        $this->assertEquals(true, $data);

        $this->assertEquals([
            $name => [
                'status' => 'online',
                'usage' => 100,
            ],
        ], self::$state->list($resourceNested . $resourceId));

        // test updating
        $data = self::$state->save($resourceNested . $resourceId, $name, 'offline', 0);
        $this->assertEquals(true, $data);

        $this->assertEquals([
            $name => [
                'status' => 'offline',
                'usage' => 0,
            ],
        ], self::$state->list($resourceNested . $resourceId));

        $nameTwo = uniqid();

        $data = self::$state->save($resourceNested . $resourceId, $nameTwo, 'online', 95);

        $this->assertEquals(true, $data);

        $this->assertEquals([
            $name => [
                'status' => 'offline',
                'usage' => 0,
            ],
            $nameTwo => [
                'status' => 'online',
                'usage' => 95,
            ],
        ], self::$state->list($resourceNested . $resourceId));
    }
}
