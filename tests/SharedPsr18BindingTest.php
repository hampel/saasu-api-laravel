<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\Http\PendingRequestClient;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Hampel\Saasu\Api\Laravel\Tests\Fixture\ForeignPsr18Provider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * This package installed beside another that binds the shared PSR-18 key.
 *
 * `Psr\Http\Client\ClientInterface` is one key in the container. When API wrappers claimed it with
 * singleton(), the last provider registered supplied the transport for all of them, so one
 * package's timeouts governed another's traffic. No single-package suite could see it, which is
 * why the foreign provider here is a stub rather than a sibling.
 */
final class SharedPsr18BindingTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Registered FIRST, so this package's provider comes after it.
        return [ForeignPsr18Provider::class, SaasuServiceProvider::class];
    }

    #[Test]
    public function a_provider_registered_before_this_one_does_not_supply_its_transport(): void
    {
        $this->assertOwnTransportIsUsed();
    }

    #[Test]
    public function a_provider_registered_before_this_one_keeps_its_own_binding(): void
    {
        // Using its own transport in this order is not enough: a provider that overwrote the shared
        // key would do that too. What matters is that another package's binding survives.
        $this->assertNotNull(ForeignPsr18Provider::$client);
        $this->assertSame(ForeignPsr18Provider::$client, $this->container()->make(ClientInterface::class));
    }

    #[Test]
    public function a_provider_registered_after_this_one_does_not_supply_its_transport(): void
    {
        // force: the class is already registered above, and register() would otherwise hand back
        // that instance without running register() again.
        $this->container()->register(new ForeignPsr18Provider($this->container()), force: true);

        $this->assertOwnTransportIsUsed();
    }

    #[Test]
    public function a_sibling_adapter_that_is_also_faked_does_not_lend_its_timeout(): void
    {
        // The realistic collision, which the 418 stub cannot stand in for. A sibling wrapper's
        // adapter sends through the SAME faked HTTP factory, so Http::fake() answers whichever
        // adapter sends and the response looks right. Only the timeout tells them apart.
        $app = $this->container();

        $app->singleton(ClientInterface::class, static fn (): ClientInterface => new PendingRequestClient(
            static fn (): Factory => $app->make(Factory::class),
            3.0,
            1.0,
        ));

        $this->assertOwnTransportIsUsed();
    }

    private function assertOwnTransportIsUsed(): void
    {
        $this->container()->make('config')->set('saasu.timeout', 4);

        // An ArrayObject rather than a reference: the outer arrow function captures by value, so a
        // reference taken inside it would point at a copy.
        /** @var \ArrayObject<int, mixed> $timeouts */
        $timeouts = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $timeouts): mixed {
            $timeouts[] = $options['timeout'] ?? null;

            return $handler($request, $options);
        });

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        $this->assertSame('Joe', Saasu::contacts()->get(54353)->givenName);

        $this->assertSame([], ForeignPsr18Provider::$client->sent ?? []);
        // Two requests - the login and the contact - both at this package's timeout.
        $this->assertSame([4.0, 4.0], $timeouts->getArrayCopy());
    }

    protected function tearDown(): void
    {
        ForeignPsr18Provider::$client = null;

        parent::tearDown();
    }
}
