<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Hampel\Saasu\Api\Laravel\Tests\Fixture\OverrideHttpClientProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of saasu.http_client, registered before this package's provider.
 *
 * In a full Laravel application package providers register before the application's own, so an
 * override in AppServiceProvider comes last and would win whatever the package did. Laravel Zero
 * runs no discovery: the order is config/app.php, where AppServiceProvider is listed first. A
 * provider that claimed the key with singleton() would then replace the application's override
 * without a word, so it uses singletonIf().
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OverrideHttpClientProvider::class, SaasuServiceProvider::class];
    }

    #[Test]
    public function an_override_registered_before_this_provider_supplies_the_transport(): void
    {
        // Contained twice over: stray requests are refused, and the access key connection means the
        // one request is the one the override answers.
        Http::preventStrayRequests();

        $this->assertSame('From the override', Saasu::client('legacy')->contacts()->get(54353)->givenName);
        $this->assertSame(
            ['https://api.saasu.com/Contact/54353?FileId=67890&wsAccessKey=access-key-under-test'],
            OverrideHttpClientProvider::$client->sent ?? []
        );
    }

    protected function tearDown(): void
    {
        OverrideHttpClientProvider::$client = null;

        parent::tearDown();
    }
}
