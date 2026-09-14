<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel version
 * under test comes from Composer resolution rather than from an installed framework.
 *
 * Two connections: `main` logs in with OAuth to file 12345, `legacy` uses an access key on file
 * 67890. The throttle is off, as a consumer's suite would have it, except where a test turns it on.
 */
abstract class TestCase extends BaseTestCase
{
    protected const USERNAME = 'api@example.test';

    protected const ACCESS_TOKEN = 'access-token-under-test';

    protected const REFRESH_TOKEN = 'refresh-token-under-test';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SaasuServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Saasu' => Saasu::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        $app['config']->set('saasu.default', 'main');
        $app['config']->set('saasu.connections', [
            'main' => [
                'file_id' => 12345,
                'username' => self::USERNAME,
                'password' => 'password-under-test',
            ],
            'legacy' => [
                'file_id' => 67890,
                'access_key' => 'access-key-under-test',
            ],
        ]);
        $app['config']->set('saasu.throttle', 'none');
        $app['config']->set('saasu.cache_store', null);
        $app['config']->set('saasu.base_uri', null);
        $app['config']->set('saasu.page_size', null);
    }

    /**
     * Fakes Saasu's token endpoint alongside the given routes.
     *
     * The token route is registered first because Http::fake() answers from the first pattern
     * that matches, and a test's catch-all `api.saasu.com/*` would otherwise answer the login.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakeSaasu(array $routes): void
    {
        Http::fake(['api.saasu.com/authorisation/*' => Http::response(self::token())] + $routes);
    }

    /**
     * What Saasu's token and refresh endpoints answer with.
     *
     * @return array<string, mixed>
     */
    protected static function token(string $accessToken = self::ACCESS_TOKEN, string $scope = 'full fileid:12345'): array
    {
        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 10800,
            'refresh_token' => self::REFRESH_TOKEN,
            'scope' => $scope,
        ];
    }

    /**
     * A contact as Saasu answers `GET Contact/{id}` - unwrapped, trimmed to the fields the tests
     * read.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function contact(int $id = 54353, array $overrides = []): array
    {
        return $overrides + [
            'Id' => $id,
            'LastUpdatedId' => 'AAAAAFwWAN8=',
            'GivenName' => 'Joe',
            'FamilyName' => 'Blogs',
            'EmailAddress' => 'joe@example.com',
            'IsActive' => true,
        ];
    }

    /**
     * The envelope `GET Contacts` answers in. Saasu gives no total, and a `next` link on every page.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    protected static function contacts(array $contacts): array
    {
        return [
            'Contacts' => $contacts,
            '_links' => [['rel' => 'next', 'href' => 'https://api.saasu.com/Contacts?FileId=12345&Page=2', 'method' => 'GET', 'title' => null]],
        ];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use of
     * it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the app
        // boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while running
        // tests - so phpunit.xml's failOnDeprecation never sees one and is inert in any
        // Testbench-based package. This throws on them instead.
        $this->withoutDeprecationHandling();
    }
}
