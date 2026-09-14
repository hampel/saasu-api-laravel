<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\Saasu\Api\Authentication\TokenStore;
use Hampel\Saasu\Api\Laravel\Cache\CacheTokenStore;
use Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Saasu\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the Saasu API manager into the container.
 *
 * Two override points, each under this package's own key and each bound only if nothing has bound
 * it already:
 *
 * - HTTP_CLIENT, the PSR-18 transport. The default sends through Laravel's HTTP client, which is
 *   what makes the package's traffic visible to Http::fake() - see PendingRequestClient.
 * - TOKEN_STORE, where OAuth grants are kept. The default is the configured cache store - see
 *   CacheTokenStore.
 *
 * NOT `Psr\Http\Client\ClientInterface`, deliberately. That is one key in the container, and other
 * API wrappers bind it too; singleton() on a bound key replaces it, so the last provider
 * registered would supply the transport for every package - one package's timeouts governing
 * another's requests. Nor does the manager fall back to a ClientInterface binding when one exists:
 * another package, or an unrelated library, may be the one that bound it.
 */
final class SaasuServiceProvider extends ServiceProvider
{
    /**
     * The container key this package's PSR-18 transport is bound under - the override point for
     * an application that must send this package's requests through a client of its own.
     */
    public const HTTP_CLIENT = 'saasu.http_client';

    /**
     * The container key the OAuth token store is bound under - the override point for an
     * application that keeps credentials somewhere other than its cache.
     */
    public const TOKEN_STORE = 'saasu.token_store';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/saasu.php', 'saasu');

        // Laravel binds the HTTP client factory as a singleton in FoundationServiceProvider,
        // which a full application registers and a Laravel Zero one does NOT. Unbound, the
        // container builds a fresh Factory on every make(), so the one this package sends through
        // is not the one the Http facade configures, and Http::fake() silently fails to
        // intercept: the request goes to the real API.
        //
        // singletonIf, so a full Laravel application keeps the framework's own binding and this
        // is a no-op there.
        $this->app->singletonIf(HttpClientFactory::class, static fn (Container $app): HttpClientFactory => new HttpClientFactory(
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's fills both roles and this package requires it. The core package would find one
        // itself through Psr17Discovery; binding them means an application can say which.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        // singletonIf, so an application's override survives whichever order the providers
        // register in. A full Laravel application registers its own providers after discovered
        // ones, so its override would win anyway; Laravel Zero runs no discovery, and its
        // config/app.php lists AppServiceProvider before a package provider added after it -
        // where singleton() would replace the override without a word.
        $this->app->singletonIf(self::HTTP_CLIENT, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // The Factory the Http facade resolves, read on every send rather than once here:
            // that is what puts this package's requests among the ones Http::fake() and
            // Http::assertSent() see, including after Http::swap() replaces the factory.
            $app = $this->app;

            return new PendingRequestClient(
                static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                $this->seconds($config->get('saasu.timeout'), 30.0),
                $this->seconds($config->get('saasu.connect_timeout'), 5.0),
            );
        });

        $this->app->singletonIf(self::TOKEN_STORE, fn (): TokenStore => new CacheTokenStore($this->cacheStore()));

        $this->app->singleton(SaasuManager::class, function (): SaasuManager {
            $client = $this->app->make(self::HTTP_CLIENT);

            if (! $client instanceof ClientInterface) {
                throw InvalidConfiguration::binding(self::HTTP_CLIENT, ClientInterface::class, get_debug_type($client));
            }

            return new SaasuManager(
                $this->app->make(Config::class),
                $client,
                $this->app->make(RequestFactoryInterface::class),
                $this->app->make(StreamFactoryInterface::class),
                $this->app->make(LoggerInterface::class),
                function (): TokenStore {
                    $store = $this->app->make(self::TOKEN_STORE);

                    if (! $store instanceof TokenStore) {
                        throw InvalidConfiguration::binding(self::TOKEN_STORE, TokenStore::class, get_debug_type($store));
                    }

                    return $store;
                },
                fn (): CacheRepository => $this->cacheStore(),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // configPath() rather than the config_path() helper: the helper is defined by
            // illuminate/foundation, which this package does not require and should not.
            $this->publishes([
                __DIR__ . '/../config/saasu.php' => $this->app->configPath('saasu.php'),
            ], 'saasu-config');
        }
    }

    /**
     * The configured cache store, or the application's default.
     */
    private function cacheStore(): CacheRepository
    {
        $name = $this->app->make(Config::class)->get('saasu.cache_store');

        return $this->app->make(CacheFactory::class)->store(is_string($name) && trim($name) !== '' ? trim($name) : null);
    }

    private function seconds(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
