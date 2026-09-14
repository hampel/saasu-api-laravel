<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Cache;

use Hampel\Saasu\Api\Authentication\AccessGrant;
use Hampel\Saasu\Api\Authentication\TokenStore;
use Hampel\Saasu\Api\Exception\InvalidArgumentException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps OAuth grants in a Laravel cache store, so every process shares one token per login.
 *
 * THE REASON THIS EXISTS IS THE QUOTA, NOT SPEED. Every token request counts against the file's
 * daily quota, and a PHP application is many short processes: with the core package's in-memory
 * store, each web request and each worker restart would log in again. Saasu blocks the whole
 * file for 24 hours once the quota is exceeded.
 *
 * NO LOCK AROUND A REFRESH. Measured against the live API on 2026-09-14: a refresh does not
 * rotate the refresh token, and the old one keeps working. Two workers that find the same token
 * expired both refresh it and both succeed, at the cost of one extra request - not a stranded
 * credential.
 *
 * STORED WITHOUT AN EXPIRY. The grant carries its own, and OAuth checks it on every request. An
 * expired grant is still worth keeping for its refresh token, and a refresh costs the same one
 * request as logging in again, so a cache TTL would buy nothing.
 *
 * Stored as AccessGrant::toArray() - both tokens, in plain form. jsonSerialize() hides the tokens
 * on purpose, so it is not a storable form. A cache store is as sensitive as whatever it holds.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
    ) {
    }

    public function get(string $key): ?AccessGrant
    {
        $stored = $this->cache->get($key);

        if (! is_array($stored)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $stored */
            return AccessGrant::fromArray($stored);
        } catch (InvalidArgumentException) {
            // Unreadable - written by something else under the same key, or damaged. Treated as
            // absent, so the next request obtains a grant rather than failing on every request.
            $this->cache->forget($key);

            return null;
        }
    }

    public function put(string $key, AccessGrant $grant): void
    {
        $this->cache->forever($key, $grant->toArray());
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
