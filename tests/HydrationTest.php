<?php


namespace nickdnk\Klaviyo\Tests;

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\TagGroup;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use PHPUnit\Framework\TestCase;
use nickdnk\Klaviyo\Resources\Response\WhatsappConsent;
use nickdnk\Klaviyo\Resources\Response\WhatsappSubscription;

/** An empty to-many relationship is data ("no tags"), not absence, and identifiers keep their `meta`. */
class HydrationTest extends TestCase
{

    public function testIncludedResourcesAreSplicedIntoRelationships(): void
    {

        $result = APIClient::hydrateResponse([
            'data'     => [
                'type'          => 'tag',
                'id'            => 't1',
                'attributes'    => ['name' => 'vip'],
                'relationships' => [
                    'tag-group' => ['data' => ['type' => 'tag-group', 'id' => 'g1'], 'links' => ['self' => 'https://a.klaviyo.com/api/tags/t1/relationships/tag-group']],
                    'lists'     => ['data' => [['type' => 'list', 'id' => 'L1'], ['type' => 'list', 'id' => 'L2']]],
                ],
            ],
            'included' => [
                ['type' => 'tag-group', 'id' => 'g1', 'attributes' => ['name' => 'Ungrouped Tags', 'exclusive' => false, 'default' => true]],
                ['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'Newsletter']],
                // L2 is deliberately not included: it must stay an identifier
            ],
            'links'    => ['self' => 'https://a.klaviyo.com/api/tags/t1'],
        ]);

        /** @var Tag $tag */
        $tag = $result['data'];
        self::assertInstanceOf(Tag::class, $tag);

        $group = $tag->getRelationship('tag-group');
        self::assertInstanceOf(Relationship::class, $group);
        self::assertInstanceOf(TagGroup::class, $group->data);
        self::assertSame('g1', $group->data->id);
        self::assertSame('Ungrouped Tags', $group->data->name, 'attributes come from `included`');
        self::assertTrue($group->data->default);
        self::assertSame('https://a.klaviyo.com/api/tags/t1/relationships/tag-group', $group->links?->self);

        $lists = $tag->getRelationship('lists');
        self::assertCount(2, $lists->data);
        self::assertSame('Newsletter', $lists->data[0]->name);
        self::assertInstanceOf(KlaviyoList::class, $lists->data[1]);
        self::assertNull($lists->data[1]->name, 'not in `included` → identifier only');
        self::assertSame(['L1', 'L2'], $lists->ids());

        self::assertCount(2, $result['included']);
        self::assertInstanceOf(TagGroup::class, $result['included'][0]);
        self::assertInstanceOf(KlaviyoList::class, $result['included'][1]);

    }

    public function testIncludedWorksForListsAndNestedRelationshipsWithoutInfiniteRecursion(): void
    {

        // campaign ↔ campaign-message reference each other; both are included.
        $result = APIClient::hydrateResponse([
            'data'     => [[
                'type'          => 'campaign',
                'id'            => 'c1',
                'attributes'    => ['name' => 'Launch'],
                'relationships' => ['campaign-messages' => ['data' => [['type' => 'campaign-message', 'id' => 'm1']]]],
            ]],
            'included' => [
                [
                    'type'          => 'campaign-message',
                    'id'            => 'm1',
                    'attributes'    => ['label' => 'Main'],
                    'relationships' => [
                        'campaign' => ['data' => ['type' => 'campaign', 'id' => 'c1']],
                        'template' => ['data' => ['type' => 'template', 'id' => 'tpl1']],
                    ],
                ],
                ['type' => 'campaign', 'id' => 'c1', 'attributes' => ['name' => 'Launch']],
                ['type' => 'template', 'id' => 'tpl1', 'attributes' => ['name' => 'Base']],
            ],
            'links'    => ['self' => 'x', 'next' => null],
        ]);

        /** @var Campaign $campaign */
        $campaign = $result['data'][0];
        /** @var CampaignMessage $message */
        $message = $campaign->getRelationship('campaign-messages')->data[0];
        self::assertInstanceOf(CampaignMessage::class, $message);
        self::assertSame('Main', $message->label);
        self::assertSame('Base', $message->getRelationship('template')->data->name, 'nested include resolved');
        $back = $message->getRelationship('campaign')->data;
        self::assertInstanceOf(Campaign::class, $back);
        self::assertSame('c1', $back->id);
        self::assertNull($back->name, 'cycle broken: back-reference stays an identifier');

    }

    public function testEmptyAndNullAndLinksOnlyRelationshipsAreKept(): void
    {

        $result = APIClient::hydrateResponse(['data' => [
            'type'          => 'campaign',
            'id'            => 'c1',
            'attributes'    => ['name' => 'x'],
            'relationships' => [
                'tags'              => ['data' => [], 'links' => ['self' => 's', 'related' => 'r']],
                'campaign-messages' => ['links' => ['self' => 's2', 'related' => 'r2']],
                'image'             => ['data' => null],
            ],
        ]]);

        /** @var Campaign $c */
        $c = $result['data'];

        $tags = $c->getRelationship('tags');
        self::assertInstanceOf(Relationship::class, $tags, 'empty to-many is a relationship, not null');
        self::assertSame([], $tags->data);
        self::assertTrue($tags->hasData);
        self::assertSame('r', $tags->links?->related);

        $messages = $c->getRelationship('campaign-messages');
        self::assertInstanceOf(Relationship::class, $messages);
        self::assertFalse($messages->hasData, 'links only: data not inlined');
        self::assertSame([], $messages->data);
        self::assertSame('r2', $messages->links?->related);

        $image = $c->getRelationship('image');
        self::assertInstanceOf(Relationship::class, $image);
        self::assertNull($image->data, 'empty to-one');
        self::assertSame([], $image->ids());

        self::assertNull($c->getRelationship('nope'));

    }

    public function testIdentifierMetaSurvivesOnRelationshipListings(): void
    {

        // GET /api/object-schemas/{id}/relationships/object-schemas returns identifiers whose
        // meta carries the linkage's relationship_id.
        $result = APIClient::hydrateResponse(['data' => [
            ['type' => 'object-schema', 'id' => 's2', 'meta' => ['relationship_id' => 'rel_1', 'name' => 'orders']],
        ]]);

        /** @var ObjectSchema $s */
        $s = $result['data'][0];
        self::assertInstanceOf(ObjectSchema::class, $s);
        self::assertSame('rel_1', $s->getMeta()['relationship_id']);

        // ...and when the same identifier is also in `included`, both metas are merged.
        $result = APIClient::hydrateResponse([
            'data'     => ['type' => 'profile', 'id' => 'p1', 'attributes' => [], 'relationships' => [
                'lists' => ['data' => [['type' => 'list', 'id' => 'L1', 'meta' => ['joined' => '2026-01-01']]]],
            ]],
            'included' => [['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP'], 'meta' => ['extra' => 1]]],
        ]);
        /** @var Profile $p */
        $p = $result['data'];
        $list = $p->getRelationship('lists')->data[0];
        self::assertSame('VIP', $list->name);
        self::assertSame(['joined' => '2026-01-01', 'extra' => 1], $list->getMeta());

    }

    public function testUnknownTypesInIncludedAreIgnored(): void
    {

        $result = APIClient::hydrateResponse([
            'data'     => ['type' => 'tag', 'id' => 't1', 'attributes' => ['name' => 'x'], 'relationships' => ['thing' => ['data' => ['type' => 'not-a-type', 'id' => 'z']]]],
            'included' => [['type' => 'not-a-type', 'id' => 'z', 'attributes' => ['a' => 1]]],
        ]);

        self::assertSame(['type' => 'not-a-type', 'id' => 'z'], $result['data']->getRelationship('thing')->data, 'unknown type stays a raw identifier');
        self::assertSame([], $result['included']);

    }

    public function testWhatsappSubscriptionHydratesNestedConsents(): void
    {

        $result = APIClient::hydrateResponse(['data' => ['type' => 'profile', 'id' => 'p1', 'attributes' => ['subscriptions' => ['whatsapp' => [
            'marketing'      => ['consent' => 'SUBSCRIBED', 'phone_number' => '+4512345678'],
            'transactional'  => ['consent' => 'SUBSCRIBED'],
            'conversational' => null,
        ]]]]]);

        /** @var Profile $p */
        $p = $result['data'];
        self::assertInstanceOf(WhatsappSubscription::class, $p->subscriptions->whatsapp);
        self::assertInstanceOf(WhatsappConsent::class, $p->subscriptions->whatsapp->marketing);
        self::assertSame('+4512345678', $p->subscriptions->whatsapp->marketing->phone_number);
        self::assertNull($p->subscriptions->whatsapp->conversational);

    }


    public function testHydrationIgnoresMalformedRelationshipEntries(): void
    {

        $result = APIClient::hydrateResponse(['data' => ['type' => 'list', 'id' => 'L1', 'attributes' => [], 'relationships' => ['tags' => 'not-an-object', 'profiles' => ['data' => []]]]]);
        self::assertNull($result['data']->getRelationship('tags'));
        self::assertNotNull($result['data']->getRelationship('profiles'));

    }

}
