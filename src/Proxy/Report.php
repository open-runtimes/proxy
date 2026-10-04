<?php

namespace OpenRuntimes\Proxy;

use Throwable;
use Utopia\Span\Span;

class Report
{
    /**
     * Report a throwable on its own span so the registered exporters can ship it.
     *
     * Client errors (4xx) are marked error.publish=false: they still reach stdout,
     * but the Sentry exporter's sampler drops them.
     *
     * @param array<string, string|int|float|bool|null> $attributes
     */
    public static function error(Throwable $error, string $action, array $attributes = []): void
    {
        $code = (int) $error->getCode();

        $span = Span::init($action);
        foreach ($attributes as $key => $value) {
            $span->set($key, $value);
        }
        $span->set('error.code', $code);
        $span->set('error.publish', $code === 0 || $code >= 500);
        $span->finish(error: $error);
    }
}
