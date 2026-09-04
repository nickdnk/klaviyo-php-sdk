<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateConversationMessage;
use nickdnk\Klaviyo\Resources\Request\CreateWebFeed;
use nickdnk\Klaviyo\Resources\Request\UpdateReview;
use nickdnk\Klaviyo\Resources\Request\UpdateTrackingSetting;
use nickdnk\Klaviyo\Resources\Request\UpdateWebFeed;
use nickdnk\Klaviyo\Resources\Response\Review;
use nickdnk\Klaviyo\Resources\Response\TrackingSetting;
use nickdnk\Klaviyo\Resources\Response\WebFeed;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for the account-scoped settings and content services: review moderation, the
 * single UTM tracking setting keyed by account id, web feeds, and the send-only
 * conversation-message endpoint that answers 202 with an empty body.
 */
class SettingsAndContentServicesTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

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

    // region Reviews

    public function testReviewListGetUpdate(): void
    {

        $mock = new MockHandler([
            self::json([
                ['type' => 'review', 'id' => 'r1', 'attributes' => [
                    'email'       => 'a@b.test',
                    'rating'      => 5,
                    'verified'    => true,
                    'review_type' => 'review',
                    'status'      => ['value' => 'published'],
                    'product'     => ['name' => 'VIP table', 'external_id' => 'tbl-1'],
                ]],
                ['type' => 'review', 'id' => 'r2', 'attributes' => ['rating' => 2, 'status' => ['value' => 'pending']]],
            ]),
            self::json(['type' => 'review', 'id' => 'r1', 'attributes' => [
                'title'        => 'Great night',
                'content'      => 'Loved it',
                'author'       => 'Ada',
                'public_reply' => ['content' => 'Thanks!', 'author' => 'Acme', 'updated' => '2026-03-04T05:06:07+00:00'],
            ]]),
            self::json(['type' => 'review', 'id' => 'r1', 'attributes' => ['status' => ['value' => 'rejected']]]),
        ]);
        $reviews = self::client($mock)->reviews;

        $list = $reviews->list(
            (new Query())->fields('review', 'rating', 'status')->include('event')->filter(Filter::equals('rating', 5))->sort('created', true)->pageSize(10)
        );
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/reviews', self::path($mock));
        self::assertSame([
            'fields'  => ['review' => 'rating,status'],
            'filter'  => 'equals(rating,5)',
            'include' => 'event',
            'sort'    => '-created',
            'page'    => ['size' => '10'],
        ], self::query($mock));
        self::assertCount(2, $list['data']);
        self::assertInstanceOf(Review::class, $list['data'][0]);
        self::assertSame(5, $list['data'][0]->rating);
        self::assertInstanceOf(AttributeBag::class, $list['data'][0]->status);
        self::assertSame('published', $list['data'][0]->status->value);
        self::assertInstanceOf(AttributeBag::class, $list['data'][0]->product);
        self::assertSame('VIP table', $list['data'][0]->product->name);

        $review = $reviews->get('r1', (new Query())->include('event'));
        self::assertSame('/api/reviews/r1', self::path($mock));
        self::assertSame(['include' => 'event'], self::query($mock));
        self::assertInstanceOf(Review::class, $review);
        self::assertSame('Great night', $review->title);
        self::assertInstanceOf(AttributeBag::class, $review->public_reply);
        self::assertSame('Thanks!', $review->public_reply->content);

        $updated = $reviews->update(
            new UpdateReview('r1', 'rejected', 'inappropriate', 'Contains a competitor link'),
            (new Query())->fields('review', 'status')
        );
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/reviews/r1', self::path($mock));
        self::assertSame(['fields' => ['review' => 'status']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'review',
                'attributes' => [
                    'status' => [
                        'value'            => 'rejected',
                        'rejection_reason' => [
                            'reason'             => 'inappropriate',
                            'status_explanation' => 'Contains a competitor link',
                        ],
                    ],
                ],
                'id'         => 'r1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Review::class, $updated);
        self::assertSame('rejected', $updated->status->value);

    }

    public function testReviewUpdateOmitsRejectionReasonForNonRejectedStatus(): void
    {

        $mock = new MockHandler([self::json(['type' => 'review', 'id' => 'r2', 'attributes' => ['status' => ['value' => 'featured']]])]);

        self::client($mock)->reviews->update(new UpdateReview('r2', 'featured'));

        self::assertSame([
            'data' => [
                'type'       => 'review',
                'attributes' => ['status' => ['value' => 'featured']],
                'id'         => 'r2',
            ],
        ], self::body($mock));

    }

    // endregion

    // region Tracking settings

    public function testTrackingSettingListGetUpdate(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_tracking_settings.200'),
            Fixtures::response('get_tracking_setting.200'),
            Fixtures::response('update_tracking_setting.200'),
        ]);
        $settings = self::client($mock)->trackingSettings;

        $list = $settings->list((new Query())->fields('tracking-setting', 'utm_source')->pageSize(1));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/tracking-settings', self::path($mock));
        self::assertSame([
            'fields' => ['tracking-setting' => 'utm_source'],
            'page'   => ['size' => '1'],
        ], self::query($mock));
        self::assertCount(1, $list['data']);
        self::assertInstanceOf(TrackingSetting::class, $list['data'][0]);
        self::assertSame('WBhXHN', $list['data'][0]->id);
        self::assertFalse($list['data'][0]->auto_add_parameters);
        self::assertInstanceOf(AttributeBag::class, $list['data'][0]->utm_source);
        self::assertSame(['type' => 'static', 'value' => 'Klaviyo'], $list['data'][0]->utm_source->flow);
        self::assertInstanceOf(AttributeBag::class, $list['data'][0]->utm_medium);
        self::assertSame('message_type', $list['data'][0]->utm_medium->campaign['value']);
        self::assertNull($list['data'][0]->utm_campaign);
        self::assertSame([], $list['data'][0]->custom_parameters);

        $setting = $settings->get('acct1', (new Query())->fields('tracking-setting', 'utm_medium'));
        self::assertSame('/api/tracking-settings/acct1', self::path($mock));
        self::assertSame(['fields' => ['tracking-setting' => 'utm_medium']], self::query($mock));
        self::assertInstanceOf(TrackingSetting::class, $setting);
        self::assertSame('WBhXHN', $setting->id);
        self::assertFalse($setting->auto_add_parameters);

        $update = new UpdateTrackingSetting('acct1');
        $update->auto_add_parameters = false;
        $update->utm_campaign = [
            'flow'     => ['type' => 'dynamic', 'value' => 'flow_name'],
            'campaign' => ['type' => 'static', 'value' => 'winter'],
        ];
        $update->custom_parameters = [
            ['name' => 'venue', 'flow' => ['type' => 'static', 'value' => 'acme'], 'campaign' => ['type' => 'static', 'value' => 'acme']],
        ];
        $updated = $settings->update($update, (new Query())->fields('tracking-setting', 'auto_add_parameters'));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/tracking-settings/acct1', self::path($mock));
        self::assertSame(['fields' => ['tracking-setting' => 'auto_add_parameters']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'tracking-setting',
                'attributes' => [
                    'auto_add_parameters' => false,
                    'utm_campaign'        => [
                        'flow'     => ['type' => 'dynamic', 'value' => 'flow_name'],
                        'campaign' => ['type' => 'static', 'value' => 'winter'],
                    ],
                    'custom_parameters'   => [
                        ['name' => 'venue', 'flow' => ['type' => 'static', 'value' => 'acme'], 'campaign' => ['type' => 'static', 'value' => 'acme']],
                    ],
                ],
                'id'         => 'acct1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(TrackingSetting::class, $updated);
        self::assertFalse($updated->auto_add_parameters);

    }

    // endregion

    // region Web feeds

    public function testWebFeedListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_web_feeds.200'),
            Fixtures::response('get_web_feed.200'),
            Fixtures::response('create_web_feed.201'),
            Fixtures::response('update_web_feed.200'),
            Fixtures::response('delete_web_feed.204'),
        ]);
        $feeds = self::client($mock)->webFeeds;

        $list = $feeds->list((new Query())->fields('web-feed', 'name', 'status')->filter(Filter::equals('status', 'ok'))->sort('name')->pageSize(25));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/web-feeds', self::path($mock));
        self::assertSame([
            'fields' => ['web-feed' => 'name,status'],
            'filter' => 'equals(status,"ok")',
            'sort'   => 'name',
            'page'   => ['size' => '25'],
        ], self::query($mock));
        self::assertCount(1, $list['data']);
        self::assertInstanceOf(WebFeed::class, $list['data'][0]);
        self::assertSame('9055661', $list['data'][0]->id);
        self::assertSame('sdk_smoke_0904f206_feed_json', $list['data'][0]->name);
        self::assertSame('https://help.klaviyo.com/api/v2/help_center/en-us/articles.json', $list['data'][0]->url);
        self::assertSame('get', $list['data'][0]->request_method);
        self::assertSame('json', $list['data'][0]->content_type);
        self::assertSame('ok', $list['data'][0]->status);
        self::assertNotNull($list['links']?->next);
        self::assertStringContainsString('page%5Bcursor%5D=', $list['links']->next);
        self::assertNull($list['links']->prev);

        $feed = $feeds->get('wf1', (new Query())->fields('web-feed', 'status'));
        self::assertSame('/api/web-feeds/wf1', self::path($mock));
        self::assertSame(['fields' => ['web-feed' => 'status']], self::query($mock));
        self::assertInstanceOf(WebFeed::class, $feed);
        self::assertSame('9055661', $feed->id);
        self::assertSame('sdk_smoke_0904f206_feed_json', $feed->name);

        $created = $feeds->create(
            new CreateWebFeed('Guestlist', 'https://acme.test/guestlist.xml', 'post', 'xml'),
            (new Query())->fields('web-feed', 'name')
        );
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/web-feeds', self::path($mock));
        self::assertSame(['fields' => ['web-feed' => 'name']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'web-feed',
                'attributes' => [
                    'name'           => 'Guestlist',
                    'url'            => 'https://acme.test/guestlist.xml',
                    'request_method' => 'post',
                    'content_type'   => 'xml',
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(WebFeed::class, $created);
        self::assertSame('9055663', $created->id);
        self::assertSame('post', $created->request_method);
        self::assertNull($created->status);

        $update = new UpdateWebFeed('wf1');
        $update->url = 'https://acme.test/v2/events.json';
        $update->content_type = 'xml';
        $updated = $feeds->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/web-feeds/wf1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'web-feed',
                'attributes' => [
                    'url'          => 'https://acme.test/v2/events.json',
                    'content_type' => 'xml',
                ],
                'id'         => 'wf1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(WebFeed::class, $updated);
        self::assertSame('9055661', $updated->id);
        self::assertSame('sdk_smoke_0904f206_feed_json_final', $updated->name);
        self::assertSame('https://postman-echo.com/post', $updated->url);

        self::assertNull($feeds->delete('wf1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/web-feeds/wf1', self::path($mock));

    }

    public function testWebFeedCreateDefaultsToGetJson(): void
    {

        $mock = new MockHandler([self::json(['type' => 'web-feed', 'id' => 'wf4', 'attributes' => ['name' => 'Specials']], 201)]);

        self::client($mock)->webFeeds->create(new CreateWebFeed('Specials', 'https://acme.test/specials.json'));

        self::assertSame([
            'name'           => 'Specials',
            'url'            => 'https://acme.test/specials.json',
            'request_method' => 'get',
            'content_type'   => 'json',
        ], self::body($mock)['data']['attributes']);

    }

    // endregion

    // region Conversation messages

    public function testConversationMessageCreateReturnsNullOn202(): void
    {

        $mock = new MockHandler([new Response(202)]);

        $result = self::client($mock)->conversationMessages->create(new CreateConversationMessage('Doors open at 23:00', 'c1'));

        self::assertNull($result);
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/conversation-messages', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertSame([
            'data' => [
                'type'          => 'conversation-message',
                'attributes'    => ['body' => 'Doors open at 23:00'],
                'relationships' => ['conversation' => ['data' => ['type' => 'conversation', 'id' => 'c1']]],
            ],
        ], self::body($mock));

    }

    public function testConversationMessageCreateSendsMessageHierarchy(): void
    {

        $mock = new MockHandler([new Response(202)]);

        $hierarchy = [
            [
                'message'        => 'Doors open at 23:00',
                'message_format' => 'RCS',
                'rich_content'   => ['suggestions' => [['text' => 'Get tickets']]],
            ],
            [
                'message'        => 'Doors open at 23:00',
                'message_format' => 'SMS',
            ],
        ];

        self::client($mock)->conversationMessages->create(
            new CreateConversationMessage('Doors open at 23:00', 'c1', $hierarchy)
        );

        self::assertSame([
            'body'              => 'Doors open at 23:00',
            'message_hierarchy' => $hierarchy,
        ], self::body($mock)['data']['attributes']);

    }

    // endregion

}
