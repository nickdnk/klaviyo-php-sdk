<?php
/**
 * Runs with the OAuth client (harness picks it from the `oauth_` prefix). Covers the OAuth-only
 * webhook endpoints end to end — including a live delivery through ngrok and HMAC verification with
 * APIClient::parseWebhookRequest() — plus the token lifecycle: explicit refresh, and the automatic
 * refresh on 401 with the credentials handed back through the callback.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\UpdateWebhook;
use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use nickdnk\Klaviyo\Resources\Shared\Explicit;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Webhooks\WebhookRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Smoke\Harness;

return function (Harness $h): void {

    $h->assert($h->oauth, 'suite must run with the OAuth client');

    // ── OAuth lifecycle
    $h->step('oauth', 'getCredentials', 'client carries OAuth credentials with scopes', fn(APIClient $c) => $c->getCredentials(),
        fn($r) => $h->assert($r instanceof OAuthCredentials && count($r->scopes()) >= 40 && !$r->isExpired(), 'credentials w/ scopes, not expired'));
    $before = $h->client->getCredentials();
    $h->step('oauth', 'refreshCredentials', 'explicit refresh rotates both tokens and fires the persistence callback', fn(APIClient $c) => $c->refreshCredentials(),
        function ($r) use ($h, $before) {
            $h->assert($r instanceof OAuthCredentials && $r->accessToken !== $before->accessToken && $r->expiresAt >= $before->expiresAt && $h->client->getCredentials() === $r, 'new access token adopted');
            $h->note('Refresh token ' . ($r->refreshToken === $before->refreshToken ? 'NOT rotated (same value re-issued)' : 'rotated') . ' on refresh.');
        });
    $h->step('oauth', 'withOAuth', 'automatic refresh on 401: bogus access token + valid refresh token → request succeeds after one refresh', function (APIClient $c) use ($h) {
        $valid = $c->getCredentials();
        $c->setCredentials(new OAuthCredentials('bogus-access-token', $valid->refreshToken, $valid->expiresAt, $valid->scope));
        $acc = $c->accounts->list()['data'][0];
        $h->assert($acc->id === 'WBhXHN', 'call succeeded');
        $h->assert($c->getCredentials()->accessToken !== 'bogus-access-token' && $c->getCredentials()->accessToken !== $valid->accessToken, 'client swapped to a freshly issued access token');
        return $c->getCredentials();
    });

    // ── webhook topics
    $topics = $h->step('webhookTopics', 'list', 'list webhook topics w/ fields', fn(APIClient $c) => $c->webhookTopics->list((new Query())->fields('webhook-topic', 'id')),
        fn($r) => $h->assert(count($r['data']) >= 20 && $r['data'][0] instanceof WebhookTopic && is_string($r['data'][0]->id) && str_starts_with($r['data'][0]->id, 'event:'), 'topics hydrate (ids are open-ended: system topics + one per metric)'));
    $unknownTopics = array_values(array_filter(array_map(fn($t) => $t->id, $topics['data'] ?? []), fn($id) => !WebhookTopic::of($id)->isSystemTopic()));
    if ($unknownTopics) {
        $h->note('Non-system topics offered by the account (one per metric): ' . implode(', ', $unknownTopics));
    }
    $h->step('webhookTopics', 'get', 'get topic by id', fn(APIClient $c) => $c->webhookTopics->get(WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING),
        fn($r) => $h->assert($r instanceof WebhookTopic && $r->id === WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING, 'topic (id=' . ($r?->id ?? 'null') . ')'));
    $h->step('webhookTopics', 'get', 'unknown topic → null', fn(APIClient $c) => $c->webhookTopics->get('event:klaviyo.nope'), fn($r) => $h->assert($r === null, 'null'));

    // ── webhook CRUD
    $secret = bin2hex(random_bytes(16));
    $endpoint = $h->webhookUrl();
    /** @var Webhook|null $hook */
    $hook = $h->step('webhooks', 'create', 'create webhook (name, endpoint=ngrok sink, secret, description, 3 topics) + fields', fn(APIClient $c) => $c->webhooks->create(new CreateWebhook(
        $h->name('hook'), $endpoint, $secret,
        [WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING, WebhookTopic::MANUALLY_UNSUPPRESSED_FROM_EMAIL_MARKETING, 'event:klaviyo.subscribed_to_email_marketing'],
        'sdk smoke ' . $h->runId,
    ), (new Query())->fields('webhook', 'name', 'endpoint_url', 'enabled', 'description', 'created_at')),
        function ($r) use ($h, $endpoint) {
            $h->assert($r instanceof Webhook && $r->id && $r->name === $h->name('hook') && $r->enabled === true && $r->description === 'sdk smoke ' . $h->runId, 'hydrated webhook');
            $h->assert(str_starts_with($r->endpoint_url, dirname($endpoint) . '/') || str_starts_with($r->endpoint_url, parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST)), 'endpoint_url echoed (Klaviyo masks the path as /*****)');
            $h->note('Klaviyo masks endpoint_url in responses: ' . $r->endpoint_url);
        });
    if (!$hook) {
        $h->note('Webhook creation failed; the remaining webhook steps depend on it.');
        return;
    }
    $h->cleanup('webhooks', 'delete', 'webhook', fn(APIClient $c) => $c->webhooks->delete($hook->id));

    $h->step('webhooks', 'get', 'get webhook w/ fields + include(webhook-topics)', fn(APIClient $c) => $c->webhooks->get($hook->id, (new Query())->fields('webhook', 'name', 'enabled', 'endpoint_url')->fields('webhook-topic', 'id')->include('webhook-topics')),
        function ($r) use ($h, $hook) {
            $h->assert($r instanceof Webhook && $r->id === $hook->id, 'webhook');
            $rel = $r->getRelationship('webhook-topics');
            $h->assert($rel !== null && count($rel->data) === 3, '3 topics linked');
            $h->assert(in_array(WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING, $rel->ids(), true), 'topic ids');
        });
    $h->step('webhooks', 'get', 'unknown webhook → null', fn(APIClient $c) => $c->webhooks->get('999999999'), fn($r) => $h->assert($r === null, 'null'));
    $h->step('webhooks', 'list', 'list webhooks w/ fields + include(webhook-topics)', fn(APIClient $c) => $c->webhooks->list((new Query())->fields('webhook', 'name', 'enabled')->include('webhook-topics')),
        fn($r) => $h->assert(in_array($hook->id, array_map(fn($w) => $w->id, $r['data']), true), 'ours listed'));
    $h->step('webhooks', 'update', 'update name + clear description (Explicit::null) + replace topics with 2', function (APIClient $c) use ($h, $hook) {
        $u = new UpdateWebhook($hook->id);
        $u->name = $h->name('hook-renamed');
        $u->description = Explicit::null();
        $u->setTopics([WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING, WebhookTopic::MANUALLY_UNSUPPRESSED_FROM_EMAIL_MARKETING]);
        return $c->webhooks->update($u, (new Query())->fields('webhook', 'name', 'description', 'enabled'));
    }, function ($r) use ($h) {
        $h->assert($r instanceof Webhook && $r->name === $h->name('hook-renamed'), 'renamed');
        $h->note('PATCH /api/webhooks with "description": null was sent but the description stayed "' . $r->description . '" — the API ignores null here (cannot clear via API).');
    });
    $h->step('webhooks', 'get', 'topics replaced (2 linked)', fn(APIClient $c) => $c->webhooks->get($hook->id, (new Query())->include('webhook-topics')),
        fn($r) => $h->assert(count($r->getRelationship('webhook-topics')->data) === 2, '2 topics'));
    $h->step('webhooks', 'update', 'disable then re-enable', function (APIClient $c) use ($h, $hook) {
        $u = new UpdateWebhook($hook->id);
        $u->enabled = false;
        $off = $c->webhooks->update($u);
        $h->assert($off->enabled === false, 'disabled');
        $u2 = new UpdateWebhook($hook->id);
        $u2->enabled = true;
        return $c->webhooks->update($u2);
    }, fn($r) => $h->assert($r->enabled === true, 're-enabled'));

    // ── live delivery: suppress/unsuppress our own profile → two webhooks to the ngrok sink
    $email = $h->email('hook');
    $profile = $h->step('profiles', 'create', 'create profile to trigger webhooks', function (APIClient $c) use ($email) {
        $p = new CreateProfile();
        $p->email = $email;
        return $c->profiles->create($p);
    });
    if ($profile) {
        $h->cleanup('profiles', 'deleteProfile', 'data-privacy deletion of webhook profile', fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($profile->id))));
        $h->mutation("Data-privacy deletion requested for {$email}.");
    }
    $logFile = __DIR__ . '/../webhooks/logs/webhooks.jsonl';
    $seenLines = count(file($logFile) ?: []);
    $deliveries = function () use ($logFile, $endpoint, &$seenLines): array {
        $path = parse_url($endpoint, PHP_URL_PATH);
        $out = [];
        foreach (array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], $seenLines) as $line) {
            $j = json_decode($line, true);
            if (($j['method'] ?? '') === 'POST' && ($j['uri'] ?? '') === $path) {
                $out[] = $j;
            }
        }
        return $out;
    };
    $toServerRequest = function (array $j): \Psr\Http\Message\ServerRequestInterface {
        $f = new Psr17Factory();
        return $f->createServerRequest('POST', 'https://sink.test' . $j['uri'])
            ->withHeader('Klaviyo-Signature', $j['klaviyo_signature'])
            ->withHeader('Klaviyo-Timestamp', $j['klaviyo_timestamp'])
            ->withHeader('Content-Type', $j['content_type'])
            ->withBody($f->createStream($j['body']));
    };

    $h->step('profiles', 'suppress', 'suppress profile globally (fires manually_suppressed_from_email_marketing)', fn(APIClient $c) => $c->profiles->suppress(new SuppressionCreateJob([$email])));
    $matching = function (string $topic) use ($deliveries, $toServerRequest, $secret, $profile): ?array {
        // The webhook is account-wide; other suites running in parallel trigger deliveries too. Pick the one for our profile.
        foreach ($deliveries() as $j) {
            $parsed = APIClient::parseWebhookRequest($toServerRequest($j), $secret);
            foreach ($parsed?->eventsFor($topic) ?? [] as $e) {
                if (($e['payload'] instanceof Event ? $e['payload']->getRelationship('profile')?->ids() : null) === [$profile?->id]) {
                    return $j;
                }
            }
        }
        return null;
    };
    $first = $h->step('webhooks', 'parseWebhookRequest', 'live webhook arrives at ngrok sink (poll ≤4 min) and verifies with the secret', fn() => $h->waitFor(fn() => $matching(WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING), 240, 10, 'webhook delivery for our profile'), function ($j) use ($h, $secret, $toServerRequest, $profile) {
        $h->note('Webhook delivery headers: signature=' . substr($j['klaviyo_signature'], 0, 12) . '… timestamp=' . $j['klaviyo_timestamp'] . ' user-agent=' . $j['user_agent'] . ' bytes=' . strlen($j['body']));
        $parsed = APIClient::parseWebhookRequest($toServerRequest($j), $secret);
        $h->assert($parsed instanceof WebhookRequest, 'signature verified and body parsed (' . ($parsed ? 'ok' : 'parseWebhookRequest returned null') . ')');
        $h->assert($parsed->webhookId !== '' && $parsed->apiVersion !== '', 'meta present: id=' . $parsed->webhookId . ' version=' . $parsed->apiVersion);
        $h->assert(count($parsed->events) >= 1, 'at least one event');
        $ours = array_values(array_filter($parsed->eventsFor(WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING), fn($e) => $e['payload'] instanceof Event && $e['payload']->getRelationship('profile')?->ids() === [$profile?->id]));
        $h->assert(count($ours) === 1, 'exactly one suppressed event for our profile in this delivery (' . count($parsed->events) . ' events total, topics: ' . implode(',', array_unique(array_map(fn($e) => $e['topic']->id, $parsed->events))) . ')');
        $payload = $ours[0]['payload'];
        $h->assert($ours[0]['topic'] instanceof WebhookTopic && $ours[0]['topic']->is(WebhookTopic::MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING) && $ours[0]['topic']->isSystemTopic(), 'topic hydrated as WebhookTopic');
        $h->assert($payload instanceof Event && $payload->id !== null && $payload->datetime !== null, 'payload hydrated as Event');
        $h->assert($payload->getRelationship('metric')?->ids() !== [], 'event carries metric relationship');
        $h->assert(APIClient::parseWebhookRequest($toServerRequest($j), 'wrong-secret') === null, 'wrong secret → null');
        $stale = $j;
        $h->assert(APIClient::parseWebhookRequest($toServerRequest($stale), $secret, tolerance: 300, now: time() + 3600) === null, 'stale timestamp → null');
    });
    if ($first) {
        $seenLines = count(file($logFile) ?: []);
        $h->step('profiles', 'unsuppress', 'unsuppress profile (fires manually_unsuppressed_from_email_marketing)', fn(APIClient $c) => $c->profiles->unsuppress(new SuppressionDeleteJob([$email])));
        $h->step('webhooks', 'parseWebhookRequest', 'second live webhook (unsuppressed) verified', fn() => $h->waitFor(fn() => $matching(WebhookTopic::MANUALLY_UNSUPPRESSED_FROM_EMAIL_MARKETING), 240, 10, 'second webhook delivery for our profile'), function ($j) use ($h, $secret, $toServerRequest) {
            $parsed = APIClient::parseWebhookRequest($toServerRequest($j), $secret);
            $h->assert($parsed instanceof WebhookRequest && count($parsed->eventsFor(WebhookTopic::MANUALLY_UNSUPPRESSED_FROM_EMAIL_MARKETING)) >= 1, 'unsuppressed event present');
        });
    }
};
