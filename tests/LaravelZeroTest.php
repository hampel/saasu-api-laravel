<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package on a Laravel Zero application, which is not the same container as a Laravel one.
 *
 * Laravel binds Illuminate\Http\Client\Factory as a singleton in FoundationServiceProvider. Laravel
 * Zero does not register that provider, so unless this package binds one, the container builds a
 * fresh Factory on every make() and the instance this package sends through is not the instance
 * the Http facade configures - the fake does not intercept and the request goes to the real API.
 * Testbench boots a full application, so nothing in the rest of the suite can see it.
 *
 * Laravel Zero does register the cache, which the token store and the shared throttle use.
 *
 * WHAT THIS DOES NOT PROVE: that a Laravel Zero application registers the provider. It does not -
 * Laravel Zero empties the package manifest, so a consumer lists the provider in config/app.php.
 * The application below registers it by hand.
 */
final class LaravelZeroTest extends \PHPUnit\Framework\TestCase
{
    #[Test]
    public function a_fake_registered_after_the_client_was_resolved_still_intercepts(): void
    {
        $app = $this->laravelZeroApplication();

        $client = $app->make(SaasuManager::class)->client('main');

        Http::preventStrayRequests();
        Http::fake([
            'api.saasu.com/authorisation/*' => Http::response(['access_token' => 'zero-token', 'expires_in' => 10800]),
            'api.saasu.com/*' => Http::response(['Id' => 54353, 'GivenName' => 'Joe']),
        ]);

        try {
            $this->assertSame('Joe', $client->contacts()->get(54353)->givenName);
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function the_http_factory_is_bound_as_a_singleton_when_nothing_else_binds_one(): void
    {
        $app = $this->laravelZeroApplication();

        try {
            $this->assertTrue($app->bound(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), $app->make(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), Http::getFacadeRoot());
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function an_application_that_already_binds_a_factory_keeps_its_own(): void
    {
        $existing = new HttpClientFactory();

        $app = $this->laravelZeroApplication(function (Application $app) use ($existing): void {
            $app->instance(HttpClientFactory::class, $existing);
        });

        try {
            $this->assertSame($existing, $app->make(HttpClientFactory::class));
        } finally {
            $this->tearDownFacades();
        }
    }

    private function laravelZeroApplication(?callable $before = null): Application
    {
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());
        $app->register(new EventServiceProvider($app));
        $app->register(new LogServiceProvider($app));
        $app->register(new CacheServiceProvider($app));

        $config = $app->make('config');
        $config->set('logging.default', 'null');
        $config->set('logging.channels.null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
        $config->set('cache.default', 'array');
        $config->set('cache.stores.array', ['driver' => 'array']);

        if ($before !== null) {
            $before($app);
        }

        (new SaasuServiceProvider($app))->register();

        $config->set('saasu.throttle', 'none');
        $config->set('saasu.connections.main', [
            'file_id' => 12345,
            'username' => 'api@example.test',
            'password' => 'password-under-test',
        ]);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    private function tearDownFacades(): void
    {
        // A facade left pointing at a dead application fails a later test for a reason that has
        // nothing to do with it.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
