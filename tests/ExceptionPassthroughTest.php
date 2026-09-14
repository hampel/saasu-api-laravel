<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests;

use Hampel\Saasu\Api\Exception\ClientException;
use Hampel\Saasu\Api\Exception\ConcurrencyException;
use Hampel\Saasu\Api\Exception\MalformedResponseException;
use Hampel\Saasu\Api\Exception\NotAuthenticatedException;
use Hampel\Saasu\Api\Exception\NotFoundException;
use Hampel\Saasu\Api\Exception\NotPermittedException;
use Hampel\Saasu\Api\Exception\ServerException;
use Hampel\Saasu\Api\Exception\TooManyRequestsException;
use Hampel\Saasu\Api\Exception\ValidationException;
use Hampel\Saasu\Api\Laravel\Facades\Saasu;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * What the core package is worth is not its transport but its taxonomy: a client that reports
 * every unsuccessful status the same way cannot tell a missing contact from a refused login, and
 * only one of them is a configuration error. Laravel's own Http:: is the thing that flattens them.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, string, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected value' => [400, '{"Message":"The GivenName field is required."}', ValidationException::class],
            'a stale update, told apart by its sentence' => [409, '"Record to be updated has changed since last read."', ConcurrencyException::class],
            'a forbidden action is its own failure' => [403, '{"Message":"Not permitted."}', NotPermittedException::class],
            'a missing contact' => [404, '', NotFoundException::class],
            'rate limiting is typed so a caller can back off' => [429, '', TooManyRequestsException::class],
            'Saasu broke, not the caller' => [500, '', ServerException::class],
            'anything else the caller got wrong' => [418, '', ClientException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $body, string $expected): void
    {
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response($body, $status, ['Content-Type' => 'application/json'])]);

        $this->expectException($expected);

        Saasu::contacts()->get(54353);
    }

    #[Test]
    public function a_refused_token_is_renewed_once_and_then_reported(): void
    {
        // A bodiless 401 is Saasu refusing the credential. The core package gives OAuth one chance
        // to recover - through the token endpoint, which has to be faked like anything else - and
        // reports the second refusal rather than looping.
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response('', 401)]);

        try {
            Saasu::contacts()->get(54353);
            $this->fail('Expected a NotAuthenticatedException.');
        } catch (NotAuthenticatedException) {
            $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'Contact/54353'))->count());
        }
    }

    #[Test]
    public function the_retry_after_header_survives_the_trip(): void
    {
        $this->fakeSaasu(['api.saasu.com/Contact/*' => Http::response('', 429, ['Retry-After' => '30'])]);

        try {
            Saasu::contacts()->get(54353);
            $this->fail('Expected a TooManyRequestsException.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a Cloudflare challenge and a truncated body are all a 200 with
        // something other than JSON in it.
        $this->fakeSaasu(['api.saasu.com/Contacts*' => Http::response('<html><body>Down for maintenance</body></html>', 200)]);

        $this->expectException(MalformedResponseException::class);

        Saasu::contacts()->list();
    }

    #[Test]
    public function faking_with_no_arguments_fails_loudly_rather_than_reporting_an_empty_file(): void
    {
        // Http::fake() with no arguments answers every request with an empty 200 - the easiest
        // mistake to make in a consumer's suite. The access key connection, so the empty answer
        // reaches a list rather than the login.
        Http::fake();

        $this->expectException(MalformedResponseException::class);

        Saasu::client('legacy')->contacts()->list();
    }
}
