<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Throttle;

use Closure;
use Hampel\Saasu\Api\Throttle\IntervalThrottle;
use Hampel\Saasu\Api\Throttle\Throttle;

/**
 * One request per interval for each Saasu file, within this process.
 *
 * The core package's IntervalThrottle ignores the file a request names, so one instance shared by
 * every connection would make a request to one file wait for a request to another. This keeps an
 * IntervalThrottle per file id instead, which is what `throttle = process` means: the same
 * per-file limit as the cache throttle, without the cache, and blind to other processes.
 */
final class ProcessThrottle implements Throttle
{
    /** @var array<int|string, IntervalThrottle> */
    private array $throttles = [];

    /** @var Closure(): IntervalThrottle */
    private readonly Closure $make;

    /**
     * @param  (callable(): IntervalThrottle)|null  $make  builds the throttle for a file; defaults to
     *         Saasu's interval
     */
    public function __construct(?callable $make = null)
    {
        $this->make = $make !== null
            ? Closure::fromCallable($make)
            : static fn (): IntervalThrottle => new IntervalThrottle();
    }

    public function wait(?int $fileId): void
    {
        ($this->throttles[$fileId ?? 'no-file'] ??= ($this->make)())->wait($fileId);
    }
}
