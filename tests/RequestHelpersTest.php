<?php


namespace nickdnk\Klaviyo\Tests;

use nickdnk\Klaviyo\Resources\Request\BulkImportJob;
use nickdnk\Klaviyo\Resources\Request\CreateTagGroup;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Request\EmailSubscription;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\MarketingConsent;
use nickdnk\Klaviyo\Resources\Request\SubscribeProfile;
use nickdnk\Klaviyo\Resources\Request\SubscriptionChannels;
use nickdnk\Klaviyo\Resources\Request\SubscriptionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SubscriptionDeleteJob;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use PHPUnit\Framework\TestCase;
use nickdnk\Klaviyo\Resources\Request\ProfileObjectSchemaRelationship;
use nickdnk\Klaviyo\Resources\Request\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\UpdateReview;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;

/**
 * Constructor conveniences added after the live smoke run: list relationships on the profile
 * bulk jobs, topics on webhooks, `exclusive` on tag groups.
 */
class RequestHelpersTest extends TestCase
{

    private static function subscriber(): SubscribeProfile
    {

        return new SubscribeProfile(new SubscriptionChannels(email: new EmailSubscription(new MarketingConsent('SUBSCRIBED'))), email: 'a@b.test');

    }

    public function testSubscriptionJobsCarryListRelationship(): void
    {

        $create = json_decode(json_encode(new SubscriptionCreateJob([self::subscriber()], true, 'sdk', 'L1')), true);
        self::assertSame(['data' => ['type' => 'list', 'id' => 'L1']], $create['relationships']['list']);
        self::assertTrue($create['attributes']['historical_import']);
        self::assertSame('sdk', $create['attributes']['custom_source']);

        $fluent = json_decode(json_encode((new SubscriptionCreateJob([self::subscriber()]))->forList('L2')), true);
        self::assertSame('L2', $fluent['relationships']['list']['data']['id']);

        $none = json_decode(json_encode(new SubscriptionCreateJob([self::subscriber()])), true);
        self::assertArrayNotHasKey('relationships', $none);

        $delete = json_decode(json_encode(new SubscriptionDeleteJob([self::subscriber()], 'L3')), true);
        self::assertSame(['data' => ['type' => 'list', 'id' => 'L3']], $delete['relationships']['list']);

    }

    public function testBulkImportJobCarriesListsRelationship(): void
    {

        $p = new ImportProfile();
        $p->email = 'a@b.test';

        $job = json_decode(json_encode(new BulkImportJob([$p], ['L1', 'L2'])), true);
        self::assertSame([['type' => 'list', 'id' => 'L1'], ['type' => 'list', 'id' => 'L2']], $job['relationships']['lists']['data']);

        $fluent = json_decode(json_encode((new BulkImportJob([$p]))->forLists('L9')), true);
        self::assertSame([['type' => 'list', 'id' => 'L9']], $fluent['relationships']['lists']['data']);

    }

    public function testCreateWebhookTopicsAndDescription(): void
    {

        $w = json_decode(json_encode(new CreateWebhook('hook', 'https://example.test/k', 'secret', [WebhookTopic::OPENED_EMAIL, 'event:klaviyo.clicked_email', WebhookTopic::of('event:api.viewed_product')], 'desc')), true);

        self::assertSame('desc', $w['attributes']['description']);
        self::assertSame([
            ['type' => 'webhook-topic', 'id' => 'event:klaviyo.opened_email'],
            ['type' => 'webhook-topic', 'id' => 'event:klaviyo.clicked_email'],
            ['type' => 'webhook-topic', 'id' => 'event:api.viewed_product'],
        ], $w['relationships']['webhook-topics']['data']);

        $u = new \nickdnk\Klaviyo\Resources\Request\UpdateWebhook('w1');
        $u->setTopics([WebhookTopic::BOUNCED_EMAIL]);
        self::assertSame([['type' => 'webhook-topic', 'id' => 'event:klaviyo.bounced_email']], json_decode(json_encode($u), true)['relationships']['webhook-topics']['data']);

        $bare = json_decode(json_encode(new CreateWebhook('hook', 'https://example.test/k', 'secret')), true);
        self::assertArrayNotHasKey('relationships', $bare);
        self::assertArrayNotHasKey('description', $bare['attributes']);

    }

    public function testUpdateMappedMetricUnsetSendsNullData(): void
    {

        $json = json_decode(json_encode(\nickdnk\Klaviyo\Resources\Request\UpdateMappedMetric::unset('revenue')), true);
        self::assertSame('revenue', $json['id']);
        self::assertArrayHasKey('metric', $json['relationships']);
        self::assertNull($json['relationships']['metric']['data']);
        self::assertStringContainsString('"metric":{"data":null}', json_encode(\nickdnk\Klaviyo\Resources\Request\UpdateMappedMetric::unset('revenue')));

    }

    public function testWebhookTopicHelpers(): void
    {

        $t = WebhookTopic::of('event:api.viewed_product');
        self::assertSame('api', $t->integration());
        self::assertSame('viewed_product', $t->slug());
        self::assertFalse($t->isSystemTopic());
        self::assertTrue($t->is('event:api.viewed_product'));
        self::assertTrue($t->is(WebhookTopic::of('event:api.viewed_product')));
        self::assertFalse($t->is(WebhookTopic::OPENED_EMAIL));

        $s = WebhookTopic::of(WebhookTopic::OPENED_EMAIL);
        self::assertSame('klaviyo', $s->integration());
        self::assertSame('opened_email', $s->slug());
        self::assertTrue($s->isSystemTopic());

        $odd = WebhookTopic::of('weird');
        self::assertNull($odd->integration());
        self::assertNull($odd->slug());
        self::assertFalse($odd->isSystemTopic());
        self::assertFalse((new WebhookTopic())->is('event:api.x'), 'no id never matches');

        self::assertSame('webhook-topic', WebhookTopic::type());
        self::assertSame(['type' => 'webhook-topic', 'id' => 'event:api.viewed_product'], $t->jsonSerialize());

    }

    /**
     * subscribe()/unsubscribe() are plain POSTs: a 400 for an unsupported phone region propagates
     * untouched (the SDK no longer strips profiles and retries — that is application policy), and
     * KlaviyoError::indexIn() hands the caller the offending profile indexes.
     */
    public function testSubscribeIsAPlainPostAndRegionErrorsPropagate(): void
    {

        $rejection = json_encode(['errors' => [
            ['id' => 'e1', 'status' => 400, 'code' => 'invalid', 'title' => 'Invalid input.', 'detail' => 'Phone number is valid but is not in a supported region for this account.', 'source' => ['pointer' => '/data/attributes/profiles/data/2/attributes/phone_number']],
            ['id' => 'e2', 'status' => 400, 'code' => 'invalid', 'title' => 'Invalid input.', 'detail' => 'Phone number is valid but is not in a supported region for this account.', 'source' => ['pointer' => '/data/attributes/profiles/data/0/attributes/phone_number']],
        ]]);
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(202, [], ''),
            new \GuzzleHttp\Psr7\Response(400, [], $rejection),
        ]);
        $client = \nickdnk\Klaviyo\APIClient::withApiKey('pk', \nickdnk\Klaviyo\Http\GuzzleTransport::fromHandlerStack(\GuzzleHttp\HandlerStack::create($mock)));
        $job = new SubscriptionCreateJob([self::subscriber()], listId: 'L1');

        self::assertNull($client->profiles->subscribe($job));
        self::assertSame('/api/profile-subscription-bulk-create-jobs', $mock->getLastRequest()->getUri()->getPath());

        try {
            $client->profiles->subscribe($job);
            self::fail('400 must propagate');
        } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) {
            self::assertSame(0, $mock->count(), 'no automatic retry');
            $indexes = array_map(fn($err) => $err->indexIn('/data/attributes/profiles/data'), $e->getErrorsWithCode('invalid'));
            self::assertSame([2, 0], $indexes);
        }

    }

    public function testCreateTagGroupExclusive(): void
    {

        self::assertTrue(json_decode(json_encode(new CreateTagGroup('g', true)), true)['attributes']['exclusive']);
        self::assertArrayNotHasKey('exclusive', json_decode(json_encode(new CreateTagGroup('g')), true)['attributes']);

    }

    public function testMarketingConsentCarriesConsentedAtOnlyWhenGiven(): void
    {

        self::assertSame(['consent' => 'SUBSCRIBED'], (new MarketingConsent('SUBSCRIBED'))->jsonSerialize());
        self::assertSame(['consent' => 'SUBSCRIBED', 'consented_at' => '2026-01-01T00:00:00+00:00'], (new MarketingConsent('SUBSCRIBED', '2026-01-01T00:00:00+00:00'))->jsonSerialize());

    }


    public function testProfileObjectSchemaRelationshipMeta(): void
    {

        $json = (new ProfileObjectSchemaRelationship('profile', 'rel_1', 'owner', 'The owning profile'))->jsonSerialize();
        self::assertSame('profile', $json['id']);
        self::assertSame(['relationship_id' => 'rel_1', 'name' => 'owner', 'description' => 'The owning profile'], $json['meta']);
        self::assertArrayNotHasKey('meta', (new ProfileObjectSchemaRelationship('profile'))->jsonSerialize());

    }


    public function testSuppressionJobsCarryListOrSegmentRelationship(): void
    {

        $create = json_decode(json_encode(new SuppressionCreateJob([], segmentId: 'S1')), true);
        self::assertSame(['data' => ['type' => 'segment', 'id' => 'S1']], $create['relationships']['segment']);
        self::assertArrayNotHasKey('profiles', $create['attributes'] ?? []);

        $delete = json_decode(json_encode(new SuppressionDeleteJob(['a@example.com'], listId: 'L1', segmentId: 'S1')), true);
        self::assertSame('a@example.com', $delete['attributes']['profiles']['data'][0]['attributes']['email']);
        self::assertSame('L1', $delete['relationships']['list']['data']['id']);
        self::assertSame('S1', $delete['relationships']['segment']['data']['id']);
        self::assertSame('profile-suppression-bulk-delete-job', $delete['type']);

    }


    public function testUpdateReviewStatusBody(): void
    {

        self::assertArrayNotHasKey('attributes', (new UpdateReview('r1'))->jsonSerialize(), 'no status → nothing to send');
        $json = (new UpdateReview('r1', 'rejected', 'spam', 'Looks automated'))->jsonSerialize();
        self::assertSame('r1', $json['id']);
        self::assertSame('rejected', $json['attributes']['status']['value']);
        self::assertSame(['reason' => 'spam', 'status_explanation' => 'Looks automated'], $json['attributes']['status']['rejection_reason']);

    }


    public function testCampaignSendStrategies(): void
    {

        self::assertSame(['method' => 'immediate'], CampaignSendStrategy::immediate()->jsonSerialize());
        self::assertSame(['method' => 'smart_send_time', 'date' => '2026-12-24'], CampaignSendStrategy::smartSendTime('2026-12-24')->jsonSerialize());

    }

}
