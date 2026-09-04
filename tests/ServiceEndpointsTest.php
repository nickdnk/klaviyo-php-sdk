<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreatePushToken;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\ProfileMerge;
use nickdnk\Klaviyo\Resources\Request\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\Conversation;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\ImportError;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\PushToken;
use nickdnk\Klaviyo\Resources\Response\Segment;
use nickdnk\Klaviyo\Resources\Response\SubscriptionChannels;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob as ResponseSuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Response\SuppressionDeleteJob as ResponseSuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\DeviceMetadata;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format and hydration checks for the endpoints added to the accounts / events / lists /
 * profiles / webhooks services, plus the push-token and webhook-topic services. Each test
 * pins the path, verb, query and body Klaviyo expects and the class the response hydrates to.
 */
class ServiceEndpointsTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

    }

    private static function path(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getUri()->getPath();

    }

    private static function query(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function body(MockHandler $mock): array
    {

        return json_decode((string)$mock->getLastRequest()->getBody(), true);

    }

    // region Events

    public function testEventToOneRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_profile_for_event.200'),
            Fixtures::response('get_profile_id_for_event.200'),
            Fixtures::response('get_metric_for_event.200'),
            Fixtures::response('get_metric_id_for_event.200'),
        ]);
        $events = self::client($mock)->events;

        $profile = $events->profile('e1', (new Query())->fields('profile', 'email'));
        self::assertSame('/api/events/e1/profile', self::path($mock));
        self::assertSame(['fields' => ['profile' => 'email']], self::query($mock));
        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('01M1PA9MBA7VB258TP74BXMQTG', $profile->id);
        self::assertSame('sdk-smoke+09042132-ev2@nickdnktech.com', $profile->email);
        self::assertInstanceOf(SubscriptionChannels::class, $profile->subscriptions);
        self::assertSame('NEVER_SUBSCRIBED', $profile->subscriptions->email->marketing->consent);
        self::assertFalse($profile->subscriptions->email->marketing->can_receive_email_marketing);
        // The related profile carries its own relationships as links only.
        self::assertFalse($profile->getRelationship('lists')->hasData);

        self::assertSame('01M1PA9MBA7VB258TP74BXMQTG', $events->profileId('e1')->id);
        self::assertSame('/api/events/e1/relationships/profile', self::path($mock));

        $metric = $events->metric('e1');
        self::assertSame('/api/events/e1/metric', self::path($mock));
        self::assertInstanceOf(Metric::class, $metric);
        self::assertSame('SkDfHe', $metric->id);
        self::assertSame('SDK Smoke', $metric->name);

        self::assertSame('SkDfHe', $events->metricId('e1')->id);
        self::assertSame('/api/events/e1/relationships/metric', self::path($mock));

    }

    public function testToOneRelationshipReturnsNullWhenEmptyOr404(): void
    {

        $mock = new MockHandler([
            // Recorded: a profile with no conversation answers 200 with `data: null`.
            Fixtures::response('get_conversation_for_profile.200'),
            new Response(404, [], json_encode(['errors' => [['detail' => 'not found']]])),
        ]);
        $profiles = self::client($mock)->profiles;

        self::assertNull($profiles->conversation('p1'));
        self::assertNull($profiles->conversation('missing'));

    }

    // endregion

    // region Lists

    public function testListTagsAndFlowTriggers(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'tag', 'id' => 't1', 'attributes' => ['name' => 'VIP']]]),
            self::json([['type' => 'tag', 'id' => 't1']]),
            self::json([['type' => 'flow', 'id' => 'f1', 'attributes' => ['name' => 'Welcome', 'status' => 'live', 'trigger_type' => 'Added to List']]]),
            self::json([['type' => 'flow', 'id' => 'f1']]),
        ]);
        $lists = self::client($mock)->lists;

        $tags = $lists->tags('L1', (new Query())->fields('tag', 'name'));
        self::assertSame('/api/lists/L1/tags', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name']], self::query($mock));
        self::assertInstanceOf(Tag::class, $tags['data'][0]);
        self::assertSame('VIP', $tags['data'][0]->name);

        self::assertSame('t1', $lists->tagIds('L1')['data'][0]->id);
        self::assertSame('/api/lists/L1/relationships/tags', self::path($mock));

        $flows = $lists->flowTriggers('L1');
        self::assertSame('/api/lists/L1/flow-triggers', self::path($mock));
        self::assertInstanceOf(Flow::class, $flows['data'][0]);
        self::assertSame('Added to List', $flows['data'][0]->trigger_type);

        self::assertSame('f1', $lists->flowTriggerIds('L1')['data'][0]->id);
        self::assertSame('/api/lists/L1/relationships/flow-triggers', self::path($mock));

    }

    // endregion

    // region Profiles

    public function testProfileToManyRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_lists_for_profile.200'),
            Fixtures::response('get_list_ids_for_profile.200'),
            Fixtures::response('get_segments_for_profile.200'),
            // get_segment_ids_for_profile.200 was recorded empty; kept inline so the id assertion has something to read.
            self::json([['type' => 'segment', 'id' => 's1']]),
            self::json([['type' => 'push-token', 'id' => 'pt1', 'attributes' => ['token' => 'abc', 'platform' => 'ios', 'metadata' => ['os_name' => 'ios', 'app_version' => '3.1']]]]),
            self::json([['type' => 'push-token', 'id' => 'pt1']]),
            self::json([['type' => 'conversation', 'id' => 'c1', 'attributes' => ['channel' => 'sms']]]),
            self::json([['type' => 'conversation', 'id' => 'c1']]),
            self::json(['type' => 'conversation', 'id' => 'c1', 'attributes' => ['channel' => 'sms']]),
            self::json(['type' => 'conversation', 'id' => 'c1']),
        ]);
        $profiles = self::client($mock)->profiles;

        $lists = $profiles->lists('p1');
        self::assertSame('/api/profiles/p1/lists', self::path($mock));
        self::assertInstanceOf(KlaviyoList::class, $lists['data'][0]);
        self::assertSame('YafG4m', $lists['data'][0]->id);
        self::assertSame('sdk-smoke-09047c86-list-renamed', $lists['data'][0]->name);
        self::assertFalse($lists['data'][0]->getRelationship('tags')->hasData);
        self::assertSame('YafG4m', $profiles->listIds('p1')['data'][0]->id);
        self::assertSame('/api/profiles/p1/relationships/lists', self::path($mock));

        $segments = $profiles->segments('p1');
        self::assertSame('/api/profiles/p1/segments', self::path($mock));
        self::assertCount(2, $segments['data']);
        self::assertInstanceOf(Segment::class, $segments['data'][0]);
        self::assertSame('RvKscW', $segments['data'][0]->id);
        self::assertSame('sdk-smoke-0904b052-segment-renamed', $segments['data'][0]->name);
        self::assertSame('sdk-smoke-0904b052-segment2', $segments['data'][1]->name);
        self::assertSame('s1', $profiles->segmentIds('p1')['data'][0]->id);

        $tokens = $profiles->pushTokens('p1');
        self::assertSame('/api/profiles/p1/push-tokens', self::path($mock));
        self::assertInstanceOf(PushToken::class, $tokens['data'][0]);
        self::assertInstanceOf(DeviceMetadata::class, $tokens['data'][0]->metadata);
        self::assertSame('3.1', $tokens['data'][0]->metadata->app_version);
        self::assertSame('pt1', $profiles->pushTokenIds('p1')['data'][0]->id);

        $conversations = $profiles->conversations('p1');
        self::assertSame('/api/profiles/p1/conversations', self::path($mock));
        self::assertInstanceOf(Conversation::class, $conversations['data'][0]);
        self::assertSame('sms', $conversations['data'][0]->channel);
        self::assertSame('c1', $profiles->conversationIds('p1')['data'][0]->id);

        self::assertSame('sms', $profiles->conversation('p1')->channel);
        self::assertSame('/api/profiles/p1/conversation', self::path($mock));
        self::assertSame('c1', $profiles->conversationId('p1')->id);
        self::assertSame('/api/profiles/p1/relationships/conversation', self::path($mock));

    }

    public function testMergeSendsDestinationIdAndSourceRelationship(): void
    {

        $mock = new MockHandler([Fixtures::response('merge_profiles.202')]);

        $result = self::client($mock)->profiles->merge(new ProfileMerge('keep', ['x' => 'dup1', 'y' => 'dup2']));

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/profile-merge', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'profile-merge',
                'relationships' => [
                    'profiles' => ['data' => [['type' => 'profile', 'id' => 'dup1'], ['type' => 'profile', 'id' => 'dup2']]],
                ],
                'id'            => 'keep',
            ],
        ], self::body($mock));
        // Klaviyo answers 202 with the surviving profile as a bare identifier - `attributes` is empty.
        self::assertInstanceOf(Profile::class, $result);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $result->id);
        self::assertNull($result->email);

    }

    public function testSuppressAndUnsuppressJobs(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_suppress_profiles.202'),
            Fixtures::response('bulk_unsuppress_profiles.202'),
        ]);
        $profiles = self::client($mock)->profiles;

        $created = $profiles->suppress(new SuppressionCreateJob(['a@b.test', 'c@d.test'], listId: 'L1'));
        self::assertSame('/api/profile-suppression-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'profile-suppression-bulk-create-job',
                'attributes'    => [
                    'profiles' => ['data' => [
                        ['type' => 'profile', 'attributes' => ['email' => 'a@b.test']],
                        ['type' => 'profile', 'attributes' => ['email' => 'c@d.test']],
                    ]],
                ],
                'relationships' => ['list' => ['data' => ['type' => 'list', 'id' => 'L1']]],
            ],
        ], self::body($mock));
        self::assertInstanceOf(ResponseSuppressionCreateJob::class, $created);
        self::assertSame('01M1PACBYASEKY1KPG9G9M7XW1', $created->id);
        self::assertSame('processing', $created->status);
        self::assertSame(0, $created->total_count);
        self::assertSame(0, $created->skipped_count);
        self::assertFalse($created->getRelationship('lists')->hasData);

        $deleted = $profiles->unsuppress(new SuppressionDeleteJob(segmentId: 's1'));
        self::assertSame('/api/profile-suppression-bulk-delete-jobs', self::path($mock));
        $body = self::body($mock);
        self::assertSame('profile-suppression-bulk-delete-job', $body['data']['type']);
        self::assertArrayNotHasKey('attributes', $body['data'], 'Empty profile list must be omitted, not sent as [].');
        self::assertSame(['segment' => ['data' => ['type' => 'segment', 'id' => 's1']]], $body['data']['relationships']);
        self::assertInstanceOf(ResponseSuppressionDeleteJob::class, $deleted);
        self::assertSame('01M1PAKPRYBBTAT06B79RS1VGJ', $deleted->id);
        self::assertSame('processing', $deleted->status);

    }

    public function testBulkImportJobSubResources(): void
    {

        $mock = new MockHandler([
            // get_errors_for_bulk_import_profiles_job.200 was recorded on a clean job (no errors), so the
            // import-error hydration below stays on a hand-written body.
            self::json([['type' => 'import-error', 'id' => 'e1', 'attributes' => ['code' => 'invalid', 'title' => 'Invalid input.', 'detail' => 'Bad email', 'source' => ['pointer' => '/data/attributes/profiles/data/0/attributes/email'], 'original_payload' => ['email' => 'nope']]]]),
            Fixtures::response('get_list_for_bulk_import_profiles_job.200'),
            Fixtures::response('get_list_ids_for_bulk_import_profiles_job.200'),
            Fixtures::response('get_profiles_for_bulk_import_profiles_job.200'),
            Fixtures::response('get_profile_ids_for_bulk_import_profiles_job.200'),
        ]);
        $profiles = self::client($mock)->profiles;

        $errors = $profiles->getBulkImportJobErrors('j1', (new Query())->pageSize(100));
        self::assertSame('/api/profile-bulk-import-jobs/j1/import-errors', self::path($mock));
        self::assertSame(['page' => ['size' => '100']], self::query($mock));
        self::assertInstanceOf(ImportError::class, $errors['data'][0]);
        self::assertSame('invalid', $errors['data'][0]->code);
        self::assertInstanceOf(AttributeBag::class, $errors['data'][0]->source);
        self::assertSame('/data/attributes/profiles/data/0/attributes/email', $errors['data'][0]->source->pointer);
        self::assertSame('nope', $errors['data'][0]->original_payload->email);

        self::assertSame('sdk-smoke-09047c86-list-renamed', $profiles->getBulkImportJobLists('j1')['data'][0]->name);
        self::assertSame('/api/profile-bulk-import-jobs/j1/lists', self::path($mock));
        self::assertSame('YafG4m', $profiles->getBulkImportJobListIds('j1')['data'][0]->id);
        self::assertSame('/api/profile-bulk-import-jobs/j1/relationships/lists', self::path($mock));

        $imported = $profiles->getBulkImportJobProfiles('j1');
        self::assertSame('sdk-smoke+09047c86-e@nickdnktech.com', $imported['data'][0]->email);
        self::assertSame('USER_SUPPRESSED', $imported['data'][0]->subscriptions->email->marketing->suppression[0]['reason']);
        self::assertSame('/api/profile-bulk-import-jobs/j1/profiles', self::path($mock));

        $importedIds = $profiles->getBulkImportJobProfileIds('j1');
        self::assertCount(3, $importedIds['data']);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $importedIds['data'][0]->id);
        self::assertSame('/api/profile-bulk-import-jobs/j1/relationships/profiles', self::path($mock));

    }

    // endregion

    // region Push tokens

    public function testPushTokenService(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'push-token', 'id' => 'pt1', 'attributes' => ['token' => 'abc', 'platform' => 'ios', 'vendor' => 'apns']]]),
            self::json(['type' => 'push-token', 'id' => 'pt1', 'attributes' => ['token' => 'abc']]),
            new Response(202),
            new Response(204),
            self::json(['type' => 'profile', 'id' => 'p1', 'attributes' => ['email' => 'a@b.test']]),
            self::json(['type' => 'profile', 'id' => 'p1']),
        ]);
        $tokens = self::client($mock)->pushTokens;

        $list = $tokens->list((new Query())->filter(Filter::equals('profile.id', 'p1')));
        self::assertSame('/api/push-tokens', self::path($mock));
        self::assertSame(['filter' => 'equals(profile.id,"p1")'], self::query($mock));
        self::assertInstanceOf(PushToken::class, $list['data'][0]);
        self::assertSame('apns', $list['data'][0]->vendor);

        self::assertSame('abc', $tokens->get('pt1')->token);
        self::assertSame('/api/push-tokens/pt1', self::path($mock));

        $profile = new ImportProfile();
        $profile->external_id = '42';
        $meta = new DeviceMetadata();
        $meta->os_name = 'ios';
        $meta->app_version = '3.1';
        self::assertNull($tokens->create(new CreatePushToken('abc', 'ios', 'AUTHORIZED', 'apns', $profile, 'AVAILABLE', $meta)));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/push-tokens', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'push-token',
                'attributes' => [
                    'token'             => 'abc',
                    'platform'          => 'ios',
                    'enablement_status' => 'AUTHORIZED',
                    'vendor'            => 'apns',
                    'profile'           => ['data' => ['type' => 'profile', 'attributes' => ['external_id' => '42']]],
                    'background'        => 'AVAILABLE',
                    'device_metadata'   => ['os_name' => 'ios', 'app_version' => '3.1'],
                ],
            ],
        ], self::body($mock));

        $tokens->delete('pt1');
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/push-tokens/pt1', self::path($mock));

        self::assertSame('a@b.test', $tokens->profile('pt1')->email);
        self::assertSame('/api/push-tokens/pt1/profile', self::path($mock));
        self::assertSame('p1', $tokens->profileId('pt1')->id);
        self::assertSame('/api/push-tokens/pt1/relationships/profile', self::path($mock));

    }

    // endregion

    // region Webhook topics

    public function testWebhookTopicService(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_webhook_topics.200'),
            Fixtures::response('get_webhook_topic.200', static function (array $body) {
                $body['data']['id'] = 'event:klaviyo.bounced_email';

                return $body;
            }),
        ]);
        $topics = self::client($mock)->webhookTopics;

        $all = $topics->list();
        self::assertSame('/api/webhook-topics', self::path($mock));
        self::assertCount(63, $all['data']);
        self::assertInstanceOf(WebhookTopic::class, $all['data'][0]);
        self::assertSame('event:api.active_on_site', $all['data'][0]->id);
        self::assertContains('event:klaviyo.bounced_email', array_map(static fn(WebhookTopic $t) => $t->id, $all['data']));

        self::assertSame('event:klaviyo.bounced_email', $topics->get('event:klaviyo.bounced_email')->id);
        self::assertSame('/api/webhook-topics/event:klaviyo.bounced_email', self::path($mock));

    }

    // endregion

}
