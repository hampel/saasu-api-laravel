<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Cache\CacheThrottle;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Saasu's one request a second, kept across processes.
 *
 * Each "process" here is a separate CacheThrottle over one shared store - which is all a second
 * queue worker is, as far as the throttle can tell. The clock is fixed and the sleep recorded, so
 * the waits are asserted rather than taken.
 */
final class CacheThrottleTest extends TestCase
{
    private ArrayStore $store;

    private Repository $cache;

    private float $now = 1_000_000.0;

    /** @var list<float> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ArrayStore();
        $this->cache = new Repository($this->store);
    }

    #[Test]
    public function the_first_request_goes_immediately(): void
    {
        $this->throttle()->wait(12345);

        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function a_second_process_waits_for_the_first_ones_interval(): void
    {
        $this->throttle()->wait(12345);
        $this->throttle()->wait(12345);

        $this->assertSame([1.0], $this->slept);
    }

    #[Test]
    public function processes_arriving_together_are_given_consecutive_slots(): void
    {
        // Reserved, not raced: each takes the slot after the last one handed out, so four workers
        // at the same instant go at 0, 1, 2 and 3 seconds rather than all at 1.
        for ($i = 0; $i < 4; $i++) {
            $this->throttle()->wait(12345);
        }

        $this->assertSame([1.0, 2.0, 3.0], $this->slept);
    }

    #[Test]
    public function time_already_passed_counts_towards_the_interval(): void
    {
        $this->throttle()->wait(12345);
        $this->now += 0.75;
        $this->throttle()->wait(12345);

        $this->assertCount(1, $this->slept);
        $this->assertEqualsWithDelta(0.25, $this->slept[0], 0.0001);
    }

    #[Test]
    public function a_request_after_the_interval_does_not_wait(): void
    {
        $this->throttle()->wait(12345);
        $this->now += 5.0;
        $this->throttle()->wait(12345);

        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function different_files_do_not_wait_for_each_other(): void
    {
        $this->throttle()->wait(12345);
        $this->throttle()->wait(67890);

        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function one_instance_keeps_each_files_turn_separately(): void
    {
        // One throttle serves every connection, so it has to key on the file each request names
        // rather than on a file fixed when it was built.
        $throttle = $this->throttle();

        $throttle->wait(12345);
        $throttle->wait(67890);
        $throttle->wait(12345);

        $this->assertSame([1.0], $this->slept);
    }

    #[Test]
    public function requests_naming_no_file_share_a_turn_of_their_own(): void
    {
        $throttle = $this->throttle();

        $throttle->wait(null);
        $throttle->wait(12345);
        $throttle->wait(null);

        $this->assertSame([1.0], $this->slept);
        $this->assertNotNull($this->cache->get(CacheThrottle::keyFor(null)));
    }

    #[Test]
    public function the_last_slot_is_kept_until_a_queue_of_waiting_workers_has_gone(): void
    {
        // The slot's lifetime is measured from the slot, not from now. Kept for one interval from
        // now, the entry would expire while three workers were still waiting, and a fourth
        // arriving then would take slot zero beside the one already sleeping until it.
        Carbon::setTestNow(Carbon::createFromTimestamp((int) $this->now));

        try {
            for ($i = 0; $i < 4; $i++) {
                $this->throttle()->wait(12345);
            }

            Carbon::setTestNow(Carbon::createFromTimestamp((int) $this->now + 3));

            $this->assertEqualsWithDelta($this->now + 3.0, $this->cache->get(CacheThrottle::keyFor(12345)), 0.0001);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_lock_that_is_not_released_raises_rather_than_sending_anyway(): void
    {
        $held = $this->store->lock(CacheThrottle::keyFor(12345) . ':lock', 10);
        $this->assertTrue($held->get());

        $this->expectException(LockTimeoutException::class);

        $this->throttle(lockTimeout: 0)->wait(12345);
    }

    #[Test]
    public function the_configured_throttle_reserves_a_slot_before_each_request(): void
    {
        // Wiring, not timing: the shared throttle is what the client actually waits on. One
        // request, so the test does not sleep.
        $this->container()->make(Config::class)->set('saasu.throttle', 'cache');

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        $this->assertNull(Cache::store('array')->get(CacheThrottle::keyFor(67890)));

        // The access key connection, so the one request is the contact and not a login.
        Saasu::client('legacy')->contacts()->get(54353);

        $this->assertIsFloat(Cache::store('array')->get(CacheThrottle::keyFor(67890)));
    }

    #[Test]
    public function a_client_moved_to_another_file_waits_on_that_files_turn(): void
    {
        // The core package passes the file each request names, so withFileId() is followed rather
        // than the client waiting on the file its connection was configured with.
        $this->container()->make(Config::class)->set('saasu.throttle', 'cache');

        Http::fake(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::client('legacy')->withFileId(99999)->contacts()->get(54353);

        $this->assertIsFloat(Cache::store('array')->get(CacheThrottle::keyFor(99999)));
        $this->assertNull(Cache::store('array')->get(CacheThrottle::keyFor(67890)));
    }

    #[Test]
    public function a_login_waits_on_the_turn_for_requests_naming_no_file(): void
    {
        $this->container()->make(Config::class)->set('saasu.throttle', 'cache');

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        $this->assertIsFloat(Cache::store('array')->get(CacheThrottle::keyFor(null)));
        $this->assertIsFloat(Cache::store('array')->get(CacheThrottle::keyFor(12345)));
    }

    private function throttle(int $lockTimeout = CacheThrottle::LOCK_TIMEOUT): CacheThrottle
    {
        return new CacheThrottle(
            $this->cache,
            $this->store,
            lockTimeout: $lockTimeout,
            sleep: function (float $seconds): void {
                $this->slept[] = $seconds;
            },
            clock: fn (): float => $this->now,
        );
    }
}
