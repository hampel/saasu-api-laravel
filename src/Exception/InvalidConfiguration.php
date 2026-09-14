<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Exception;

use Hampel\Saasu\Api\Exception\SaasuException;

/**
 * The configuration cannot be turned into a client.
 *
 * Raised when the client is built rather than letting a half-configured connection through. A
 * missing credential would otherwise reach Saasu and come back 401 - which reads as a revoked
 * login rather than as an unset environment variable, and spends a request from the daily quota
 * to say so.
 */
final class InvalidConfiguration extends SaasuException
{
    public static function missingCredential(string $connection): self
    {
        return new self(sprintf(
            'Saasu connection "%s" has no credential. Set a username and password, or an access_key, '
                . 'in config/saasu.php - or in the environment if the shipped config is in use.',
            $connection
        ));
    }

    /**
     * One half of an OAuth login. Refused rather than falling back to an access key, so an unset
     * password cannot quietly move an application onto the legacy scheme.
     */
    public static function incompleteLogin(string $connection, string $missing): self
    {
        return new self(sprintf(
            'Saasu connection "%s" has a %s but no %s. OAuth needs both, and a connection with either '
                . 'is not allowed to fall back to its access_key.',
            $connection,
            $missing === 'password' ? 'username' : 'password',
            $missing
        ));
    }

    public static function accessKeyWithoutFile(string $connection): self
    {
        return new self(sprintf(
            'Saasu connection "%s" uses an access_key but has no file_id. A web services access key '
                . 'belongs to one file, so the connection has to name it.',
            $connection
        ));
    }

    public static function fileId(string $connection, mixed $value): self
    {
        return new self(sprintf(
            'Saasu connection "%s" has a file_id of %s. It must be a positive whole number - the file '
                . 'id shown in Saasu under Settings, Web Services.',
            $connection,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value)
        ));
    }

    public static function throttle(mixed $value): self
    {
        return new self(sprintf(
            'saasu.throttle is %s. Use "cache" to share the rate limit across processes, "process" '
                . 'to keep it within each one, or "none" in a test suite.',
            is_scalar($value) ? var_export($value, true) : get_debug_type($value)
        ));
    }

    /**
     * The cache store cannot hold a lock, which the shared throttle needs.
     */
    public static function storeWithoutLocks(string $store): self
    {
        return new self(sprintf(
            'The cache store "%s" does not support atomic locks, which saasu.throttle "cache" needs. '
                . 'Set saasu.cache_store to a store that does - redis, database, memcached, dynamodb '
                . 'or file - or set saasu.throttle to "process".',
            $store
        ));
    }

    /**
     * A container binding an application rebinds to supply its own implementation holds
     * something else.
     */
    public static function binding(string $key, string $expected, string $type): self
    {
        return new self(sprintf(
            'The container binding %s must be a %s; it resolved to %s.',
            $key,
            $expected,
            $type
        ));
    }

    /**
     * The core package refused a setting.
     *
     * Wrapped rather than passed through, so the message names the configuration rather than an
     * argument - and kept as the previous exception, so the original reason is still readable.
     */
    public static function api(string $connection, \Throwable $previous): self
    {
        return new self(
            sprintf(
                'The saasu.page_size, saasu.base_uri and connection "%s" settings do not describe an API '
                    . 'this package can talk to. %s',
                $connection,
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}
