<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEvents;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEventsJob;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\ProfileMetaPatch;
use nickdnk\Klaviyo\Resources\Request\SubscriptionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SubscriptionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\Account;
use nickdnk\Klaviyo\Resources\Response\BulkImportJob;
use nickdnk\Klaviyo\Resources\Response\ContactInformation;
use nickdnk\Klaviyo\Resources\Response\Event as ResponseEvent;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList as ResponseKlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\Profile as ResponseProfile;
use nickdnk\Klaviyo\Resources\Response\StreetAddress;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Response\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Event;
use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\Shared\ProfileLocation;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\RelationshipLinks;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use PHPUnit\Framework\TestCase;

class KlaviyoResourceTest extends TestCase
{

    public function testProfileSerializesWithTypeAndAttributes(): void
    {

        $profile = new CreateProfile();
        $profile->email = 'test@example.com';
        $profile->first_name = 'John';

        $json = $profile->jsonSerialize();

        self::assertSame('profile', $json['type']);
        self::assertSame('test@example.com', $json['attributes']['email']);
        self::assertSame('John', $json['attributes']['first_name']);
        self::assertArrayNotHasKey('id', $json);

    }

    public function testProfileSerializesWithIdWhenSet(): void
    {

        $profile = new Profile('abc123');
        $profile->email = 'test@example.com';

        $json = $profile->jsonSerialize();

        self::assertSame('abc123', $json['id']);
        self::assertSame('profile', $json['type']);
        self::assertSame('test@example.com', $json['attributes']['email']);

    }

    public function testNullAttributesAreStripped(): void
    {

        $profile = new CreateProfile();
        $profile->email = 'test@example.com';
        $profile->first_name = null;
        $profile->last_name = null;

        $json = $profile->jsonSerialize();

        self::assertArrayHasKey('email', $json['attributes']);
        self::assertArrayNotHasKey('first_name', $json['attributes']);
        self::assertArrayNotHasKey('last_name', $json['attributes']);

    }

    public function testNestedNullsInArrayAreStripped(): void
    {

        $profile = new CreateProfile();
        $profile->email = 'test@example.com';
        $profile->properties = [
            'birthday' => '1990-01-15',
            'gender'   => null,
        ];

        $json = $profile->jsonSerialize();

        self::assertSame(['birthday' => '1990-01-15'], $json['attributes']['properties']);

    }

    public function testFullyNullArrayIsOmitted(): void
    {

        $profile = new CreateProfile();
        $profile->email = 'test@example.com';
        $profile->properties = [
            'birthday' => null,
            'gender'   => null,
        ];

        $json = $profile->jsonSerialize();

        self::assertArrayNotHasKey('properties', $json['attributes']);

    }

    public function testNestedResourceSerializesFlat(): void
    {

        $location = new ProfileLocation();
        $location->city = 'Copenhagen';
        $location->country = 'DK';

        $json = $location->jsonSerialize();

        // Nested resources (no type) serialize flat — no type/attributes wrapper
        self::assertSame('Copenhagen', $json['city']);
        self::assertSame('DK', $json['country']);
        self::assertArrayNotHasKey('type', $json);
        self::assertArrayNotHasKey('attributes', $json);

    }

    public function testListSerializesWithType(): void
    {

        $list = new KlaviyoList('XyZ123');
        $list->name = 'Newsletter';

        $json = $list->jsonSerialize();

        self::assertSame('list', $json['type']);
        self::assertSame('XyZ123', $json['id']);
        self::assertSame('Newsletter', $json['attributes']['name']);

    }

    public function testHydrateCollectionResponse(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('get_lists.200'));

        self::assertCount(2, $result['data']);

        self::assertInstanceOf(ResponseKlaviyoList::class, $result['data'][0]);
        self::assertSame('TjmPN9', $result['data'][0]->id);
        self::assertSame('sdk-smoke-09047c86-list2', $result['data'][0]->name);
        self::assertSame('double_opt_in', $result['data'][0]->opt_in_process);

        self::assertInstanceOf(ResponseKlaviyoList::class, $result['data'][1]);
        self::assertSame('YafG4m', $result['data'][1]->id);
        self::assertSame('sdk-smoke-09047c86-list', $result['data'][1]->name);
        self::assertSame('single_opt_in', $result['data'][1]->opt_in_process);

        // `include=tags` matched nothing: `tags` came back with an empty `data` (not links-only),
        // while the relationships that were not included carry links only.
        $tags = $result['data'][0]->getRelationship('tags');
        self::assertTrue($tags->hasData);
        self::assertSame([], $tags->data);
        self::assertFalse($result['data'][0]->getRelationship('profiles')->hasData);

        self::assertInstanceOf(PaginationLinks::class, $result['links']);
        self::assertNull($result['links']->next);
        self::assertStringContainsString('page%5Bcursor%5D=', $result['links']->prev);

    }

    public function testHydrateSingleResourceResponse(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('create_list.201'));

        self::assertInstanceOf(ResponseKlaviyoList::class, $result['data']);
        self::assertSame('VAnKig', $result['data']->id);
        self::assertSame('sdk-smoke-09048514-l', $result['data']->name);
        self::assertSame('single_opt_in', $result['data']->opt_in_process);

    }

    public function testHydrateWebhookResponse(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('get_webhooks.200'));

        $webhook = $result['data'][0];
        self::assertInstanceOf(Webhook::class, $webhook);
        self::assertSame('01M1PAC5N9PMXJ7672AC8B75VN', $webhook->id);
        self::assertSame('sdk-smoke-09045284-hook', $webhook->name);
        self::assertTrue($webhook->enabled);
        // Recorded with fields[webhook]=name,enabled, so the rest of the attributes are absent.
        self::assertNull($webhook->endpoint_url);
        self::assertNull($webhook->created_at);
        self::assertNull($webhook->updated_at);

        $relationship = $webhook->getRelationship('webhook-topics');
        self::assertNotNull($relationship);
        self::assertIsArray($relationship->data);
        self::assertCount(3, $relationship->data);
        self::assertContainsOnlyInstancesOf(WebhookTopic::class, $relationship->data);
        self::assertSame(
            [
                'event:klaviyo.manually_suppressed_from_email_marketing',
                'event:klaviyo.manually_unsuppressed_from_email_marketing',
                'event:klaviyo.subscribed_to_email_marketing',
            ],
            array_map(fn(WebhookTopic $t) => $t->id, $relationship->data)
        );

    }

    public function testHydrateBulkImportJobResponse(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('get_bulk_import_profiles_job.200'));

        $job = $result['data'];
        self::assertInstanceOf(BulkImportJob::class, $job);
        self::assertSame('V0JoWEhOX21haW50ZW5hbmNlLlBIV0tSRC4xNzg4NTI5MjAxLkVHZEk1Tw', $job->id);
        self::assertSame('queued', $job->status);
        self::assertSame(3, $job->total_count);
        self::assertSame(0, $job->completed_count);
        self::assertSame(0, $job->failed_count);

        // `include=lists` returned the list under `included`; the SDK splices it into the relationship.
        $lists = $job->getRelationship('lists');
        self::assertCount(1, $lists->data);
        self::assertInstanceOf(ResponseKlaviyoList::class, $lists->data[0]);
        self::assertSame('YafG4m', $lists->data[0]->id);
        self::assertSame('sdk-smoke-09047c86-list-renamed', $lists->data[0]->name);

    }

    public function testHydrateAccountResponse(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('get_accounts.200'));

        $account = $result['data'][0];
        self::assertInstanceOf(Account::class, $account);
        self::assertSame('WBhXHN', $account->id);
        self::assertTrue($account->test_account);
        self::assertSame('Europe/Copenhagen', $account->timezone);
        self::assertSame('DKK', $account->preferred_currency);
        self::assertSame('en-US', $account->locale);
        self::assertSame('Software / SaaS', $account->industry);

        self::assertInstanceOf(ContactInformation::class, $account->contact_information);
        self::assertSame('Example Org', $account->contact_information->default_sender_name);
        self::assertSame('customer@example.com', $account->contact_information->default_sender_email);
        self::assertSame('Example Org', $account->contact_information->organization_name);
        self::assertSame('http://www.example.com', $account->contact_information->website_url);

        self::assertInstanceOf(StreetAddress::class, $account->contact_information->street_address);
        self::assertSame('Artillerivej 86', $account->contact_information->street_address->address1);
        self::assertSame('Copenhagen', $account->contact_information->street_address->city);
        // Klaviyo returns the country name, not an ISO code.
        self::assertSame('Denmark', $account->contact_information->street_address->country);
        self::assertSame('2300', $account->contact_information->street_address->zip);
        self::assertNull($account->contact_information->street_address->region);

    }

    public function testHydratePaginationLinksAllFields(): void
    {

        $raw = [
            'data'  => [],
            'links' => [
                'self'  => 'https://a.klaviyo.com/api/lists',
                'first' => 'https://a.klaviyo.com/api/lists?page[cursor]=first',
                'last'  => 'https://a.klaviyo.com/api/lists?page[cursor]=last',
                'prev'  => 'https://a.klaviyo.com/api/lists?page[cursor]=prev',
                'next'  => 'https://a.klaviyo.com/api/lists?page[cursor]=next',
            ],
        ];

        $result = APIClient::hydrateResponse($raw);

        $links = $result['links'];
        self::assertInstanceOf(PaginationLinks::class, $links);
        self::assertSame('https://a.klaviyo.com/api/lists', $links->self);
        self::assertSame('https://a.klaviyo.com/api/lists?page[cursor]=first', $links->first);
        self::assertSame('https://a.klaviyo.com/api/lists?page[cursor]=last', $links->last);
        self::assertSame('https://a.klaviyo.com/api/lists?page[cursor]=prev', $links->prev);
        self::assertSame('https://a.klaviyo.com/api/lists?page[cursor]=next', $links->next);

    }

    public function testHydrateWithNoLinks(): void
    {

        $raw = [
            'data' => [
                'type'       => 'list',
                'id'         => 'list_1',
                'attributes' => ['name' => 'Test'],
            ],
        ];

        $result = APIClient::hydrateResponse($raw);

        self::assertArrayHasKey('links', $result);
        self::assertNull($result['links']);

    }

    public function testHydrateAutoResolvesFromType(): void
    {

        $result = APIClient::hydrateResponse(Fixtures::body('get_profiles.200'));

        self::assertInstanceOf(ResponseProfile::class, $result['data'][0]);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $result['data'][0]->id);
        self::assertSame('sdk-smoke+09047c86-a@nickdnktech.com', $result['data'][0]->email);
        self::assertSame('sdk-smoke-09047c86-ext-a', $result['data'][0]->external_id);

        self::assertInstanceOf(PaginationLinks::class, $result['links']);

    }

    public function testHydrateSkipsUnknownType(): void
    {

        $raw = [
            'data' => [
                ['type' => 'unknown-thing', 'id' => 'x1', 'attributes' => ['foo' => 'bar']],
            ],
        ];

        $result = APIClient::hydrateResponse($raw);

        self::assertIsArray($result['data'][0]);
        self::assertSame('x1', $result['data'][0]['id']);

    }

    public function testFromSetsIdAndAttributes(): void
    {

        $profile = ResponseProfile::from(['email' => 'test@test.com', 'phone_number' => '+4512345678'], 'prof_1');

        self::assertSame('prof_1', $profile->id);
        self::assertSame('test@test.com', $profile->email);
        self::assertSame('+4512345678', $profile->phone_number);

    }

    public function testFromWithoutId(): void
    {

        $list = ResponseKlaviyoList::from(['name' => 'Test List']);

        self::assertNull($list->id);
        self::assertSame('Test List', $list->name);

    }

    public function testNestedListHydratesElementWise(): void
    {

        $profile = APIClient::hydrateResponse(Fixtures::body('get_profile.200'))['data'];
        self::assertInstanceOf(ResponseProfile::class, $profile);
        // `location` is declared nested, so it hydrates to its own class rather than staying an array.
        self::assertInstanceOf(ProfileLocation::class, $profile->location);
        self::assertSame('Copenhagen', $profile->location->city);
        self::assertSame(55.66, $profile->location->latitude);
        // `properties` is a free-form attribute and stays a plain array.
        self::assertSame(['plan' => 'pro', 'tags' => ['x', 'y'], 'score' => 7], $profile->properties);

        $bag = new class extends \nickdnk\Klaviyo\Resources\Shared\Resource {
            protected static function nested(): array
            {

                return ['rows' => AttributeBag::class, 'one' => AttributeBag::class];
            }
        };
        $hydrated = $bag::from(['rows' => [['a' => 1], ['a' => 2], 'scalar'], 'one' => ['b' => 3], 'plain' => []]);

        self::assertCount(3, $hydrated->rows);
        self::assertInstanceOf(AttributeBag::class, $hydrated->rows[0]);
        self::assertSame(2, $hydrated->rows[1]->a);
        self::assertSame('scalar', $hydrated->rows[2]);
        self::assertInstanceOf(AttributeBag::class, $hydrated->one);
        self::assertSame(3, $hydrated->one->b);
        self::assertSame([], $hydrated->plain);

    }

    public function testHydratedResourceKeepsMeta(): void
    {

        $raw = [
            'data' => [
                ['type' => 'profile', 'id' => 'p1', 'meta' => ['relationship_id' => 'r1', 'count' => 2]],
                ['type' => 'profile', 'id' => 'p2'],
            ],
        ];

        $result = APIClient::hydrateResponse($raw);

        self::assertSame(['relationship_id' => 'r1', 'count' => 2], $result['data'][0]->getMeta());
        self::assertSame([], $result['data'][1]->getMeta());
        self::assertSame(
            ['type' => 'profile', 'id' => 'p1', 'meta' => ['relationship_id' => 'r1', 'count' => 2]],
            json_decode(json_encode($result['data'][0]), true),
            'Meta round-trips through serialization after attributes / relationships.'
        );

    }

    public function testMetaSerializesAsSiblingOfAttributes(): void
    {

        $profile = new ImportProfile();
        $profile->email = 'test@example.com';
        $profile->addMeta('patch_properties', new ProfileMetaPatch(
            append: ['tags' => 'vip'],
            unset: 'old_field',
        ));

        $json = $profile->jsonSerialize();

        self::assertSame('profile', $json['type']);
        self::assertSame('test@example.com', $json['attributes']['email']);
        self::assertArrayHasKey('meta', $json);
        self::assertSame(['tags' => 'vip'], $json['meta']['patch_properties']['append']);
        self::assertSame('old_field', $json['meta']['patch_properties']['unset']);
        self::assertArrayNotHasKey('unappend', $json['meta']['patch_properties']);

    }

    public function testParseWebhookRequest(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:50:12.101159+00:00',
                'klaviyo_webhook_id' => '01KP4928STE8FKCWB6WDHZ4ZB7',
                'klaviyo_account_id' => 'WBhXHN',
                'version'            => '2024-06-28',
            ],
            'data' => [
                [
                    'topic'       => 'event:klaviyo.unsubscribed_from_email_marketing',
                    'external_id' => '73WCCK7JKtE',
                    'payload'     => [
                        'data' => [
                            'id'         => '73WCCK7JKtE',
                            'type'       => 'event',
                            'attributes' => [
                                'timestamp'        => 1776118606,
                                'event_properties' => [
                                    'email_address' => 'foo@bar.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $hmacTimestamp = 'Tue, 14 Apr 2026 03:50:12 GMT';
        $secret = 'mysecret';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        $result = APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T22:50:30+00:00'));

        self::assertNotNull($result);
        self::assertSame('01KP4928STE8FKCWB6WDHZ4ZB7', $result->webhookId);
        self::assertSame('2026-04-13T22:50:12.101159+00:00', $result->timestamp);
        self::assertSame('2024-06-28', $result->apiVersion);

        self::assertCount(1, $result->events);
        self::assertInstanceOf(WebhookTopic::class, $result->events[0]['topic']);
        self::assertTrue($result->events[0]['topic']->is(WebhookTopic::UNSUBSCRIBED_FROM_EMAIL_MARKETING));
        self::assertInstanceOf(ResponseEvent::class, $result->events[0]['payload']);
        self::assertSame('73WCCK7JKtE', $result->events[0]['payload']->id);
        self::assertSame('foo@bar.com', $result->events[0]['payload']->event_properties['email_address']);

    }

    public function testParseWebhookRequestHydratesEventWithRelationships(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:49:36.261080+00:00',
                'klaviyo_webhook_id' => '01KP4EGG9B0AEF4VJD749M6H9D',
                'klaviyo_account_id' => 'WBhXHN',
                'version'            => '2024-06-28',
            ],
            'data' => [
                [
                    'topic'       => 'event:klaviyo.unsubscribed_from_email_marketing',
                    'external_id' => '73WCCK7JKtE',
                    'payload'     => [
                        'data' => [
                            'id'         => '73WCCK7JKtE',
                            'type'       => 'event',
                            'links'      => [
                                'self' => 'https://a.klaviyo.com/api/events/73WCCK7JKtE/',
                            ],
                            'attributes' => [
                                'uuid'             => '75669b00-3786-11f1-8001-4e2db0d1c5b6',
                                'datetime'         => '2026-04-13T22:16:46+00:00',
                                'timestamp'        => 1776118606,
                                'event_properties' => [
                                    'method'        => 'PROFILE_PAGE',
                                    '$event_id'     => '1776118606',
                                    'email_address' => 'email@example.com',
                                    'method_detail' => 'foo@bar.com',
                                ],
                            ],
                            'relationships' => [
                                'metric'  => [
                                    'data'  => ['id' => 'SHi67A', 'type' => 'metric'],
                                    'links' => [
                                        'self'    => 'https://a.klaviyo.com/api/events/73WCCK7JKtE/relationships/metric/',
                                        'related' => 'https://a.klaviyo.com/api/events/73WCCK7JKtE/metric/',
                                    ],
                                ],
                                'profile' => [
                                    'data'  => ['id' => '01KP4EGHH8QJ3N3AY2Z6V7RMDK', 'type' => 'profile'],
                                    'links' => [
                                        'self'    => 'https://a.klaviyo.com/api/events/73WCCK7JKtE/relationships/profile/',
                                        'related' => 'https://a.klaviyo.com/api/events/73WCCK7JKtE/profile/',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $hmacTimestamp = '2026-04-13T22:49:36+00:00';
        $secret = 'mysecret';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        $result = APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T22:50:00+00:00'));

        self::assertNotNull($result);
        self::assertCount(1, $result->events);

        $event = $result->events[0]['payload'];
        self::assertInstanceOf(ResponseEvent::class, $event);
        self::assertSame('73WCCK7JKtE', $event->id);
        self::assertSame('75669b00-3786-11f1-8001-4e2db0d1c5b6', $event->uuid);
        self::assertSame('2026-04-13T22:16:46+00:00', $event->datetime);
        self::assertSame(1776118606, $event->timestamp);
        self::assertSame('email@example.com', $event->event_properties['email_address']);

        $relationships = $event->getRelationships();

        self::assertInstanceOf(Relationship::class, $relationships['metric']);
        self::assertInstanceOf(Metric::class, $relationships['metric']->data);
        self::assertSame('SHi67A', $relationships['metric']->data->id);
        self::assertInstanceOf(RelationshipLinks::class, $relationships['metric']->links);
        self::assertSame('https://a.klaviyo.com/api/events/73WCCK7JKtE/relationships/metric/', $relationships['metric']->links->self);
        self::assertSame('https://a.klaviyo.com/api/events/73WCCK7JKtE/metric/', $relationships['metric']->links->related);

        self::assertInstanceOf(Relationship::class, $relationships['profile']);
        self::assertInstanceOf(ResponseProfile::class, $relationships['profile']->data);
        self::assertSame('01KP4EGHH8QJ3N3AY2Z6V7RMDK', $relationships['profile']->data->id);
        self::assertInstanceOf(RelationshipLinks::class, $relationships['profile']->links);
        self::assertSame('https://a.klaviyo.com/api/events/73WCCK7JKtE/relationships/profile/', $relationships['profile']->links->self);
        self::assertSame('https://a.klaviyo.com/api/events/73WCCK7JKtE/profile/', $relationships['profile']->links->related);

    }

    public function testEventPropertiesReturnsNullForMissingKey(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:49:36.261080+00:00',
                'klaviyo_webhook_id' => '01KP4EGG9B0AEF4VJD749M6H9D',
                'klaviyo_account_id' => 'WBhXHN',
                'version'            => '2024-06-28',
            ],
            'data' => [
                [
                    'topic'       => 'event:klaviyo.unsubscribed_from_email_marketing',
                    'external_id' => '73WCCK7JKtE',
                    'payload'     => [
                        'data' => [
                            'id'         => '73WCCK7JKtE',
                            'type'       => 'event',
                            'attributes' => [
                                'timestamp'        => 1776118606,
                                'event_properties' => [
                                    'email_address' => 'foo@bar.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $hmacTimestamp = '2026-04-13T22:49:36+00:00';
        $secret = 'mysecret';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        $result = APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T22:50:00+00:00'));

        self::assertNotNull($result);
        $event = $result->events[0]['payload'];
        self::assertInstanceOf(AttributeBag::class, $event->event_properties);

        // Array-style access on missing key — must return null without raising "Undefined array key".
        self::assertNull($event->event_properties['custom_method_detail']);
        // Property-style access on missing key — same contract.
        self::assertNull($event->event_properties->custom_method_detail);
        // Existing key still resolves.
        self::assertSame('foo@bar.com', $event->event_properties['email_address']);

    }

    public function testParseWebhookRequestRejectsWithoutSignature(): void
    {

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [], '{}');

        self::assertNull(APIClient::parseWebhookRequest($request, 'secret'));

    }

    public function testParseWebhookRequestRejectsMalformedBody(): void
    {

        $hmacTimestamp = '2026-04-13T22:49:36+00:00';
        $secret = 'mysecret';
        $body = 'not json';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        self::assertNull(APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T22:50:00+00:00')));

    }

    public function testParseWebhookRequestRejectsBadSignature(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:49:36+00:00',
                'klaviyo_webhook_id' => 'wh_test',
                'version'            => '2024-06-28',
            ],
            'data' => [],
        ]);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => 'wrong',
            'Klaviyo-Timestamp' => '2026-04-13T22:49:36+00:00',
        ], $body);

        self::assertNull(APIClient::parseWebhookRequest($request, 'mysecret', now: strtotime('2026-04-13T22:50:00+00:00')));

    }

    public function testParseWebhookRequestRejectsStaleTimestamp(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:49:36+00:00',
                'klaviyo_webhook_id' => 'wh_test',
                'version'            => '2024-06-28',
            ],
            'data' => [],
        ]);

        $hmacTimestamp = '2026-04-13T22:49:36+00:00';
        $secret = 'mysecret';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        self::assertNull(APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T23:00:00+00:00')));

    }

    public function testParseWebhookRequestKeepsUnknownTopics(): void
    {

        $body = json_encode([
            'meta' => [
                'timestamp'          => '2026-04-13T22:49:36+00:00',
                'klaviyo_webhook_id' => 'wh_test',
                'klaviyo_account_id' => 'ABC123',
                'version'            => '2024-06-28',
            ],
            'data' => [
                ['topic' => 'event:klaviyo.unknown_topic', 'payload' => ['data' => []]],
                ['topic' => 'event:klaviyo.unsubscribed_from_email_marketing', 'payload' => ['data' => ['type' => 'event', 'id' => 'evt_1', 'attributes' => ['datetime' => '2026-04-13T22:49:00+00:00']]]],
            ],
        ]);

        $hmacTimestamp = '2026-04-13T22:49:36+00:00';
        $secret = 'mysecret';
        $signature = hash_hmac('sha256', $body . $hmacTimestamp, $secret);

        $request = new ServerRequest('POST', '/webhooks/klaviyo', [
            'Klaviyo-Signature' => $signature,
            'Klaviyo-Timestamp' => $hmacTimestamp,
        ], $body);

        $result = APIClient::parseWebhookRequest($request, $secret, now: strtotime('2026-04-13T22:50:00+00:00'));

        self::assertNotNull($result);
        self::assertCount(2, $result->events, 'unknown topics are kept: every metric is a topic');
        self::assertInstanceOf(WebhookTopic::class, $result->events[0]['topic']);
        self::assertSame('event:klaviyo.unknown_topic', $result->events[0]['topic']->id, 'unfamiliar topics hydrate like any other');
        self::assertTrue($result->events[1]['topic']->is(WebhookTopic::UNSUBSCRIBED_FROM_EMAIL_MARKETING));
        self::assertSame('event:klaviyo.unsubscribed_from_email_marketing', $result->events[1]['topic']->id);
        self::assertCount(1, $result->eventsFor('event:klaviyo.unknown_topic'));
        self::assertCount(1, $result->eventsFor(WebhookTopic::UNSUBSCRIBED_FROM_EMAIL_MARKETING));
        self::assertCount(1, $result->eventsFor(WebhookTopic::of(WebhookTopic::UNSUBSCRIBED_FROM_EMAIL_MARKETING)));
        self::assertSame('evt_1', $result->events[1]['payload']->id);

    }

    public function testTypes(): void
    {

        self::assertSame('profile', Profile::type());
        self::assertSame('list', KlaviyoList::type());
        self::assertSame('event', Event::type());
        self::assertSame('metric', \nickdnk\Klaviyo\Resources\Shared\Metric::type());
        self::assertSame('webhook-topic', WebhookTopic::type());
        self::assertSame('webhook', \nickdnk\Klaviyo\Resources\Shared\Webhook::type());
        self::assertSame('account', Account::type());
        self::assertSame('profile-bulk-import-job', BulkImportJob::type());
        self::assertSame('event-bulk-create', BulkCreateEvents::type());
        self::assertSame('event-bulk-create-job', BulkCreateEventsJob::type());
        self::assertSame('profile-subscription-bulk-create-job', SubscriptionCreateJob::type());
        self::assertSame('profile-subscription-bulk-delete-job', SubscriptionDeleteJob::type());
        self::assertSame('data-privacy-deletion-job', DataPrivacyDeletionJob::type());
        self::assertSame('profile-suppression-bulk-create-job', SuppressionCreateJob::type());
        self::assertSame('profile-suppression-bulk-delete-job', SuppressionDeleteJob::type());

    }

}
