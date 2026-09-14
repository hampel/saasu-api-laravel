<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use DateTimeImmutable;
use Hampel\Saasu\Api\Authentication\AccessGrant;
use Hampel\Saasu\Api\Authentication\OAuth;
use Hampel\Saasu\Api\Laravel\Cache\CacheTokenStore;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * One login per login, not one per process.
 *
 * Every token request counts against the file's daily quota, and exceeding it blocks the whole file
 * for 24 hours. The core package's in-memory store would log in again in every PHP process; these
 * tests are the claim that this package does not.
 */
final class TokenStoreTest extends TestCase
{
    #[Test]
    public function a_grant_round_trips_with_both_tokens(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $grant = new AccessGrant('access', new DateTimeImmutable('2026-09-15T03:00:00+00:00'), 'refresh', 'Bearer', 'full fileid:12345');

        $store->put('key', $grant);
        $read = $store->get('key');

        $this->assertNotNull($read);
        $this->assertSame($grant->toArray(), $read->toArray());
    }

    #[Test]
    public function a_grant_is_stored_as_its_portable_form_not_its_json(): void
    {
        // jsonSerialize() hides the tokens on purpose; storing that would store a grant that can
        // never authenticate.
        $cache = new Repository(new ArrayStore());

        (new CacheTokenStore($cache))->put('key', new AccessGrant('access', new DateTimeImmutable(), 'refresh'));

        $stored = $cache->get('key');
        $this->assertIsArray($stored);
        $this->assertSame('access', $stored['access_token'] ?? null);
        $this->assertSame('refresh', $stored['refresh_token'] ?? null);
    }

    #[Test]
    public function a_grant_is_kept_past_its_own_expiry(): void
    {
        // Its refresh token outlives the access token by months, and OAuth checks the expiry itself.
        $cache = new Repository(new ArrayStore());
        $store = new CacheTokenStore($cache);

        $store->put('key', new AccessGrant('access', new DateTimeImmutable('+3 hours'), 'refresh'));

        Carbon::setTestNow(Carbon::now()->addYear());

        try {
            $this->assertNotNull($store->get('key'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function an_unreadable_entry_is_forgotten_and_treated_as_absent(): void
    {
        $cache = new Repository(new ArrayStore());
        $cache->forever('key', ['access_token' => 'no expiry']);

        $this->assertNull((new CacheTokenStore($cache))->get('key'));
        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    public function forgetting_removes_the_grant(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $store->put('key', new AccessGrant('access', new DateTimeImmutable('+3 hours')));

        $store->forget('key');

        $this->assertNull($store->get('key'));
    }

    #[Test]
    public function a_second_process_reuses_the_first_ones_token(): void
    {
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        // A new process: nothing memoised, only the cache left behind.
        $this->container()->forgetInstance(SaasuManager::class);
        $this->container()->forgetInstance(SaasuServiceProvider::TOKEN_STORE);
        Saasu::clearResolvedInstances();

        Saasu::contacts()->get(54353);

        $this->assertSame(1, $this->countSentTo('authorisation/token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Contact/54353')
            && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN));
    }

    #[Test]
    public function connections_sharing_a_login_share_one_token(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('saasu.connections.other-file', [
            'file_id' => 22222,
            'username' => self::USERNAME,
            'password' => 'password-under-test',
        ]);

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);
        Saasu::client('other-file')->contacts()->get(54353);

        $this->assertSame(1, $this->countSentTo('authorisation/token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'FileId=22222'));
    }

    #[Test]
    public function the_token_lands_in_the_configured_cache_store(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('cache.stores.tokens', ['driver' => 'array']);
        $config->set('saasu.cache_store', 'tokens');

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        $this->assertIsArray(Cache::store('tokens')->get(OAuth::keyFor(self::USERNAME)));
        $this->assertNull(Cache::store('array')->get(OAuth::keyFor(self::USERNAME)));
    }

    #[Test]
    public function an_expired_grant_in_the_cache_is_refreshed_rather_than_logged_in_again(): void
    {
        Cache::store('array')->forever(OAuth::keyFor(self::USERNAME), (new AccessGrant(
            'expired-access-token',
            new DateTimeImmutable('@0'),
            'stored-refresh-token',
            'Bearer',
            'full fileid:12345',
        ))->toArray());

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'authorisation/refresh')
            && $request['refresh_token'] === 'stored-refresh-token');
        $this->assertSame(0, $this->countSentTo('authorisation/token'));
    }

    #[Test]
    public function an_application_can_bind_a_store_of_its_own(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $this->container()->instance(SaasuServiceProvider::TOKEN_STORE, $store);

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        $this->assertNotNull($store->get(OAuth::keyFor(self::USERNAME)));
    }

    private function countSentTo(string $path): int
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), $path))->count();
    }
}
