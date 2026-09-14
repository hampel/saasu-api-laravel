<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Entity\Contact;
use Hampel\Saasu\Api\Exception\NotFoundException;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Hampel\Saasu\Api\Request\ContactRequest;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Http::fake() sees the core package's traffic.
 *
 * hampel/saasu-api holds its own PSR-18 client, so by default nothing it sends is visible to
 * Http::fake() and an application testing against it has to fake at the transport library
 * instead. Everything below goes through the package's real request building, token handling,
 * status mapping and exception hierarchy; only the socket is replaced.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        $contact = Saasu::contacts()->get(54353);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(54353, $contact->id);
        $this->assertSame('Joe', $contact->givenName);
    }

    #[Test]
    public function the_login_and_the_request_are_both_recorded_for_assertion(): void
    {
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::contacts()->get(54353);

        Http::assertSentInOrder([
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://api.saasu.com/authorisation/token'
                && $request['grant_type'] === 'password'
                && $request['username'] === self::USERNAME,
            fn (Request $request): bool => $request->method() === 'GET'
                && $request->url() === 'https://api.saasu.com/Contact/54353?FileId=12345'
                && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN)
                && $request->hasHeader('X-Api-Version', '1.0'),
        ]);
    }

    #[Test]
    public function an_access_key_travels_in_the_query_string(): void
    {
        Http::fake(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::client('legacy')->contacts()->get(54353);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.saasu.com/Contact/54353?FileId=67890&wsAccessKey=access-key-under-test'
            && ! $request->hasHeader('Authorization'));
    }

    #[Test]
    public function a_json_body_is_readable_by_the_assertion(): void
    {
        // Illuminate\Http\Client\Request::isJson() is a substring test over the Content-Type, which
        // the core package's bare `application/json` satisfies - so data() parses the body.
        $this->fakeSaasu(['api.saasu.com/Contact*' => Http::response(['InsertedContactId' => 123, 'LastUpdatedId' => 'AAAAAFwWAN8='])]);

        $saved = Saasu::contacts()->create(ContactRequest::person('Jane', 'Citizen'));

        $this->assertSame(123, $saved->id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.saasu.com/Contact?FileId=12345'
            && $request->isJson()
            && $request['GivenName'] === 'Jane'
            && $request['FamilyName'] === 'Citizen');
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the factory's stub
        // collection, and createPendingRequest() copies whatever is there when it is called.
        $client = Saasu::client();

        Http::preventStrayRequests();
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact(1, ['GivenName' => 'Late']))]);

        $this->assertSame('Late', $client->contacts()->get(1)->givenName);
    }

    #[Test]
    public function swapping_the_factory_after_the_client_was_resolved_still_intercepts(): void
    {
        // Http::swap(new Factory) is how a test gets a clean set of fakes, because fake() merges and
        // the first match wins. The transport resolves the factory at the moment of sending.
        $client = Saasu::client();

        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact(1, ['GivenName' => 'Before']))]);
        $this->assertSame('Before', $client->contacts()->get(1)->givenName);

        Http::swap(new Factory());
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact(1, ['GivenName' => 'After']))]);

        $this->assertSame('After', $client->contacts()->get(1)->givenName);
    }

    #[Test]
    public function a_swapped_factorys_stray_request_guard_applies_to_a_client_resolved_before_it(): void
    {
        // The dangerous half of the same property. preventStrayRequests() is set on the NEW factory;
        // a transport still holding the old, unfaked one would send the request for real, carrying
        // the configured credential.
        //
        // Pointed at a reserved .invalid host, so that if this ever regresses the escaped request
        // fails to resolve instead of reaching Saasu.
        $this->container()->make(Config::class)->set('saasu.base_uri', 'https://api.saasu.invalid');

        $client = Saasu::client('legacy');

        Http::swap(new Factory());
        Http::preventStrayRequests();

        $this->expectException(StrayRequestException::class);

        $client->contacts()->get(1);
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so it
        // arrives with Laravel's own message and the URL still in it.
        //
        // The access key connection, so the stray request is the one asked for rather than a login.
        // Its key is in that URL, which is Laravel's message and not the core package's log line.
        $this->container()->make(Config::class)->set('saasu.base_uri', 'https://api.saasu.invalid');

        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api.saasu.invalid/Contact/54353?FileId=67890');

        Saasu::client('legacy')->contacts()->get(54353);
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response.
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response('', 404)]);

        $this->expectException(NotFoundException::class);

        Saasu::contacts()->get(54353);
    }

    #[Test]
    public function a_client_moved_to_another_file_sends_through_the_same_faked_transport(): void
    {
        // withFileId() builds a new Client over the connection's existing transport. If it did not,
        // an application reaching a second file would fake the first call and reach the real API
        // on the second.
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response(self::contact())]);

        Saasu::withFileId(99999)->contacts()->get(54353);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.saasu.com/Contact/54353?FileId=99999'
            && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN));
    }
}
