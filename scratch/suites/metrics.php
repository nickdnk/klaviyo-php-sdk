<?php
/**
 * EventService, MetricService, MetricPropertyService, CustomMetricService, MappedMetricService, ReportingService.
 * Events are tracked for profiles created here; the resulting metric is account-permanent (metrics cannot be deleted).
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEvents;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEventsJob;
use nickdnk\Klaviyo\Resources\Request\CampaignValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\CreateCustomMetric;
use nickdnk\Klaviyo\Resources\Request\CreateEvent;
use nickdnk\Klaviyo\Resources\Request\CreateEventForProfile;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\FlowSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FlowValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FormSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FormValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\Metric as MetricRequest;
use nickdnk\Klaviyo\Resources\Request\MetricAggregateQuery;
use nickdnk\Klaviyo\Resources\Request\SegmentSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\SegmentValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\UpdateCustomMetric;
use nickdnk\Klaviyo\Resources\Request\UpdateMappedMetric;
use nickdnk\Klaviyo\Resources\Response\CustomMetric;
use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Response\MappedMetric;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\MetricAggregate;
use nickdnk\Klaviyo\Resources\Response\MetricProperty;
use nickdnk\Klaviyo\Resources\Response\Profile;
use Smoke\Harness;

return function (Harness $h): void {

    $metricName = 'SDK Smoke'; // fixed name: metrics are permanent, one shared metric across runs
    $emailE = $h->email('ev');
    $emailF = $h->email('ev2');
    $h->mutation("Events tracked on permanent metric \"{$metricName}\" (metrics cannot be deleted; earlier runs also left SDK Smoke <runId> metrics).");

    // ── events.create: three events with every attribute the schema offers
    $uniq = $h->name('evt-1');
    foreach ([
        ['unique_id' => $uniq, 'value' => 12.5, 'time' => (new DateTimeImmutable('-2 hours'))->format(DATE_ATOM), 'props' => ['sku' => 'A1', 'qty' => 2, 'tags' => ['x']]],
        ['unique_id' => $h->name('evt-2'), 'value' => 7.25, 'time' => (new DateTimeImmutable('-1 day'))->format(DATE_ATOM), 'props' => ['sku' => 'B2', 'qty' => 1]],
        ['unique_id' => $h->name('evt-3'), 'value' => null, 'time' => null, 'props' => ['sku' => 'C3']],
    ] as $i => $spec) {
        $h->step('events', 'create', "create event #{$i} (metric, profile by email w/ attributes, properties, unique_id, value, value_currency, time)", function (APIClient $c) use ($spec, $emailE, $metricName) {
            $profile = new ImportProfile();
            $profile->email = $emailE;
            $profile->first_name = 'Eve';
            $profile->properties = ['source' => 'events'];
            $metric = new MetricRequest($metricName);
            $metric->service = 'sdk-smoke';
            $e = new CreateEventForProfile($metric, $profile, $spec['props']);
            $e->unique_id = $spec['unique_id'];
            if ($spec['value'] !== null) {
                $e->value = $spec['value'];
                $e->value_currency = 'EUR';
            }
            if ($spec['time'] !== null) {
                $e->time = $spec['time'];
            }
            return $c->events->create($e);
        }, fn($r) => $h->assert($r === null, '202 void'));
    }
    $h->step('events', 'create', 'create event with duplicate unique_id (idempotent, still 202)', function (APIClient $c) use ($uniq, $emailE, $metricName) {
        $profile = new ImportProfile();
        $profile->email = $emailE;
        $e = new CreateEventForProfile(new MetricRequest($metricName), $profile, ['sku' => 'A1', 'dup' => true]);
        $e->unique_id = $uniq;
        return $c->events->create($e);
    });

    // ── events.bulkCreate
    $h->step('events', 'bulkCreate', 'bulk create: 1 profile × 2 events', function (APIClient $c) use ($h, $emailF, $metricName) {
        $profile = new CreateProfile();
        $profile->email = $emailF;
        $profile->first_name = 'Fay';
        $e1 = new CreateEvent(new MetricRequest($metricName), ['sku' => 'D4', 'bulk' => true]);
        $e1->value = 3.0;
        $e1->value_currency = 'EUR';
        $e1->unique_id = $h->name('bulk-1');
        $e2 = new CreateEvent(new MetricRequest($metricName), ['sku' => 'E5', 'bulk' => true]);
        $e2->time = (new DateTimeImmutable('-3 hours'))->format(DATE_ATOM);
        return $c->events->bulkCreate(new BulkCreateEventsJob([new BulkCreateEvents($profile, [$e1, $e2])]));
    }, fn($r) => $h->assert($r === null, '202 void'));

    // ── find the metric (async). metrics.list has no name filter; walk pages (filter integration.name=API narrows it).
    /** @var Metric|null $metric */
    $metric = $h->step('metrics', 'list', 'list metrics w/ filter equals(integration.name,"API") + fields + include(flow-triggers), paginate until ours appears (poll)', fn(APIClient $c) => $h->waitFor(function () use ($c, $metricName) {
        $next = null;
        $pages = 0;
        do {
            $page = $next
                ? $c->metrics->list(next: $next)
                : $c->metrics->list((new Query())->filter(Filter::equals('integration.name', 'API'))->fields('metric', 'name', 'created', 'updated', 'integration')->include('flow-triggers'));
            foreach ($page['data'] as $m) {
                if ($m->name === $metricName) {
                    return $m;
                }
            }
            $next = $page['links']?->next;
        } while ($next && ++$pages < 20);
        return null;
    }, 300, 10, 'metric to appear'), fn($r) => $h->assert($r instanceof Metric && $r->id && is_array($r->integration) && ($r->integration['name'] ?? null) === 'API', 'hydrated metric w/ integration'));

    $h->step('metrics', 'list', 'list metrics w/ filter equals(integration.category,"API")', fn(APIClient $c) => $c->metrics->list((new Query())->filter(Filter::equals('integration.category', 'API'))->fields('metric', 'name')),
        fn($r) => $h->assert(is_array($r['data']), 'array (Klaviyo returns 0 rows for category "API" although the metric reports integration.category=API — see notes)'));
    $h->step('metrics', 'list', 'list metrics w/ page[cursor] from links.next', function (APIClient $c) use ($h) {
        $p1 = $c->metrics->list((new Query())->fields('metric', 'name'));
        if (!$p1['links']?->next) {
            $h->note('metrics.list fits in one page on this account; cursor pagination not observable.');
            return $p1;
        }
        return $c->metrics->list((new Query())->cursor($p1['links']->next));
    });

    $profileE = $h->step('profiles', 'list', 'find profile E by email', fn(APIClient $c) => $c->profiles->list((new Query())->filter(Filter::equals('email', $emailE))), fn($r) => $h->assert(count($r['data']) === 1, 'E exists'));
    $eId = $profileE['data'][0]->id ?? null;
    $profileF = $h->step('profiles', 'list', 'find profile F (bulk) by email (poll)', fn(APIClient $c) => $h->waitFor(function () use ($c, $emailF) {
        $r = $c->profiles->list((new Query())->filter(Filter::equals('email', $emailF)));
        return count($r['data']) === 1 ? $r : null;
    }, 180, 10, 'bulk profile'));
    $fId = $profileF['data'][0]->id ?? null;
    foreach (array_filter([$eId, $fId]) as $pid) {
        $h->cleanup('profiles', 'deleteProfile', "data-privacy deletion {$pid}", fn(APIClient $c) => $c->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile($pid))));
    }
    $h->mutation("Data-privacy deletion requested for {$emailE}, {$emailF}.");

    if (!$metric) {
        $h->note('Metric never appeared; metric/event/report steps below will fail or be skipped.');
    }
    $mid = $metric?->id ?? 'NOPE01';

    // ── metrics.*
    $h->step('metrics', 'get', 'get metric w/ fields + include(flow-triggers)', fn(APIClient $c) => $c->metrics->get($mid, (new Query())->fields('metric', 'name', 'integration')->fields('flow', 'name')->include('flow-triggers')),
        fn($r) => $h->assert($r instanceof Metric && $r->name === $metricName, 'metric'));
    $h->step('metrics', 'get', 'unknown metric → null', fn(APIClient $c) => $c->metrics->get('NOPE01'), fn($r) => $h->assert($r === null, 'null'));
    $h->step('metrics', 'flowTriggers', 'flows triggered by metric', fn(APIClient $c) => $c->metrics->flowTriggers($mid, (new Query())->fields('flow', 'name', 'status')), fn($r) => $h->assert(is_array($r['data']), 'array'));
    $h->step('metrics', 'flowTriggerIds', 'flow ids triggered by metric', fn(APIClient $c) => $c->metrics->flowTriggerIds($mid), fn($r) => $h->assert(is_array($r['data']), 'array'));

    $props = $h->step('metrics', 'properties', 'metric properties w/ fields + additional-fields(sample_values) (poll until indexed)', fn(APIClient $c) => $h->waitFor(function () use ($c, $mid) {
        $r = $c->metrics->properties($mid, (new Query())->fields('metric-property', 'label', 'property', 'inferred_type')->additionalFields('metric-property', 'sample_values'));
        return count($r['data']) >= 1 ? $r : null;
    }, 240, 15, 'metric properties'), fn($r) => $h->assert($r['data'][0] instanceof MetricProperty && $r['data'][0]->property !== null, 'metric property hydrated'));
    $h->step('metrics', 'propertyIds', 'metric property ids', fn(APIClient $c) => $c->metrics->propertyIds($mid), fn($r) => $h->assert(is_array($r['data']) && count($r['data']) >= 1, 'ids'));
    $propId = $props['data'][0]->id ?? null;
    if ($propId) {
        $h->step('metricProperties', 'get', 'get metric property w/ fields + additional-fields + include(metric)', fn(APIClient $c) => $c->metricProperties->get($propId, (new Query())->fields('metric-property', 'label', 'property', 'inferred_type')->additionalFields('metric-property', 'sample_values')->fields('metric', 'name')->include('metric')),
            fn($r) => $h->assert($r instanceof MetricProperty && $r->id === $propId && $r->getRelationship('metric') !== null, 'property + metric relationship'));
        $h->step('metricProperties', 'metric', 'metric for metric property', fn(APIClient $c) => $c->metricProperties->metric($propId, (new Query())->fields('metric', 'name')), fn($r) => $h->assert($r instanceof Metric && $r->id === $mid, 'our metric'));
        $h->step('metricProperties', 'metricId', 'metric id for metric property', fn(APIClient $c) => $c->metricProperties->metricId($propId), fn($r) => $h->assert($r instanceof Metric && $r->id === $mid, 'id'));
    } else {
        foreach (['get', 'metric', 'metricId'] as $m) {
            $h->skip('metricProperties', $m, $m, 'no metric property id available');
        }
    }
    $h->step('metricProperties', 'get', 'unknown metric property → null', fn(APIClient $c) => $c->metricProperties->get('NOPE01'), fn($r) => $h->assert($r === null, 'null'));

    // ── events.list / get / relationships
    $events = $h->step('events', 'list', 'list events w/ filter equals(metric_id) + fields[event,metric,profile] + include(metric,profile) + sort=-datetime + page[size]=2 (poll)', fn(APIClient $c) => $h->waitFor(function () use ($c, $mid) {
        $r = $c->events->list((new Query())
            ->filter(Filter::equals('metric_id', $mid))
            ->fields('event', 'timestamp', 'datetime', 'event_properties', 'uuid')
            ->fields('metric', 'name')
            ->fields('profile', 'email')
            ->include('metric', 'profile')
            ->sort('datetime', descending: true)
            ->pageSize(2));
        return count($r['data']) >= 2 ? $r : null;
    }, 240, 10, 'events indexed'), fn($r) => $h->assert($r['data'][0] instanceof Event && $r['data'][0]->datetime !== null && $r['data'][0]->getRelationship('metric')?->data?->id === $mid && $r['data'][0]->getRelationship('profile') !== null, 'events hydrated with includes'));

    if ($events) {
        $h->step('events', 'list', 'events page 2 via next', fn(APIClient $c) => $c->events->list(next: $events['links']->next), fn($r) => $h->assert(count($r['data']) >= 1, 'more events'));
        $h->step('events', 'list', 'list events w/ filter equals(profile_id) AND greater-than(datetime) + sort=timestamp (poll: back-dated events index later)', fn(APIClient $c) => $h->waitFor(function () use ($c, $eId) {
            $r = $c->events->list((new Query())->filter(Filter::all(Filter::equals('profile_id', $eId), Filter::greaterThan('datetime', new DateTimeImmutable('-2 days'))))->sort('timestamp'));
            return count($r['data']) >= 3 ? $r : null;
        }, 180, 10, 'all 3 distinct events of E'), fn($r) => $h->assert(count($r['data']) === 3, 'E has exactly 3 events (dup unique_id collapsed)'));
        $h->step('events', 'list', 'list events w/ filter greater-or-equal(timestamp,unix) + less-than(timestamp,unix)', fn(APIClient $c) => $c->events->list((new Query())->filter(Filter::all(Filter::equals('metric_id', $mid), Filter::greaterOrEqual('timestamp', time() - 3 * 86400), Filter::lessThan('timestamp', time() + 60)))),
            fn($r) => $h->assert(count($r['data']) >= 1, 'timestamp range'));
        /** @var Event $ev */
        $ev = $events['data'][0];
        $h->step('events', 'get', 'get event w/ fields + include(metric,profile,attributions)', fn(APIClient $c) => $c->events->get($ev->id, (new Query())->fields('event', 'event_properties', 'datetime')->fields('metric', 'name')->fields('profile', 'email')->include('metric', 'profile', 'attributions')),
            fn($r) => $h->assert($r instanceof Event && $r->id === $ev->id && isset($r->event_properties), 'event w/ properties (AttributeBag)'));
        $h->step('events', 'get', 'malformed event id → Klaviyo answers 400 (not 404), surfaced as ClientException', fn(APIClient $c) => (function () use ($c) {
        try { return $c->events->get('NOPE01'); } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) { return $e; }
    })(), fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $r->getHttpStatus() === 400, '400 for malformed id'));
        $h->step('events', 'profile', 'profile for event w/ fields + additional-fields', fn(APIClient $c) => $c->events->profile($ev->id, (new Query())->fields('profile', 'email')->additionalFields('profile', 'subscriptions')),
            fn($r) => $h->assert($r instanceof Profile && in_array($r->email, [$emailE, $emailF], true), 'our profile'));
        $h->step('events', 'profileId', 'profile id for event', fn(APIClient $c) => $c->events->profileId($ev->id), fn($r) => $h->assert($r instanceof Profile && $r->id, 'id'));
        $h->step('events', 'metric', 'metric for event w/ fields', fn(APIClient $c) => $c->events->metric($ev->id, (new Query())->fields('metric', 'name')), fn($r) => $h->assert($r instanceof Metric && $r->id === $mid, 'metric'));
        $h->step('events', 'metricId', 'metric id for event', fn(APIClient $c) => $c->events->metricId($ev->id), fn($r) => $h->assert($r instanceof Metric && $r->id === $mid, 'id'));
        $h->step('events', 'executePool', 'executePool: get 2 events concurrently', function (APIClient $c) use ($h, $events) {
            $res = $c->executePool(array_map(fn(Event $e) => $c->events->get($e->id, returnRequest: true), $events['data']));
            APIClient::assertNoExceptions($res);
            $h->assert(count($res) === 2 && $res[1] instanceof Event, 'pooled');
            return $res;
        });
    }

    // ── metrics.aggregates
    $h->step('metrics', 'aggregates', 'aggregate count+sum_value+unique by day over 3 days, timezone, page_size=500 (API minimum), fields (sort omitted: upstream 400/500)', function (APIClient $c) use ($mid) {
        $q = new MetricAggregateQuery($mid, ['count', 'sum_value', 'unique']);
        $q->interval = 'day';
        $q->filter = [
            'greater-or-equal(datetime,' . (new DateTimeImmutable('-3 days'))->format('Y-m-d\TH:i:s') . ')',
            'less-than(datetime,' . (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i:s') . ')',
        ];
        $q->timezone = 'Europe/Copenhagen';
        // no `sort`: Klaviyo answers 400 "unsupported operand type(s) for +" (or 500 with `by`) whenever sort is set — see notes
        $q->page_size = 500; // API minimum is 500
        return $c->metrics->aggregates($q, (new Query())->fields('metric-aggregate', 'data', 'dates'));
    }, function ($r) use ($h) {
        $h->assert($r instanceof MetricAggregate && is_array($r->dates) && is_array($r->data) && isset($r->data[0]['measurements']['count']), 'aggregate shape: dates + data[].measurements.count');
        if (array_sum($r->data[0]['measurements']['count']) < 1) {
            $h->note('Aggregate counts were still 0 seconds after the events were tracked; the aggregates index lags behind /api/events.');
        }
    });
    $h->step('metrics', 'aggregates', 'aggregate hourly grouped by $attributed_channel w/ return_fields', function (APIClient $c) use ($mid) {
        $q = new MetricAggregateQuery($mid, ['count']);
        $q->interval = 'hour';
        $q->by = ['$attributed_channel'];
        $q->return_fields = ['count'];
        $q->filter = [
            'greater-or-equal(datetime,' . (new DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i:s') . ')',
            'less-than(datetime,' . (new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i:s') . ')',
        ];
        return $c->metrics->aggregates($q);
    }, fn($r) => $h->assert($r instanceof MetricAggregate, 'aggregate'));

    // ── custom metrics
    /** @var CustomMetric|null $cm */
    $cm = $h->step('customMetrics', 'create', 'create custom metric (value aggregation, metric group w/ filters + value_property)', fn(APIClient $c) => $c->customMetrics->create(new CreateCustomMetric($h->name('custom'), 'value', [[
        'metric_id' => $mid,
        'metric_filters' => [['property' => 'sku', 'filter' => ['type' => 'string', 'operator' => 'starts-with', 'value' => 'A']]],
        'value_property' => '$value',
    ]]), (new Query())->fields('custom-metric', 'name', 'definition')),
        fn($r) => $h->assert($r instanceof CustomMetric && $r->id && $r->name === $h->name('custom') && is_array($r->definition), 'custom metric'));
    if ($cm) {
        $h->cleanup('customMetrics', 'delete', 'custom metric', fn(APIClient $c) => $c->customMetrics->delete($cm->id));
        $h->step('customMetrics', 'get', 'get custom metric w/ fields + include(metrics)', fn(APIClient $c) => $c->customMetrics->get($cm->id, (new Query())->fields('custom-metric', 'name', 'definition', 'created', 'updated')->fields('metric', 'name')->include('metrics')),
            fn($r) => $h->assert($r instanceof CustomMetric && $r->id === $cm->id && $r->getRelationship('metrics') !== null, 'w/ metrics relationship'));
        $h->step('customMetrics', 'list', 'list custom metrics w/ fields + include(metrics) (no paging params allowed)', fn(APIClient $c) => $c->customMetrics->list((new Query())->fields('custom-metric', 'name')->fields('metric', 'name')->include('metrics')),
            fn($r) => $h->assert(in_array($cm->id, array_map(fn($x) => $x->id, $r['data']), true), 'ours listed'));
        $h->step('customMetrics', 'update', 'update name + definition (count aggregation)', function (APIClient $c) use ($h, $cm, $mid) {
            $u = new UpdateCustomMetric($cm->id);
            $u->name = $h->name('custom-renamed');
            $u->definition = ['aggregation_method' => 'count', 'metric_groups' => [['metric_id' => $mid, 'metric_filters' => [], 'value_property' => null]]];
            return $c->customMetrics->update($u, (new Query())->fields('custom-metric', 'name', 'definition'));
        }, fn($r) => $h->assert($r instanceof CustomMetric && $r->name === $h->name('custom-renamed') && ($r->definition['aggregation_method'] ?? null) === 'count', 'updated'));
        $h->step('customMetrics', 'metrics', 'metrics for custom metric w/ fields', fn(APIClient $c) => $c->customMetrics->metrics($cm->id, (new Query())->fields('metric', 'name')), fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $mid, 'source metric'));
        $h->step('customMetrics', 'metricIds', 'metric ids for custom metric', fn(APIClient $c) => $c->customMetrics->metricIds($cm->id), fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $mid, 'ids'));
    }
    $h->step('customMetrics', 'get', 'unknown custom metric → null', fn(APIClient $c) => $c->customMetrics->get('NOPE01'), fn($r) => $h->assert($r === null, 'null'));

    // ── mapped metrics (account-level mapping slots such as "revenue"; we point one at our metric and restore it)
    $mapped = $h->step('mappedMetrics', 'list', 'list mapped metrics w/ fields + include(metric,custom-metric)', fn(APIClient $c) => $c->mappedMetrics->list((new Query())->fields('mapped-metric', 'id', 'updated')->fields('metric', 'name')->fields('custom-metric', 'name')->include('metric', 'custom-metric')),
        fn($r) => $h->assert(count($r['data']) >= 1 && $r['data'][0] instanceof MappedMetric, 'mapped metric slots'));
    /** @var MappedMetric|null $slot */
    $slot = null;
    foreach ($mapped['data'] ?? [] as $m) {
        if ($m->id === 'revenue') {
            $slot = $m;
        }
    }
    $slot ??= $mapped['data'][0] ?? null;
    if ($slot) {
        $prevMetric = $slot->getRelationship('metric')?->data?->id;
        $prevCustom = $slot->getRelationship('custom-metric')?->data?->id;
        $h->step('mappedMetrics', 'get', "get mapped metric \"{$slot->id}\" w/ fields + include", fn(APIClient $c) => $c->mappedMetrics->get($slot->id, (new Query())->fields('mapped-metric', 'updated')->include('metric', 'custom-metric')),
            fn($r) => $h->assert($r instanceof MappedMetric && $r->id === $slot->id, 'slot'));
        $remapped = $h->step('mappedMetrics', 'update', "point \"{$slot->id}\" at our metric (Klaviyo allows 2 updates per slot per day)", fn(APIClient $c) => $c->mappedMetrics->update(new UpdateMappedMetric($slot->id, metricId: $mid), (new Query())->fields('mapped-metric', 'updated')),
            fn($r) => $h->assert($r instanceof MappedMetric && $r->getRelationship('metric')?->data?->id === $mid, 'remapped'));
        $expectMetric = $remapped ? $mid : $prevMetric;
        $h->step('mappedMetrics', 'metric', 'metric for mapped metric', fn(APIClient $c) => $c->mappedMetrics->metric($slot->id, (new Query())->fields('metric', 'name')), fn($r) => $h->assert(($r?->id ?? null) === $expectMetric, 'expected metric ' . ($expectMetric ?? 'null')));
        $h->step('mappedMetrics', 'metricId', 'metric id for mapped metric', fn(APIClient $c) => $c->mappedMetrics->metricId($slot->id), fn($r) => $h->assert(($r?->id ?? null) === $expectMetric, 'id'));
        if ($cm) {
            $h->step('mappedMetrics', 'update', "point \"{$slot->id}\" at our custom metric", fn(APIClient $c) => $c->mappedMetrics->update(new UpdateMappedMetric($slot->id, customMetricId: $cm->id)),
                fn($r) => $h->assert($r instanceof MappedMetric, 'remapped to custom'));
        }
        $h->step('mappedMetrics', 'customMetric', 'custom metric for mapped metric', fn(APIClient $c) => $c->mappedMetrics->customMetric($slot->id, (new Query())->fields('custom-metric', 'name')));
        $h->step('mappedMetrics', 'customMetricId', 'custom metric id for mapped metric', fn(APIClient $c) => $c->mappedMetrics->customMetricId($slot->id));
        $h->cleanup('mappedMetrics', 'update', "restore \"{$slot->id}\" mapping (metric=" . ($prevMetric ?? 'null') . ', custom=' . ($prevCustom ?? 'null') . ')', function (APIClient $c) use ($slot, $prevMetric, $prevCustom) {
            $now = $c->mappedMetrics->get($slot->id, (new Query())->include('metric', 'custom-metric'));
            if (($now->getRelationship('metric')?->ids() ?? []) === array_filter([$prevMetric]) && ($now->getRelationship('custom-metric')?->ids() ?? []) === array_filter([$prevCustom])) {
                return 'already-restored';
            }
            if ($prevMetric === null && $prevCustom === null) {
                return $c->mappedMetrics->update(UpdateMappedMetric::unset($slot->id));
            }
            return $c->mappedMetrics->update(new UpdateMappedMetric($slot->id, metricId: $prevMetric, customMetricId: $prevCustom));
        });
        $h->mutation("Mapped metric \"{$slot->id}\" temporarily remapped; cleanup restores metric=" . ($prevMetric ?? 'null') . ', custom-metric=' . ($prevCustom ?? 'null') . '. Verify in Klaviyo → Analytics → Metrics mapping.');
    }
    $h->step('mappedMetrics', 'get', 'unknown mapped metric id → Klaviyo answers 400 (id is an enum), surfaced as ClientException', fn(APIClient $c) => (function () use ($c) {
        try { return $c->mappedMetrics->get('nope'); } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) { return $e; }
    })(), fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $r->getHttpStatus() === 400, '400'));

    // ── reporting (account has one sent campaign and no flows/forms; results may be empty but the calls must work)
    $h->step('reports', 'campaignValues', 'campaign values: opens/open_rate/recipients/conversions, last_30_days, group_by campaign_id+campaign_message_id+send_channel, fields', function (APIClient $c) use ($mid) {
        $r = new CampaignValuesReportQuery(['opens', 'open_rate', 'recipients', 'conversions', 'conversion_value'], ['key' => 'last_30_days'], $mid);
        $r->group_by = ['campaign_id', 'campaign_message_id', 'send_channel'];
        return $c->reports->campaignValues($r, (new Query())->fields('campaign-values-report', 'results'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CampaignValuesReport && is_array($r->results), 'results array'));
    $h->step('reports', 'campaignValues', 'campaign values: custom timeframe + filter equals(send_channel,"email") + page_cursor null', function (APIClient $c) use ($mid) {
        $r = new CampaignValuesReportQuery(['delivered', 'clicks'], ['start' => (new DateTimeImmutable('-60 days'))->format(DATE_ATOM), 'end' => (new DateTimeImmutable('now'))->format(DATE_ATOM)], $mid);
        $r->filter = 'equals(send_channel,"email")';
        return $c->reports->campaignValues($r, null, null);
    });
    $h->step('reports', 'flowValues', 'flow values last_7_days grouped by flow_id+flow_message_id (both required)', function (APIClient $c) use ($mid) {
        $r = new FlowValuesReportQuery(['opens', 'recipients'], ['key' => 'last_7_days'], $mid);
        $r->group_by = ['flow_id', 'flow_message_id'];
        return $c->reports->flowValues($r, (new Query())->fields('flow-values-report', 'results'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\FlowValuesReport && is_array($r->results), 'results'));
    $h->step('reports', 'flowSeries', 'flow series daily last_7_days grouped by flow_id+flow_message_id', function (APIClient $c) use ($mid) {
        $r = new FlowSeriesReportQuery(['opens'], ['key' => 'last_7_days'], 'daily', $mid);
        $r->group_by = ['flow_id', 'flow_message_id'];
        return $c->reports->flowSeries($r);
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\FlowSeriesReport && is_array($r->results), 'results'));
    $h->step('reports', 'formValues', 'form values last_30_days grouped by form_id', function (APIClient $c) {
        $r = new FormValuesReportQuery(['viewed_form', 'submits', 'submit_rate'], ['key' => 'last_30_days']);
        $r->group_by = ['form_id'];
        return $c->reports->formValues($r, (new Query())->fields('form-values-report', 'results'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\FormValuesReport && is_array($r->results), 'results'));
    $h->step('reports', 'formSeries', 'form series weekly last_30_days', function (APIClient $c) {
        return $c->reports->formSeries(new FormSeriesReportQuery(['viewed_form'], ['key' => 'last_30_days'], 'weekly'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\FormSeriesReport && is_array($r->results), 'results'));
    $h->step('reports', 'segmentValues', 'segment values last_30_days', function (APIClient $c) {
        return $c->reports->segmentValues(new SegmentValuesReportQuery(['total_members', 'members_added'], ['key' => 'last_30_days']), (new Query())->fields('segment-values-report', 'results'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\SegmentValuesReport && is_array($r->results), 'results'));
    $h->step('reports', 'segmentSeries', 'segment series daily last_30_days', function (APIClient $c) {
        return $c->reports->segmentSeries(new SegmentSeriesReportQuery(['total_members', 'net_members_changed'], ['key' => 'last_30_days'], 'daily'));
    }, fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\SegmentSeriesReport && is_array($r->results), 'results'));
};
