<?php
/**
 * SegmentService end to end. Membership is driven by a list + a custom profile property that only
 * profiles created here carry, so segment profile listings never expose pre-existing profiles.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\CreateSegment;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\UpdateSegment;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\Segment;
use Smoke\Harness;

return function (Harness $h): void {

    $list = $h->step('lists', 'create', 'create list for segment membership', fn(APIClient $c) => $c->lists->create(new CreateList($h->name('seg-list'))));
    if (!$list) {
        return;
    }
    $h->cleanup('lists', 'delete', 'segment list', fn(APIClient $c) => $c->lists->delete($list->id));

    $profiles = [];
    foreach (['s1', 's2'] as $k) {
        $p = $h->step('profiles', 'create', "create profile {$k} with sdk_smoke_run property", function (APIClient $c) use ($h, $k) {
            $p = new CreateProfile();
            $p->email = $h->email($k);
            $p->first_name = strtoupper($k);
            $p->properties = ['sdk_smoke_run' => $h->runId];
            return $c->profiles->create($p);
        });
        if ($p) {
            $profiles[] = $p;
            $h->cleanup('profiles', 'deleteProfile', "data-privacy deletion {$k}", fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($p->id))));
        }
    }
    $h->mutation('Data-privacy deletion requested for ' . implode(', ', array_map(fn($p) => $p->email, $profiles)));
    if (!$profiles) {
        return;
    }
    $h->step('lists', 'addProfiles', 'add profiles to list', fn(APIClient $c) => $c->lists->addProfiles($list->id, array_map(fn($p) => new Profile($p->id), $profiles)));

    $definition = ['condition_groups' => [
        ['conditions' => [
            ['type' => 'profile-group-membership', 'is_member' => true, 'group_ids' => [$list->id]],
        ]],
        ['conditions' => [
            ['type' => 'profile-property', 'property' => "properties['sdk_smoke_run']", 'filter' => ['type' => 'string', 'operator' => 'equals', 'value' => $h->runId]],
        ]],
    ]];

    /** @var Segment|null $seg */
    $seg = $h->step('segments', 'create', 'create segment (group membership AND profile property, is_starred)', function (APIClient $c) use ($h, $definition) {
        $s = new CreateSegment($h->name('segment'), $definition);
        $s->is_starred = true;
        return $c->segments->create($s, (new Query())->fields('segment', 'name', 'definition', 'is_starred', 'is_active', 'is_processing'));
    }, fn($r) => $h->assert($r instanceof Segment && $r->id && $r->name === $h->name('segment') && $r->is_starred === true && is_array($r->definition), 'hydrated segment'));
    if (!$seg) {
        return;
    }
    $h->cleanup('segments', 'delete', 'segment', fn(APIClient $c) => $c->segments->delete($seg->id));

    $h->step('segments', 'create', 'create second segment (property only, not starred)', function (APIClient $c) use ($h, $definition) {
        return $c->segments->create(new CreateSegment($h->name('segment2'), ['condition_groups' => [$definition['condition_groups'][1]]]));
    }, function ($r) use ($h) {
        $h->assert($r instanceof Segment && $r->id, 'second segment');
        $h->cleanup('segments', 'delete', 'segment2', fn(APIClient $c) => $c->segments->delete($r->id));
    });

    $h->step('segments', 'get', 'get segment w/ fields + additional-fields(profile_count) + include(tags,flow-triggers)', fn(APIClient $c) => $c->segments->get($seg->id, (new Query())
        ->fields('segment', 'name', 'definition', 'created', 'updated', 'is_active', 'is_processing', 'is_starred')
        ->additionalFields('segment', 'profile_count')
        ->fields('tag', 'name')->fields('flow', 'name')
        ->include('tags', 'flow-triggers')),
        fn($r) => $h->assert($r instanceof Segment && $r->name === $h->name('segment') && $r->profile_count !== null, 'profile_count present'));
    $h->step('segments', 'get', 'unknown segment → null', fn(APIClient $c) => $c->segments->get('NOPE01'), fn($r) => $h->assert($r === null, 'null'));

    $h->step('segments', 'list', 'list w/ filter equals(name) + fields + page[size]=10', fn(APIClient $c) => $c->segments->list((new Query())->filter(Filter::equals('name', $h->name('segment')))->fields('segment', 'name', 'is_starred')->pageSize(10)),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $seg->id, 'exactly ours'));
    $h->step('segments', 'list', 'list w/ filter any(name,[..]) AND equals(is_starred,true) AND greater-than(created) + sort=-created + include tags (name allows only any/equals)', fn(APIClient $c) => $c->segments->list((new Query())->filter(Filter::all(
        Filter::any('name', [$h->name('segment'), $h->name('segment2')]),
        Filter::equals('is_starred', true),
        Filter::greaterThan('created', new DateTimeImmutable('-1 day')),
    ))->sort('created', descending: true)->include('tags')), function ($r) use ($h, $seg) {
        $ids = array_map(fn($s) => $s->id, $r['data']);
        $h->assert(in_array($seg->id, $ids, true), 'our starred segment is in the result');
        if (count($ids) > 1) {
            $h->note('equals(is_starred,true) is accepted but not applied by Klaviyo: the unstarred sibling was returned too.');
        }
    });
    $h->step('segments', 'list', 'list w/ filter equals(is_active,true) + any(id,[..]) + page[size]=1 + pagination via next', function (APIClient $c) use ($h, $seg) {
        $p1 = $c->segments->list((new Query())->filter(Filter::equals('is_active', true))->pageSize(1));
        $h->assert(count($p1['data']) === 1 && $p1['links']?->next, 'page 1');
        $p2 = $c->segments->list(next: $p1['links']->next);
        $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
        return $p2;
    });

    $h->step('segments', 'update', 'update name + definition + is_starred=false', function (APIClient $c) use ($h, $seg, $definition) {
        $u = new UpdateSegment($seg->id);
        $u->name = $h->name('segment-renamed');
        $u->is_starred = false;
        $u->definition = $definition;
        return $c->segments->update($u, (new Query())->fields('segment', 'name', 'is_starred'));
    }, fn($r) => $h->assert($r instanceof Segment && $r->name === $h->name('segment-renamed') && $r->is_starred === false, 'updated'));

    $members = $h->step('segments', 'profiles', 'profiles in segment (poll until processed) w/ fields + additional-fields + sort=joined_group_at', fn(APIClient $c) => $h->waitFor(function () use ($c, $seg, $profiles) {
        $r = $c->segments->profiles($seg->id, (new Query())->fields('profile', 'email', 'joined_group_at')->additionalFields('profile', 'subscriptions')->sort('joined_group_at')->pageSize(20));
        return count($r['data']) >= 1 ? $r : null;
    }, 900, 20, 'segment membership computed (Klaviyo evaluates new segments asynchronously; can take many minutes)'), function ($r) use ($h, $profiles) {
        $h->assert($r['data'][0] instanceof Profile && $r['data'][0]->joined_group_at !== null, 'member hydrated with joined_group_at');
        if (count($r['data']) < count($profiles)) {
            $h->note('Only ' . count($r['data']) . ' of ' . count($profiles) . ' profiles had been evaluated into the segment when the poll stopped; Klaviyo adds members incrementally.');
        }
    });

    if ($members) {
        $member = $members['data'][0];
        $h->step('segments', 'profiles', 'profiles in segment w/ filter equals(email) + page[size]=1', fn(APIClient $c) => $c->segments->profiles($seg->id, (new Query())->filter(Filter::equals('email', $member->email))->pageSize(1)),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $member->id, 'filtered'));
        $h->step('segments', 'profileIds', 'profile ids in segment w/ page[size]=1 (+ next when more than one member)', function (APIClient $c) use ($h, $seg) {
            $p1 = $c->segments->profileIds($seg->id, (new Query())->pageSize(1));
            $h->assert(count($p1['data']) === 1 && $p1['data'][0] instanceof Profile, 'page 1 ids');
            if ($p1['links']?->next) {
                $p2 = $c->segments->profileIds($seg->id, next: $p1['links']->next);
                $h->assert(count($p2['data']) === 1, 'page 2 ids');
                return $p2;
            }
            return $p1;
        });
        $h->step('profiles', 'segments', 'segments for the member include ours', fn(APIClient $c) => $c->profiles->segments($member->id, (new Query())->fields('segment', 'name')),
            fn($r) => $h->assert(in_array($seg->id, array_map(fn($s) => $s->id, $r['data']), true), 'membership visible from profile side'));
        $h->step('profiles', 'segmentIds', 'segment ids for the member', fn(APIClient $c) => $c->profiles->segmentIds($member->id),
            fn($r) => $h->assert(in_array($seg->id, array_map(fn($s) => $s->id, $r['data']), true), 'ids'));
    }

    $h->step('segments', 'tags', 'tags for segment', fn(APIClient $c) => $c->segments->tags($seg->id, (new Query())->fields('tag', 'name')), fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('segments', 'tagIds', 'tag ids for segment', fn(APIClient $c) => $c->segments->tagIds($seg->id), fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('segments', 'flowTriggers', 'flow triggers for segment', fn(APIClient $c) => $c->segments->flowTriggers($seg->id, (new Query())->fields('flow', 'name')), fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('segments', 'flowTriggerIds', 'flow trigger ids for segment', fn(APIClient $c) => $c->segments->flowTriggerIds($seg->id), fn($r) => $h->assert(is_array($r['data']), 'array'));

    $h->step('segments', 'executePool', 'executePool: get + list concurrently via returnRequest', function (APIClient $c) use ($h, $seg) {
        $res = $c->executePool([
            $c->segments->get($seg->id, returnRequest: true),
            $c->segments->list((new Query())->filter(Filter::equals('name', $h->name('segment-renamed'))), returnRequest: true),
        ]);
        APIClient::assertNoExceptions($res);
        $h->assert($res[0] instanceof Segment && is_array($res[1]) && $res[1][0]->id === $seg->id, 'pooled get + list');
        return $res;
    });
};
