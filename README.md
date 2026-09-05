# Klaviyo PHP SDK

[![CI](https://github.com/nickdnk/klaviyo-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/nickdnk/klaviyo-php-sdk/actions/workflows/ci.yml)
[![Coverage](https://coveralls.io/repos/github/nickdnk/klaviyo-php-sdk/badge.svg?branch=main)](https://coveralls.io/github/nickdnk/klaviyo-php-sdk?branch=main)
[![Latest version](https://img.shields.io/packagist/v/nickdnk/klaviyo-php-sdk)](https://packagist.org/packages/nickdnk/klaviyo-php-sdk)
[![Downloads](https://img.shields.io/packagist/dt/nickdnk/klaviyo-php-sdk)](https://packagist.org/packages/nickdnk/klaviyo-php-sdk/stats)
[![PHP](https://img.shields.io/packagist/dependency-v/nickdnk/klaviyo-php-sdk/php)](composer.json)
![GitHub License](https://img.shields.io/github/license/nickdnk/klaviyo-php-sdk)

This is a custom PHP client for the [Klaviyo API](https://developers.klaviyo.com/en/reference/api_overview).

Klaviyo publishes an official, generated package, [`klaviyo/api`](https://github.com/klaviyo/klaviyo-api-php). This one
differs from it in a few ways:

- Every endpoint has a request class and a typed response class. The official package works with associative arrays.
- Relationships and `include`d resources are hydrated into objects.
- OAuth is built in, including automatic token refresh. The official package supports API keys only.
- Webhook deliveries can be verified and parsed. The official package has no webhook support.
- Any PSR-18 HTTP client can be used. Guzzle is optional.
- Retries follow Klaviyo's published rate-limit guidance.
- A request pool sends bulk work concurrently.
- It does not implement the public-key based [Client API](https://developers.klaviyo.com/en/reference/client).

Things to know before choosing it:

- It is not an official Klaviyo project.
- It is pinned to one API revision per major version (see below). New endpoints appear here later than in the official
  package subject to community effort.
- Every endpoint was exercised against a live test account. Features that account could not enable, such as custom
  object types and push tokens, were only checked as far as their error responses.

### PHP Versions support
Requires **PHP 8.3** and is tested against 8.3, 8.4 and 8.5.

```bash
composer require nickdnk/klaviyo-php-sdk
composer require guzzlehttp/guzzle   # optional; any PSR-18 client works
```

## API revision

Klaviyo versions its API with dated revisions and keeps old revisions available for a long time. This package pins one:

- Every request is sent with `revision: 2026-07-15` (`APIClient::API_REVISION`).
- The request and response classes model that revision's
  [OpenAPI document](https://raw.githubusercontent.com/klaviyo/openapi/a6d7b76168b2410d8f2cbb0bf6e01996dae37318/openapi/stable.json).
- The revision only changes with a new major version of this package. The changelog names the new revision.
- Within a major version the revision never changes, no matter when you update.

## Authentication

```php
use nickdnk\Klaviyo\APIClient;

$client = APIClient::withApiKey('pk_...');          // private API key
$client = APIClient::withAccessToken('eyJhbGci...'); // bearer token managed elsewhere, never refreshed
```

### OAuth

`withOAuth()` takes three things:

- the credentials you stored for the account,
- your app's client id and secret,
- a callback that produces new credentials when the current ones stop working.

The callback is the only point where the SDK calls back into your code. It receives the credentials the client holds
and a `TokenExchange` that performs the token request against Klaviyo (it already knows your client id, secret and
transport). It returns the credentials to continue
with. The SDK never calls the token endpoint by itself, so the callback decides whether a refresh happens at all.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\TokenExchange;

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
    refresh: function (OAuthCredentials $current, TokenExchange $exchange): OAuthCredentials {
        // Called on a 401, before the request that triggered it is retried.
        // See lock rotation example below for details.
        return $exchange($current);
    },
);
```

What happens on a 401:

1. The client calls your `refresh` callback with the credentials it currently holds.
2. The callback returns new `OAuthCredentials`, usually by calling `$exchange()` and persisting the result.
3. The original request is retried once with the returned access token.
4. If that retry is also a 401, you get a `ClientException`.

If `$exchange()` fails you get an `OAuthException`. Its `isInvalidGrant()` returns true when the refresh token was
revoked or expired, meaning the user has to connect the account again. Anything the callback throws propagates out of
the API call that triggered it.

Always store the whole `OAuthCredentials` object. The access token changes on every refresh, and the refresh token may
rotate in the future.

You can also refresh ahead of time, for example before a long job. This runs the same callback:

```php
if ($client->getCredentials()->isExpired(graceSeconds: 120)) {
    $client->refreshCredentials();
}
```

#### Several processes sharing one connection

Klaviyo rotates the refresh token, so two processes refreshing the same connection at once leaves one of them with a
dead pair. Because the callback owns the exchange, it can serialise refreshes and reuse what another process already
stored. `$lock` and `$store` below are pseudocode for whatever your application uses (a Redis lock, a database row, ...):

```php
refresh: function (OAuthCredentials $current, TokenExchange $exchange) use ($store, $lock, $accountId): OAuthCredentials {
    return $lock->synchronized("klaviyo-refresh:$accountId", function () use ($current, $exchange, $store, $accountId) {
        $stored = $store->load($accountId);
        if ($stored->accessToken !== $current->accessToken) {
            return $stored;                 // someone else refreshed while we waited for the lock
        }
        $fresh = $exchange($stored);
        $store->save($accountId, $fresh);
        return $fresh;
    });
},
```

Compare access tokens rather than calling `isExpired()`: a 401 can also mean the token was revoked before its expiry,
and a stored pair that differs from the one the client holds is fresh either way.

#### Connecting an account

This is the authorization-code flow with PKCE:

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

#### Disconnecting an account

Call `APIClient::revokeToken($clientId, $clientSecret, $credentials->refreshToken)`. It invalidates the refresh token
and the access token. Then delete the credentials you stored.

## Requests

Each API area is a property on the client, such as `$client->profiles` or `$client->campaigns`. All of them are declared
on `APIClient`, so your IDE lists them.

- Request bodies are objects from `Resources\Request`.
- Responses are hydrated into objects from `Resources\Response`.
- `get()` returns `null` when the resource does not exist.
- `list()` returns `['data' => [...], 'links' => ?PaginationLinks]`.

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

foreach ($client->profiles->iterate($query) as $p) {            // all pages, fetched as you go
    // ...
}
```

The maximum `page[size]` differs per endpoint, and Klaviyo answers 400 when it is exceeded:

- 10 for lists, segments and templates
- 20 for web feeds
- 25 for tag groups
- 50 for tags and flows
- 100 for most other endpoints
- no page size at all for metrics, mapped metrics, object types and bulk-job listings (cursor only)

### Queries and filters

`Query` collects the query parameters of a request. `Filter` builds Klaviyo's filter expressions and takes care of the
quoting, so you never concatenate user input into a filter string yourself.

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

How values are written:

- Strings are double-quoted and escaped.
- Integers, floats and booleans are written as they are.
- `DateTimeInterface` becomes an unquoted ISO 8601 timestamp.
- Arrays become `[...]`.

A `Filter` is `Stringable`. `Query::filter()` also accepts a raw string for expressions the builder does not cover.

Each endpoint accepts its own set of fields and operators, listed in Klaviyo's API reference. Anything else is a 400.
Some examples:

- Lists and segments allow only `equals` and `any` on `name`.
- Campaigns allow only `contains` on `name`.
- `campaigns.list` requires an `equals(messages.channel, ...)` filter.

### Pagination

Klaviyo paginates with cursors. Every `list()` result carries a `links` object:

- `$page['links']->next` is the full URL of the next page, or `null` on the last page.
- `$page['links']->prev` points back, `self` is the current page, `first` and `last` exist for some endpoints.
- `page[size]` sets how many resources one page holds (see the limits above).

The easiest way through all pages is `iterate()`, available on every service that has `list()`. It is a generator
that fetches the next page only when the current one is exhausted, so breaking out of the loop stops the requests:

```php
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;

$query = (new Query())->filter(Filter::equals('email', 'jane@example.com'))->pageSize(50);

foreach ($client->profiles->iterate($query) as $profile) {
    echo $profile->email, PHP_EOL;
}

$all = iterator_to_array($client->profiles->iterate($query));   // everything in one array; fine for small sets
```

To see each page with its links, use `paginate()` with the same `list()` call:

```php
$paginator = $client->paginate(fn(?string $next) => $client->profiles->list($query, $next));
foreach ($paginator->pages() as $page) { /* ['data' => Profile[], 'links' => ?PaginationLinks] */ }
$all = $paginator->all();
```

Relationship listings take a `next:` argument instead, so wrap them with `paginate()`. The callback gets `null` for the
first page and `links->next` after that:

```php
$paginator = $client->paginate(fn(?string $next) => $client->lists->profiles($listId, $query, next: $next));
foreach ($paginator->items() as $profile) {
    echo $profile->email, PHP_EOL;
}
```

To page by hand, pass `links->next` back with `next:`. The URL already contains every query parameter of the original
request, so the `Query` is not needed again:

```php
$page = $client->profiles->list($query);
while ($page['links']?->next !== null) {
    $page = $client->profiles->list(next: $page['links']->next);
}
```

If you keep the cursor somewhere, for example to resume a job later, use `Query::cursor()`. It accepts either the bare
`page[cursor]` value or the full `next` URL and extracts the cursor from it, and it keeps the rest of the `Query` (filter,
fields, page size) so the follow-up request matches the first one:

```php
$cursor = $page['links']->next;                                      // store this
$page   = $client->profiles->list((new Query())->filter(Filter::equals('email', 'jane@example.com'))->pageSize(50)->cursor($cursor));
```

### Relationships and `include`

`getRelationship($name)` returns a `Relationship` object:

- `data` is a single resource, a list of resources, or `null` for an empty to-one relation.
- `links` holds the relationship URLs.
- `ids()` returns the related ids.

Without `include`, the related resources carry only their `id`. With `include`, they arrive fully hydrated in the same
place:

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

Two cases look similar but are not:

- An empty to-many relation has `data === []`.
- A relation Klaviyo returned as links only, without inlining `data`, has `hasData === false`.

### Clearing a value

Request bodies leave out properties that are `null` or an empty array. Some PATCH endpoints need an explicit `null` or
`[]` to clear a field. Use `Explicit` for those:

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

All exceptions extend `nickdnk\Klaviyo\Exceptions\BaseException`.

- `ClientException`: a 4xx answer from the API. `getHttpStatus()` gives the status. `getErrors()` gives the JSON:API
  errors as `KlaviyoError` objects, with `getFirstError()` and `getErrorsWithCode()` as shortcuts.
- `ServerException`: a 5xx answer that survived the retries.
- `ConnectionException`: the transport failed after the retries. `getPrevious()` is the PSR-18 exception.
- `OAuthException`: a 4xx answer from the token endpoint. Check `isInvalidGrant()`.

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

Every service method accepts `returnRequest: true`. It then returns the prepared PSR-7 request instead of sending it.
`executePool()` sends a batch of such requests and returns the results in the same order. A failed entry is an
exception object in that position, not a thrown exception.

Whether the batch actually runs in parallel depends on the transport:

- With Guzzle (the default when it is installed) requests go through Guzzle's pool, up to `concurrency` at a time.
- With any other PSR-18 client (`Psr18Transport`) they run one after another, because PSR-18 has no asynchronous send.
  The result shape and the retry behaviour are the same either way.

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

#### Building requests lazily

Every prepared request carries its own encoded body, and the pool only ever holds `concurrency` of them at a time.
Yield the requests from a generator so they are built as the pool consumes them, instead of building all of them first.

```php
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\BulkImportJob;

/** @var iterable<array{email: string, first_name: string}> $rows  a database cursor, a CSV reader, anything */
$rows = readRowsFromSomewhere();
$listId = 'YafG4m';

$batches = (function () use ($client, $rows, $listId): Generator {

    $chunk = [];

    foreach ($rows as $row) {

        $profile = new ImportProfile();
        $profile->email = $row['email'];
        $profile->first_name = $row['first_name'];
        $chunk[] = $profile;

        if (count($chunk) === 10000) {
            // Yielded, not collected: this request exists only while the pool holds it.
            yield $client->profiles->bulkImport(new BulkImportJob($chunk, [$listId]), returnRequest: true);
            $chunk = [];
        }

    }

    if ($chunk) {
        yield $client->profiles->bulkImport(new BulkImportJob($chunk, [$listId]), returnRequest: true);
    }

})();

$results = $client->executePool($batches, concurrency: 3);
APIClient::assertNoExceptions($results);
```

The body has to be built inside the generator for this to help; a generator over ready-made requests saves nothing.
`executePoolLazy($items, $toRequest)` is the same thing for shorter cases, calling `$toRequest` on each item just
before that request is sent.

```php
$results = $client->executePoolLazy(
    $profileIds,
    function (string $id) use ($client) {
        $patch = new PatchProfile($id);
        $patch->properties = ['synced_at' => date(DATE_ATOM)];
        return $client->profiles->update($patch, returnRequest: true);
    },
    concurrency: 5
);
```

Two things to know when choosing `concurrency`:

- Keep it at or below the endpoint's burst limit. Klaviyo's tiers range from 1 to 350 requests per second; going above
  only produces 429s and retry sleeps. `$client->getLastRateLimit()?->burstLimit()` reports the burst limit of the last
  endpoint called.
- Requests that come back 429/503 or fail on the network are retried in a later round, one at a time, so the retry
  cannot trip the burst limit again. Non-retryable errors stay in their slot as exception objects.

### Retries and rate limits

The client retries these on its own:

- 429 (rate limited) and 503, which is what Klaviyo's guidance asks for
- 504 and 524, gateway timeouts from Klaviyo's CDN
- network failures

When Klaviyo sends a `Retry-After` header, the client waits exactly that long. Otherwise it backs off exponentially
(`2s × 2^(attempt−1)`, capped at 60 s, with ±50 % jitter). Ten attempts in total. Every other 4xx or 5xx is final.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\RetryPolicy;

$client = APIClient::withApiKey('pk_...', retry: new RetryPolicy(maxAttempts: 5, baseDelaySeconds: 1.0, maxDelaySeconds: 20.0));

$limits = $client->getLastRateLimit();          // RateLimit-Limit / -Remaining / -Reset headers of the last response
if ($limits?->isNearlyExhausted(5)) {
    sleep($limits->resetSeconds ?? 1);
}
$limits?->burstLimit();                          // e.g. 75 for /api/profiles, 1 for /api/accounts
$limits?->windows;                               // e.g. [1 => 75, 60 => 750]
```

## Transports

With Guzzle installed, nothing needs configuring. Any other PSR-18 client can be wrapped in a `Psr18Transport`.

- PSR-17 factories are discovered from `nyholm/psr7` or `guzzlehttp/psr7` when you do not pass them.
- PSR-18 has no asynchronous send, so `executePool()` runs one request at a time on that transport.
- The test suite drives the SDK through Symfony's `Psr18Client` as well as Guzzle, and CI runs those tests again with
  Guzzle uninstalled, so swapping the client out is verified rather than assumed.

```php
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\Psr18Transport;
use Symfony\Component\HttpClient\Psr18Client;   // symfony/http-client, as one example of a PSR-18 client

$http = new Psr18Client();   // also a PSR-17 factory; pass factories explicitly when discovery should not decide
$client = APIClient::withApiKey('pk_...', Psr18Transport::create($http, requestFactory: $http, streamFactory: $http));
```

Every constructor and static OAuth call also accepts a transport explicitly; `APIClient::setDefaultTransport()` sets
a process-wide fallback for the ones that don't get one.

## Webhooks

A few things to know first:

- `$client->webhooks` and `$client->webhookTopics` only work with an OAuth token. A private API key gets a 403.
- Topics are an account-specific resource. `webhookTopics->list()` returns Klaviyo's system topics plus one topic per
  metric on the account, for example `event:api.viewed_product`.
- The system topics are available as constants on `WebhookTopic`.

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

What `parseWebhookRequest()` verifies:

- The `Klaviyo-Signature` header must match the HMAC-SHA256 of the body plus the `Klaviyo-Timestamp` header, keyed
  with your secret.
- The delivery must be younger than `$tolerance` seconds (300 by default). This check reads the body's
  `meta.timestamp`, not the header: as of 2026-09-04 Klaviyo sends the `Klaviyo-Timestamp` header with a wrong clock,
  so it is only usable as HMAC input.

## Development

```bash
composer install
composer test
```

- `tests/fixtures/responses` holds real API responses recorded against a test account; `RecordedResponsesTest` replays them
  (`tests/fixtures/README.md`).
- `scratch/` is the live smoke-test tooling that produces those recordings (`scratch/README.md`), plus Klaviyo behaviour
  that is not in any docblock (`scratch/KLAVIYO_NOTES.md`). Not part of the Composer dist.

## Contributing

Issues and pull requests are welcome, in particular for:

- Endpoints, parameters or response attributes that behave differently from what the classes model.
- A newer API revision. Open an issue with the revision you need; a revision bump is a major release and goes through
  the spec diff, the smoke suites and a fixture refresh (see `scratch/README.md`).
- Anything the live run could not verify on the test account (custom objects, push tokens, SMS).

For a pull request:

- Keep `composer test` green.
- Add or update a test for the change.
- Mention the API revision you tested against.
- Do not commit credentials or unscrubbed recordings.

## License

MIT. See [LICENSE](LICENSE).
