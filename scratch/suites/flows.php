<?php
/**
 * FlowService + FlowActionService + FlowMessageService, end to end.
 *
 * Builds two real (draft) flows from encoded definitions — a list-triggered flow with a
 * time-delay action followed by a send-email action that renders a template, and a minimal
 * one-action flow used for collection filters/pagination — then exercises every read knob the
 * spec lists (fields / additional-fields / include / filter / sort / page[size] / cursor),
 * both mutations (flows.update status, flowActions.update definition), the relationship
 * endpoints in both flavours (full + identifiers) and the delete endpoints. Nothing is ever
 * set to `live`, so no message can be delivered.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateFlow;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateTag;
use nickdnk\Klaviyo\Resources\Request\CreateTemplate;
use nickdnk\Klaviyo\Resources\Request\UpdateFlow;
use nickdnk\Klaviyo\Resources\Request\UpdateFlowAction;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
use nickdnk\Klaviyo\Resources\Response\FlowMessage;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Shared\Flow as SharedFlow;
use Smoke\Harness;

return function (Harness $h): void {

    $h->note('SDK doc mismatch: Resources\Response\FlowMessageDefinition documents/nests `channel`, `label`, `content`, `send_options`, `tracking_options`, `render_options`, but GET /api/flow-messages/{id} returns a flat channel-specific payload (from_email, subject_line, preview_text, template_id, smart_sending_enabled, add_tracking_params, custom_tracking_params, additional_filters, name, id). The nested() map therefore never fires and every documented property reads back null.');
    $h->note('Attaching a template to a send-email action is a one-way door: Klaviyo copies the template into the flow, the copy cannot be deleted while its flow message exists, and the flow message survives DELETE of both its flow action and its flow. Each run of this suite therefore leaves one flow-owned template copy behind.');

    // ───────────────────── prerequisites (list / template / tag) ─────────────────────

    $list = $h->step('lists', 'create', 'trigger list for the flow',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('l'))),
        fn($r) => $h->assert($r->id !== null, 'list created'));
    if (!$list) {
        $h->note('Cannot build a list-triggered flow without a list.');
        return;
    }
    $h->cleanup('lists', 'delete', 'trigger list', fn(APIClient $c) => $c->lists->delete($list->id));

    $tpl = $h->step('templates', 'create', 'email template for the send-email action',
        fn(APIClient $c) => $c->templates->create(new CreateTemplate($h->name('tpl'), 'CODE',
            '<html><body><p>Smoke test {{ first_name|default:"there" }}</p></body></html>', 'Smoke test')),
        fn($r) => $h->assert($r->id !== null, 'template created'));
    if ($tpl) {
        $h->cleanup('templates', 'delete', 'email template', fn(APIClient $c) => $c->templates->delete($tpl->id));
    }

    $tag = $h->step('tags', 'create', 'tag to attach to the flow',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('tag'))),
        fn($r) => $h->assert($r->id !== null, 'tag created'));
    if ($tag) {
        $h->cleanup('tags', 'delete', 'tag', fn(APIClient $c) => $c->tags->delete($tag->id));
    }

    // ───────────────────── flows.create ─────────────────────

    $fullDefinition = [
        'triggers'         => [['type' => 'list', 'id' => $list->id]],
        'profile_filter'   => ['condition_groups' => [['conditions' => [[
            'type'     => 'profile-property',
            'property' => "properties['sdk_smoke_flag']",
            'filter'   => ['type' => 'string', 'operator' => 'equals', 'value' => 'yes'],
        ]]]]],
        'actions'          => [
            [
                'temporary_id' => 'delay-1',
                'type'         => 'time-delay',
                'links'        => ['next' => 'email-1'],
                'data'         => ['unit' => 'hours', 'value' => 2, 'timezone' => 'profile'],
            ],
            [
                'temporary_id' => 'email-1',
                'type'         => 'send-email',
                'links'        => ['next' => null],
                'data'         => [
                    'status'  => 'draft',
                    'message' => [
                        'name'                  => $h->name('msg'),
                        'subject_line'          => 'SDK smoke subject',
                        'preview_text'          => 'SDK smoke preview',
                        'template_id'           => $tpl?->id,
                        'smart_sending_enabled' => true,
                        'transactional'         => false,
                        'add_tracking_params'   => false,
                    ],
                ],
            ],
        ],
        'entry_action_id'  => 'delay-1',
        'reentry_criteria' => ['duration' => 1, 'unit' => 'alltime'],
    ];

    /** @var Flow|null $flow */
    $flow = $h->step('flows', 'create', 'create flow (list trigger + profile_filter + reentry_criteria + time-delay → send-email) w/ fields[flow] + additional-fields[flow]=definition',
        fn(APIClient $c) => $c->flows->create(new CreateFlow($h->name('flow'), $fullDefinition), (new Query())
            ->fields('flow', 'name', 'status', 'archived', 'created', 'updated', 'trigger_type', 'definition')
            ->additionalFields('flow', 'definition')),
        function ($r) use ($h) {
            $h->assert($r instanceof Flow && $r->id !== null, 'Flow hydrated with id');
            $h->assert($r->name === $h->name('flow'), 'name echoed');
            $h->assert($r->status === 'draft', 'created as draft, status=' . var_export($r->status, true));
            $h->assert(is_array($r->definition), 'definition returned via additional-fields');
            $h->assert(count($r->definition['actions']) === 2, 'two actions stored');
            foreach ($r->definition['actions'] as $a) {
                $h->assert(!empty($a['id']) && !isset($a['temporary_id']), 'temporary_id replaced by a real id');
            }
            $h->assert($r->definition['entry_action_id'] === $r->definition['actions'][0]['id'], 'entry_action_id resolved to the real id');
            $h->assert(($r->definition['profile_filter']['condition_groups'][0]['conditions'][0]['type'] ?? null) === 'profile-property', 'profile_filter round-tripped');
        });

    if (!$flow) {
        $h->note('Flow creation failed — see http[].requestBody / responseBody on the failed step above. Every read/mutate step below is skipped because the account carries no other flows to fall back on.');
        return;
    }
    $h->cleanup('flows', 'delete', 'main flow', fn(APIClient $c) => $c->flows->delete($flow->id));

    // a second, minimal flow: needed to prove collection pagination and any(id) filters
    /** @var Flow|null $flow2 */
    $flow2 = $h->step('flows', 'create', 'create minimal flow (one time-delay action, no profile_filter)',
        fn(APIClient $c) => $c->flows->create(new CreateFlow($h->name('flow2'), [
            'triggers'        => [['type' => 'list', 'id' => $list->id]],
            'actions'         => [['temporary_id' => 'only', 'type' => 'time-delay', 'data' => ['unit' => 'days', 'value' => 1]]],
            'entry_action_id' => 'only',
        ])),
        fn($r) => $h->assert($r instanceof Flow && $r->id !== null && $r->status === 'draft', 'second draft flow'));
    if ($flow2) {
        $h->cleanup('flows', 'delete', 'minimal flow', fn(APIClient $c) => $c->flows->delete($flow2->id));
    }

    // action ids straight from the created definition
    $delayId = $flow->definition['actions'][0]['id'];
    $emailId = $flow->definition['actions'][1]['id'];
    $messageId = $flow->definition['actions'][1]['data']['message']['id'] ?? null;
    $clonedTemplateId = $flow->definition['actions'][1]['data']['message']['template_id'] ?? null;

    // ───────────────────── flows.get ─────────────────────

    $h->step('flows', 'get', 'get flow w/ fields[flow] + additional-fields[flow]=definition',
        fn(APIClient $c) => $c->flows->get($flow->id, (new Query())
            ->fields('flow', 'name', 'status', 'archived', 'created', 'updated', 'trigger_type')
            ->additionalFields('flow', 'definition')),
        function ($r) use ($h, $flow) {
            $h->assert($r instanceof Flow && $r->id === $flow->id, 'same flow');
            $h->assert($r->trigger_type === 'Added to List', 'trigger_type derived from the list trigger, got ' . var_export($r->trigger_type, true));
            $h->assert($r->archived === false, 'archived=false');
            $h->assert(is_string($r->created) && is_string($r->updated), 'timestamps hydrated');
            $h->assert(is_array($r->definition) && isset($r->definition['triggers'][0]['id']), 'definition carries the trigger list id');
        });

    $h->step('flows', 'get', 'get flow w/ include(flow-actions,tags) + fields[flow-action] + fields[tag]',
        fn(APIClient $c) => $c->flows->get($flow->id, (new Query())
            ->include('flow-actions', 'tags')
            ->fields('flow', 'name', 'status')
            ->fields('flow-action', 'definition', 'created', 'updated')
            ->fields('tag', 'name')),
        function ($r) use ($h, $delayId, $emailId) {
            $h->assert($r instanceof Flow, 'Flow');
            $rel = $r->getRelationship('flow-actions');
            $h->assert($rel !== null && is_array($rel->data) && count($rel->data) === 2, 'flow-actions relationship carries both actions');
            $ids = array_map(fn($a) => $a->id, $rel->data);
            $h->assert($rel->data[0] instanceof FlowAction, 'included actions hydrate to FlowAction');
            sort($ids);
            $expect = [$delayId, $emailId];
            sort($expect);
            $h->assert($ids === $expect, 'relationship ids match the definition ids');
            $h->assert($r->getRelationship('tags') !== null,
                'SDK BUG: the response carries relationships.tags with an empty data array, but APIClient::hydrateResource() only adds a Relationship when `$rel[\'data\']` is truthy, so an empty to-many relationship disappears instead of hydrating as Relationship(data: [])');
        });

    $h->step('flows', 'get', 'get unknown flow id → null',
        fn(APIClient $c) => $c->flows->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────── flows.list ─────────────────────

    $h->step('flows', 'list', 'list w/ filter equals(name) + fields[flow] + sort=-created + page[size]=50',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::equals('name', $h->name('flow')))
            ->fields('flow', 'name', 'status', 'trigger_type')
            ->sort('created', descending: true)
            ->pageSize(50)),
        function ($r) use ($h, $flow) {
            $h->assert(is_array($r['data']) && array_key_exists('links', $r), 'list envelope');
            $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $flow->id, 'exactly our flow');
        });

    $h->step('flows', 'list', 'list w/ filter all(equals(status,"draft"), equals(archived,false), contains(name,runId))',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::all(
                Filter::equals('status', 'draft'),
                Filter::equals('archived', false),
                Filter::contains('name', 'sdk-smoke-' . $h->runId),
            ))
            ->fields('flow', 'name', 'status', 'archived')
            ->sort('name')),
        function ($r) use ($h, $flow2) {
            $h->assert(count($r['data']) === ($flow2 ? 2 : 1), 'both our draft flows, got ' . count($r['data']));
            foreach ($r['data'] as $f) {
                $h->assert($f->status === 'draft' && $f->archived === false, 'filter honoured');
            }
        });

    $h->step('flows', 'list', 'list w/ filter equals(trigger_type,"Added to List") + any(id,[..]) + sort=-updated',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::all(
                Filter::equals('trigger_type', 'Added to List'),
                Filter::any('id', array_values(array_filter([$flow->id, $flow2?->id]))),
            ))
            ->fields('flow', 'name', 'trigger_type', 'updated')
            ->sort('updated', descending: true)),
        fn($r) => $h->assert(count($r['data']) === (isset($flow2) && $flow2 ? 2 : 1), 'trigger_type + any(id) filter'));

    $h->step('flows', 'list', 'list w/ filter greater-than(created,-1 day) + less-than(created,now) + starts-with(name) + sort=created',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::all(
                Filter::greaterThan('created', new DateTimeImmutable('-1 day')),
                Filter::lessThan('created', new DateTimeImmutable()),
                Filter::startsWith('name', 'sdk-smoke-' . $h->runId),
            ))
            ->sort('created')
            ->pageSize(10)),
        fn($r) => $h->assert(count($r['data']) >= 1, 'date + string filters combine'));

    $h->step('flows', 'list', 'list w/ filter greater-or-equal(updated,-1 day) + equals(archived,false) + sort=-updated',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::all(
                Filter::greaterOrEqual('updated', new DateTimeImmutable('-1 day')),
                Filter::equals('archived', false),
            ))
            ->sort('updated', descending: true)
            ->pageSize(50)),
        fn($r) => $h->assert(count($r['data']) >= 1, 'updated filter with a matching sort'));
    $h->note('flows.list constrains date filters twice over: the bound may not be in the future ("End date may not be in the future" for less-or-equal(updated, now+1h)) and `sort` must be the same field as the date filter or omitted — so a filter mixing `created` and `updated` bounds cannot be sorted at all.');

    $h->step('flows', 'list', 'list w/ include(flow-actions,tags) + sort=name',
        fn(APIClient $c) => $c->flows->list((new Query())
            ->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))
            ->include('flow-actions', 'tags')
            ->fields('flow', 'name')
            ->fields('flow-action', 'definition')
            ->fields('tag', 'name')
            ->sort('name')),
        function ($r) use ($h) {
            $h->assert(count($r['data']) >= 1, 'at least our flow');
            $h->assert($r['data'][0]->getRelationship('flow-actions') !== null, 'includes hydrate on collection items');
        });

    if ($flow2) {
        $h->step('flows', 'list', 'paginate flows via links.next + Query::cursor (page[size]=1)', function (APIClient $c) use ($h) {
            $q = fn() => (new Query())->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))->pageSize(1)->sort('created');
            $first = $c->flows->list($q());
            $h->assert(count($first['data']) === 1, 'first page has 1');
            $h->assert($first['links']?->next !== null, 'next link present');
            $second = $c->flows->list(next: $first['links']->next);
            $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
            $viaCursor = $c->flows->list($q()->cursor($first['links']->next));
            $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) equals links.next');
            return $second;
        });
    } else {
        $h->skip('flows', 'list', 'pagination', 'second flow was not created, cannot page a one-item collection');
    }

    // ───────────────────── flows.flowActions / flowActionIds ─────────────────────

    $h->step('flows', 'flowActions', 'actions for flow w/ fields[flow-action] + sort=created + page[size]=50',
        fn(APIClient $c) => $c->flows->flowActions($flow->id, (new Query())
            ->fields('flow-action', 'definition', 'created', 'updated')
            ->sort('created')
            ->pageSize(50)),
        function ($r) use ($h, $delayId, $emailId) {
            $h->assert(count($r['data']) === 2, 'two actions');
            $types = [];
            foreach ($r['data'] as $a) {
                $h->assert($a instanceof FlowAction && $a->id !== null, 'FlowAction hydrated');
                $h->assert($a->definition !== null && $a->definition->type !== null, 'definition hydrated with a type');
                $h->assert($a->definition->data !== null, 'definition.data hydrated to an AttributeBag');
                $types[$a->definition->type] = $a->id;
            }
            $h->assert(($types['time-delay'] ?? null) === $delayId, 'time-delay id matches');
            $h->assert(($types['send-email'] ?? null) === $emailId, 'send-email id matches');
            $h->assert($r['data'][0]->definition->links !== null, 'definition.links hydrated');
        });

    $h->step('flows', 'flowActions', 'actions for flow w/ filter equals(action_type,"SEND_EMAIL")',
        fn(APIClient $c) => $c->flows->flowActions($flow->id, (new Query())
            ->filter(Filter::equals('action_type', 'SEND_EMAIL'))
            ->fields('flow-action', 'definition')),
        function ($r) use ($h, $emailId) {
            $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $emailId, 'only the send-email action');
        });
    $h->note('The action_type filter on GET /api/flows/{id}/flow-actions takes Klaviyo\'s internal upper-snake names (SEND_EMAIL, TIME_DELAY, BOOLEAN_BRANCH, UPDATE_CUSTOMER, SEND_PUSH_NOTIFICATION, BACK_IN_STOCK_DELAY, COUNTDOWN_DELAY, SEND_SMS, SEND_NOTIFICATION_MESSAGE, WEBHOOK, AB_TEST), not the kebab-case `definition.type` values the create/update bodies use.');

    $h->step('flows', 'flowActions', 'actions for flow w/ filter any(action_type,[TIME_DELAY,SEND_EMAIL]) + sort=-action_type',
        fn(APIClient $c) => $c->flows->flowActions($flow->id, (new Query())
            ->filter(Filter::any('action_type', ['TIME_DELAY', 'SEND_EMAIL']))
            ->sort('action_type', descending: true)),
        fn($r) => $h->assert(count($r['data']) === 2, 'any(action_type) filter, got ' . count($r['data'])));

    $h->step('flows', 'flowActions', 'actions for flow w/ filter equals(status,"draft") + sort=status',
        fn(APIClient $c) => $c->flows->flowActions($flow->id, (new Query())
            ->filter(Filter::equals('status', 'draft'))
            ->sort('status')),
        function ($r) use ($h, $emailId) {
            $h->assert(count($r['data']) === 1, 'only the sending action carries a status, got ' . count($r['data']));
            $h->assert($r['data'][0]->id === $emailId, 'the send-email action is the one with status=draft');
        });
    $h->note('filter=equals(status,"draft") on a flow\'s actions returns only the sending action: a time-delay action\'s definition carries no `status`, so status filters silently exclude non-sending steps.');

    $h->step('flows', 'flowActions', 'actions for flow w/ filter greater-than(created,-1 day) + any(id,[..]) + sort=created',
        fn(APIClient $c) => $c->flows->flowActions($flow->id, (new Query())
            ->filter(Filter::all(
                Filter::greaterThan('created', new DateTimeImmutable('-1 day')),
                Filter::any('id', [$delayId, $emailId]),
            ))
            ->sort('created')),
        fn($r) => $h->assert(count($r['data']) === 2, 'date + any(id) filter on actions'));
    $h->note('GET /api/flows/{id}/flow-actions couples filter and sort: a `created` filter forces `sort=created` (or no sort at all) — sort=id answers 400 "If a date filter is provided, sort field must also match or be omitted."');

    $h->step('flows', 'flowActions', 'paginate actions via links.next (page[size]=1)', function (APIClient $c) use ($h, $flow) {
        $first = $c->flows->flowActions($flow->id, (new Query())->pageSize(1)->sort('created'));
        $h->assert(count($first['data']) === 1 && $first['links']?->next !== null, 'first page + next link');
        $second = $c->flows->flowActions($flow->id, next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        return $second;
    });

    $h->step('flows', 'flowActionIds', 'action ids for flow w/ sort=created + page[size]=50',
        fn(APIClient $c) => $c->flows->flowActionIds($flow->id, (new Query())->sort('created')->pageSize(50)),
        function ($r) use ($h, $delayId, $emailId) {
            $h->assert(count($r['data']) === 2, 'two identifiers');
            $h->assert($r['data'][0] instanceof FlowAction, 'identifiers hydrate to FlowAction');
            $h->assert($r['data'][0]->id === $delayId && $r['data'][1]->id === $emailId, 'ids in creation order');
            $h->assert($r['data'][0]->definition === null, 'identifier-only payload carries no attributes');
        });

    $h->step('flows', 'flowActionIds', 'action ids for flow w/ filter equals(action_type,"TIME_DELAY")',
        fn(APIClient $c) => $c->flows->flowActionIds($flow->id, (new Query())->filter(Filter::equals('action_type', 'TIME_DELAY'))),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $delayId, 'only the delay id'));

    $h->step('flows', 'flowActionIds', 'paginate action ids via links.next (page[size]=1)', function (APIClient $c) use ($h, $flow) {
        $first = $c->flows->flowActionIds($flow->id, (new Query())->pageSize(1)->sort('id'));
        $h->assert(count($first['data']) === 1 && $first['links']?->next !== null, 'first page + next link');
        $second = $c->flows->flowActionIds($flow->id, next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        return $second;
    });

    // ───────────────────── flows.tags / tagIds ─────────────────────

    $h->step('flows', 'tags', 'tags for flow (none yet) w/ fields[tag]',
        fn(APIClient $c) => $c->flows->tags($flow->id, (new Query())->fields('tag', 'name')),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no tags yet'));

    if ($tag) {
        $tagged = $h->step('tags', 'tagFlows', 'attach the tag to the flow',
            fn(APIClient $c) => $c->tags->tagFlows($tag->id, [new SharedFlow($flow->id)]),
            fn($r) => $h->assert($r === null, 'void'));
        $h->cleanup('tags', 'untagFlows', 'detach tag from flow', fn(APIClient $c) => $c->tags->untagFlows($tag->id, [new SharedFlow($flow->id)]));

        $h->step('flows', 'tags', 'tags for flow after tagging w/ fields[tag]',
            fn(APIClient $c) => $c->flows->tags($flow->id, (new Query())->fields('tag', 'name')),
            function ($r) use ($h, $tag) {
                $h->assert(count($r['data']) === 1, 'one tag');
                $h->assert($r['data'][0] instanceof Tag && $r['data'][0]->id === $tag->id, 'Tag hydrated');
                $h->assert($r['data'][0]->name === $h->name('tag'), 'tag name hydrated');
            });

        $h->step('flows', 'tagIds', 'tag ids for flow after tagging',
            fn(APIClient $c) => $c->flows->tagIds($flow->id),
            function ($r) use ($h, $tag) {
                $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $tag->id, 'one tag identifier');
                $h->assert($r['data'][0] instanceof Tag && $r['data'][0]->name === null, 'identifier-only Tag');
            });
    } else {
        $h->step('flows', 'tagIds', 'tag ids for flow (none)',
            fn(APIClient $c) => $c->flows->tagIds($flow->id),
            fn($r) => $h->assert(is_array($r['data']), 'array'));
    }

    // ───────────────────── flows.update ─────────────────────

    $h->step('flows', 'update', 'set status=manual w/ fields[flow]',
        fn(APIClient $c) => $c->flows->update(new UpdateFlow($flow->id, 'manual'), (new Query())->fields('flow', 'name', 'status')),
        function ($r) use ($h, $flow) {
            $h->assert($r instanceof Flow && $r->id === $flow->id, 'Flow returned');
            $h->assert($r->status === 'manual', 'status=manual, got ' . var_export($r->status, true));
        });

    $h->step('flows', 'update', 'set status back to draft',
        fn(APIClient $c) => $c->flows->update(new UpdateFlow($flow->id, 'draft')),
        fn($r) => $h->assert($r instanceof Flow && $r->status === 'draft', 'back to draft'));

    $h->skip('flows', 'update', 'set status=live', 'activating the flow would deliver the send-email action to real profiles on the trigger list');

    // ───────────────────── flowActions.get ─────────────────────

    $h->step('flowActions', 'get', 'get send-email action w/ fields[flow-action]',
        fn(APIClient $c) => $c->flowActions->get($emailId, (new Query())->fields('flow-action', 'definition', 'created', 'updated')),
        function ($r) use ($h, $emailId, $messageId) {
            $h->assert($r instanceof FlowAction && $r->id === $emailId, 'FlowAction hydrated');
            $h->assert($r->definition?->type === 'send-email', 'type=send-email');
            $h->assert(($r->definition->data['status'] ?? null) === 'draft', 'action status draft');
            $h->assert(($r->definition->data['message']['id'] ?? null) === $messageId, 'message id matches the created definition');
            $h->assert(($r->definition->data['message']['subject_line'] ?? null) === 'SDK smoke subject', 'subject line stored');
        });

    $h->step('flowActions', 'get', 'get send-email action w/ include(flow,flow-messages) + fields[flow] + fields[flow-message]',
        fn(APIClient $c) => $c->flowActions->get($emailId, (new Query())
            ->include('flow', 'flow-messages')
            ->fields('flow-action', 'definition')
            ->fields('flow', 'name', 'status')
            ->fields('flow-message', 'channel', 'definition', 'created', 'updated')),
        function ($r) use ($h, $flow, $messageId) {
            $h->assert($r instanceof FlowAction, 'FlowAction');
            $flowRel = $r->getRelationship('flow');
            $h->assert($flowRel !== null && $flowRel->data instanceof Flow && $flowRel->data->id === $flow->id, 'flow relationship hydrated to the parent flow');
            $msgRel = $r->getRelationship('flow-messages');
            $h->assert($msgRel !== null && is_array($msgRel->data) && count($msgRel->data) === 1, 'one flow-message');
            $h->assert($msgRel->data[0] instanceof FlowMessage && $msgRel->data[0]->id === $messageId, 'FlowMessage hydrated');
            $h->assert($flowRel->data->name === $h->name('flow'),
                'SDK BUG: the compound document\'s `included` array carries the flow with its attributes (name, status), but APIClient::hydrateResponse() ignores `included`, so relationship data stays identifier-only and `include=` buys nothing over the relationship endpoints');
        });

    $h->step('flowActions', 'get', 'get time-delay action w/ include(flow)',
        fn(APIClient $c) => $c->flowActions->get($delayId, (new Query())->include('flow')->fields('flow', 'name')),
        function ($r) use ($h, $delayId) {
            $h->assert($r instanceof FlowAction && $r->id === $delayId, 'FlowAction');
            $h->assert($r->definition?->type === 'time-delay', 'type=time-delay');
            $h->assert(($r->definition->data['value'] ?? null) === 2 && ($r->definition->data['unit'] ?? null) === 'hours', 'delay data stored');
        });

    $h->step('flowActions', 'get', 'get unknown (numeric) action id → null',
        fn(APIClient $c) => $c->flowActions->get('999999999999'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->note('flowActions.get() maps 404 to null only for numeric ids: GET /api/flow-actions/NOPE01 answers 400 "Flow Action ID must be a digit", which surfaces as a ClientException (verified out of band, not asserted here).');

    // ───────────────────── flowActions.flow / flowId ─────────────────────

    $h->step('flowActions', 'flow', 'flow for action w/ fields[flow]',
        fn(APIClient $c) => $c->flowActions->flow($emailId, (new Query())->fields('flow', 'name', 'status', 'trigger_type')),
        function ($r) use ($h, $flow) {
            $h->assert($r instanceof Flow && $r->id === $flow->id, 'parent flow');
            $h->assert($r->name === $h->name('flow') && $r->status === 'draft', 'sparse fields hydrated');
        });

    $h->step('flowActions', 'flowId', 'flow id for action',
        fn(APIClient $c) => $c->flowActions->flowId($delayId),
        function ($r) use ($h, $flow) {
            $h->assert($r instanceof Flow && $r->id === $flow->id, 'identifier only');
            $h->assert($r->name === null, 'no attributes on the identifier payload');
        });

    $h->step('flowActions', 'flow', 'flow for unknown action id → null',
        fn(APIClient $c) => $c->flowActions->flow('999999999999'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────── flowActions.messages / messageIds ─────────────────────

    $h->step('flowActions', 'messages', 'messages for send-email action w/ fields[flow-message] + sort=created + page[size]=50',
        fn(APIClient $c) => $c->flowActions->messages($emailId, (new Query())
            ->fields('flow-message', 'channel', 'definition', 'created', 'updated')
            ->sort('created')
            ->pageSize(50)),
        function ($r) use ($h, $messageId) {
            $h->assert(count($r['data']) === 1, 'one message');
            $m = $r['data'][0];
            $h->assert($m instanceof FlowMessage && $m->id === $messageId, 'FlowMessage hydrated');
            $h->assert($m->channel === 'Email', 'channel');
            $h->assert($m->definition !== null, 'definition hydrated');
            $h->assert(($m->definition['subject_line'] ?? null) === 'SDK smoke subject', 'definition carries the subject line');
        });

    $h->step('flowActions', 'messages', 'messages w/ filter contains(name) + starts-with(name) + sort=-updated',
        fn(APIClient $c) => $c->flowActions->messages($emailId, (new Query())
            ->filter(Filter::all(
                Filter::contains('name', 'sdk-smoke-' . $h->runId),
                Filter::startsWith('name', 'sdk-smoke'),
            ))
            ->sort('updated', descending: true)),
        fn($r) => $h->assert(count($r['data']) === 1, 'name filters match the message name'));

    $h->step('flowActions', 'messages', 'messages w/ filter equals(name,<other>) → empty',
        fn(APIClient $c) => $c->flowActions->messages($emailId, (new Query())->filter(Filter::equals('name', 'no-such-message-' . $h->runId))),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty result set'));

    $h->step('flowActions', 'messages', 'messages for the time-delay action → empty (non-sending action)',
        fn(APIClient $c) => $c->flowActions->messages($delayId, (new Query())->pageSize(1)),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no messages on a delay'));

    $h->step('flowActions', 'messageIds', 'message ids for send-email action w/ sort=created + page[size]=50',
        fn(APIClient $c) => $c->flowActions->messageIds($emailId, (new Query())->sort('created')->pageSize(50)),
        function ($r) use ($h, $messageId) {
            $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $messageId, 'one identifier');
            $h->assert($r['data'][0] instanceof FlowMessage && $r['data'][0]->channel === null, 'identifier-only FlowMessage');
        });

    $h->step('flowActions', 'messageIds', 'message ids w/ filter greater-than(created,-1 day) + sort=-created',
        fn(APIClient $c) => $c->flowActions->messageIds($emailId, (new Query())
            ->filter(Filter::greaterThan('created', new DateTimeImmutable('-1 day')))
            ->sort('created', descending: true)
            ->pageSize(1)),
        fn($r) => $h->assert(count($r['data']) === 1, 'date filter on message identifiers'));

    // ───────────────────── flowMessages.* ─────────────────────

    if ($messageId === null) {
        $h->skip('flowMessages', 'get', 'flow message reads', 'created definition carried no message id');
    } else {
        $h->step('flowMessages', 'get', 'get message w/ fields[flow-message]',
            fn(APIClient $c) => $c->flowMessages->get($messageId, (new Query())->fields('flow-message', 'channel', 'definition', 'created', 'updated')),
            function ($r) use ($h, $messageId) {
                $h->assert($r instanceof FlowMessage && $r->id === $messageId, 'FlowMessage hydrated');
                $h->assert($r->channel === 'Email', 'channel');
                $h->assert(is_string($r->created) && is_string($r->updated), 'timestamps');
                $h->assert(($r->definition['name'] ?? null) === $h->name('msg'), 'message name stored');
            });

        $h->step('flowMessages', 'get', 'get message w/ include(flow-action,template) + fields[flow-action] + fields[template]',
            fn(APIClient $c) => $c->flowMessages->get($messageId, (new Query())
                ->include('flow-action', 'template')
                ->fields('flow-message', 'channel', 'definition')
                ->fields('flow-action', 'definition')
                ->fields('template', 'name', 'editor_type', 'created', 'updated')),
            function ($r) use ($h, $emailId, $clonedTemplateId) {
                $h->assert($r instanceof FlowMessage, 'FlowMessage');
                $actionRel = $r->getRelationship('flow-action');
                $h->assert($actionRel !== null && $actionRel->data instanceof FlowAction && $actionRel->data->id === $emailId, 'flow-action relationship hydrated');
                if ($clonedTemplateId !== null) {
                    $tplRel = $r->getRelationship('template');
                    $h->assert($tplRel !== null && $tplRel->data instanceof Template && $tplRel->data->id === $clonedTemplateId, 'template relationship hydrated to the flow-owned template copy');
                    $h->assert($tplRel->data->editor_type !== null && $tplRel->data->name !== null, 'included template attributes merged into the relationship');
                }
            });

        $h->step('flowMessages', 'get', 'get unknown message id → null',
            fn(APIClient $c) => $c->flowMessages->get('NOPE01'),
            fn($r) => $h->assert($r === null, 'null on 404'));

        $h->step('flowMessages', 'flowAction', 'action for message w/ fields[flow-action]',
            fn(APIClient $c) => $c->flowMessages->flowAction($messageId, (new Query())->fields('flow-action', 'definition', 'created')),
            function ($r) use ($h, $emailId) {
                $h->assert($r instanceof FlowAction && $r->id === $emailId, 'sending action');
                $h->assert($r->definition?->type === 'send-email', 'definition hydrated');
            });

        $h->step('flowMessages', 'flowActionId', 'action id for message',
            fn(APIClient $c) => $c->flowMessages->flowActionId($messageId),
            function ($r) use ($h, $emailId) {
                $h->assert($r instanceof FlowAction && $r->id === $emailId, 'identifier only');
                $h->assert($r->definition === null, 'no attributes');
            });

        $h->step('flowMessages', 'template', 'template for message w/ fields[template]',
            fn(APIClient $c) => $c->flowMessages->template($messageId, (new Query())->fields('template', 'name', 'editor_type', 'html', 'text')),
            function ($r) use ($h, $clonedTemplateId) {
                if ($clonedTemplateId === null) {
                    $h->assert($r === null, 'no template attached → null');
                    return;
                }
                $h->assert($r instanceof Template && $r->id === $clonedTemplateId, 'Template hydrated');
                $h->assert($r->name === $h->name('tpl'), 'copy keeps the source template name');
                $h->assert(is_string($r->html) && str_contains($r->html, 'Smoke test'), 'html hydrated');
            });

        $h->step('flowMessages', 'templateId', 'template id for message',
            fn(APIClient $c) => $c->flowMessages->templateId($messageId),
            function ($r) use ($h, $clonedTemplateId) {
                if ($clonedTemplateId === null) {
                    $h->assert($r === null, 'no template attached → null');
                    return;
                }
                $h->assert($r instanceof Template && $r->id === $clonedTemplateId, 'identifier only');
                $h->assert($r->name === null, 'no attributes');
            });

        $h->step('flowMessages', 'template', 'template for unknown message id → null',
            fn(APIClient $c) => $c->flowMessages->template('NOPE01'),
            fn($r) => $h->assert($r === null, 'null on 404'));
    }

    // ───────────────────── returnRequest + executePool ─────────────────────

    $h->step('flows', 'executePool', 'executePool: flows.get + flowActions.get + flowMessages.get + flows.list via returnRequest',
        function (APIClient $c) use ($h, $flow, $emailId, $messageId) {
            $requests = [
                $c->flows->get($flow->id, (new Query())->fields('flow', 'name', 'status'), returnRequest: true),
                $c->flowActions->get($emailId, (new Query())->fields('flow-action', 'definition'), returnRequest: true),
                $c->flows->list((new Query())->filter(Filter::equals('name', $h->name('flow')))->pageSize(1), returnRequest: true),
                $c->flowActions->messageIds($emailId, returnRequest: true),
            ];
            if ($messageId !== null) {
                $requests[] = $c->flowMessages->get($messageId, returnRequest: true);
            }
            foreach ($requests as $r) {
                $h->assert($r instanceof Psr\Http\Message\RequestInterface, 'returnRequest yields a PSR-7 request');
            }
            $res = $c->executePool($requests, 4);
            APIClient::assertNoExceptions($res);
            $h->assert($res[0] instanceof Flow && $res[0]->id === $flow->id, 'pooled flows.get');
            $h->assert($res[1] instanceof FlowAction && $res[1]->id === $emailId, 'pooled flowActions.get');
            $h->assert(is_array($res[2]) && count($res[2]) === 1 && $res[2][0] instanceof Flow, 'pooled flows.list returns the data list');
            $h->assert(is_array($res[3]) && $res[3][0] instanceof FlowMessage, 'pooled messageIds');
            if ($messageId !== null) {
                $h->assert($res[4] instanceof FlowMessage && $res[4]->id === $messageId, 'pooled flowMessages.get');
            }
            return $res;
        });

    // ───────────────────── flowActions.update ─────────────────────

    $h->step('flowActions', 'update', 'update send-email definition: rename message, new subject/preview, smart sending off, tracking params on',
        fn(APIClient $c) => $c->flowActions->update(new UpdateFlowAction($emailId, [
            'id'    => $emailId,
            'type'  => 'send-email',
            'links' => ['next' => null],
            'data'  => [
                'status'  => 'draft',
                'message' => [
                    'name'                   => $h->name('msg-renamed'),
                    'subject_line'           => 'SDK smoke subject (updated)',
                    'preview_text'           => 'updated preview',
                    'template_id'            => $clonedTemplateId,
                    'smart_sending_enabled'  => false,
                    'transactional'          => false,
                    'add_tracking_params'    => true,
                    'custom_tracking_params' => [
                        ['param' => 'utm_source', 'value' => 'klaviyo_flow'],
                        ['param' => 'utm_campaign', 'value' => 'sdk-smoke'],
                    ],
                ],
            ],
        ]), (new Query())->fields('flow-action', 'definition', 'updated')),
        function ($r) use ($h, $emailId) {
            $h->assert($r instanceof FlowAction && $r->id === $emailId, 'FlowAction returned');
            $msg = $r->definition?->data['message'] ?? null;
            $h->assert(is_array($msg), 'message payload returned');
            $h->assert(($msg['name'] ?? null) === $h->name('msg-renamed'), 'message renamed');
            $h->assert(($msg['subject_line'] ?? null) === 'SDK smoke subject (updated)', 'subject updated');
            $h->assert(($msg['smart_sending_enabled'] ?? null) === false, 'smart sending off');
            $h->assert(($msg['add_tracking_params'] ?? null) === true, 'tracking params on');
            $h->assert(count($msg['custom_tracking_params'] ?? []) === 2, 'two utm params stored');
            $h->assert(($r->definition->data['status'] ?? null) === 'draft', 'still draft');
        });

    $h->step('flowActions', 'update', 'update time-delay definition: unit/value/timezone/delay_until_time/delay_until_weekdays',
        fn(APIClient $c) => $c->flowActions->update(new UpdateFlowAction($delayId, [
            'id'    => $delayId,
            'type'  => 'time-delay',
            'links' => ['next' => $emailId],
            'data'  => [
                'unit'                 => 'days',
                'value'                => 3,
                'timezone'             => 'UTC',
                'delay_until_time'     => '09:00:00',
                'delay_until_weekdays' => ['monday', 'wednesday', 'friday'],
            ],
        ])),
        function ($r) use ($h, $delayId, $emailId) {
            $h->assert($r instanceof FlowAction && $r->id === $delayId, 'FlowAction returned');
            $d = $r->definition?->data;
            $h->assert(($d['unit'] ?? null) === 'days' && ($d['value'] ?? null) === 3, 'delay changed to 3 days');
            $h->assert(($d['timezone'] ?? null) === 'UTC', 'timezone stored');
            $h->assert(($d['delay_until_time'] ?? null) === '09:00:00', 'delay_until_time stored');
            $h->assert(($d['delay_until_weekdays'] ?? null) === ['monday', 'wednesday', 'friday'], 'weekdays stored');
            $h->assert(($r->definition->links['next'] ?? null) === $emailId, 'link to the send-email action preserved');
        });

    // ───────────────────── flowActions.delete ─────────────────────

    $h->step('flowActions', 'delete', 'delete the time-delay action of our own draft flow',
        fn(APIClient $c) => $c->flowActions->delete($delayId),
        fn($r) => $h->assert($r === null, 'void'));

    $h->step('flows', 'flowActionIds', 'action ids after deleting the delay → only the send-email action',
        fn(APIClient $c) => $c->flows->flowActionIds($flow->id),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $emailId, 'one action left'));

    $h->step('flowActions', 'get', 'get the deleted action → null',
        fn(APIClient $c) => $c->flowActions->get($delayId),
        fn($r) => $h->assert($r === null, 'deleted action 404s → null'));

    $h->step('flowActions', 'delete', 'delete the send-email action (last action of the flow)',
        fn(APIClient $c) => $c->flowActions->delete($emailId),
        fn($r) => $h->assert($r === null, 'void'));

    // ───────────────────── leftovers ─────────────────────

    if ($clonedTemplateId !== null) {
        $h->step('templates', 'delete', "flow-owned template copy {$clonedTemplateId} (expected 409 while a flow message references it)",
            fn(APIClient $c) => $c->templates->delete($clonedTemplateId));
        $h->reclassify('account-limitation', 'Klaviyo copies the referenced template into the flow and refuses to delete the copy while the flow message exists; the flow message survives DELETE of both its action and its flow, so the copy is permanently undeletable');
        $h->mutation("Klaviyo cloned template {$h->name('tpl')} into the flow as template {$clonedTemplateId}; it cannot be deleted (409 \"attached to FlowMessage {$messageId}\") and the flow message survives deletion of the flow, so template {$clonedTemplateId} and flow message {$messageId} stay on the account.");
    }
};
