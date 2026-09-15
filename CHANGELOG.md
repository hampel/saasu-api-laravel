# CHANGELOG

## 0.1.0 (2026-09-15)

- `SaasuServiceProvider`, `SaasuManager` and the `Saasu` facade for `hampel/saasu-api` `^0.2`
- Named connections, each with a `file_id` and a credential; `Saasu::client('name')` reaches one
- OAuth is used whenever a connection has a username and password; the web services access key only
  when it is the connection's only credential
- A connection with only one of `username` and `password`, no credential, or an access key without a
  `file_id` raises `InvalidConfiguration` when its client is built
- OAuth grants are kept in a cache store by `CacheTokenStore`, bound as `saasu.token_store`
- `CacheThrottle` keeps to one request a second per file across processes sharing a cache store,
  and `ProcessThrottle` per file within one process; `saasu.throttle` selects `cache`, `process` or
  `none`. Both wait on the file each request names, including after `withFileId()`
- Requests are sent through Laravel's HTTP client, so `Http::fake()`, `Http::assertSent()` and
  `Http::preventStrayRequests()` apply to them; the transport is bound as `saasu.http_client`
- `saasu.page_size`, `saasu.base_uri`, `saasu.timeout` and `saasu.connect_timeout` settings
