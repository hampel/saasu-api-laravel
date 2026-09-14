<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Exception;

use Hampel\Saasu\Api\Exception\SaasuException;

/**
 * A connection was asked for by a name that is not in the configuration.
 *
 * Extends the core package's base exception, so an application already catching SaasuException -
 * or ExceptionInterface - catches a misconfigured connection name alongside every other way a
 * call can fail.
 */
final class UnknownConnection extends SaasuException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'Saasu connection "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No connections are configured; add one under saasu.connections.'
                : 'Configured connections: ' . implode(', ', $configured) . '.'
        ));
    }
}
