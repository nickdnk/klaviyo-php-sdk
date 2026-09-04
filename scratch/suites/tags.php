<?php
/**
 * TagService + TagGroupService, end to end: tag groups (default vs. custom, exclusive vs.
 * shared) → tags inside them → every read knob (fields, include, filter, sort, page, cursor)
 * → the four tag/untag relationship pairs (lists, segments, campaigns, flows) → cleanup.
 *
 * Helper resources it creates to have something to tag: one list, one segment (membership of
 * that list) and one draft email campaign. Flows cannot be created cheaply, so an existing
 * flow is tagged and untagged again (reversible) when the account has one.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CampaignMessage;
use nickdnk\Klaviyo\Resources\Request\CreateCampaign;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateSegment;
use nickdnk\Klaviyo\Resources\Request\CreateTag;
use nickdnk\Klaviyo\Resources\Request\CreateTagGroup;
use nickdnk\Klaviyo\Resources\Request\UpdateTag;
use nickdnk\Klaviyo\Resources\Request\UpdateTagGroup;
use nickdnk\Klaviyo\Resources\Response\Campaign as ResponseCampaign;
use nickdnk\Klaviyo\Resources\Response\Flow as ResponseFlow;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList as ResponseKlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Segment as ResponseSegment;
use nickdnk\Klaviyo\Resources\Response\Tag as ResponseTag;
use nickdnk\Klaviyo\Resources\Response\TagGroup as ResponseTagGroup;
use nickdnk\Klaviyo\Resources\Shared\Campaign as SharedCampaign;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageContent;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\Flow as SharedFlow;
use nickdnk\Klaviyo\Resources\Shared\KlaviyoList as SharedKlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Segment as SharedSegment;
use Smoke\Harness;

return function (Harness $h): void {

    $noSuchId = '00000000-0000-0000-0000-000000000000';

    // ───────────────────────── Tag groups ─────────────────────────

    $defaultGroups = $h->step('tagGroups', 'list', 'list w/ filter equals(default,true) + fields[tag-group] → the account default group',
        fn(APIClient $c) => $c->tagGroups->list((new Query())
            ->filter(Filter::equals('default', true))
            ->fields('tag-group', 'name', 'exclusive', 'default')),
        function ($r) use ($h) {
            $h->assert(count($r['data']) === 1, 'exactly one default tag group');
            $h->assert($r['data'][0] instanceof ResponseTagGroup && $r['data'][0]->id, 'hydrated TagGroup with id');
            $h->assert($r['data'][0]->default === true, 'default flag true');
        });

    /** @var ResponseTagGroup|null $defaultGroup */
    $defaultGroup = $defaultGroups['data'][0] ?? null;

    $sharedGroup = $h->step('tagGroups', 'create', 'create non-exclusive tag group (exclusive=false) w/ fields[tag-group]',
        function (APIClient $c) use ($h) {
            $g = new CreateTagGroup($h->name('tg-shared'));
            $g->exclusive = false;
            return $c->tagGroups->create($g, (new Query())->fields('tag-group', 'name', 'exclusive', 'default'));
        },
        function ($r) use ($h) {
            $h->assert($r instanceof ResponseTagGroup && $r->id, 'hydrated TagGroup with id');
            $h->assert($r->name === $h->name('tg-shared'), 'name echoed');
            $h->assert($r->exclusive === false, 'exclusive=false echoed');
            $h->assert($r->default === false, 'not the default group');
        });
    if ($sharedGroup) {
        $h->cleanup('tagGroups', 'delete', 'non-exclusive tag group', fn(APIClient $c) => $c->tagGroups->delete($sharedGroup->id));
    }

    $exclusiveGroup = $h->step('tagGroups', 'create', 'create exclusive tag group (exclusive=true)',
        function (APIClient $c) use ($h) {
            $g = new CreateTagGroup($h->name('tg-excl'));
            $g->exclusive = true;
            return $c->tagGroups->create($g);
        },
        function ($r) use ($h) {
            $h->assert($r instanceof ResponseTagGroup && $r->id, 'hydrated TagGroup with id');
            $h->assert($r->exclusive === true, 'exclusive=true echoed');
        });
    if ($exclusiveGroup) {
        $h->cleanup('tagGroups', 'delete', 'exclusive tag group', fn(APIClient $c) => $c->tagGroups->delete($exclusiveGroup->id));
    }

    if (!$sharedGroup || !$exclusiveGroup) {
        $h->note('Tag group creation failed — the rest of the suite cannot run.');
        return;
    }

    $h->step('tagGroups', 'get', 'get tag group w/ fields[tag-group]',
        fn(APIClient $c) => $c->tagGroups->get($sharedGroup->id, (new Query())->fields('tag-group', 'name', 'exclusive', 'default')),
        fn($r) => $h->assert($r instanceof ResponseTagGroup && $r->id === $sharedGroup->id && $r->name === $h->name('tg-shared'), 'same group hydrated'));

    $h->step('tagGroups', 'get', 'get tag group w/ fields[tag-group]=name only (sparse)',
        fn(APIClient $c) => $c->tagGroups->get($exclusiveGroup->id, (new Query())->fields('tag-group', 'name')),
        function ($r) use ($h) {
            $h->assert($r instanceof ResponseTagGroup && $r->name !== null, 'name present');
            $h->assert(!isset($r->exclusive) && !isset($r->default), 'exclusive/default omitted by sparse fieldset');
        });

    $h->step('tagGroups', 'get', 'get unknown tag group → null',
        fn(APIClient $c) => $c->tagGroups->get($noSuchId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('tagGroups', 'list', 'list w/ filter contains(name) + sort=name + page[size]=25',
        fn(APIClient $c) => $c->tagGroups->list((new Query())
            ->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))
            ->sort('name')
            ->pageSize(25)),
        fn($r) => $h->assert(count($r['data']) === 2, 'both of our groups, nothing else'));

    $h->step('tagGroups', 'list', 'list w/ filter startsWith(name) + sort=-name (descending)',
        fn(APIClient $c) => $c->tagGroups->list((new Query())
            ->filter(Filter::startsWith('name', 'sdk-smoke-' . $h->runId))
            ->sort('name', descending: true)),
        function ($r) use ($h) {
            $h->assert(count($r['data']) === 2, 'two groups');
            $h->assert($r['data'][0]->name > $r['data'][1]->name, 'descending name order');
        });

    $h->step('tagGroups', 'list', 'list w/ filter equals(exclusive,true) + endsWith(name)',
        fn(APIClient $c) => $c->tagGroups->list((new Query())->filter(Filter::all(
            Filter::equals('exclusive', true),
            Filter::endsWith('name', $h->runId . '-tg-excl'),
        ))),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $exclusiveGroup->id, 'only the exclusive group'));

    $h->step('tagGroups', 'list', 'paginate tag groups via links.next (page[size]=1) + Query::cursor',
        function (APIClient $c) use ($h) {
            $first = $c->tagGroups->list((new Query())->pageSize(1)->sort('id'));
            $h->assert(count($first['data']) === 1, 'first page has 1');
            $h->assert($first['links']?->next !== null, 'links.next present');
            $second = $c->tagGroups->list(next: $first['links']->next);
            $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
            $viaCursor = $c->tagGroups->list((new Query())->pageSize(1)->sort('id')->cursor($first['links']->next));
            $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(links.next) equals next:');
            return $second;
        });

    $h->step('tagGroups', 'update', 'rename tag group (204 → null)',
        function (APIClient $c) use ($h, $sharedGroup) {
            $u = new UpdateTagGroup($sharedGroup->id);
            $u->name = $h->name('tg-shared-renamed');
            return $c->tagGroups->update($u);
        },
        fn($r) => $h->assert($r === null, 'null on 204'));

    $h->step('tagGroups', 'get', 'get tag group after rename',
        fn(APIClient $c) => $c->tagGroups->get($sharedGroup->id),
        fn($r) => $h->assert($r?->name === $h->name('tg-shared-renamed'), 'rename persisted'));

    $h->step('tagGroups', 'update', 'rename tag group w/ return_fields + fields[tag-group]',
        function (APIClient $c) use ($h, $exclusiveGroup) {
            $u = new UpdateTagGroup($exclusiveGroup->id);
            $u->name = $h->name('tg-excl-renamed');
            $u->return_fields = ['name', 'exclusive'];
            return $c->tagGroups->update($u, (new Query())->fields('tag-group', 'name', 'exclusive'));
        },
        fn($r) => $h->assert($r === null, 'null on 204 (return_fields accepted)'));

    // ───────────────────────── Tags ─────────────────────────

    $tagDefault = $h->step('tags', 'create', 'create tag in the account default tag group (no tagGroupId)',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('t-default'))),
        function ($r) use ($h) {
            $h->assert($r instanceof ResponseTag && $r->id, 'hydrated Tag with id');
            $h->assert($r->name === $h->name('t-default'), 'name echoed');
            $h->assert($r->getRelationship('tag-group')?->data instanceof ResponseTagGroup, 'tag-group relationship hydrated on create');
        });
    if ($tagDefault) {
        $h->cleanup('tags', 'delete', 'tag in default group', fn(APIClient $c) => $c->tags->delete($tagDefault->id));
    }

    $tagShared = $h->step('tags', 'create', 'create tag in custom non-exclusive group w/ fields[tag]',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('t-shared'), $sharedGroup->id), (new Query())->fields('tag', 'name')),
        function ($r) use ($h, $sharedGroup) {
            $h->assert($r instanceof ResponseTag && $r->id, 'hydrated Tag with id');
            $h->assert($r->getRelationship('tag-group')?->data->id === $sharedGroup->id, 'landed in our group');
        });
    if ($tagShared) {
        $h->cleanup('tags', 'delete', 'tag in non-exclusive group', fn(APIClient $c) => $c->tags->delete($tagShared->id));
    }

    $tagShared2 = $h->step('tags', 'create', 'create second tag in the same non-exclusive group',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('t-shared-2'), $sharedGroup->id)),
        fn($r) => $h->assert($r instanceof ResponseTag && $r->id, 'hydrated Tag with id'));
    if ($tagShared2) {
        $h->cleanup('tags', 'delete', 'second tag in non-exclusive group', fn(APIClient $c) => $c->tags->delete($tagShared2->id));
    }

    $tagExclA = $h->step('tags', 'create', 'create tag in exclusive group',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('t-excl-a'), $exclusiveGroup->id)),
        fn($r) => $h->assert($r instanceof ResponseTag && $r->id, 'hydrated Tag with id'));
    if ($tagExclA) {
        $h->cleanup('tags', 'delete', 'tag A in exclusive group', fn(APIClient $c) => $c->tags->delete($tagExclA->id));
    }

    $tagExclB = $h->step('tags', 'create', 'create second tag in exclusive group',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('t-excl-b'), $exclusiveGroup->id)),
        fn($r) => $h->assert($r instanceof ResponseTag && $r->id, 'hydrated Tag with id'));
    if ($tagExclB) {
        $h->cleanup('tags', 'delete', 'tag B in exclusive group', fn(APIClient $c) => $c->tags->delete($tagExclB->id));
    }

    if (!$tagShared) {
        $h->note('Tag creation in the custom group failed — relationship coverage cannot run.');
        return;
    }

    $h->step('tags', 'create', 'create tag with unknown tag group id → rejected',
        function (APIClient $c) use ($h, $noSuchId) {
            try {
                $t = $c->tags->create(new CreateTag($h->name('t-orphan'), $noSuchId));
                $h->cleanup('tags', 'delete', 'unexpected orphan tag', fn(APIClient $c2) => $c2->tags->delete($t->id));
                $h->assert(false, 'expected a 4xx for an unknown tag group id, got a tag');
            } catch (ClientException $e) {
                $h->assert($e->getHttpStatus() >= 400 && $e->getHttpStatus() < 500, 'client error for unknown tag group, got ' . $e->getHttpStatus());
                return $e->getHttpStatus();
            }
            return null;
        });

    $h->step('tags', 'get', 'get tag w/ fields[tag] + fields[tag-group] + include(tag-group)',
        fn(APIClient $c) => $c->tags->get($tagShared->id, (new Query())
            ->fields('tag', 'name')
            ->fields('tag-group', 'name', 'exclusive', 'default')
            ->include('tag-group')),
        function ($r) use ($h, $sharedGroup, $tagShared) {
            $h->assert($r instanceof ResponseTag && $r->id === $tagShared->id, 'same tag');
            $h->assert($r->name === $h->name('t-shared'), 'name present');
            $rel = $r->getRelationship('tag-group');
            $h->assert($rel?->data instanceof ResponseTagGroup && $rel->data->id === $sharedGroup->id, 'tag-group relationship id hydrated');
        });

    $h->step('tags', 'get', 'get unknown tag → null',
        fn(APIClient $c) => $c->tags->get($noSuchId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('tags', 'list', 'list w/ filter equals(name) + fields[tag] + sort=name + page[size]=50',
        fn(APIClient $c) => $c->tags->list((new Query())
            ->filter(Filter::equals('name', $h->name('t-shared')))
            ->fields('tag', 'name')
            ->sort('name')
            ->pageSize(50)),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $tagShared->id, 'exactly our tag'));

    $h->step('tags', 'list', 'list w/ filter contains(name) + include(tag-group) + sort=-name',
        fn(APIClient $c) => $c->tags->list((new Query())
            ->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))
            ->include('tag-group')
            ->sort('name', descending: true)),
        function ($r) use ($h) {
            $h->assert(count($r['data']) >= 5, 'at least the 5 tags we made (got ' . count($r['data']) . ')');
            $h->assert($r['data'][0]->getRelationship('tag-group')?->data instanceof ResponseTagGroup, 'tag-group hydrated on list rows');
        });

    $h->step('tags', 'list', 'list w/ filter startsWith(name) + endsWith(name) combined',
        fn(APIClient $c) => $c->tags->list((new Query())->filter(Filter::all(
            Filter::startsWith('name', 'sdk-smoke-' . $h->runId),
            Filter::endsWith('name', '-t-excl-a'),
        ))),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $tagExclA?->id, 'only t-excl-a'));

    $h->step('tags', 'list', 'paginate tags via links.next (page[size]=1) + Query::cursor',
        function (APIClient $c) use ($h) {
            $first = $c->tags->list((new Query())->pageSize(1)->sort('id'));
            $h->assert(count($first['data']) === 1, 'first page has 1');
            $h->assert($first['links']?->next !== null, 'links.next present');
            $second = $c->tags->list(next: $first['links']->next);
            $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
            $viaCursor = $c->tags->list((new Query())->pageSize(1)->sort('id')->cursor($first['links']->next));
            $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(links.next) equals next:');
            return $second;
        });

    $h->step('tags', 'update', 'rename tag (204 → null)',
        function (APIClient $c) use ($h, $tagShared) {
            $u = new UpdateTag($tagShared->id);
            $u->name = $h->name('t-shared-renamed');
            return $c->tags->update($u);
        },
        fn($r) => $h->assert($r === null, 'null on 204'));

    $h->step('tags', 'get', 'get tag after rename',
        fn(APIClient $c) => $c->tags->get($tagShared->id),
        fn($r) => $h->assert($r?->name === $h->name('t-shared-renamed'), 'rename persisted'));

    $h->step('tags', 'update', 'rename tag w/ fields[tag] on the echo',
        function (APIClient $c) use ($h, $tagShared) {
            $u = new UpdateTag($tagShared->id);
            $u->name = $h->name('t-shared');
            return $c->tags->update($u, (new Query())->fields('tag', 'name'));
        },
        fn($r) => $h->assert($r === null, 'null on 204'));

    // ── tag ↔ tag group navigation

    $h->step('tags', 'tagGroup', 'tag-group for tag w/ fields[tag-group]',
        fn(APIClient $c) => $c->tags->tagGroup($tagShared->id, (new Query())->fields('tag-group', 'name', 'exclusive', 'default')),
        function ($r) use ($h, $sharedGroup) {
            $h->assert($r instanceof ResponseTagGroup && $r->id === $sharedGroup->id, 'our group returned');
            $h->assert($r->name === $h->name('tg-shared-renamed') && $r->exclusive === false, 'attributes hydrated');
        });

    $h->step('tags', 'tagGroup', 'tag-group for unknown tag → null',
        fn(APIClient $c) => $c->tags->tagGroup($noSuchId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('tags', 'tagGroupId', 'tag-group id for tag (identifier only)',
        fn(APIClient $c) => $c->tags->tagGroupId($tagShared->id),
        function ($r) use ($h, $sharedGroup) {
            $h->assert($r instanceof ResponseTagGroup && $r->id === $sharedGroup->id, 'identifier hydrated');
            $h->assert(!isset($r->name), 'no attributes on a relationship identifier');
        });

    $h->step('tags', 'tagGroupId', 'tag-group id for unknown tag → null',
        fn(APIClient $c) => $c->tags->tagGroupId($noSuchId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('tagGroups', 'tags', 'tags for our non-exclusive group w/ fields[tag]',
        fn(APIClient $c) => $c->tagGroups->tags($sharedGroup->id, (new Query())->fields('tag', 'name')),
        function ($r) use ($h, $tagShared, $tagShared2) {
            $ids = array_map(fn($t) => $t->id, $r['data']);
            $h->assert(count($r['data']) === ($tagShared2 ? 2 : 1), 'both tags of the group');
            $h->assert(in_array($tagShared->id, $ids, true), 'contains t-shared');
            $h->assert($r['data'][0] instanceof ResponseTag && $r['data'][0]->name !== null, 'attributes hydrated');
        });

    $h->step('tagGroups', 'tagIds', 'tag ids for our exclusive group (identifiers only)',
        fn(APIClient $c) => $c->tagGroups->tagIds($exclusiveGroup->id),
        function ($r) use ($h, $tagExclA, $tagExclB) {
            $ids = array_map(fn($t) => $t->id, $r['data']);
            $h->assert(count($r['data']) === count(array_filter([$tagExclA, $tagExclB])), 'both exclusive-group tags');
            $h->assert($tagExclA === null || in_array($tagExclA->id, $ids, true), 'contains t-excl-a');
            $h->assert($r['data'][0] instanceof ResponseTag && !isset($r['data'][0]->name), 'identifiers carry no attributes');
        });

    if ($defaultGroup) {
        $h->step('tagGroups', 'tags', 'tags for the account default group w/ fields[tag] + our tag present',
            fn(APIClient $c) => $c->tagGroups->tags($defaultGroup->id, (new Query())->fields('tag', 'name')),
            function ($r) use ($h, $tagDefault) {
                $h->assert(is_array($r['data']), 'array of tags');
                $h->assert($tagDefault === null || in_array($tagDefault->id, array_map(fn($t) => $t->id, $r['data']), true), 'our default-group tag listed');
            });
        $h->step('tagGroups', 'tagIds', 'tag ids for the account default group',
            fn(APIClient $c) => $c->tagGroups->tagIds($defaultGroup->id),
            fn($r) => $h->assert(is_array($r['data']), 'array of identifiers'));
        $h->skip('tagGroups', 'delete', 'delete the account default tag group', 'Klaviyo forbids deleting the default tag group, and it holds tags this account depends on.');
    }

    // ───────────────────────── Lists ─────────────────────────

    $list = $h->step('lists', 'create', 'create list to tag',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('l'))),
        fn($r) => $h->assert($r instanceof ResponseKlaviyoList && $r->id, 'hydrated list with id'));
    if ($list) {
        $h->cleanup('lists', 'delete', 'list', fn(APIClient $c) => $c->lists->delete($list->id));

        $h->step('tags', 'listIds', 'list ids for tag before tagging (empty)',
            fn(APIClient $c) => $c->tags->listIds($tagShared->id),
            fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no lists yet'));

        $h->step('tags', 'tagLists', 'tag the list',
            fn(APIClient $c) => $c->tags->tagLists($tagShared->id, [new SharedKlaviyoList($list->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'listIds', 'list ids for tag after tagging',
            fn(APIClient $c) => $c->tags->listIds($tagShared->id),
            function ($r) use ($h, $list) {
                $h->assert(count($r['data']) === 1, 'one list linked');
                $h->assert($r['data'][0] instanceof ResponseKlaviyoList && $r['data'][0]->id === $list->id, 'identifier hydrated as KlaviyoList');
                $h->assert(!isset($r['data'][0]->name), 'identifier carries no attributes');
            });

        if ($tagExclA && $tagExclB) {
            $h->step('tags', 'tagLists', 'two tags from the exclusive group on one list (exclusivity probe)',
                function (APIClient $c) use ($h, $list, $tagExclA, $tagExclB) {
                    $c->tags->tagLists($tagExclA->id, [new SharedKlaviyoList($list->id)]);
                    $outcome = 'accepted';
                    try {
                        $c->tags->tagLists($tagExclB->id, [new SharedKlaviyoList($list->id)]);
                        $h->note('Klaviyo accepted two tags from an exclusive tag group on the same list; exclusivity is not enforced on this endpoint.');
                        $c->tags->untagLists($tagExclB->id, [new SharedKlaviyoList($list->id)]);
                    } catch (ClientException $e) {
                        $outcome = 'rejected with HTTP ' . $e->getHttpStatus();
                        $h->note('Exclusive tag group enforced: second tag on the same list rejected with HTTP ' . $e->getHttpStatus() . '.');
                    }
                    $c->tags->untagLists($tagExclA->id, [new SharedKlaviyoList($list->id)]);
                    return $outcome;
                });
        }

        $h->step('tags', 'untagLists', 'untag the list',
            fn(APIClient $c) => $c->tags->untagLists($tagShared->id, [new SharedKlaviyoList($list->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'listIds', 'list ids for tag after untagging (empty again)',
            fn(APIClient $c) => $c->tags->listIds($tagShared->id),
            fn($r) => $h->assert(count($r['data']) === 0, 'link removed'));
    } else {
        foreach (['tagLists', 'listIds', 'untagLists'] as $m) {
            $h->skip('tags', $m, 'needs a list', 'list creation failed');
        }
    }

    // ───────────────────────── Segments ─────────────────────────

    $segment = null;
    if ($list) {
        $segment = $h->step('segments', 'create', 'create segment (members of our list) to tag',
            fn(APIClient $c) => $c->segments->create(new CreateSegment($h->name('s'), [
                'condition_groups' => [
                    ['conditions' => [
                        ['type' => 'profile-group-membership', 'is_member' => true, 'group_ids' => [$list->id]],
                    ]],
                ],
            ])),
            fn($r) => $h->assert($r instanceof ResponseSegment && $r->id, 'hydrated segment with id'));
    }
    if ($segment) {
        $h->cleanup('segments', 'delete', 'segment', fn(APIClient $c) => $c->segments->delete($segment->id));

        $h->step('tags', 'tagSegments', 'tag the segment',
            fn(APIClient $c) => $c->tags->tagSegments($tagShared->id, [new SharedSegment($segment->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'segmentIds', 'segment ids for tag after tagging',
            fn(APIClient $c) => $c->tags->segmentIds($tagShared->id),
            function ($r) use ($h, $segment) {
                $h->assert(count($r['data']) === 1, 'one segment linked');
                $h->assert($r['data'][0] instanceof ResponseSegment && $r['data'][0]->id === $segment->id, 'identifier hydrated as Segment');
            });

        $h->step('tags', 'untagSegments', 'untag the segment',
            fn(APIClient $c) => $c->tags->untagSegments($tagShared->id, [new SharedSegment($segment->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'segmentIds', 'segment ids for tag after untagging (empty)',
            fn(APIClient $c) => $c->tags->segmentIds($tagShared->id),
            fn($r) => $h->assert(count($r['data']) === 0, 'link removed'));
    } else {
        foreach (['tagSegments', 'segmentIds', 'untagSegments'] as $m) {
            $h->skip('tags', $m, 'needs a segment', 'segment creation failed (or its list did)');
        }
    }

    // ───────────────────────── Campaigns ─────────────────────────

    $campaign = null;
    if ($list) {
        $campaign = $h->step('campaigns', 'create', 'create draft email campaign (scheduled far out, never sent) to tag',
            function (APIClient $c) use ($h, $list) {
                $content = new CampaignMessageContent();
                $content->subject = $h->name('subject');
                $content->preview_text = 'SDK smoke test draft — not sent';
                $content->from_email = $h->senderEmail();
                $content->from_label = 'SDK Smoke';
                $definition = new CampaignMessageDefinition('email');
                $definition->label = $h->name('msg');
                $definition->content = $content;
                $create = new CreateCampaign($h->name('c'), new CampaignAudiences([$list->id]), [new CampaignMessage($definition)]);
                $create->send_strategy = CampaignSendStrategy::at((new DateTimeImmutable('+60 days'))->format(DateTimeInterface::ATOM));
                return $c->campaigns->create($create);
            },
            fn($r) => $h->assert($r instanceof ResponseCampaign && $r->id, 'hydrated campaign with id'));
    }
    if ($campaign) {
        $h->cleanup('campaigns', 'delete', 'draft campaign', fn(APIClient $c) => $c->campaigns->delete($campaign->id));

        $h->step('tags', 'tagCampaigns', 'tag the draft campaign',
            fn(APIClient $c) => $c->tags->tagCampaigns($tagShared->id, [new SharedCampaign($campaign->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'campaignIds', 'campaign ids for tag after tagging',
            fn(APIClient $c) => $c->tags->campaignIds($tagShared->id),
            function ($r) use ($h, $campaign) {
                $h->assert(count($r['data']) === 1, 'one campaign linked');
                $h->assert($r['data'][0] instanceof ResponseCampaign && $r['data'][0]->id === $campaign->id, 'identifier hydrated as Campaign');
            });

        $h->step('tags', 'untagCampaigns', 'untag the draft campaign',
            fn(APIClient $c) => $c->tags->untagCampaigns($tagShared->id, [new SharedCampaign($campaign->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'campaignIds', 'campaign ids for tag after untagging (empty)',
            fn(APIClient $c) => $c->tags->campaignIds($tagShared->id),
            fn($r) => $h->assert(count($r['data']) === 0, 'link removed'));
    } else {
        foreach (['tagCampaigns', 'campaignIds', 'untagCampaigns'] as $m) {
            $h->skip('tags', $m, 'needs a draft campaign', 'campaign creation failed (or its list did)');
        }
    }

    // ───────────────────────── Flows ─────────────────────────

    $flows = $h->step('flows', 'list', 'list one existing flow to tag/untag (reversible)',
        fn(APIClient $c) => $c->flows->list((new Query())->pageSize(1)),
        fn($r) => $h->assert(is_array($r['data']), 'array'));

    /** @var ResponseFlow|null $flow */
    $flow = $flows['data'][0] ?? null;
    if ($flow) {
        $h->step('tags', 'flowIds', 'flow ids for tag before tagging (empty)',
            fn(APIClient $c) => $c->tags->flowIds($tagShared->id),
            fn($r) => $h->assert(count($r['data']) === 0, 'no flows yet'));

        $h->step('tags', 'tagFlows', 'tag an existing flow',
            fn(APIClient $c) => $c->tags->tagFlows($tagShared->id, [new SharedFlow($flow->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'flowIds', 'flow ids for tag after tagging',
            fn(APIClient $c) => $c->tags->flowIds($tagShared->id),
            function ($r) use ($h, $flow) {
                $h->assert(count($r['data']) === 1, 'one flow linked');
                $h->assert($r['data'][0] instanceof ResponseFlow && $r['data'][0]->id === $flow->id, 'identifier hydrated as Flow');
            });

        $h->step('tags', 'untagFlows', 'untag the flow again',
            fn(APIClient $c) => $c->tags->untagFlows($tagShared->id, [new SharedFlow($flow->id)]),
            fn($r) => $h->assert($r === null, 'null on 204'));

        $h->step('tags', 'flowIds', 'flow ids for tag after untagging (empty)',
            fn(APIClient $c) => $c->tags->flowIds($tagShared->id),
            fn($r) => $h->assert(count($r['data']) === 0, 'link removed'));
    } else {
        foreach (['tagFlows', 'flowIds', 'untagFlows'] as $m) {
            $h->skip('tags', $m, 'needs a flow', 'account has no flows and the API cannot create one; nothing to tag');
        }
    }

    // ───────────────────────── returnRequest + executePool ─────────────────────────

    $h->step('tags', 'get', 'executePool: 3 tag GETs via returnRequest (one 404)',
        function (APIClient $c) use ($h, $tagShared, $tagExclA, $noSuchId) {
            $res = $c->executePool([
                $c->tags->get($tagShared->id, returnRequest: true),
                $c->tags->get($noSuchId, returnRequest: true),
                $c->tags->get($tagExclA?->id ?? $tagShared->id, (new Query())->fields('tag', 'name'), returnRequest: true),
            ], 3);
            $h->assert(count($res) === 3, '3 results');
            $h->assert($res[0] instanceof ResponseTag && $res[0]->id === $tagShared->id, 'first hydrated');
            $h->assert($res[1] instanceof ClientException && $res[1]->getHttpStatus() === 404, 'second is a 404 exception');
            $h->assert($res[2] instanceof ResponseTag, 'third hydrated');
            return $res;
        });

    $h->step('tagGroups', 'get', 'executePool: tag group GET + tags + tagIds via returnRequest',
        function (APIClient $c) use ($h, $sharedGroup) {
            $res = $c->executePool([
                $c->tagGroups->get($sharedGroup->id, (new Query())->fields('tag-group', 'name'), returnRequest: true),
                $c->tagGroups->tags($sharedGroup->id, (new Query())->fields('tag', 'name'), returnRequest: true),
                $c->tagGroups->tagIds($sharedGroup->id, returnRequest: true),
            ], 3);
            APIClient::assertNoExceptions($res);
            $h->assert($res[0] instanceof ResponseTagGroup && $res[0]->id === $sharedGroup->id, 'group hydrated');
            $h->assert(is_array($res[1]) && $res[1][0] instanceof ResponseTag, 'tags unwrapped to a list of Tag');
            $h->assert(is_array($res[2]) && $res[2][0] instanceof ResponseTag, 'tagIds unwrapped to a list of Tag identifiers');
            return $res;
        });

    $h->note('Tag/untag pairs are symmetric: every link created above is removed again before cleanup, so the only account changes are the tags, tag groups, list, segment and draft campaign, all deleted in cleanup.');
};
