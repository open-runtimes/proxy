<?php

namespace Tests;

use Exception;
use OpenRuntimes\Proxy\Report;
use PHPUnit\Framework\TestCase;
use Utopia\Span\Span;

class ReportTest extends TestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = new Recorder();
        Span::setExporters($this->recorder);
    }

    protected function tearDown(): void
    {
        Span::setExporters();
    }

    public function testServerErrorIsExportedWithItsThrowable(): void
    {
        $error = new Exception('Unexpected curl error between proxy and executor', 500);

        Report::error($error, 'http.error', ['http.method' => 'POST', 'http.path' => '/v1/runtimes']);

        $this->assertCount(1, $this->recorder->spans);
        $span = $this->recorder->spans[0];
        $this->assertSame($error, $span->getError());
        $this->assertSame('http.error', $span->getAction());
        $this->assertSame('error', $span->get('level'));
        $this->assertSame('POST', $span->get('http.method'));
        $this->assertSame('/v1/runtimes', $span->get('http.path'));
        $this->assertSame(500, $span->get('error.code'));
        $this->assertTrue($span->get('error.publish'));
    }

    public function testUncodedErrorIsPublished(): void
    {
        $error = new Exception('Executor went away');

        Report::error($error, 'healthcheck.error');

        $this->assertSame($error, $this->recorder->spans[0]->getError());
        $this->assertTrue($this->recorder->spans[0]->get('error.publish'));
    }

    public function testClientErrorIsNotPublished(): void
    {
        $error = new Exception('Incorrect proxy key.', 401);

        Report::error($error, 'http.error');

        $this->assertSame($error, $this->recorder->spans[0]->getError());
        $this->assertSame(401, $this->recorder->spans[0]->get('error.code'));
        $this->assertFalse($this->recorder->spans[0]->get('error.publish'));
    }
}
