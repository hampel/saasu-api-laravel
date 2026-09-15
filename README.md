# Saasu API for Laravel

[![Tests](https://github.com/hampel/saasu-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/saasu-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/saasu-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/saasu-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/saasu-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/saasu-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/saasu-api-laravel.svg?style=flat-square)](https://github.com/hampel/saasu-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/saasu-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/saasu-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/saasu-api`][core] — a service provider, a manager for named
connections, and a facade.

The Saasu API is rationed: one request a second, a daily quota, and a 24-hour block on the **whole
file** — every integration using it — once the quota is exceeded. The first two things this
package adds are about that:

- **OAuth tokens are cached.** Every PHP process shares one token per login, rather than logging in
  again on every web request and every worker restart, each time spending quota.
- **The rate limit is shared.** Several queue workers keep to one request a second per file between
  them, not one each.
- **Named connections.** A file id and a credential per connection, a default, and
  `Saasu::client('name')` to reach one.
- **`Http::fake()` sees the API client's traffic.** Every request goes through Laravel's own handler
  stack, so `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` all work.

The request building, status mapping and exception hierarchy are the core package's, untouched.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero works too, with the HTTP component installed (`php <app> app:install http`). Laravel
binds `Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`, which a
Laravel Zero application does not register; unbound, `Http::fake()` silently fails to intercept
and the request reaches the real API. This package binds one when nothing else has, so the
behaviour is the same on both.

## Installation

```bash
composer require hampel/saasu-api-laravel
```

In a Laravel application the provider and the `Saasu` alias are discovered automatically. Publish
the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=saasu-config
```

**Laravel Zero does not run package discovery**, so there the provider has to be listed by hand, in
`config/app.php`:

```php
'providers' => [
    Hampel\Saasu\Api\Laravel\SaasuServiceProvider::class,
],
```

The global `Saasu` alias is not registered either. Import the facade class —
`use Hampel\Saasu\Api\Laravel\Facades\Saasu;` — or inject `SaasuManager`.

`vendor:publish --tag=saasu-config` works in Laravel Zero once the provider is listed, though `list`
does not show it: Laravel Zero hides the command rather than removing it.

## Configuration

```dotenv
SAASU_FILE_ID=12345
SAASU_USERNAME=api@example.com
SAASU_PASSWORD=your-password
```

The shipped `config/saasu.php` defines one connection called `main`. Add more by naming them — one
per file, or one per login:

```php
'default' => 'main',

'connections' => [
    'main' => [
        'file_id' => env('SAASU_FILE_ID'),
        'username' => env('SAASU_USERNAME'),
        'password' => env('SAASU_PASSWORD'),
    ],

    'subsidiary' => [
        'file_id' => env('SUBSIDIARY_SAASU_FILE_ID'),
        'username' => env('SAASU_USERNAME'),
        'password' => env('SAASU_PASSWORD'),
    ],
],
```

Connections sharing a login share its token: it is cached under a key derived from the username.

**An application config file named `saasu.php` replaces these settings key by key.** Laravel merges
a package's configuration shallowly, and the application's file wins for every top-level key it
defines. So an application that already keeps its own settings in `config/saasu.php` — a `timeout`
meaning something else, say — silently changes this package's settings of the same name. Publish
this file and edit it, or name your own settings file something else.

### Credentials

**A connection uses OAuth whenever it has a username and password, and its access key only when
that is the only credential it has.**

| the connection has | it uses |
|---|---|
| `username` and `password` | OAuth |
| `username`, `password` and `access_key` | OAuth — the key is ignored |
| `access_key` only | the web services access key |
| only one of `username` and `password` | nothing — refused, even with an `access_key` |
| none of them | nothing — refused |

- **OAuth is Saasu's preferred scheme.** The login must not have two-factor authentication on,
  because nothing unattended can answer the code Saasu texts.
- **Give the integration a login restricted to what it needs.** A Saasu user whose permissions in
  the file are limited — to contacts, say — gets a token like any other, and Saasu holds that token
  to those permissions: a request outside them raises `NotPermittedException`. A leaked password
  that reaches only contacts cannot raise an invoice.
- **The access key is Saasu's legacy scheme**, from Settings, Web Services. It travels in the URL
  of every request, where proxies and access logs can see it, and it belongs to one file, so a
  connection using it must have a `file_id`.
- **Half a login is refused rather than ignored**, so an unset `SAASU_PASSWORD` cannot quietly move
  a connection that also has an access key onto the legacy scheme.

Every refusal happens when the client is built, before a request is spent, and raises
`Hampel\Saasu\Api\Laravel\Exception\InvalidConfiguration`. An empty string counts as unset.

A connection without a `file_id` is allowed with OAuth, for listing the files a login can reach
with `Saasu::client('name')->files()->all()`.

### Rate limit

```php
'throttle' => env('SAASU_THROTTLE', 'cache'),   // cache, process or none
'cache_store' => env('SAASU_CACHE_STORE'),      // null uses the default store
```

**Every request waits for its turn, logins included.** Saasu allows one request a second per file.

| `throttle` | keeps to one request a second |
|---|---|
| `cache` | per file, across every process sharing the cache store — the default |
| `process` | per file, within each PHP process only |
| `none` | not at all — for a test suite |

The `cache` mode reserves each process a slot under a short cache lock, then sleeps until it, so
workers take turns in the order they arrived. It needs a store with atomic locks — redis, database,
memcached, dynamodb, file or array — and is refused on one without. Workers on several hosts need
clocks that agree. **The `null` store defeats it silently**: it remembers nothing, so nothing waits.

**The turn is the file's, whichever connection asks.** Two connections to one file wait for each
other, and a client moved to another file with `Saasu::withFileId(67890)` waits on file 67890.
Logins and the list of files a login reaches name no file, and share a turn of their own.

### Tokens

**An OAuth token is cached in the same store, without an expiry**, under a key derived from the
username. Its refresh token lasts twelve months, and refreshing costs the same one request as
logging in, so the grant is kept rather than left to expire. Two workers that find a token expired
at the same moment both refresh it, and both succeed.

**A cached token is a credential, stored unencrypted**, as the cache stores anything else. Point
`cache_store` at a store no more widely readable than your `.env` file.

To keep tokens somewhere else, bind your own `Hampel\Saasu\Api\Authentication\TokenStore`:

```php
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;

$this->app->singleton(SaasuServiceProvider::TOKEN_STORE, fn () => new MyTokenStore());
```

### Page size and base URI

```php
'page_size' => env('SAASU_PAGE_SIZE'),   // 1 to 100; null uses 100
'base_uri' => env('SAASU_API_URL'),      // null uses Saasu's own host
```

Both are shared by every connection and validated when a client is built. **The page size defaults
to Saasu's maximum** rather than its own default of 25, because every page is a request against the
quota. `base_uri` exists for a recorded fixture served locally and for an outbound proxy that
terminates the connection.

**There is no request budget setting**, because clients are kept for the life of the process, and a
budget configured here would count every request a queue worker had sent since it started. Give a
job its own instead:

```php
$saasu = Saasu::withRequestBudget(50);     // this job may send 50 requests, counted from zero
```

Past it, the next request raises `RequestBudgetExhaustedException` without being sent.

### Transport

```php
'timeout' => 30,
'connect_timeout' => 5,
```

Applied to every request, alongside any `Http::globalRequestMiddleware()` the application has
configured and the transport settings from `Http::globalOptions()` — a proxy, a CA bundle or client
certificate, the protocol version, curl options. **Global options that would change the request
itself are not applied**: `headers`, `auth`, `query` and the body options would overwrite what the
core package built, including its credential and file id. The timeout does not include the time a
request waits under the rate limit.

## Usage

The facade reaches the default connection directly:

```php
use Hampel\Saasu\Api\Laravel\Facades\Saasu;

$file = Saasu::verify();                    // does this credential reach this file?

$contacts = Saasu::contacts()->list();      // the first page of up to 100
$contact = Saasu::contacts()->get(54353);
```

Name a connection to reach another:

```php
$invoices = Saasu::client('subsidiary')->invoices()->list();
```

**It is `client()` and not `connection()`.** `Saasu::connection()` is the core package's transport,
and the manager forwards unknown calls to the default client, so a method on both would mean
different things depending on which class you thought you were calling.

Everything past that point is the core package — see [its documentation][core] for the endpoints,
entities, filters, pagination and updating a record.

Inject the manager where a facade is not wanted:

```php
use Hampel\Saasu\Api\Laravel\SaasuManager;

public function __construct(private readonly SaasuManager $saasu) {}

$this->saasu->client('subsidiary')->contacts()->list();
```

## Errors

The core package's exceptions arrive untouched:

```php
use Hampel\Saasu\Api\Exception\ConcurrencyException;
use Hampel\Saasu\Api\Exception\NotAuthenticatedException;
use Hampel\Saasu\Api\Exception\NotFoundException;

try {
    $contact = Saasu::contacts()->get($id);
} catch (NotFoundException $e) {
    // no such contact in this file
} catch (NotAuthenticatedException $e) {
    // the login or key is no good. A configuration error, not an empty result.
}
```

**A restricted login is refused with `NotPermittedException`** — usually a 403, though tax codes
answer a 400 saying so. One refusal does not say so: a restricted user's payment list answers a 400
about a null collection, which arrives as `ValidationException`.

`UnknownConnection` and `InvalidConfiguration` extend the core package's `SaasuException`, so an
application already catching that catches these too.

## Testing

**Set `SAASU_THROTTLE=none` in `phpunit.xml`**, or every faked request after the first waits a
second:

```xml
<env name="SAASU_THROTTLE" value="none"/>
```

Then fake the API with the vocabulary the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'api.saasu.com/authorisation/*' => Http::response([
        'access_token' => 'test-token',
        'expires_in' => 10800,
    ]),
    'api.saasu.com/Contact/*' => Http::response([
        'Id' => 54353,
        'GivenName' => 'Joe',
    ]),
]);

$contact = Saasu::contacts()->get(54353);

Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
```

The package's real code path runs; only the socket is replaced. So a faked 404 still arrives as
`NotFoundException`, and a faked 200 whose body is HTML still arrives as
`MalformedResponseException`.

- **An OAuth connection logs in first.** Fake `api.saasu.com/authorisation/*`, and list it before
  any catch-all such as `api.saasu.com/*`: `Http::fake()` answers from the first pattern that
  matches. Once a token is cached, later requests in the same test do not log in again.
- **Give every fake a body.** `Http::fake()` with no arguments answers every request with an empty
  200, which the core package refuses for a read.
- **Order does not matter.** Faking after the client has been resolved works, and so does
  `Http::swap(new Factory)` to start a test from a clean set of fakes: the transport looks the
  factory up at the moment of sending.

Replace the transport entirely by binding `saasu.http_client` — how an application with its own
outbound HTTP policy makes this package use it:

```php
use Hampel\Saasu\Api\Laravel\SaasuServiceProvider;

$this->app->singleton(SaasuServiceProvider::HTTP_CLIENT, fn () => $myPsr18Client);
```

The package binds that key and `saasu.token_store` only if nothing has already, so an override works
from any service provider, whether it registers before or after this package's — including
`AppServiceProvider` in a Laravel Zero `config/app.php`, where it is listed first.

**This package does not use a `Psr\Http\Client\ClientInterface` binding**, yours or another
package's, and does not bind that key itself. It is one key shared by every package that uses it,
so an application with several API integrations installed would otherwise get whichever registered
last.

### What Laravel's HTTP events see

**`RequestSending` fires; `ResponseReceived` and `ConnectionFailed` do not.** Laravel raises the
first from inside the handler stack this package sends through, and the other two from a layer
above it. So Telescope's HTTP client watcher, which listens for `ResponseReceived`, will not show
this traffic. The core package logs every request at `debug` through PSR-3, and an error response
at `error`, with any access key removed from the URI.

## Versioning

`hampel/saasu-api` is 0.x, so its public API can change in a minor release; this package constrains
it at `^0.2` and expects to bump.

## License

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/saasu-api
