<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests\Fixture;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that keeps values and cannot lock - the shape of a custom or third-party driver
 * that never implemented LockProvider. Every store Laravel ships can lock, so there is none of
 * those to use instead.
 */
final class LocklessStore implements Store
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get($key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @param  array<array-key, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->values[$key] ?? null;
        }

        return $found;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }

        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        return false;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return false;
    }

    public function forever($key, $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /**
     * Untyped, as the contract declares it: Laravel 13 added touch() to Store, and a typed parameter
     * would be narrower than the interface's.
     *
     * @param  string  $key
     * @param  int  $seconds
     */
    public function touch($key, $seconds): bool
    {
        return isset($this->values[$key]);
    }

    public function forget($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
