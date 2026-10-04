<?php

namespace Tests;

use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Span;

class Recorder implements Exporter
{
    /**
     * @var array<Span>
     */
    public array $spans = [];

    public function export(Span $span): void
    {
        $this->spans[] = $span;
    }

    public function sample(Span $span): bool
    {
        return true;
    }
}
