<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | Which entry under "connections" a call without a name uses: Saasu::contacts()
    | is Saasu::client(<this>)->contacts(). An application working in one file
    | never needs to name it.
    |
    */

    'default' => env('SAASU_CONNECTION', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One entry per Saasu file you work in, named however you like -- the name is
    | what Saasu::client('...') takes. A file is one set of books, and nearly every
    | request names the file it acts on, so each connection carries its file id.
    |
    | Each needs ONE credential, and OAuth wins when both are given:
    |
    |   - username and password: Saasu's preferred scheme. The login must not have
    |     two-factor authentication on, because nothing unattended can answer the
    |     code Saasu texts. Setting only one of the two is refused rather than
    |     falling back to an access key.
    |
    |   - access_key: the web services access key from Settings - Web Services, used
    |     only when neither username nor password is set. Saasu calls this scheme
    |     legacy, and the key travels in the URL of every request. It belongs to one
    |     file, so a connection using it needs a file_id.
    |
    | Several connections may share one login. Its token is cached once, under a
    | key derived from the username, and reused by all of them.
    |
    */

    'connections' => [

        'main' => [
            'file_id' => env('SAASU_FILE_ID'),
            'username' => env('SAASU_USERNAME'),
            'password' => env('SAASU_PASSWORD'),
            'access_key' => env('SAASU_WS_ACCESS_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Saasu allows one request a second per file, and a daily quota. Exceed the
    | quota and Saasu blocks the WHOLE FILE for 24 hours -- every integration
    | using it, not only this one. So every request waits for its turn:
    |
    |   "cache"   - shared by every process through the cache store below, so
    |               several queue workers keep to one request a second between
    |               them. The store must support atomic locks.
    |   "process" - one request a second within each PHP process only.
    |   "none"    - no waiting. For a test suite faking the API; set
    |               SAASU_THROTTLE=none in phpunit.xml, or every faked request
    |               after the first waits a second.
    |
    */

    'throttle' => env('SAASU_THROTTLE', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Where OAuth tokens and the shared rate limit are kept. Null uses the
    | application's default store.
    |
    | A CACHED TOKEN IS A CREDENTIAL. It is stored as the cache stores anything
    | else, unencrypted, and its refresh token can obtain new access tokens for
    | twelve months. Name a store here that is no more widely readable than your
    | .env file. The "null" store keeps nothing, which would request a new token
    | on every PHP process and spend the quota doing it.
    |
    */

    'cache_store' => env('SAASU_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | How many items a list request asks for, from 1 to 100. Null uses 100,
    | Saasu's maximum, rather than its own default of 25: every page is a request
    | against the daily quota.
    |
    */

    'page_size' => env('SAASU_PAGE_SIZE'),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | Null uses Saasu's own host, which is what all but two situations want: a
    | recorded fixture served locally, and an outbound proxy that terminates the
    | connection.
    |
    */

    'base_uri' => env('SAASU_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, alongside a consumer's own
    | Http::globalRequestMiddleware() and the transport settings from
    | Http::globalOptions() -- proxy, TLS, protocol version, curl options. Global
    | headers, auth, query and body options are not applied: they would change the
    | request the core package built.
    |
    | The timeout bounds one request, and does not include the time a request
    | spends waiting for its turn under the rate limit.
    |
    */

    'timeout' => env('SAASU_TIMEOUT', 30),

    'connect_timeout' => env('SAASU_CONNECT_TIMEOUT', 5),

];
