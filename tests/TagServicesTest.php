<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateTag;
use nickdnk\Klaviyo\Resources\Request\CreateTagGroup;
use nickdnk\Klaviyo\Resources\Request\UpdateTag;
use nickdnk\Klaviyo\Resources\Request\UpdateTagGroup;
use nickdnk\Klaviyo\Resources\Response\Campaign as ResponseCampaign;
use nickdnk\Klaviyo\Resources\Response\Flow as ResponseFlow;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList as ResponseKlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Segment as ResponseSegment;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\TagGroup;
use nickdnk\Klaviyo\Resources\Shared\Campaign;
use nickdnk\Klaviyo\Resources\Shared\Flow;
use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Segment;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format and hydration checks for the tag and tag-group services: every operation's
 * verb, path, query and body, plus the class each response hydrates to. Tagging is the
 * JSON:API relationship trio on `tags/{id}/relationships/{relation}`, so the tag / untag
 * pairs are pinned per related type.
 *
 * Responses come from the recorded fixtures in tests/fixtures/responses wherever one exists for
 * the operation, so the hydration assertions read back real Klaviyo payloads; the `FIXTURE_*`
 * ids below are the ids that corpus was recorded with.
 */
class TagServicesTest extends TestCase
{

    private const string FIXTURE_TAG_SHARED    = 'f837de98-3527-4bae-8226-bda7c2395e29';
    private const string FIXTURE_TAG_SHARED_2  = 'b3200a12-69f6-437c-8c7a-e8ac6838429c';
    private const string FIXTURE_TAG_DEFAULT   = '3153c2aa-8f96-40bb-be8f-6d3573129b95';
    private const string FIXTURE_TAG_CREATED   = 'fe22c973-0e88-4ae3-8229-6700b2c0f43f';
    private const string FIXTURE_GROUP_SHARED  = '34c01a4a-208c-4428-a80b-4d0b4e0741db';
    private const string FIXTURE_GROUP_EXCL    = 'c8aa0a0d-bad4-457f-b709-cf62fdeb9b09';
    private const string FIXTURE_GROUP_DEFAULT = 'e30baa69-f382-4eef-8ff6-222d5c3d9a8a';

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

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

    // region Tags

    public function testTagCrud(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_tags.200'),
            Fixtures::response('get_tag.200'),
            Fixtures::response('create_tag.201'),
            Fixtures::response('update_tag.204'),
            Fixtures::response('delete_tag.204'),
        ]);
        $tags = self::client($mock)->tags;

        $list = $tags->list((new Query())->filter(Filter::equals('name', 'VIP'))->sort('name')->pageSize(50));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/tags', self::path($mock));
        self::assertSame(['filter' => 'equals(name,"VIP")', 'sort' => 'name', 'page' => ['size' => '50']], self::query($mock));
        self::assertCount(5, $list['data']);
        self::assertInstanceOf(Tag::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_TAG_SHARED_2, $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904b975-t-shared-2', $list['data'][0]->name);
        // `include=tag-group` splices the three included groups into the tags that reference them.
        $group = $list['data'][0]->getRelationship('tag-group')->data;
        self::assertInstanceOf(TagGroup::class, $group);
        self::assertSame(self::FIXTURE_GROUP_SHARED, $group->id);
        self::assertSame('sdk-smoke-0904b975-tg-shared-renamed', $group->name);
        self::assertFalse($group->exclusive);
        self::assertSame(self::FIXTURE_GROUP_DEFAULT, $list['data'][4]->getRelationship('tag-group')->data->id);
        self::assertTrue($list['data'][4]->getRelationship('tag-group')->data->default);
        // The taggable relationships themselves are never inlined, only linked.
        self::assertFalse($list['data'][0]->getRelationship('campaigns')->hasData);

        $tag = $tags->get('t1', (new Query())->fields('tag', 'name')->include('tag-group'));
        self::assertSame('/api/tags/t1', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name'], 'include' => 'tag-group'], self::query($mock));
        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame(self::FIXTURE_TAG_SHARED, $tag->id);
        self::assertSame('sdk-smoke-0904b975-t-shared', $tag->name);
        self::assertSame('sdk-smoke-0904b975-tg-shared-renamed', $tag->getRelationship('tag-group')->data->name);

        $created = $tags->create(new CreateTag('Guestlist', 'tg1'));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tags', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'tag',
                'attributes'    => ['name' => 'Guestlist'],
                'relationships' => ['tag-group' => ['data' => ['type' => 'tag-group', 'id' => 'tg1']]],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Tag::class, $created);
        self::assertSame(self::FIXTURE_TAG_CREATED, $created->id);
        self::assertSame('sdk-smoke-09048514-tag', $created->name);
        // Klaviyo always answers with the tag's group attached, here the account's default one.
        self::assertSame(self::FIXTURE_GROUP_DEFAULT, $created->getRelationship('tag-group')->data->id);

        $update = new UpdateTag('t1');
        $update->name = 'Very important';
        self::assertNull($tags->update($update));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/tags/t1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'tag',
                'attributes' => ['name' => 'Very important'],
                'id'         => 't1',
            ],
        ], self::body($mock));

        self::assertNull($tags->delete('t1'));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tags/t1', self::path($mock));

    }

    public function testCreateTagWithoutTagGroupOmitsRelationship(): void
    {

        $mock = new MockHandler([self::json(['type' => 'tag', 'id' => 't3', 'attributes' => ['name' => 'Staff']], 201)]);

        self::client($mock)->tags->create(new CreateTag('Staff'));

        self::assertSame(['data' => ['type' => 'tag', 'attributes' => ['name' => 'Staff']]], self::body($mock));

    }

    public function testTagRelationshipIdReads(): void
    {

        // The smoke tag these were recorded against had nothing tagged, so every recorded body is
        // `data: []`; each identifier the class-mapping assertions need is put back in, keeping the
        // recorded status, headers and `links`. There is no recorded body for `flows` at all.
        $identifier = static fn(string $type, string $id) => static fn(array $b) => ['data' => [['type' => $type, 'id' => $id]]] + $b;
        $mock = new MockHandler([
            Fixtures::response('get_campaign_ids_for_tag.200', $identifier('campaign', 'c1')),
            self::json([['type' => 'flow', 'id' => 'f1']]),
            Fixtures::response('get_list_ids_for_tag.200', $identifier('list', 'L1')),
            Fixtures::response('get_segment_ids_for_tag.200', $identifier('segment', 's1')),
        ]);
        $tags = self::client($mock)->tags;

        $campaigns = $tags->campaignIds('t1');
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/campaigns', self::path($mock));
        self::assertInstanceOf(ResponseCampaign::class, $campaigns['data'][0]);
        self::assertSame('c1', $campaigns['data'][0]->id);

        $flows = $tags->flowIds('t1');
        self::assertSame('/api/tags/t1/relationships/flows', self::path($mock));
        self::assertInstanceOf(ResponseFlow::class, $flows['data'][0]);
        self::assertSame('f1', $flows['data'][0]->id);

        $lists = $tags->listIds('t1');
        self::assertSame('/api/tags/t1/relationships/lists', self::path($mock));
        self::assertInstanceOf(ResponseKlaviyoList::class, $lists['data'][0]);
        self::assertSame('L1', $lists['data'][0]->id);

        $segments = $tags->segmentIds('t1');
        self::assertSame('/api/tags/t1/relationships/segments', self::path($mock));
        self::assertInstanceOf(ResponseSegment::class, $segments['data'][0]);
        self::assertSame('s1', $segments['data'][0]->id);

    }

    public function testTagAndUntagResources(): void
    {

        $mock = new MockHandler(array_map(fn() => new Response(204), range(1, 8)));
        $tags = self::client($mock)->tags;

        self::assertNull($tags->tagCampaigns('t1', [new Campaign('c1'), new Campaign('c2')]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/campaigns', self::path($mock));
        self::assertSame([
            'data' => [
                ['type' => 'campaign', 'id' => 'c1'],
                ['type' => 'campaign', 'id' => 'c2'],
            ],
        ], self::body($mock));

        self::assertNull($tags->untagCampaigns('t1', [new Campaign('c1')]));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/campaigns', self::path($mock));
        self::assertSame(['data' => [['type' => 'campaign', 'id' => 'c1']]], self::body($mock));

        self::assertNull($tags->tagFlows('t1', [new Flow('f1')]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/flows', self::path($mock));
        self::assertSame(['data' => [['type' => 'flow', 'id' => 'f1']]], self::body($mock));

        self::assertNull($tags->untagFlows('t1', [new Flow('f1')]));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/flows', self::path($mock));

        self::assertNull($tags->tagLists('t1', [new KlaviyoList('L1')]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/lists', self::path($mock));
        self::assertSame(['data' => [['type' => 'list', 'id' => 'L1']]], self::body($mock));

        self::assertNull($tags->untagLists('t1', [new KlaviyoList('L1')]));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/lists', self::path($mock));

        self::assertNull($tags->tagSegments('t1', [new Segment('s1')]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/segments', self::path($mock));
        self::assertSame(['data' => [['type' => 'segment', 'id' => 's1']]], self::body($mock));

        self::assertNull($tags->untagSegments('t1', [new Segment('s1')]));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tags/t1/relationships/segments', self::path($mock));
        self::assertSame(['data' => [['type' => 'segment', 'id' => 's1']]], self::body($mock));

    }

    public function testTagGroupForTag(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_tag_group_for_tag.200'),
            Fixtures::response('get_tag_group_id_for_tag.200'),
        ]);
        $tags = self::client($mock)->tags;

        $group = $tags->tagGroup('t1', (new Query())->fields('tag-group', 'name'));
        self::assertSame('/api/tags/t1/tag-group', self::path($mock));
        self::assertSame(['fields' => ['tag-group' => 'name']], self::query($mock));
        self::assertInstanceOf(TagGroup::class, $group);
        self::assertSame(self::FIXTURE_GROUP_SHARED, $group->id);
        self::assertSame('sdk-smoke-0904b975-tg-shared-renamed', $group->name);
        self::assertFalse($group->exclusive);
        self::assertFalse($group->default);
        self::assertFalse($group->getRelationship('tags')->hasData);

        $groupId = $tags->tagGroupId('t1');
        self::assertSame('/api/tags/t1/relationships/tag-group', self::path($mock));
        self::assertInstanceOf(TagGroup::class, $groupId);
        self::assertSame(self::FIXTURE_GROUP_SHARED, $groupId->id);
        self::assertNull($groupId->name);

    }

    // endregion

    // region Tag groups

    public function testTagGroupCrud(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_tag_groups.200'),
            Fixtures::response('get_tag_group.200'),
            Fixtures::response('create_tag_group.201'),
            Fixtures::response('update_tag_group.204'),
            Fixtures::response('delete_tag_group.204'),
        ]);
        $groups = self::client($mock)->tagGroups;

        $list = $groups->list((new Query())->filter(Filter::equals('default', false))->sort('name', descending: true));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/tag-groups', self::path($mock));
        self::assertSame(['filter' => 'equals(default,false)', 'sort' => '-name'], self::query($mock));
        self::assertInstanceOf(TagGroup::class, $list['data'][0]);
        // Recorded against `equals(default,true)`, so this is the account's own default group.
        self::assertSame(self::FIXTURE_GROUP_DEFAULT, $list['data'][0]->id);
        self::assertSame('Ungrouped Tags', $list['data'][0]->name);
        self::assertTrue($list['data'][0]->default);
        self::assertFalse($list['data'][0]->exclusive);

        $group = $groups->get('tg1', (new Query())->fields('tag-group', 'name', 'exclusive'));
        self::assertSame('/api/tag-groups/tg1', self::path($mock));
        self::assertSame(['fields' => ['tag-group' => 'name,exclusive']], self::query($mock));
        self::assertInstanceOf(TagGroup::class, $group);
        self::assertSame(self::FIXTURE_GROUP_EXCL, $group->id);
        self::assertSame('sdk-smoke-0904b975-tg-excl', $group->name);
        // Recorded with fields[tag-group]=name only, so `default` never arrived.
        self::assertNull($group->default);

        $create = new CreateTagGroup('Venues');
        $create->exclusive = false;
        $created = $groups->create($create);
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/tag-groups', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'tag-group',
                'attributes' => ['name' => 'Venues', 'exclusive' => false],
            ],
        ], self::body($mock));
        self::assertInstanceOf(TagGroup::class, $created);
        self::assertSame(self::FIXTURE_GROUP_EXCL, $created->id);
        self::assertSame('sdk-smoke-0904b975-tg-excl', $created->name);
        self::assertTrue($created->exclusive);
        self::assertFalse($created->default);

        $update = new UpdateTagGroup('tg1');
        $update->name = 'Marketing channels';
        self::assertNull($groups->update($update));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/tag-groups/tg1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'tag-group',
                'attributes' => ['name' => 'Marketing channels'],
                'id'         => 'tg1',
            ],
        ], self::body($mock));

        self::assertNull($groups->delete('tg1'));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/tag-groups/tg1', self::path($mock));

    }

    public function testTagGroupTags(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_tags_for_tag_group.200'),
            Fixtures::response('get_tag_ids_for_tag_group.200'),
        ]);
        $groups = self::client($mock)->tagGroups;

        $tags = $groups->tags('tg1', (new Query())->fields('tag', 'name'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/tag-groups/tg1/tags', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name']], self::query($mock));
        self::assertInstanceOf(Tag::class, $tags['data'][0]);
        self::assertSame(self::FIXTURE_TAG_DEFAULT, $tags['data'][0]->id);
        self::assertSame('sdk-smoke-0904b975-t-default', $tags['data'][0]->name);
        // Read through the group, a tag's own `tag-group` comes back as links only.
        self::assertFalse($tags['data'][0]->getRelationship('tag-group')->hasData);

        $ids = $groups->tagIds('tg1');
        self::assertSame('/api/tag-groups/tg1/relationships/tags', self::path($mock));
        self::assertCount(1, $ids['data']);
        self::assertInstanceOf(Tag::class, $ids['data'][0]);
        self::assertSame(self::FIXTURE_TAG_DEFAULT, $ids['data'][0]->id);
        self::assertNull($ids['data'][0]->name);

    }

    // endregion

}
