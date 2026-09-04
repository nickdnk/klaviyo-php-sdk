<?php
/**
 * ListService + ProfileService, end to end: create → read (every query knob the spec lists)
 * → mutate → relationships → subscriptions/suppressions → bulk import → merge → cleanup.
 * Only touches profiles it creates itself (sdk-smoke+<run>-*@<domain>).
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkImportJob;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\EmailSubscription;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\MarketingConsent;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;
use nickdnk\Klaviyo\Resources\Request\ProfileMerge;
use nickdnk\Klaviyo\Resources\Request\ProfileMetaPatch;
use nickdnk\Klaviyo\Resources\Request\SubscribeProfile;
use nickdnk\Klaviyo\Resources\Request\SubscriptionChannels;
use nickdnk\Klaviyo\Resources\Request\SubscriptionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SubscriptionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\UpdateList;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Shared\ProfileLocation;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use Smoke\Harness;

return function (Harness $h): void {

    // ───────────────────────────── Lists ─────────────────────────────

    /** @var KlaviyoList|null $list */
    $list = $h->step('lists', 'create', 'create list (single_opt_in)',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('list'), 'single_opt_in')),
        fn($r) => $h->assert($r instanceof KlaviyoList && $r->id && $r->name === $h->name('list'), 'hydrated list with id+name'));
    if (!$list) {
        $h->note('Cannot continue without a list.');
        return;
    }
    $h->cleanup('lists', 'delete', 'list', fn(APIClient $c) => $c->lists->delete($list->id));

    $list2 = $h->step('lists', 'create', 'create list (double_opt_in)',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('list2'), 'double_opt_in')),
        fn($r) => $h->assert($r->opt_in_process === 'double_opt_in', 'opt_in_process echoed'));
    if ($list2) {
        $h->cleanup('lists', 'delete', 'list2', fn(APIClient $c) => $c->lists->delete($list2->id));
    }

    $h->step('lists', 'get', 'get list w/ fields + additional-fields(profile_count) + include(tags,flow-triggers)',
        fn(APIClient $c) => $c->lists->get($list->id, (new Query())
            ->fields('list', 'name', 'created', 'updated', 'opt_in_process')
            ->additionalFields('list', 'profile_count')
            ->include('tags', 'flow-triggers')),
        fn($r) => $h->assert($r instanceof KlaviyoList && $r->name === $h->name('list') && $r->profile_count !== null, 'name + profile_count present'));

    $h->step('lists', 'get', 'get unknown list → null',
        fn(APIClient $c) => $c->lists->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('lists', 'list', 'list w/ filter(name equals) + fields + page[size]=10 + sort=-created',
        fn(APIClient $c) => $c->lists->list((new Query())
            ->filter(Filter::equals('name', $h->name('list')))
            ->fields('list', 'name', 'created')
            ->pageSize(10)
            ->sort('created', descending: true)),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $list->id, 'filter by name returns exactly ours'));

    $h->step('lists', 'list', 'list w/ filter any(id,[..]) + include tags',
        fn(APIClient $c) => $c->lists->list((new Query())->filter(Filter::any('id', array_filter([$list->id, $list2?->id])))->include('tags')),
        fn($r) => $h->assert(count($r['data']) === ($list2 ? 2 : 1), 'any(id) filter'));

    $h->step('lists', 'list', 'list w/ filter equals(name) AND greater-than(created) (lists allow only any/equals on name)',
        fn(APIClient $c) => $c->lists->list((new Query())->filter(Filter::all(
            Filter::equals('name', $h->name('list')),
            Filter::greaterThan('created', new DateTimeImmutable('-1 day')),
        ))->pageSize(10)),
        fn($r) => $h->assert(count($r['data']) === 1, 'combined filter'));

    $h->step('lists', 'list', 'paginate lists via links.next (page[size]=1)', function (APIClient $c) use ($h) {
        $first = $c->lists->list((new Query())->pageSize(1));
        $h->assert(count($first['data']) === 1, 'first page has 1');
        $h->assert($first['links']?->next !== null, 'has next link');
        $second = $c->lists->list(next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        $viaCursor = $c->lists->list((new Query())->pageSize(1)->cursor($first['links']->next));
        $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) equals links.next');
        return $second;
    });

    $h->step('lists', 'update', 'update list name + opt_in_process', function (APIClient $c) use ($h, $list) {
        $u = new UpdateList($list->id);
        $u->name = $h->name('list-renamed');
        $u->opt_in_process = 'single_opt_in';
        return $c->lists->update($u);
    }, fn($r) => $h->assert($r instanceof KlaviyoList && $r->name === $h->name('list-renamed'), 'renamed'));

    $h->step('lists', 'tags', 'tags for list (empty)', fn(APIClient $c) => $c->lists->tags($list->id, (new Query())->fields('tag', 'name')),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no tags yet'));
    $h->step('lists', 'tagIds', 'tag ids for list (empty)', fn(APIClient $c) => $c->lists->tagIds($list->id),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no tag ids'));
    $h->step('lists', 'flowTriggers', 'flow triggers for list', fn(APIClient $c) => $c->lists->flowTriggers($list->id, (new Query())->fields('flow', 'name', 'status')),
        fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('lists', 'flowTriggerIds', 'flow trigger ids for list', fn(APIClient $c) => $c->lists->flowTriggerIds($list->id),
        fn($r) => $h->assert(is_array($r['data']), 'array'));

    // ───────────────────────────── Profiles ─────────────────────────────

    $emailA = $h->email('a');
    $emailB = $h->email('b');
    $emailC = $h->email('c');
    $externalId = $h->name('ext-a');

    /** @var Profile|null $a */
    $a = $h->step('profiles', 'create', 'create profile (all attributes: names, org, title, locale, image, location, properties)',
        function (APIClient $c) use ($h, $emailA, $externalId) {
            $p = new CreateProfile();
            $p->email = $emailA;
            $p->external_id = $externalId;
            $p->first_name = 'Smoke';
            $p->last_name = 'Tester';
            $p->organization = 'nickdnk';
            $p->title = 'QA';
            $p->locale = 'en-US';
            $p->image = 'https://www.klaviyo.com/favicon.ico';
            $loc = new ProfileLocation();
            $loc->address1 = 'Artillerivej 86';
            $loc->city = 'Copenhagen';
            $loc->country = 'Denmark';
            $loc->zip = '2300';
            $loc->region = 'Capital Region';
            $loc->timezone = 'Europe/Copenhagen';
            $loc->latitude = 55.66;
            $loc->longitude = 12.57;
            $p->location = $loc;
            $p->properties = ['plan' => 'pro', 'tags' => ['x', 'y'], 'score' => 7];
            return $c->profiles->create($p, (new Query())->additionalFields('profile', 'subscriptions', 'predictive_analytics'));
        },
        fn($r) => $h->assert($r instanceof Profile && $r->id && $r->email === $emailA && $r->location?->city === 'Copenhagen' && ($r->properties['plan'] ?? null) === 'pro', 'hydrated profile incl. nested location + properties'));
    if (!$a) {
        $h->note('Cannot continue without profile A.');
        return;
    }
    // Profiles cannot be hard-deleted through the API except via a data-privacy job; registered last so it runs after everything else.
    $h->cleanup('profiles', 'deleteProfile', 'data-privacy deletion job for profile A', fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($a->id))));

    $h->step('profiles', 'create', 'create duplicate email → 409 ClientException',
        fn(APIClient $c) => (function () use ($c, $emailA) {
            $p = new CreateProfile();
            $p->email = $emailA;
            try {
                $c->profiles->create($p);
                return 'no-exception';
            } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) {
                return $e;
            }
        })(),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $r->getHttpStatus() === 409 && ($r->getFirstError()?->meta['duplicate_profile_id'] ?? null) === $a->id && $r->getFirstError()->code !== null, '409 with duplicate_profile_id in KlaviyoError meta'));

    $h->step('profiles', 'get', 'get profile w/ fields + additional-fields + include(lists,segments)',
        fn(APIClient $c) => $c->profiles->get($a->id, (new Query())
            ->fields('profile', 'email', 'first_name', 'properties', 'location', 'created')
            ->fields('list', 'name')
            ->fields('segment', 'name')
            ->additionalFields('profile', 'subscriptions', 'predictive_analytics')
            ->include('lists', 'segments')),
        fn($r) => $h->assert($r instanceof Profile && $r->email === $emailA && $r->subscriptions !== null && is_array($r->getRelationships()), 'subscriptions present, relationships hydrated'));

    $h->step('profiles', 'getByExternalId', 'get by external_id', fn(APIClient $c) => $c->profiles->getByExternalId($externalId),
        fn($r) => $h->assert($r instanceof Profile && $r->id === $a->id, 'found via external_id'));
    $h->step('profiles', 'getByExternalId', 'unknown external_id → null', fn(APIClient $c) => $c->profiles->getByExternalId($h->name('nope')),
        fn($r) => $h->assert($r === null, 'null'));

    $h->step('profiles', 'list', 'list w/ filter equals(email) + fields + page[size]=20',
        fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::equals('email', $emailA))->fields('profile', 'email', 'external_id')->pageSize(20)),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $a->id && $r['data'][0]->external_id === $externalId, 'exactly one match'));

    $h->step('profiles', 'list', 'list w/ filter any(email,[a,b]) + sort=-created + additional-fields',
        fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::any('email', [$emailA, $emailB]))->sort('created', descending: true)->additionalFields('profile', 'subscriptions')),
        fn($r) => $h->assert(count($r['data']) >= 1, 'any(email)'));

    $h->step('profiles', 'list', 'list w/ filter greater-than(created) AND equals(external_id)',
        fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::all(
            Filter::greaterThan('created', new DateTimeImmutable('-1 hour')),
            Filter::equals('external_id', $externalId),
        ))),
        fn($r) => $h->assert(count($r['data']) === 1, 'created + external_id'));

    $h->step('profiles', 'update', 'update profile w/ meta patch_properties (append/unset) + new attributes', function (APIClient $c) use ($h, $a) {
        $p = new PatchProfile($a->id);
        $p->first_name = 'Smokey';
        $p->properties = ['score' => 8];
        $p->addMeta('patch_properties', new ProfileMetaPatch(append: ['tags' => 'z'], unset: 'plan'));
        return $c->profiles->update($p, (new Query())->fields('profile', 'first_name', 'properties'));
    }, fn($r) => $h->assert($r instanceof Profile && $r->first_name === 'Smokey' && !isset($r->properties['plan']) && in_array('z', $r->properties['tags'] ?? [], true) && ($r->properties['score'] ?? null) === 8, 'patch applied: unset plan, appended tag z, score 8'));

    $h->step('profiles', 'import', 'import (upsert) new profile B by email + location', function (APIClient $c) use ($h, $emailB) {
        $p = new ImportProfile();
        $p->email = $emailB;
        $p->first_name = 'Bee';
        $p->properties = ['source' => 'import'];
        return $c->profiles->import($p);
    }, fn($r) => $h->assert($r instanceof Profile && $r->id && $r->email === $emailB, 'imported B'));
    $b = $c ?? null;
    $b = $h->step('profiles', 'import', 'import (upsert) existing A by id with patch_properties meta', function (APIClient $c) use ($h, $a) {
        $p = new ImportProfile($a->id);
        $p->last_name = 'Imported';
        $p->addMeta('patch_properties', new ProfileMetaPatch(append: ['tags' => 'imp']));
        return $c->profiles->import($p);
    }, fn($r) => $h->assert($r instanceof Profile && $r->id === $a->id && $r->last_name === 'Imported', 'upserted A by id'));

    $bProfile = $h->step('profiles', 'list', 'fetch B id via filter', fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::equals('email', $emailB))),
        fn($r) => $h->assert(count($r['data']) === 1, 'B exists'));
    $bId = $bProfile['data'][0]->id ?? null;

    // ── list membership
    $h->step('lists', 'addProfiles', 'add A+B to list', fn(APIClient $c) => $c->lists->addProfiles($list->id, array_filter([new Profile($a->id), $bId ? new Profile($bId) : null])),
        fn($r) => $h->assert($r === null, 'void'));
    $h->step('lists', 'profiles', 'profiles in list w/ fields + filter(email) + page[size]=1 + sort=joined_group_at', fn(APIClient $c) => $c->lists->profiles($list->id, (new Query())->fields('profile', 'email', 'joined_group_at')->additionalFields('profile', 'subscriptions')->filter(Filter::equals('email', $emailA))->pageSize(1)->sort('joined_group_at')),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->email === $emailA && $r['data'][0]->joined_group_at !== null, 'A in list with joined_group_at'));
    $h->step('lists', 'profileIds', 'profile ids in list w/ page[size]=1 + pagination via next', function (APIClient $c) use ($h, $list) {
        $p1 = $c->lists->profileIds($list->id, (new Query())->pageSize(1));
        $h->assert(count($p1['data']) === 1 && $p1['data'][0] instanceof Profile && $p1['data'][0]->id, 'identifier hydrated to Profile with id only');
        $h->assert($p1['links']?->next !== null, 'next link');
        $p2 = $c->lists->profileIds($list->id, next: $p1['links']->next);
        $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'second page');
        return $p2;
    });
    $h->step('profiles', 'lists', 'lists for profile A w/ fields', fn(APIClient $c) => $c->profiles->lists($a->id, (new Query())->fields('list', 'name')),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $list->id, 'A belongs to our list'));
    $h->step('profiles', 'listIds', 'list ids for profile A', fn(APIClient $c) => $c->profiles->listIds($a->id),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0] instanceof KlaviyoList && $r['data'][0]->id === $list->id, 'ids hydrate to KlaviyoList'));
    $h->step('profiles', 'segments', 'segments for profile A', fn(APIClient $c) => $c->profiles->segments($a->id, (new Query())->fields('segment', 'name')),
        fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('profiles', 'segmentIds', 'segment ids for profile A', fn(APIClient $c) => $c->profiles->segmentIds($a->id),
        fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('profiles', 'pushTokens', 'push tokens for profile A', fn(APIClient $c) => $c->profiles->pushTokens($a->id, (new Query())->fields('push-token', 'token', 'platform')),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'none'));
    $h->step('profiles', 'pushTokenIds', 'push token ids for profile A', fn(APIClient $c) => $c->profiles->pushTokenIds($a->id),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'none'));
    $h->step('profiles', 'conversation', 'conversation for profile A (to-one, likely null/403)', fn(APIClient $c) => $c->profiles->conversation($a->id));
    $h->step('profiles', 'conversationId', 'conversation id for profile A', fn(APIClient $c) => $c->profiles->conversationId($a->id));
    $h->step('profiles', 'conversations', 'conversations for profile A', fn(APIClient $c) => $c->profiles->conversations($a->id));
    $h->step('profiles', 'conversationIds', 'conversation ids for profile A', fn(APIClient $c) => $c->profiles->conversationIds($a->id));

    $h->step('lists', 'removeProfiles', 'remove B from list', fn(APIClient $c) => $c->lists->removeProfiles($list->id, [new Profile($bId)]),
        fn($r) => $h->assert($r === null, 'void'));
    $h->step('lists', 'profileIds', 'B no longer in list', fn(APIClient $c) => $c->lists->profileIds($list->id),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $a->id, 'only A remains'));

    // ── subscriptions (email only: SMS needs a sending number on the account)
    $h->step('profiles', 'subscribe', 'bulk subscribe A (email marketing, historical_import, custom_source) to list via relationship', function (APIClient $c) use ($h, $a, $emailA, $list) {
        $job = new SubscriptionCreateJob([
            new SubscribeProfile(new SubscriptionChannels(email: new EmailSubscription(new MarketingConsent('SUBSCRIBED', (new DateTimeImmutable('-1 day'))->format(DATE_ATOM)))), email: $emailA, id: $a->id),
        ], historicalImport: true, customSource: 'sdk-smoke');
        $job->addRelationship('list', new Relationship(new KlaviyoList($list->id)));
        return $c->profiles->subscribe($job);
    }, fn($r) => $h->assert($r === null, '202 void'));

    $h->step('profiles', 'subscribe', 'bulk subscribe C (new profile, email, no list = global consent)', function (APIClient $c) use ($h, $emailC) {
        return $c->profiles->subscribe(new SubscriptionCreateJob([
            new SubscribeProfile(new SubscriptionChannels(email: new EmailSubscription(new MarketingConsent('SUBSCRIBED'))), email: $emailC),
        ]));
    });

    $subscribed = $h->step('profiles', 'get', 'A shows email marketing consent after subscribe (poll)', fn(APIClient $c) => $h->waitFor(function () use ($c, $a) {
        $p = $c->profiles->get($a->id, (new Query())->additionalFields('profile', 'subscriptions'));
        $consent = $p->subscriptions->email->marketing->consent ?? ($p->subscriptions['email']['marketing']['consent'] ?? null);
        return $consent === 'SUBSCRIBED' ? $p : null;
    }, 420, 15, 'email consent SUBSCRIBED (subscription jobs take minutes)'));

    $h->step('profiles', 'unsubscribe', 'bulk unsubscribe A (email) from list', function (APIClient $c) use ($a, $emailA, $list) {
        $job = new SubscriptionDeleteJob([
            new SubscribeProfile(new SubscriptionChannels(email: new EmailSubscription(new MarketingConsent('UNSUBSCRIBED'))), email: $emailA),
        ]);
        $job->addRelationship('list', new Relationship(new KlaviyoList($list->id)));
        return $c->profiles->unsubscribe($job);
    }, fn($r) => $h->assert($r === null, '202 void'));

    // ── suppressions
    $h->step('profiles', 'suppress', 'suppress B globally', fn(APIClient $c) => $c->profiles->suppress(new SuppressionCreateJob([$emailB])),
        fn($r) => $h->assert($r === null || $r instanceof \nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob, 'job or void'));
    $h->step('profiles', 'suppress', 'suppress whole list (list_id only: API forbids combining profiles with a list/segment)', fn(APIClient $c) => $c->profiles->suppress(new SuppressionCreateJob([], listId: $list->id)));
    $suppJobs = $h->step('profiles', 'getSuppressJobs', 'list suppression jobs w/ fields + filter equals(status,complete) (no page[size] on this endpoint)', fn(APIClient $c) => $c->profiles->getSuppressJobs((new Query())->fields('profile-suppression-bulk-create-job', 'status', 'created_at', 'total_count')->filter(Filter::equals('status', 'complete'))),
        fn($r) => $h->assert(count($r['data']) >= 1, 'at least one job'));
    if ($suppJobs && $suppJobs['data']) {
        $h->step('profiles', 'getSuppressJob', 'get suppression job by id', fn(APIClient $c) => $c->profiles->getSuppressJob($suppJobs['data'][0]->id, (new Query())->fields('profile-suppression-bulk-create-job', 'status')),
            fn($r) => $h->assert($r && $r->id === $suppJobs['data'][0]->id, 'job'));
    }
    $h->step('profiles', 'getSuppressJob', 'unknown suppression job → null', fn(APIClient $c) => $c->profiles->getSuppressJob('NOPE01'), fn($r) => $h->assert($r === null, 'null'));
    $h->step('profiles', 'unsuppress', 'unsuppress B globally', fn(APIClient $c) => $c->profiles->unsuppress(new SuppressionDeleteJob([$emailB])));
    $h->step('profiles', 'unsuppress', 'unsuppress whole list (list_id only)', fn(APIClient $c) => $c->profiles->unsuppress(new SuppressionDeleteJob([], listId: $list->id)));
    $unsJobs = $h->step('profiles', 'getUnsuppressJobs', 'list unsuppression jobs', fn(APIClient $c) => $c->profiles->getUnsuppressJobs((new Query())->fields('profile-suppression-bulk-delete-job', 'status', 'created_at')),
        fn($r) => $h->assert(count($r['data']) >= 1, 'at least one'));
    if ($unsJobs && $unsJobs['data']) {
        $h->step('profiles', 'getUnsuppressJob', 'get unsuppression job', fn(APIClient $c) => $c->profiles->getUnsuppressJob($unsJobs['data'][0]->id),
            fn($r) => $h->assert($r && $r->id === $unsJobs['data'][0]->id, 'job'));
    }

    // ── bulk import
    $bulkJob = $h->step('profiles', 'bulkImport', 'bulk import 3 profiles (one existing by email, two new) into list', function (APIClient $c) use ($h, $emailA, $list) {
        $ps = [];
        foreach (['a' => $emailA, 'd' => $h->email('d'), 'e' => $h->email('e')] as $k => $email) {
            $p = new ImportProfile();
            $p->email = $email;
            $p->first_name = 'Bulk' . strtoupper($k);
            $p->properties = ['bulk' => true];
            $ps[] = $p;
        }
        $job = new BulkImportJob($ps);
        $job->addRelationship('lists', new Relationship([new KlaviyoList($list->id)]));
        return $c->profiles->bulkImport($job);
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\BulkImportJob && $r->id && in_array($r->status, ['queued', 'processing', 'complete'], true), 'job resource w/ status'));

    if ($bulkJob) {
        $h->step('profiles', 'getBulkImportJob', 'poll bulk import job until complete', fn(APIClient $c) => $h->waitFor(function () use ($c, $bulkJob) {
            $j = $c->profiles->getBulkImportJob($bulkJob->id, (new Query())->fields('profile-bulk-import-job', 'status', 'completed_count', 'failed_count', 'total_count')->include('lists'));
            return $j && $j->status === 'complete' ? $j : null;
        }, 240, 5, 'bulk import complete'), fn($r) => $h->assert($r->total_count === 3 && $r->failed_count === 0, '3 processed, 0 failed'));
        $h->step('profiles', 'getBulkImportJobs', 'list bulk import jobs w/ filter status + sort + page[size]', fn(APIClient $c) => $c->profiles->getBulkImportJobs((new Query())->filter(Filter::equals('status', 'complete'))->sort('created_at', descending: true)->pageSize(10)->fields('profile-bulk-import-job', 'status', 'created_at')),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
        $h->step('profiles', 'getBulkImportJobErrors', 'import errors for job', fn(APIClient $c) => $c->profiles->getBulkImportJobErrors($bulkJob->id, (new Query())->fields('import-error', 'code', 'detail')->pageSize(10)),
            fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no errors'));
        $h->step('profiles', 'getBulkImportJobLists', 'lists for job', fn(APIClient $c) => $c->profiles->getBulkImportJobLists($bulkJob->id, (new Query())->fields('list', 'name')),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $list->id, 'our list'));
        $h->step('profiles', 'getBulkImportJobListIds', 'list ids for job', fn(APIClient $c) => $c->profiles->getBulkImportJobListIds($bulkJob->id),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $list->id, 'our list id'));
        $h->step('profiles', 'getBulkImportJobProfiles', 'profiles for job w/ fields + page[size]=2 + next', function (APIClient $c) use ($h, $bulkJob) {
            $p1 = $c->profiles->getBulkImportJobProfiles($bulkJob->id, (new Query())->fields('profile', 'email')->additionalFields('profile', 'subscriptions')->pageSize(2));
            $h->assert(count($p1['data']) === 2 && $p1['links']?->next, '2 + next');
            $p2 = $c->profiles->getBulkImportJobProfiles($bulkJob->id, next: $p1['links']->next);
            $h->assert(count($p2['data']) === 1, 'last page has 1');
            return $p2;
        });
        $h->step('profiles', 'getBulkImportJobProfileIds', 'profile ids for job', fn(APIClient $c) => $c->profiles->getBulkImportJobProfileIds($bulkJob->id, (new Query())->pageSize(10)),
            fn($r) => $h->assert(count($r['data']) === 3, '3 ids'));
    }
    $h->step('profiles', 'getBulkImportJob', 'unknown bulk import job → null', fn(APIClient $c) => $c->profiles->getBulkImportJob('NOPE01'), fn($r) => $h->assert($r === null, 'null'));

    // ── merge: D into A (D created by bulk import)
    $d = $h->step('profiles', 'list', 'find D for merge', fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::equals('email', $h->email('d')))),
        fn($r) => $h->assert(count($r['data']) === 1, 'D exists'));
    if ($d && $d['data']) {
        $h->step('profiles', 'merge', 'merge D into A', fn(APIClient $c) => $c->profiles->merge(new ProfileMerge($a->id, [$d['data'][0]->id])),
            fn($r) => $h->assert($r instanceof Profile && $r->id === $a->id, 'destination returned'));
        $h->step('profiles', 'get', 'D after merge → null or resolves to A (Klaviyo aliases merged ids for a while)', fn(APIClient $c) => $c->profiles->get($d['data'][0]->id),
            function ($r) use ($h, $a, $d) {
                $h->assert($r === null || $r->id === $a->id || $r->id === $d['data'][0]->id, 'source gone, aliased, or still being merged');
                if ($r !== null) {
                    $h->note('Merged source profile ' . $d['data'][0]->id . ' still answers ' . $r->id . ' right after the merge (merge is asynchronous).');
                }
            });
    }

    // ── pooled requests: returnRequest + executePool / executePoolLazy
    $h->step('profiles', 'executePool', 'executePool: 3 GETs (one 404) via returnRequest', function (APIClient $c) use ($h, $a, $bId) {
        $reqs = [
            $c->profiles->get($a->id, returnRequest: true),
            $c->profiles->get('NOPE01', returnRequest: true),
            $c->profiles->get($bId, (new Query())->fields('profile', 'email'), returnRequest: true),
        ];
        $res = $c->executePool($reqs, 3);
        $h->assert(count($res) === 3, '3 results');
        $h->assert($res[0] instanceof Profile && $res[0]->id === $a->id, 'first ok');
        $h->assert($res[1] instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $res[1]->getHttpStatus() === 404, 'second is 404 exception');
        $h->assert($res[2] instanceof Profile && $res[2]->id === $bId, 'third ok');
        return $res;
    });
    $h->step('profiles', 'executePoolLazy', 'executePoolLazy: PATCH A and B concurrently', function (APIClient $c) use ($h, $a, $bId) {
        $res = $c->executePoolLazy([$a->id, $bId], function (string $id) use ($c) {
            $p = new PatchProfile($id);
            $p->properties = ['pooled' => true];
            return $c->profiles->update($p, returnRequest: true);
        }, 2);
        APIClient::assertNoExceptions($res);
        $h->assert(count($res) === 2 && $res[0] instanceof Profile && ($res[0]->properties['pooled'] ?? null) === true, 'both patched');
        return $res;
    });

    // ── data privacy deletion for the throwaway profiles (B, C, E). A is deleted in cleanup.
    foreach (array_filter([$bId, null]) as $id) {
        $h->step('profiles', 'deleteProfile', 'data-privacy deletion job for B by id', fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($id))), fn($r) => $h->assert($r === null, 'void'));
    }
    foreach (['c', 'e'] as $k) {
        $h->step('profiles', 'deleteProfile', "data-privacy deletion job for {$k} by email", function (APIClient $c) use ($h, $k) {
            $p = new Profile();
            $p->email = $h->email($k);
            try {
                return $c->profiles->deleteProfile(new DataPrivacyDeletionJob($p));
            } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) {
                if ($e->getHttpStatus() === 404 && $k === 'c') {
                    $h->note('Profile C (bulk-subscribed without historical_import, no list) never came into existence; nothing to delete.');
                    return 'not-created';
                }
                throw $e;
            }
        }, fn($r) => $h->assert($r === null || $r === 'not-created', 'void or never created'));
    }
    $h->mutation('Requested data-privacy deletion for profiles ' . implode(', ', [$emailA, $emailB, $emailC, $h->email('e')]) . ' (async on Klaviyo side; D merged into A).');
    $h->mutation("Suppression/unsuppression jobs run for {$emailA} (list-scoped) and {$emailB} (global); net effect none.");
};
