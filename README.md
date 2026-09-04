# Klaviyo PHP SDK

[![CI](https://github.com/nickdnk/klaviyo-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/nickdnk/klaviyo-php-sdk/actions/workflows/ci.yml)
[![Coverage](https://coveralls.io/repos/github/nickdnk/klaviyo-php-sdk/badge.svg?branch=main)](https://coveralls.io/github/nickdnk/klaviyo-php-sdk?branch=main)
[![Latest version](https://img.shields.io/packagist/v/nickdnk/klaviyo-php-sdk)](https://packagist.org/packages/nickdnk/klaviyo-php-sdk)
[![Downloads](https://img.shields.io/packagist/dt/nickdnk/klaviyo-php-sdk)](https://packagist.org/packages/nickdnk/klaviyo-php-sdk/stats)
[![PHP](https://img.shields.io/packagist/dependency-v/nickdnk/klaviyo-php-sdk/php)](composer.json)
[![License](https://img.shields.io/packagist/l/nickdnk/klaviyo-php-sdk)](LICENSE)

This is a custom PHP client for the [Klaviyo API](https://developers.klaviyo.com/en/reference/api_overview). Klaviyo
publishes an official, generated package ([`klaviyo/api`](https://github.com/klaviyo/klaviyo-api-php)); this one differs
in what it offers:

- Request and response classes for every endpoint instead of associative arrays.
- Relationships and `include`d resources hydrate into objects.
- OAuth with automatic token refresh. The official package supports API keys only.
- Webhook signature verification and parsing. Not available in the official package.
- Any PSR-18 HTTP client; Guzzle is optional.
- Retries per Klaviyo's rate-limit guidance, and a request pool for bulk work.

Limitations:

- Not an official Klaviyo project.
- Pinned to one API revision per major version (see below). New endpoints appear here later than in the official
  package.
- Verified against a live test account, but features that account could not enable (custom object types, push tokens)
  were only checked as far as their error responses.

Requires PHP 8.3 or newer.

```bash
composer require nickdnk/klaviyo-php-sdk
composer require guzzlehttp/guzzle   # optional; any PSR-18 client works
```

## API revision

Klaviyo versions its API with dated revisions and keeps old revisions available for a long time.

- Every request is sent with `revision: 2026-07-15` (`APIClient::API_REVISION`).
- Request and response classes model that
  revision's [OpenAPI document](https://raw.githubusercontent.com/klaviyo/openapi/a6d7b76168b2410d8f2cbb0bf6e01996dae37318/openapi/stable.json).
- The revision changes only with a new major version of this package; the changelog names the new revision. Within a
  major version the revision never changes, whatever date you update on.

## Authentication

```php
use nickdnk\Klaviyo\APIClient;

$client = APIClient::withApiKey('pk_...');   // private API key
$client = new APIClient('eyJhbGci...');      // bearer token managed elsewhere, never refreshed
```

### OAuth

Pass the stored credentials, the app's client id and secret, and a callback that persists new credentials. The callback
is the only point where the SDK calls back into your code.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\OAuthCredentials;

// Loaded from your own storage (database, secret store, ...).
$saved = [
    'access_token'  => 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjAxYTA2Y...',
    'refresh_token' => 'eyJhbGciOiJFUzI1NiIsImtpZCI6IjAxYTA2Y...',
    'expires_at'    => 1788536447,
];

$client = APIClient::withOAuth(
    new OAuthCredentials($saved['access_token'], $saved['refresh_token'], $saved['expires_at']),
    clientId: $_ENV['KLAVIYO_CLIENT_ID'],
    clientSecret: $_ENV['KLAVIYO_CLIENT_SECRET'],
    onRefresh: function (OAuthCredentials $credentials): void {
        // Called after every successful refresh, before the request that triggered it is retried.
        // Write the new values back to the same place $saved came from. The previous access token keeps working
        // until it expires, so another process still holding it is not cut off; only the stored pair changes.
        $updated = [
            'access_token'  => $credentials->accessToken,
            'refresh_token' => $credentials->refreshToken,   // store it even when unchanged
            'expires_at'    => $credentials->expiresAt,      // unix timestamp
        ];
        // ... persist $updated
    },
);
```

On a 401 the client refreshes the tokens, calls `onRefresh`, and retries once; a second 401 is a
`ClientException`. A failed refresh throws `OAuthException`, whose `isInvalidGrant()` means the user must re-authorize.
Always persist the whole `OAuthCredentials` object: the access token changes on every refresh and the refresh token may
rotate.

To refresh before the token expires, e.g. ahead of a long job:

```php
if ($client->getCredentials()->isExpired(graceSeconds: 120)) {
    $client->refreshCredentials();
}
```

Several processes sharing one connection: lock around `refreshCredentials()`; after waiting on the lock, reload from
storage and call `setCredentials()` instead of refreshing again.

Connecting an account (authorization code + PKCE):

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\OAuthScope;

$clientId     = $_ENV['KLAVIYO_CLIENT_ID'];
$clientSecret = $_ENV['KLAVIYO_CLIENT_SECRET'];
$redirectUri  = 'https://example.com/klaviyo/callback';

// 1. Redirect the user. Keep $verifier and $state in the session.
$verifier = APIClient::generateCodeVerifier();
$state    = bin2hex(random_bytes(16));
$url      = APIClient::getOAuthLink($clientId, $state, $verifier, [OAuthScope::profilesRead, OAuthScope::eventsWrite], $redirectUri);

// 2. On the callback: compare $_GET['state'] with the stored $state, then exchange the code.
$credentials = APIClient::exchangeCodeForToken($clientId, $clientSecret, $_GET['code'], $verifier, $redirectUri);
// persist $credentials as shown under OAuth above
```

Disconnecting an account: `APIClient::revokeToken($clientId, $clientSecret, $credentials->refreshToken)`
invalidates both tokens; then delete what you stored.

## Requests

Each API area is a property on the client (`$client->profiles`, `$client->campaigns`, …; all are declared on `APIClient`
for autocompletion). Request bodies are `Resources\Request\*` objects, responses hydrate into `Resources\Response\*`
objects, and `get()` returns `null` on 404.

```php
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;

$new = new CreateProfile();
$new->email = 'jane@example.com';
$new->first_name = 'Jane';
$new->properties = ['plan' => 'pro'];
$profile = $client->profiles->create($new);        // Resources\Response\Profile

$patch = new PatchProfile($profile->id);
$patch->last_name = 'Doe';
$client->profiles->update($patch);

$query = (new Query())
    ->filter(Filter::all(
        Filter::equals('email', 'jane@example.com'),
        Filter::greaterThan('created', new DateTimeImmutable('-30 days')),
    ))
    ->fields('profile', 'email', 'first_name')
    ->sort('created', descending: true)
    ->pageSize(50);

$page = $client->profiles->list($query);            // ['data' => Profile[], 'links' => ?PaginationLinks]
while ($next = $page['links']?->next) {
    $page = $client->profiles->list(next: $next);
}
```

`page[size]` limits vary by endpoint (10 for lists, segments and templates; 20 for web feeds; 25 for tag groups; 50 for
tags and flows; 100 elsewhere; metric, mapped-metric, object-type and bulk-job listings take no page size). Klaviyo
answers 400 when exceeded.

### Queries and filters

`Query` collects the JSON:API query parameters and `Filter` builds Klaviyo's filter expressions with the quoting the API
expects, so user input never gets concatenated into a filter string.

```php
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;

$query = (new Query())
    ->filter(Filter::equals('email', 'jane@example.com'))   // filter=equals(email,"jane@example.com")
    ->fields('profile', 'email', 'created')                 // fields[profile]=email,created
    ->additionalFields('profile', 'subscriptions')          // additional-fields[profile]=subscriptions
    ->include('lists')                                      // include=lists
    ->sort('created', descending: true)                     // sort=-created
    ->pageSize(20);                                         // page[size]=20

$page = $client->profiles->list($query);
$page = $client->profiles->list($query->cursor($page['links']->next));   // or list(next: $page['links']->next)
```

Filter operators and what they serialise to:

| Call                                                                  | Expression                                                      |
|-----------------------------------------------------------------------|-----------------------------------------------------------------|
| `Filter::equals('email', 'a@b.test')`                                 | `equals(email,"a@b.test")`                                      |
| `Filter::any('status', ['queued', 'processing'])`                     | `any(status,["queued","processing"])`                           |
| `Filter::greaterThan('created', $dateTime)`                           | `greater-than(created,2026-01-01T00:00:00+00:00)`               |
| `Filter::lessThan(...)`, `lessOrEqual(...)`, `greaterOrEqual(...)`    | `less-than(...)`, `less-or-equal(...)`, `greater-or-equal(...)` |
| `Filter::contains('name', 'vip')`, `startsWith(...)`, `endsWith(...)` | `contains(name,"vip")`, `starts-with(...)`, `ends-with(...)`    |
| `Filter::containsAny('tags', [...])`, `containsAll(...)`              | `contains-any(tags,[...])`, `contains-all(...)`                 |
| `Filter::has('phone_number')`                                         | `has(phone_number)`                                             |
| `Filter::all($a, $b)`                                                 | `a,b` (Klaviyo's AND; there is no OR)                           |

Values: strings are double-quoted and escaped, ints/floats/bools are literal, `DateTimeInterface` becomes an unquoted
ISO 8601 timestamp, arrays become `[...]`. A `Filter` is `Stringable`, and `Query::filter()`
also takes a raw string for expressions the builder doesn't cover.

Which fields and operators an endpoint accepts is per endpoint (Klaviyo's reference lists them) and the API answers 400
for anything else; for example lists and segments allow only `equals`/`any` on
`name`, campaigns only `contains`, and `campaigns.list` requires an `equals(messages.channel,...)` filter.

### Relationships and `include`

`getRelationship($name)` returns a `Relationship` with `data` (a resource, a list, or `null` for an empty to-one),
`links` and `ids()`. Without `include=` the members carry only `id`; with it they are fully hydrated in place:

```php
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;

$tag = $client->tags->get('UXxKzq', (new Query())->include('tag-group'));
echo $tag->getRelationship('tag-group')->data->name;

$page = $client->campaigns->list((new Query())->filter(Filter::equals('messages.channel', 'email'))->include('campaign-messages'));
foreach ($page['data'][0]->getRelationship('campaign-messages')->data as $message) {
    echo $message->label;
}
```

An empty to-many relation has `data === []`; a relation returned as links only has `hasData === false`.

### Clearing a value

Request bodies omit `null` and empty-array properties. Where a PATCH needs an explicit `null` or `[]`
to clear a field, use `Explicit`:

```php
use nickdnk\Klaviyo\Resources\Request\UpdateTrackingSetting;
use nickdnk\Klaviyo\Resources\Shared\Explicit;

$accountId = $client->accounts->list()['data'][0]->id;   // tracking settings are keyed by account id

$update = new UpdateTrackingSetting($accountId);
$update->utm_term = Explicit::null();
$update->custom_parameters = Explicit::emptyList();
$client->trackingSettings->update($update);
```

### Errors

| Exception             | When                                                                                                |
|-----------------------|-----------------------------------------------------------------------------------------------------|
| `ClientException`     | 4xx: `getHttpStatus()`, `getErrors()` as `KlaviyoError[]`, `getFirstError()`, `getErrorsWithCode()` |
| `ServerException`     | 5xx after retries                                                                                   |
| `ConnectionException` | transport failure after retries; `getPrevious()` is the PSR-18 exception                            |
| `OAuthException`      | 4xx from the token endpoint; `isInvalidGrant()`                                                     |

```php
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;

$profile = new CreateProfile();
$profile->email = 'jane@example.com';

try {
    $client->profiles->create($profile);
} catch (ClientException $e) {
    $duplicateId = $e->getHttpStatus() === 409 ? $e->getFirstError()?->meta['duplicate_profile_id'] : null;
    foreach ($e->getErrors() as $error) {
        echo $error->pointer, ': ', $error->detail, PHP_EOL;
    }
}
```

### Concurrency

Every service method accepts `returnRequest: true` and hands back the PSR-7 request instead of sending it.
`executePool()` sends a batch concurrently (Guzzle) and returns results in order, with an exception object in place of a
failed entry.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;

$profileIds = ['01HZX4M2K9Q8R7T6V5W4X3Y2Z1', '01HZX4M2K9Q8R7T6V5W4X3Y2Z2'];

$requests = array_map(function (string $id) use ($client) {
    $patch = new PatchProfile($id);
    $patch->properties = ['synced_at' => date(DATE_ATOM)];
    return $client->profiles->update($patch, returnRequest: true);
}, $profileIds);

$results = $client->executePool($requests, concurrency: 5);   // Profile[] in input order, exceptions in place of failures
APIClient::assertNoExceptions($results);
```

`executePoolLazy($items, $toRequest)` builds requests as they are sent, keeping memory flat for large batches.

### Retries and rate limits

429, 503, the CDN's 504/524 and network failures are retried: after `Retry-After` when Klaviyo sends it, otherwise with
exponential backoff (`2s × 2^(attempt−1)`, capped at 60 s, ±50 % jitter), ten attempts in total. Other 4xx and 5xx are
final.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\RetryPolicy;

$client = APIClient::withApiKey('pk_...', retry: new RetryPolicy(maxAttempts: 5, baseDelaySeconds: 1.0, maxDelaySeconds: 20.0));

if ($client->getLastRateLimit()?->isNearlyExhausted(5)) {   // RateLimit-Limit / -Remaining / -Reset headers
    sleep($client->getLastRateLimit()->resetSeconds ?? 1);
}
```

## Transports

With Guzzle installed nothing needs configuring. Otherwise wrap a PSR-18 client; the PSR-17 factories are discovered
from `nyholm/psr7` or `guzzlehttp/psr7` when omitted. PSR-18 has no asynchronous send, so `executePool()` runs
sequentially on that transport.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\Psr18Transport;
use Symfony\Component\HttpClient\Psr18Client;   // symfony/http-client, as one example of a PSR-18 client

$http = new Psr18Client();   // also a PSR-17 factory; pass factories explicitly when discovery should not decide
$client = APIClient::withApiKey('pk_...', Psr18Transport::create($http, requestFactory: $http, streamFactory: $http));
```

`APIClient::setDefaultTransport()` sets a process-wide default; `APIClient::withTransport($transport, $fn)`
scopes one to a callback, which suits tests built on Guzzle's `MockHandler`.

## Webhooks

Webhook endpoints (`$client->webhooks`, `$client->webhookTopics`) are only available to OAuth-app tokens; a private API
key gets 403. Topics are an account-specific resource: Klaviyo's system topics plus one per metric on the account
(`event:api.viewed_product`). The system topics exist as constants on
`WebhookTopic`.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use Psr\Http\Message\ServerRequestInterface;

$secret = bin2hex(random_bytes(32));   // store it; it verifies every delivery
$client->webhooks->create(new CreateWebhook(
    'orders', 'https://example.com/klaviyo/webhook', $secret,
    [WebhookTopic::OPENED_EMAIL, 'event:api.viewed_product'],
));

// In the endpoint handler, with the incoming PSR-7 request:
function handleKlaviyoWebhook(ServerRequestInterface $request, string $secret): int
{
    $webhook = APIClient::parseWebhookRequest($request, $secret);
    if ($webhook === null) {
        return 400;   // bad signature, stale or malformed
    }
    foreach ($webhook->events as ['topic' => $topic, 'payload' => $event]) {
        if ($topic->is(WebhookTopic::OPENED_EMAIL)) { /* $event is a hydrated Event */ }
    }
    return 200;
}
```

`parseWebhookRequest()` checks the `Klaviyo-Signature` HMAC (SHA-256 over body + `Klaviyo-Timestamp`)
and rejects deliveries older than `$tolerance` seconds (300). The age check reads the body's
`meta.timestamp`; the `Klaviyo-Timestamp` header is used only as HMAC input because, as of 2026-09-04, Klaviyo sends it
with a wrong clock.

## Development

```bash
composer install
composer test
```

`tests/fixtures/responses` holds real API responses recorded against a test account and replayed by the tests;
`tests/fixtures/README.md` explains how to refresh them. `scratch/` contains the live smoke-test tooling (not shipped in
the Composer dist).

## Contributing

Issues and pull requests are welcome, in particular for:

- Endpoints, parameters or response attributes that behave differently from what the classes model.
- A newer API revision. Open an issue with the revision you need; a revision bump is a major release and goes through
  the spec diff, the smoke suites and a fixture refresh (see `scratch/README.md`).
- Anything the live run could not verify on the test account (custom objects, push tokens, SMS).

For a PR: keep `composer test` green, add or update a test for the change, and mention the API revision you tested
against. Do not commit credentials or unscrubbed recordings.

## License

MIT. See [LICENSE](LICENSE).
