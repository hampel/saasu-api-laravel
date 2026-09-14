<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Cache\CacheTokenStore;
use Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Saasu\Api\Laravel\Http\PendingRequestClient;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function the_package_config_is_merged_into_the_application(): void
    {
        $config = $this->container()->make(Config::class);

        foreach (['default', 'connections', 'throttle', 'cache_store', 'page_size', 'base_uri', 'timeout', 'connect_timeout'] as $key) {
            $this->assertTrue($config->has('saasu.' . $key), sprintf('saasu.%s is not merged', $key));
        }
    }

    #[Test]
    public function the_config_file_is_publishable_under_its_own_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(SaasuServiceProvider::class, 'saasu-config');

        $this->assertSame([config_path('saasu.php')], array_values($published));
    }

    #[Test]
    public function the_shipped_config_names_one_connection_and_no_credential(): void
    {
        // Read straight from the file rather than the merged config, which the test case has
        // already overridden. An unset environment must leave the connection unusable rather than
        // pointing somewhere with something.
        $defaults = require __DIR__ . '/../config/saasu.php';
        $this->assertIsArray($defaults);

        $connections = $defaults['connections'] ?? null;
        $this->assertIsArray($connections);

        $this->assertSame('main', $defaults['default'] ?? null);
        $this->assertSame(['main'], array_keys($connections));
        $this->assertSame(
            ['file_id' => null, 'username' => null, 'password' => null, 'access_key' => null],
            $connections['main'] ?? null
        );
        $this->assertSame('cache', $defaults['throttle'] ?? null);
        // array_key_exists rather than ??, which cannot tell an absent key from a null one - and
        // null is the value under test here.
        foreach (['cache_store', 'page_size', 'base_uri'] as $key) {
            $this->assertArrayHasKey($key, $defaults);
            $this->assertNull($defaults[$key]);
        }
        $this->assertSame(30, $defaults['timeout'] ?? null);
        $this->assertSame(5, $defaults['connect_timeout'] ?? null);
    }

    #[Test]
    public function the_transport_is_bound_under_this_packages_own_key_so_it_can_be_replaced(): void
    {
        $this->assertInstanceOf(PendingRequestClient::class, $this->container()->make(SaasuServiceProvider::HTTP_CLIENT));
    }

    #[Test]
    public function the_token_store_is_the_cache_by_default_under_its_own_key(): void
    {
        $this->assertInstanceOf(CacheTokenStore::class, $this->container()->make(SaasuServiceProvider::TOKEN_STORE));
    }

    #[Test]
    public function the_shared_psr18_key_is_left_for_the_application(): void
    {
        // Other API wrappers bind Psr\Http\Client\ClientInterface too; claiming it made the last
        // provider registered the transport for all of them. See SharedPsr18BindingTest.
        $this->assertFalse($this->container()->bound(ClientInterface::class));
    }

    #[Test]
    public function a_transport_binding_that_is_not_a_psr18_client_is_named_as_configuration(): void
    {
        $this->container()->instance(SaasuServiceProvider::HTTP_CLIENT, new \stdClass());

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('saasu.http_client must be a Psr\Http\Client\ClientInterface; it resolved to stdClass');

        $this->container()->make(SaasuManager::class);
    }

    #[Test]
    public function a_token_store_binding_that_is_not_a_token_store_is_named_as_configuration(): void
    {
        $this->container()->instance(SaasuServiceProvider::TOKEN_STORE, new \stdClass());

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('saasu.token_store must be a Hampel\Saasu\Api\Authentication\TokenStore; it resolved to stdClass');

        $this->container()->make(SaasuManager::class)->client('main');
    }

    #[Test]
    public function an_access_key_connection_never_resolves_the_token_store(): void
    {
        // Resolved lazily, so an application using only access keys needs no cache for tokens.
        $this->container()->instance(SaasuServiceProvider::TOKEN_STORE, new \stdClass());

        $this->assertSame(67890, $this->container()->make(SaasuManager::class)->client('legacy')->config()->fileId);
    }

    #[Test]
    public function psr17_factories_are_bound_so_the_cores_discovery_never_has_to_run(): void
    {
        $this->assertInstanceOf(RequestFactoryInterface::class, $this->container()->make(RequestFactoryInterface::class));
        $this->assertInstanceOf(StreamFactoryInterface::class, $this->container()->make(StreamFactoryInterface::class));
    }

    #[Test]
    public function registering_the_provider_binds_the_manager_and_merges_the_config(): void
    {
        // Registered here, against an application built in the test body, rather than relying on
        // the registration Testbench already did in setUp. Two reasons, and the second is the
        // important one:
        //
        // The bindings are asserted against an application that did not have them, so the
        // assertions depend on this call rather than on setUp's.
        //
        // And register() only runs under PHPUnit's error handler if it runs from here. Laravel's
        // HandleExceptions bootstrapper replaces that handler while the application boots, which
        // in a Testbench suite is during parent::setUp() - before withoutDeprecationHandling()
        // puts it back. So a deprecation raised by the provider's own registration during setUp
        // is discarded, and phpunit.xml's failOnDeprecation never sees it.
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());

        (new SaasuServiceProvider($app))->register();

        $this->assertTrue($app->bound(SaasuManager::class));
        $this->assertTrue($app->bound(SaasuServiceProvider::HTTP_CLIENT));
        $this->assertTrue($app->bound(SaasuServiceProvider::TOKEN_STORE));
        $this->assertFalse($app->bound(ClientInterface::class));
        $this->assertTrue($app->bound(RequestFactoryInterface::class));
        $this->assertTrue($app->bound(StreamFactoryInterface::class));
        $this->assertSame('main', $app->make(Config::class)->get('saasu.default'));
    }

    #[Test]
    public function booting_the_provider_publishes_this_packages_config(): void
    {
        // Booted here, against an application built in the test body, for the same reason as the
        // register() test above - and boot() needs it separately. Testbench boots every provider
        // inside parent::setUp(), under Laravel's error handler, which discards deprecations.
        //
        // The publish registry is static and keyed by provider class, and Testbench's own boot
        // has already filled it for this class. Emptied first, or this assertion passes on
        // Testbench's entry even if the boot below registered nothing - and restored after, or
        // the publish-tag test above depends on execution order.
        $publishes = new ReflectionProperty(ServiceProvider::class, 'publishes');
        $groups = new ReflectionProperty(ServiceProvider::class, 'publishGroups');
        $savedPublishes = $publishes->getValue();
        $savedGroups = $groups->getValue();

        try {
            $publishes->setValue(null, []);
            $groups->setValue(null, []);

            $app = new Application(__DIR__ . '/..');
            $app->instance('config', new ConfigRepository());

            $provider = new SaasuServiceProvider($app);
            $provider->register();
            $provider->boot();

            // The source path, not the destination: the destination is this throwaway
            // application's config directory and says nothing about the package.
            $this->assertSame(
                [realpath(__DIR__ . '/../config/saasu.php')],
                array_map(realpath(...), array_keys(
                    ServiceProvider::pathsToPublish(SaasuServiceProvider::class, 'saasu-config')
                )),
            );
        } finally {
            $publishes->setValue(null, $savedPublishes);
            $groups->setValue(null, $savedGroups);
        }
    }
}
