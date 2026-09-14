<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel;

use BadMethodCallException;
use Closure;
use Hampel\Saasu\Api\Authentication\Authentication;
use Hampel\Saasu\Api\Authentication\OAuth;
use Hampel\Saasu\Api\Authentication\TokenStore;
use Hampel\Saasu\Api\Authentication\WsAccessKey;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Config as ApiConfig;
use Hampel\Saasu\Api\Exception\InvalidArgumentException;
use Hampel\Saasu\Api\Laravel\Cache\CacheThrottle;
use Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Saasu\Api\Laravel\Exception\UnknownConnection;
use Hampel\Saasu\Api\Result\Page;
use Hampel\Saasu\Api\Throttle\IntervalThrottle;
use Hampel\Saasu\Api\Throttle\NoThrottle;
use Hampel\Saasu\Api\Throttle\Throttle;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One Saasu client per configured connection.
 *
 *     Saasu::contacts()->list();                     // the default connection
 *     Saasu::client('subsidiary')->invoices()->list();  // a named one
 *
 * IT IS NAMED client() AND NOT connection(), which would be the Laravel word and is the one name
 * not available: `Client::connection()` is the core package's transport, and the manager forwards
 * unknown calls to the default client, so a connection() here would shadow it.
 *
 * Clients are memoised per name, and so is each file's throttle: two connections to the same file
 * in one process wait for each other, whichever throttle mode is configured.
 *
 * NO REQUEST BUDGET IS CONFIGURED HERE, although the core Config takes one. A memoised client in a
 * long-running queue worker would count every request since the worker started, so a budget set
 * as configuration would become a lifetime cap and eventually refuse everything. A client derived
 * with withConfig() does not escape that: it shares its parent's request count.
 *
 * The @mixin is what makes $manager->contacts() analysable: __call() forwards anything the client
 * answers to, and one line that cannot drift says so. The facade repeats the list explicitly
 * because @method static is the only form __callStatic() can carry, and a test keeps that copy
 * honest.
 *
 * @mixin Client
 */
final class SaasuManager
{
    public const THROTTLE_CACHE = 'cache';

    public const THROTTLE_PROCESS = 'process';

    public const THROTTLE_NONE = 'none';

    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, Throttle> */
    private array $throttles = [];

    private ?TokenStore $resolvedTokenStore = null;

    /**
     * @param  Closure(): TokenStore  $tokenStore  resolved on the first OAuth connection, so an
     *         application using only access keys never touches the cache for tokens
     * @param  Closure(): CacheRepository  $cache  the store the shared throttle keeps its slots in,
     *         resolved only when that throttle is configured
     */
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly Closure $tokenStore,
        private readonly Closure $cache,
    ) {
    }

    /**
     * The client for a configured connection, or for the default when no name is given.
     */
    public function client(?string $name = null): Client
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->build($name);
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('saasu.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured connection names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredConnections(): array
    {
        $connections = $this->config->get('saasu.connections');

        return is_array($connections) ? array_values(array_filter(array_keys($connections), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $connections = $this->config->get('saasu.connections');
        $settings = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_array($settings)) {
            throw UnknownConnection::named($name, $this->configuredConnections());
        }

        $fileId = $this->fileId($name, $settings['file_id'] ?? null);
        $authentication = $this->authentication($name, $settings, $fileId);

        return new Client(
            $this->api($name, $fileId),
            $authentication,
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $this->throttle($fileId),
        );
    }

    /**
     * The credential a connection's settings describe.
     *
     * OAUTH WINS WHEN BOTH ARE GIVEN. The access key is the scheme Saasu calls legacy, and it
     * travels in every URL, so it is used only when it is the only credential there. Half an
     * OAuth login is refused rather than treated as absent: otherwise a missing password would
     * quietly move a connection that also carries an access key onto the legacy scheme.
     *
     * @param  array<mixed>  $settings
     */
    private function authentication(string $name, array $settings, ?int $fileId): Authentication
    {
        $username = $this->string($settings, 'username');
        $password = $this->password($settings);

        if ($username !== null || $password !== null) {
            if ($username === null) {
                throw InvalidConfiguration::incompleteLogin($name, 'username');
            }

            if ($password === null) {
                throw InvalidConfiguration::incompleteLogin($name, 'password');
            }

            return OAuth::password($username, $password, $this->tokenStore());
        }

        $accessKey = $this->string($settings, 'access_key');

        if ($accessKey === null) {
            throw InvalidConfiguration::missingCredential($name);
        }

        if ($fileId === null) {
            throw InvalidConfiguration::accessKeyWithoutFile($name);
        }

        return new WsAccessKey($accessKey);
    }

    /**
     * The core package's Config for one connection: its file, plus the settings shared by all.
     *
     * Its constructor validates, so a page size outside 1 to 100 or a base URI with no scheme is
     * refused here rather than on the first request - re-raised as InvalidConfiguration, so the
     * message names the configuration and not an argument.
     */
    private function api(string $name, ?int $fileId): ApiConfig
    {
        $baseUri = $this->config->get('saasu.base_uri');
        $pageSize = $this->config->get('saasu.page_size');

        try {
            return new ApiConfig(
                $fileId,
                is_numeric($pageSize) ? (int) $pageSize : Page::MAX_PAGE_SIZE,
                baseUri: is_string($baseUri) && trim($baseUri) !== '' ? trim($baseUri) : null,
            );
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::api($name, $e);
        }
    }

    /**
     * The throttle for a file, shared by every connection to it in this process.
     */
    private function throttle(?int $fileId): Throttle
    {
        $mode = $this->config->get('saasu.throttle');
        $mode = is_string($mode) && trim($mode) !== '' ? strtolower(trim($mode)) : self::THROTTLE_CACHE;
        $key = CacheThrottle::keyFor($fileId);

        return match ($mode) {
            self::THROTTLE_NONE => $this->throttles[$mode] ??= new NoThrottle(),
            self::THROTTLE_PROCESS => $this->throttles[$mode . ':' . $key] ??= new IntervalThrottle(),
            self::THROTTLE_CACHE => $this->throttles[$mode . ':' . $key] ??= $this->cacheThrottle($key),
            default => throw InvalidConfiguration::throttle($this->config->get('saasu.throttle')),
        };
    }

    private function cacheThrottle(string $key): CacheThrottle
    {
        $cache = ($this->cache)();
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            $name = $this->config->get('saasu.cache_store');

            throw InvalidConfiguration::storeWithoutLocks(is_string($name) && $name !== '' ? $name : 'default');
        }

        return new CacheThrottle($cache, $store, $key);
    }

    private function tokenStore(): TokenStore
    {
        return $this->resolvedTokenStore ??= ($this->tokenStore)();
    }

    /**
     * A file id from configuration, where an environment variable arrives as a string.
     */
    private function fileId(string $name, mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        if (! is_int($value) || $value < 1) {
            throw InvalidConfiguration::fileId($name, $value);
        }

        return $value;
    }

    /**
     * One setting, as a non-empty string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches config
     * as an empty string as readily as it reaches it as null.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The password as given - not trimmed, since a password may begin or end with a space - but
     * with an empty or whitespace-only one treated as absent.
     *
     * @param  array<mixed>  $settings
     */
    private function password(array $settings): ?string
    {
        $value = $settings['password'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Anything else goes to the default connection's client, so an application working in one
     * file never has to name one: `Saasu::contacts()` rather than
     * `Saasu::client('main')->contacts()`.
     *
     * The facade carries a @method line for each of these, which is where the types come from -
     * see Facades\Saasu, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
