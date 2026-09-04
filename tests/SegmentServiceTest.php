<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateSegment;
use nickdnk\Klaviyo\Resources\Request\UpdateSegment;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\Segment;
use nickdnk\Klaviyo\Resources\Response\Tag;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format and hydration checks for the segment service: every operation's verb, path,
 * query and body, plus the class each response hydrates to. Segment membership is derived
 * from the condition tree, so the profile endpoints are read-only.
 */
class SegmentServiceTest extends TestCase
{

    /** @var array{condition_groups: list<array{conditions: list<array<string, mixed>>}>} */
    private const array DEFINITION
        = [
            'condition_groups' => [
                [
                    'conditions' => [
                        [
                            'type'              => 'profile-group-membership',
                            'is_member'         => true,
                            'group_ids'         => ['L1'],
                            'timeframe_filter'  => null,
                        ],
                    ],
                ],
            ],
        ];

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

    }

    private static function method(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getMethod();

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

    public function testSegmentCrud(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_segments.200'),
            Fixtures::response('get_segment.200'),
            Fixtures::response('create_segment.201'),
            Fixtures::response('update_segment.200'),
            new Response(204),
        ]);
        $segments = self::client($mock)->segments;

        $list = $segments->list((new Query())->filter(Filter::equals('name', 'Members'))->include('tags')->pageSize(10));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/segments', self::path($mock));
        self::assertSame([
            'filter'  => 'equals(name,"Members")',
            'include' => 'tags',
            'page'    => ['size' => '10'],
        ], self::query($mock));
        self::assertCount(2, $list['data']);
        self::assertInstanceOf(Segment::class, $list['data'][0]);
        self::assertSame('SAx2Kq', $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904b052-segment2', $list['data'][0]->name);
        self::assertTrue($list['data'][0]->is_active);
        self::assertTrue($list['data'][0]->is_processing, 'a fresh segment is still evaluating its condition tree');
        self::assertSame('profile-property', $list['data'][0]->definition['condition_groups'][0]['conditions'][0]['type']);
        self::assertSame([], $list['data'][0]->getRelationship('tags')->data, 'include=tags on an untagged segment is data, not absence');
        self::assertFalse($list['data'][0]->getRelationship('profiles')->hasData, 'the profiles relationship is links-only');

        $segment = $segments->get('s1', (new Query())->additionalFields('segment', 'profile_count'));
        self::assertSame('/api/segments/s1', self::path($mock));
        self::assertSame(['additional-fields' => ['segment' => 'profile_count']], self::query($mock));
        self::assertInstanceOf(Segment::class, $segment);
        self::assertSame('RvKscW', $segment->id);
        self::assertSame(0, $segment->profile_count, 'profile_count is 0 while is_processing is still true');
        self::assertTrue($segment->is_starred);
        self::assertSame(
            ['type' => 'profile-group-membership', 'is_member' => true, 'group_ids' => ['SdpKaX'], 'timeframe_filter' => null],
            $segment->definition['condition_groups'][0]['conditions'][0],
            'Klaviyo echoes timeframe_filter: null back even though the request omitted it',
        );

        $created = $segments->create(new CreateSegment('Lapsed', self::DEFINITION));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/segments', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'segment',
                'attributes' => [
                    'name'       => 'Lapsed',
                    'definition' => [
                        'condition_groups' => [
                            [
                                'conditions' => [
                                    ['type' => 'profile-group-membership', 'is_member' => true, 'group_ids' => ['L1']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock), 'Null condition operands are stripped by the resource serialiser.');
        self::assertInstanceOf(Segment::class, $created);
        self::assertSame('RNftrZ', $created->id);
        self::assertTrue($created->is_active);
        self::assertSame('2026-09-04T13:38:54.426033+00:00', $created->created);

        $update = new UpdateSegment('s1');
        $update->name = 'Active members';
        $update->is_active = true;
        $updated = $segments->update($update);
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/segments/s1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'segment',
                'attributes' => ['name' => 'Active members', 'is_active' => true],
                'id'         => 's1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Segment::class, $updated);
        self::assertSame('sdk-smoke-0904b052-segment-renamed', $updated->name);
        self::assertFalse($updated->is_starred);

        self::assertNull($segments->delete('s1'));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/segments/s1', self::path($mock));

    }

    public function testSegmentRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_profiles_for_segment.200'),
            Fixtures::response('get_profile_ids_for_segment.200'),
            // get_tags_for_segment / get_flows_triggered_by_segment were both recorded empty.
            self::json([['type' => 'tag', 'id' => 't1', 'attributes' => ['name' => 'VIP']]]),
            self::json([['type' => 'tag', 'id' => 't1']]),
            self::json([['type' => 'flow', 'id' => 'f1', 'attributes' => ['name' => 'Welcome', 'trigger_type' => 'Added to Segment']]]),
            self::json([['type' => 'flow', 'id' => 'f1']]),
        ]);
        $segments = self::client($mock)->segments;

        $profiles = $segments->profiles('s1', (new Query())->fields('profile', 'email')->sort('joined_group_at')->pageSize(100));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/segments/s1/profiles', self::path($mock));
        self::assertSame([
            'fields' => ['profile' => 'email'],
            'sort'   => 'joined_group_at',
            'page'   => ['size' => '100'],
        ], self::query($mock));
        self::assertInstanceOf(Profile::class, $profiles['data'][0]);
        self::assertSame('01M1PA9DFJNNYT82A7K2AEG8QT', $profiles['data'][0]->id);
        self::assertSame('sdk-smoke+0904b052-s1@nickdnktech.com', $profiles['data'][0]->email);
        self::assertSame('S1', $profiles['data'][0]->first_name);
        self::assertSame('0904b052', $profiles['data'][0]->properties['sdk_smoke_run']);
        self::assertSame('2026-09-04T13:46:46+00:00', $profiles['data'][0]->joined_group_at, 'membership listings add joined_group_at');
        self::assertNull($profiles['data'][0]->location->city, 'an all-null location still hydrates to ProfileLocation');
        self::assertNull($profiles['links']->next);

        $profileIds = $segments->profileIds('s1', (new Query())->cursor('abc'));
        self::assertSame('/api/segments/s1/relationships/profiles', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'abc']], self::query($mock));
        self::assertInstanceOf(Profile::class, $profileIds['data'][0]);
        self::assertSame('01M1PA9DFJNNYT82A7K2AEG8QT', $profileIds['data'][0]->id);
        self::assertNull($profileIds['data'][0]->email, 'the relationships endpoint returns identifiers only');

        $tags = $segments->tags('s1', (new Query())->fields('tag', 'name'));
        self::assertSame('/api/segments/s1/tags', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name']], self::query($mock));
        self::assertInstanceOf(Tag::class, $tags['data'][0]);
        self::assertSame('VIP', $tags['data'][0]->name);

        $tagIds = $segments->tagIds('s1');
        self::assertSame('/api/segments/s1/relationships/tags', self::path($mock));
        self::assertInstanceOf(Tag::class, $tagIds['data'][0]);
        self::assertSame('t1', $tagIds['data'][0]->id);

        $flows = $segments->flowTriggers('s1', (new Query())->fields('flow', 'name'));
        self::assertSame('/api/segments/s1/flow-triggers', self::path($mock));
        self::assertSame(['fields' => ['flow' => 'name']], self::query($mock));
        self::assertInstanceOf(Flow::class, $flows['data'][0]);
        self::assertSame('Added to Segment', $flows['data'][0]->trigger_type);

        $flowIds = $segments->flowTriggerIds('s1');
        self::assertSame('/api/segments/s1/relationships/flow-triggers', self::path($mock));
        self::assertInstanceOf(Flow::class, $flowIds['data'][0]);
        self::assertSame('f1', $flowIds['data'][0]->id);

    }

    public function testListFollowsNextLinkVerbatim(): void
    {

        $mock = new MockHandler([Fixtures::response('get_segments.200')]);

        $next = 'https://a.klaviyo.com/api/segments?page%5Bcursor%5D=next-page';
        $page = self::client($mock)->segments->list((new Query())->pageSize(10), $next);

        self::assertSame('/api/segments', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'next-page']], self::query($mock), 'A next link carries its own query; $query is ignored.');
        self::assertSame(['SAx2Kq', 'RvKscW'], array_map(fn(Segment $s) => $s->id, $page['data']));
        self::assertNull($page['links']->next, 'this recording was the last page');

    }

}
