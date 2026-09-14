<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Laravel\SaasuManager;
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;
use Hampel\Saasu\Api\Request\ContactRequest;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_connection(): void
    {
        // The reason the transport is bound under its own key rather than constructed inside the
        // manager: an application with its own outbound HTTP policy binds it there and this package
        // uses it, logins included, instead of the application writing a second API client.
        $recorder = new class () implements ClientInterface {
            /** @var list<string> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request->getMethod() . ' ' . $request->getUri();

                $body = str_ends_with($request->getUri()->getPath(), 'authorisation/token')
                    ? '{"access_token":"recorded-token","expires_in":10800}'
                    : '{"Id":1,"GivenName":"Joe"}';

                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };

        $this->container()->instance(SaasuServiceProvider::HTTP_CLIENT, $recorder);

        Saasu::contacts()->get(1);
        Saasu::client('legacy')->contacts()->get(2);

        $this->assertSame([
            'POST https://api.saasu.com/authorisation/token',
            'GET https://api.saasu.com/Contact/1?FileId=12345',
            'GET https://api.saasu.com/Contact/2?FileId=67890&wsAccessKey=access-key-under-test',
        ], $recorder->sent);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Contact/54353')
            && $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function the_configured_timeouts_reach_the_request(): void
    {
        // Not automatic. PendingRequest merges its options only inside its own sendRequest(), and
        // the adapter sends through the built Guzzle client instead, so the timeouts set on the
        // pending request never reach a request unless passed on by hand. Read at the handler,
        // which is where Guzzle acts on them.
        $this->container()->make(Config::class)->set('saasu.timeout', 7);
        $this->container()->make(Config::class)->set('saasu.connect_timeout', 3);
        $this->container()->forgetInstance(SaasuServiceProvider::HTTP_CLIENT);
        $this->container()->forgetInstance(SaasuManager::class);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.saasu.com/*' => Http::response(self::contact())]);

        Saasu::client('legacy')->contacts()->get(54353);

        $this->assertSame(7.0, $options[0]['timeout'] ?? null);
        $this->assertSame(3.0, $options[0]['connect_timeout'] ?? null);
    }

    #[Test]
    public function a_global_transport_option_reaches_the_request(): void
    {
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/corporate-ca.pem',
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']],
        ]);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.saasu.com/*' => Http::response(self::contact())]);

        Saasu::client('legacy')->contacts()->get(54353);

        $this->assertSame('/etc/ssl/certs/corporate-ca.pem', $options[0]['verify'] ?? null);
        $this->assertSame(['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']], $options[0]['proxy'] ?? null);
    }

    #[Test]
    public function a_global_option_that_would_change_the_request_is_not_applied(): void
    {
        // The core package builds the request; the transport sends it as built. A global query
        // would replace the query string that carries the file id, and a global header the bearer
        // token.
        Http::globalOptions([
            'headers' => ['Accept' => 'text/html', 'Authorization' => 'Bearer not-this-one'],
            'query' => ['injected' => '1'],
            'json' => ['injected' => true],
        ]);

        $this->fakeSaasu(['api.saasu.com/Contact*' => Http::response(['InsertedContactId' => 123])]);

        Saasu::contacts()->create(ContactRequest::person('Jane', 'Citizen'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.saasu.com/Contact?FileId=12345'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN)
            && $request['GivenName'] === 'Jane'
            && ! isset($request['injected']));
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // RequestSending is dispatched from a before-sending callback INSIDE the handler stack this
        // adapter drives, so it fires. ResponseReceived is dispatched from PendingRequest::send(),
        // a layer above that stack, which the adapter never calls.
        $sending = 0;
        $received = 0;

        Event::listen(RequestSending::class, function () use (&$sending): void {
            $sending++;
        });
        Event::listen(ResponseReceived::class, function () use (&$received): void {
            $received++;
        });

        Http::fake(['api.saasu.com/*' => Http::response(self::contact())]);

        Saasu::client('legacy')->contacts()->get(54353);

        $this->assertSame(1, $sending);
        $this->assertSame(0, $received);
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // Asserted through the transport rather than only on the Config object, because a base URI
        // that reached Config and not the wire would look configured and change nothing.
        $this->container()->make(Config::class)->set('saasu.base_uri', 'http://localhost:8080');

        Http::fake([
            'localhost:8080/authorisation/*' => Http::response(self::token()),
            'localhost:8080/*' => Http::response(self::contact()),
        ]);

        Saasu::contacts()->get(54353);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/authorisation/token');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/Contact/54353?FileId=12345');
    }

    #[Test]
    public function the_configured_page_size_is_asked_for_on_every_list(): void
    {
        $this->container()->make(Config::class)->set('saasu.page_size', 40);

        $this->fakeSaasu(['api.saasu.com/Contacts*' => Http::response(self::contacts([self::contact()]))]);

        Saasu::contacts()->list();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Contacts?')
            && str_contains($request->url(), 'PageSize=40'));
    }

    /**
     * Records the Guzzle options of every request, as the handler receives them.
     *
     * @return \ArrayObject<int, array<array-key, mixed>>
     */
    private function optionsSeenByTheHandler(): \ArrayObject
    {
        /** @var \ArrayObject<int, array<array-key, mixed>> $seen */
        $seen = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
            $seen[] = $options;

            return $handler($request, $options);
        });

        return $seen;
    }
}
