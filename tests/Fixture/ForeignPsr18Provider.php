<?php

declare(strict_types=1);

namespace Hampel\Saasu\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Another package's provider, binding the shared PSR-18 key the way API wrappers used to -
 * `singleton(ClientInterface::class, ...)`.
 *
 * Any request that reaches this client is a request this package sent through someone else's
 * transport, with someone else's timeouts. It records the URI and answers with a status no
 * endpoint would accept, so a test that did not look at the recorder still fails.
 */
final class ForeignPsr18Provider extends ServiceProvider
{
    public static ?ForeignPsr18Client $client = null;

    public function register(): void
    {
        self::$client = new ForeignPsr18Client();

        $this->app->singleton(ClientInterface::class, static fn (): ClientInterface => self::$client ?? new ForeignPsr18Client());
    }
}

/**
 * @internal
 */
final class ForeignPsr18Client implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();

        return new Response(418, [], 'a foreign transport answered');
    }
}
