<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Cache;

use Closure;
use Hampel\Saasu\Api\Throttle\IntervalThrottle;
use Hampel\Saasu\Api\Throttle\Throttle;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * One request per interval for each Saasu file, across every process sharing a cache store.
 *
 * The core package's IntervalThrottle sees one process. Saasu's limit of one request a second
 * belongs to the file, so three queue workers each keeping to it would send three a second
 * between them.
 *
 * KEYED ON THE FILE EACH REQUEST NAMES, which the core package passes to wait(). So one instance
 * serves every connection, and a client moved to another file with withFileId() waits on that
 * file's turn rather than the one it was built for. A request naming no file - a login, or the
 * list of files a login reaches - shares a key of its own.
 *
 * SLOTS ARE RESERVED, AND THE WAIT HAPPENS OUTSIDE THE LOCK. Under a short lock, a process reads
 * the last slot handed out for the file, takes the later of now and that slot plus the interval,
 * and writes it back. It then releases the lock and sleeps until its slot. Holding the lock while
 * sleeping would work too, but every waiting process would then be polling for the lock, and the
 * order they got it in would be arbitrary rather than first come, first served.
 *
 * WHAT IT ASSUMES: a store with atomic locks (redis, database, memcached, dynamodb, file, array),
 * and, where the workers are on several hosts, clocks that agree to well within the interval. A
 * slot is an instant from one host's clock, slept until on another's.
 *
 * THE NULL STORE DEFEATS IT SILENTLY. Its locks always succeed and it remembers nothing, so every
 * request finds no previous slot and goes immediately.
 *
 * A LockTimeoutException means the lock was not free within the timeout. The lock is held only
 * for one read and one write, so on a working store that does not happen; it is left to reach the
 * caller, because sending anyway would break the limit this exists to keep.
 */
final class CacheThrottle implements Throttle
{
    /**
     * Seconds the lock is held at most, and by default how long to wait for it.
     */
    public const LOCK_TIMEOUT = 10;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  int  $lockTimeout  seconds to wait for the lock before raising LockTimeoutException
     * @param  (callable(float): void)|null  $sleep  receives seconds; defaults to usleep()
     * @param  (callable(): float)|null  $clock  answers seconds; defaults to microtime(true)
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly LockProvider $locks,
        public readonly float $interval = IntervalThrottle::SAASU_INTERVAL,
        private readonly int $lockTimeout = self::LOCK_TIMEOUT,
        ?callable $sleep = null,
        ?callable $clock = null,
    ) {
        $this->sleep = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            };

        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn (): float => microtime(true);
    }

    /**
     * The cache key a file's last slot is kept under.
     */
    public static function keyFor(?int $fileId): string
    {
        return 'saasu-throttle:' . ($fileId ?? 'no-file');
    }

    public function wait(?int $fileId): void
    {
        $key = self::keyFor($fileId);

        $slot = $this->locks->lock($key . ':lock', self::LOCK_TIMEOUT)
            ->block($this->lockTimeout, fn (): float => $this->reserve($key));

        $delay = (is_float($slot) ? $slot : 0.0) - ($this->clock)();

        if ($delay > 0) {
            ($this->sleep)($delay);
        }
    }

    private function reserve(string $key): float
    {
        $now = ($this->clock)();
        $last = $this->cache->get($key);

        $slot = is_numeric($last) ? max($now, (float) $last + $this->interval) : $now;

        // Kept until the slot after this one has passed. A queue of waiting workers can put the
        // latest slot many seconds ahead, so the lifetime is measured from the slot, not from now.
        $this->cache->put($key, $slot, (int) ceil($slot - $now + $this->interval) + 1);

        return $slot;
    }
}
