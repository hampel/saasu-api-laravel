<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Authentication\OAuth;
use Hampel\Saasu\Api\Authentication\WsAccessKey;
use Hampel\Saasu\Api\Client;
use Hampel\Saasu\Api\Exception\ExceptionInterface;
use Hampel\Saasu\Api\Laravel\Cache\CacheThrottle;
use Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\Saasu\Api\Laravel\Exception\UnknownConnection;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Laravel\Tests\Fixture\LocklessStore;
use Hampel\Saasu\Api\Laravel\Throttle\ProcessThrottle;
use Hampel\Saasu\Api\Throttle\NoThrottle;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_connection(): void
    {
        $client = $this->manager()->client('legacy');

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame(67890, $client->config()->fileId);
    }

    #[Test]
    public function it_defaults_to_the_configured_connection(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultConnection());
        $this->assertSame($this->manager()->client('main'), $this->manager()->client());
    }

    #[Test]
    public function clients_are_memoised_per_connection(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->client('main'), $manager->client('main'));
        $this->assertNotSame($manager->client('main'), $manager->client('legacy'));
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(SaasuManager::class));
        $this->assertSame($this->manager(), Saasu::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_connections(): void
    {
        $this->assertSame(['main', 'legacy'], $this->manager()->configuredConnections());
    }

    #[Test]
    public function an_unknown_connection_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownConnection::class);
        $this->expectExceptionMessage('Configured connections: main, legacy.');

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->manager()->client('nope');
    }

    #[Test]
    public function a_username_and_password_log_in_with_oauth(): void
    {
        $this->assertInstanceOf(OAuth::class, $this->manager()->client('main')->authentication());
    }

    #[Test]
    public function an_access_key_alone_uses_the_access_key(): void
    {
        $this->assertInstanceOf(WsAccessKey::class, $this->manager()->client('legacy')->authentication());
    }

    #[Test]
    public function oauth_wins_when_both_credentials_are_given(): void
    {
        // The access key is Saasu's legacy scheme and travels in every URL, so it is used only
        // when it is the only credential there.
        $this->configure('both', [
            'file_id' => 12345,
            'username' => self::USERNAME,
            'password' => 'password-under-test',
            'access_key' => 'access-key-under-test',
        ]);

        $this->assertInstanceOf(OAuth::class, $this->manager()->client('both')->authentication());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function halfALogin(): array
    {
        return [
            'a username without a password' => [['username' => self::USERNAME], 'has a username but no password'],
            'a password without a username' => [['password' => 'secret'], 'has a password but no username'],
            'a blank password beside a username' => [['username' => self::USERNAME, 'password' => '   '], 'has a username but no password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $login
     */
    #[Test]
    #[DataProvider('halfALogin')]
    public function half_a_login_is_refused_rather_than_falling_back_to_the_access_key(array $login, string $message): void
    {
        // Otherwise an unset SAASU_PASSWORD would quietly move a connection that also carries an
        // access key onto the legacy scheme.
        $this->configure('half', $login + ['file_id' => 12345, 'access_key' => 'access-key-under-test']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        $this->manager()->client('half');
    }

    #[Test]
    public function a_connection_without_a_credential_is_refused(): void
    {
        // Refused here rather than allowed to 401 on first use, which would read as a revoked login
        // rather than as an unset environment variable - and spend a request saying so.
        $this->configure('nowhere', ['file_id' => 12345, 'username' => '', 'access_key' => ' ']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no credential');

        $this->manager()->client('nowhere');
    }

    #[Test]
    public function an_access_key_without_a_file_is_refused(): void
    {
        $this->configure('keyed', ['access_key' => 'access-key-under-test']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('uses an access_key but has no file_id');

        $this->manager()->client('keyed');
    }

    #[Test]
    public function an_oauth_connection_may_have_no_file(): void
    {
        // A login with no file configured can still list the files it reaches.
        $this->configure('files', ['username' => self::USERNAME, 'password' => 'password-under-test']);

        $this->assertNull($this->manager()->client('files')->config()->fileId);
    }

    #[Test]
    public function a_file_id_read_from_the_environment_as_a_string_is_still_a_number(): void
    {
        $this->configure('env', ['file_id' => ' 424242 ', 'access_key' => 'k']);

        $this->assertSame(424242, $this->manager()->client('env')->config()->fileId);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badFileIds(): array
    {
        return [
            'zero' => [0],
            'negative' => ['-5'],
            'not a number' => ['file-one'],
            'a decimal' => ['12.5'],
        ];
    }

    #[Test]
    #[DataProvider('badFileIds')]
    public function a_file_id_that_is_not_a_positive_whole_number_is_refused(mixed $fileId): void
    {
        $this->configure('bad', ['file_id' => $fileId, 'access_key' => 'k']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Saasu connection "bad" has a file_id of');

        $this->manager()->client('bad');
    }

    #[Test]
    public function the_page_size_defaults_to_saasus_maximum(): void
    {
        $this->assertSame(100, $this->manager()->client()->config()->pageSize);
    }

    #[Test]
    public function the_api_settings_reach_the_cores_config(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('saasu.base_uri', 'https://saasu.example.test/');
        $config->set('saasu.page_size', '50');

        $api = $this->manager()->client()->config();

        $this->assertSame('https://saasu.example.test', $api->baseUri);
        $this->assertSame(50, $api->pageSize);
        $this->assertSame(12345, $api->fileId);
    }

    #[Test]
    public function a_page_size_above_the_apis_maximum_names_the_configuration(): void
    {
        $this->container()->make(Config::class)->set('saasu.page_size', 500);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('saasu.page_size');

        $this->manager()->client();
    }

    #[Test]
    public function a_base_uri_without_a_scheme_is_refused_before_a_request_is_spent(): void
    {
        $this->container()->make(Config::class)->set('saasu.base_uri', 'api.saasu.com');

        try {
            $this->manager()->client();
            $this->fail('Expected an InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $this->assertStringContainsString('saasu.base_uri', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertStringContainsString('must be absolute', $e->getPrevious()->getMessage());
        }
    }

    #[Test]
    public function an_unset_default_falls_back_to_main(): void
    {
        $this->container()->make(Config::class)->set('saasu.default', '');

        $this->assertSame('main', $this->manager()->getDefaultConnection());
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        $this->container()->make(Config::class)->set('saasu.connections', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredConnections());

        $this->expectException(UnknownConnection::class);
        $this->expectExceptionMessage('No connections are configured');

        $manager->client('somewhere');
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_connection(): void
    {
        $this->assertSame($this->manager()->client()->contacts(), $this->manager()->contacts());
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        $this->manager()->__call('noSuchEndpoint', []);
    }

    #[Test]
    public function no_throttle_is_configured_for_a_test_suite(): void
    {
        $this->assertInstanceOf(NoThrottle::class, $this->manager()->client()->connection()->throttle());
    }

    #[Test]
    public function every_connection_shares_one_throttle(): void
    {
        // It keys on the file each request names, so sharing it is what makes two connections to
        // one file wait for each other.
        foreach (['process' => ProcessThrottle::class, 'cache' => CacheThrottle::class] as $mode => $class) {
            $this->container()->make(Config::class)->set('saasu.throttle', $mode);
            $this->container()->forgetInstance(SaasuManager::class);
            $manager = $this->manager();

            $this->assertInstanceOf($class, $manager->client('main')->connection()->throttle());
            $this->assertSame($manager->client('main')->connection()->throttle(), $manager->client('legacy')->connection()->throttle());
        }
    }

    #[Test]
    public function the_throttle_mode_is_read_case_insensitively(): void
    {
        $this->container()->make(Config::class)->set('saasu.throttle', ' Process ');

        $this->assertInstanceOf(ProcessThrottle::class, $this->manager()->client()->connection()->throttle());
    }

    #[Test]
    public function an_unknown_throttle_mode_is_refused(): void
    {
        $this->container()->make(Config::class)->set('saasu.throttle', 'redis');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage("saasu.throttle is 'redis'");

        $this->manager()->client();
    }

    #[Test]
    public function a_cache_store_that_cannot_lock_is_refused_for_the_shared_throttle(): void
    {
        Cache::extend('lockless', fn () => Cache::repository(new LocklessStore()));

        $config = $this->container()->make(Config::class);
        $config->set('cache.stores.lockless', ['driver' => 'lockless']);
        $config->set('saasu.cache_store', 'lockless');
        $config->set('saasu.throttle', 'cache');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The cache store "lockless" does not support atomic locks');

        $this->manager()->client();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $connections = $config->get('saasu.connections');
        $config->set('saasu.connections', array_merge(is_array($connections) ? $connections : [], [$name => $settings]));
    }

    private function manager(): SaasuManager
    {
        return $this->container()->make(SaasuManager::class);
    }
}
