# hampel/saasu-api-laravel

Laravel integration for `hampel/saasu-api`. A service provider, a manager for named connections, a
facade, the PSR-18 adapter that makes `Http::fake()` see the traffic — and the two things the Saasu
API's rationing makes necessary: a token store and a throttle that span processes.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/SaasuManager.php` | one client per configured connection, memoised; credential precedence; throttle per file |
| `src/SaasuServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Cache/CacheTokenStore.php` | OAuth grants in a cache store |
| `src/Cache/CacheThrottle.php` | one request a second per file, across processes |
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/Facades/Saasu.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/saasu.php` | the published config |

## The API is rationed, and that is why this package is more than wiring

**Saasu allows one request a second, a daily quota, and blocks the whole file for 24 hours once the
quota is exceeded** — every integration on that file, not only this one. Read the core package's
`CLAUDE.md` for the measured facts. Two consequences here:

- **Every token request spends quota**, so OAuth grants go through `CacheTokenStore` rather than the
  core's in-memory store, which would log in again in every PHP process. `TokenStoreTest` asserts a
  second process reuses the first one's token.
- **The core's `IntervalThrottle` sees one process**, so `CacheThrottle` reserves slots under a
  cache lock. `CacheThrottleTest` drives several instances over one store with a fixed clock.

**Treat any change that adds a request as a change with a cost** — a verification call on boot, a
retry, a warm-up. None exists, deliberately.

## Tokens: cached, unencrypted, no expiry, no lock

- **No lock around refresh.** Saasu does not rotate the refresh token, and the old one keeps working
  (measured against the live API on 2026-09-14), so two workers refreshing at once both succeed.
- **No cache TTL.** The grant carries its own expiry and OAuth checks it; the refresh token outlives
  the access token by months; a refresh costs the same one request as a login.
- **Stored as `AccessGrant::toArray()`**, never `jsonSerialize()`, which hides the tokens on
  purpose.
- **Unencrypted**, like everything else in a cache. Encrypting would need `APP_KEY`, which a Laravel
  Zero application often lacks. The README says to choose the store accordingly.
- **Resolved lazily** through `SaasuServiceProvider::TOKEN_STORE`, so an access-key-only application
  never resolves the cache for tokens. `ConfigurationTest` pins that with an unusable binding.

## The throttle: per file, slots reserved, the wait outside the lock

- **Keyed on the file each request names**, which the core passes to `Throttle::wait(?int $fileId)`
  from 0.2. So the manager builds one throttle per mode for every connection, and a client derived
  with `withFileId()` waits on its new file. Requests naming no file — logins, the file list — share
  `CacheThrottle::keyFor(null)`. Saasu documents the limit without saying what it counts; per file is
  the assumption the core package documents, and is unmeasured.
- **`process` mode is `ProcessThrottle`, one `IntervalThrottle` per file id**, because the core's
  `IntervalThrottle` ignores the argument, and one shared instance would make different files wait
  for each other. Probed: keying every file alike fails `ProcessThrottleTest`, and doing the same
  in `CacheThrottle` fails eight tests, including the derived-client one.
- **The lock is held for one read and one write.** The process then sleeps until its slot. A
  `LockTimeoutException` reaches the caller rather than sending anyway.
- **The slot's cache lifetime is measured from the slot, not from now**, or a queue of waiting
  workers outlives the entry and a newcomer takes slot zero. Probed: shortening it fails
  `the_last_slot_is_kept_until_a_queue_of_waiting_workers_has_gone`.
- **A store without `LockProvider` is refused** for `throttle = cache`. Every store Laravel ships
  implements it, including `null`, whose locks always succeed and which remembers nothing — so the
  null store defeats the throttle silently. The README warns; there is no reliable check.

## Credentials: OAuth wins, half a login is refused

**A username and password mean OAuth, whatever else is set; the access key is used only when it is
the connection's only credential.** The access key is Saasu's legacy scheme and travels in every
URL. **One of username or password without the other is refused**, not treated as absent — otherwise
an unset password would move a connection that also carries an access key onto the legacy scheme
without a word. `ManagerTest` covers each case; probed by checking the access key first, which fails
four of them.

## No request budget in configuration

The core `Config` takes a request budget, and it is deliberately not exposed. Clients are memoised
for the life of the process, so in a queue worker a configured budget would be a lifetime cap. A job
gets its own with `Client::withRequestBudget()`, from core 0.2, whose count starts at zero; the
README shows it through the facade. `withConfig()` with a budget still shares the parent's count.

## The transport lives under `saasu.http_client`, never the shared PSR-18 key

**The provider binds `PendingRequestClient` under `SaasuServiceProvider::HTTP_CLIENT` with
`singletonIf()`, and builds the manager from that key alone.** `singletonIf()` because Laravel Zero
lists `AppServiceProvider` before a package provider, so `singleton()` would silently replace an
application's override there; probed, `HttpClientOverrideTest` fails with `singleton()`.

It does not bind `Psr\Http\Client\ClientInterface`, and does not read it: in an application with
several API wrappers installed, the last provider to claim that key would supply every package's
transport. **Do not add a fallback to `ClientInterface` when it is bound.** Probed by binding and
reading the shared key: nine tests fail, including `SharedPsr18BindingTest`'s before-order binding
case and its faked-sibling case.

The adapter is shared with the sibling Laravel API wrappers, and its docblock carries the reasoning
for each load-bearing decision: rebuilt per send, factory resolved per send, one Guzzle handler
reused, `send()` with four options set by hand (Laravel 12 reads `laravel_data` and `on_stats`
without a default, so only the Laravel 12 CI job catches their removal), and transport options
passed on from an allowlist. `TransportTest` pins the options.

## Facts worth not rediscovering

- **The core constraint is `^0.2`**, for `Throttle::wait(?int $fileId)` — a breaking change to the
  interface both throttles implement — and `Client::withRequestBudget()`.
- **The named-connection accessor is `client()` because `connection()` is taken** by `Client`.
  `FacadeConformanceTest::the_manager_does_not_shadow_a_client_method` keeps any name from being
  reintroduced. The config key is still `connections`.
- **An OAuth connection sends a login before its first request**, so a test faking only the endpoint
  under test fails on the token. `TestCase::fakeSaasu()` registers the token route first, because
  `Http::fake()` answers from the first matching pattern. Tests that need exactly one request use
  the `legacy` access-key connection.
- **Every test that could send a real request carries its own containment**: `Http::fake()`,
  `preventStrayRequests()`, or a base URI on `api.saasu.invalid`. Keep it that way before probing
  one by reverting a fix — a reverted fix is exactly the request that escapes.
- **`failOnDeprecation` is inert in a Testbench package without help.**
  `withoutDeprecationHandling()` in `setUp()` covers test-executed paths; `ConfigurationTest`
  registers and boots the provider against an application built in the test body to cover
  `register()` and `boot()`. All three probed with `trigger_error(..., E_USER_DEPRECATED)`; each
  exits 2.
- **Laravel Zero does not bind the HTTP client factory, nor run package discovery.** The provider
  binds the factory with `singletonIf`; `LaravelZeroTest` builds that container by hand, and cannot
  test the README's registration instructions.
- **`composer-require-checker` carries the undeclared-dependency check.** Every whitelisted symbol
  in `.github/composer-require-checker.json` belongs to a component in `require`, unattributable
  because `laravel/framework` `replace`s them. A *new* `Illuminate` symbol reported there is a
  prompt to check `require`, not to extend the list. `ext-ctype` is declared for `ctype_digit()`.

## The facade's annotations are the only types it has

`Saasu::contacts()` goes through the manager's `__call()` and returns `mixed`; the `@method static`
block is what makes the chain analysable. `tests/FacadeConformanceTest.php` compares that block with
`Client`'s public methods in both directions and checks every annotated type resolves.

## No harness, because what the suite cannot see is Saasu, not the container

Nothing here owns behaviour Testbench cannot reach: no artisan command, no queued job. The one
multi-process behaviour, the throttle, is driven by `CacheThrottleTest` with several instances over
one store. The live API is the core package's harness's job, and a Laravel harness would reach it
through a thicker stack with Laravel inside it.
