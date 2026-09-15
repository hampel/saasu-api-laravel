<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Throttle\ProcessThrottle;
use Hampel\Saasu\Api\Throttle\IntervalThrottle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ProcessThrottleTest extends BaseTestCase
{
    private float $now = 1_000_000.0;

    /** @var list<float> */
    private array $slept = [];

    #[Test]
    public function each_file_keeps_its_own_turn(): void
    {
        // IntervalThrottle ignores the file id, so one shared by every connection would make a
        // request to one file wait for a request to another.
        $throttle = $this->throttle();

        $throttle->wait(12345);
        $throttle->wait(67890);
        $throttle->wait(null);
        $throttle->wait(12345);

        $this->assertSame([1.0], $this->slept);
    }

    private function throttle(): ProcessThrottle
    {
        return new ProcessThrottle(fn (): IntervalThrottle => new IntervalThrottle(
            sleep: function (float $seconds): void {
                $this->slept[] = $seconds;
            },
            clock: fn (): float => $this->now,
        ));
    }
}
