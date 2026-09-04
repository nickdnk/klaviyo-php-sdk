<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateFlow;
use nickdnk\Klaviyo\Resources\Request\UpdateFlow;
use nickdnk\Klaviyo\Resources\Request\UpdateFlowAction;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
use nickdnk\Klaviyo\Resources\Response\FlowActionDefinition;
use nickdnk\Klaviyo\Resources\Response\FlowMessage;
use nickdnk\Klaviyo\Resources\Response\FlowMessageDefinition;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format and hydration checks for the flows, flow-actions and flow-messages services:
 * every operation's verb, path, query and body, and the class each response hydrates to.
 */
class FlowServicesTest extends TestCase
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

    private static function flowDefinition(): array
    {

        return [
            'triggers'         => [['type' => 'metric', 'id' => 'm1']],
            'profile_filter'   => ['condition_groups' => [['conditions' => [['type' => 'profile-region']]]]],
            'actions'          => [
                ['temporary_id' => 'a1', 'type' => 'time-delay', 'links' => ['next' => 'a2'], 'data' => ['unit' => 'hours', 'value' => 2]],
                ['temporary_id' => 'a2', 'type' => 'send-email', 'data' => ['status' => 'draft']],
            ],
            'entry_action_id'  => 'a1',
            'reentry_criteria' => ['duration' => 30, 'unit' => 'day'],
        ];

    }

    // region Flows

    public function testFlowListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_flows.200'),
            Fixtures::response('get_flow.200'),
            Fixtures::response('create_flow.201'),
            Fixtures::response('update_flow.200'),
            new Response(204),
        ]);
        $flows = self::client($mock)->flows;

        $list = $flows->list((new Query())->fields('flow', 'name', 'status')->filter(Filter::equals('status', 'live'))->pageSize(50));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flows', self::path($mock));
        self::assertSame([
            'fields' => ['flow' => 'name,status'],
            'filter' => 'equals(status,"live")',
            'page'   => ['size' => '50'],
        ], self::query($mock));
        self::assertInstanceOf(Flow::class, $list['data'][0]);
        self::assertSame(['T6EyQF', 'T23ivh'], array_map(static fn(Flow $f) => $f->id, $list['data']));
        self::assertSame('sdk-smoke-09049919-flow', $list['data'][0]->name);
        // The fixture was recorded with a sparse fieldset of just `name`, so status and
        // trigger_type are absent rather than null-valued attributes.
        self::assertNull($list['data'][0]->status);
        self::assertSame(['116466132', '116466131'], $list['data'][0]->getRelationship('flow-actions')->ids());

        $flow = $flows->get('f1', (new Query())->additionalFields('flow', 'definition')->include('flow-actions'));
        self::assertSame('/api/flows/f1', self::path($mock));
        self::assertSame([
            'additional-fields' => ['flow' => 'definition'],
            'include'           => 'flow-actions',
        ], self::query($mock));
        self::assertInstanceOf(Flow::class, $flow);
        self::assertSame('T6EyQF', $flow->id);
        self::assertSame('draft', $flow->status);
        // include=flow-actions: the identifiers resolve to the resources from `included`.
        $entry = $flow->getRelationship('flow-actions')->data[0];
        self::assertInstanceOf(FlowAction::class, $entry);
        self::assertSame('send-email', $entry->definition->type);
        self::assertSame('draft', $entry->definition->data->status);
        self::assertSame('Ug5pJx', $entry->definition->data->message['template_id']);

        $created = $flows->create(new CreateFlow('Win-back', self::flowDefinition()));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flows', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'flow',
                'attributes' => [
                    'name'       => 'Win-back',
                    'definition' => self::flowDefinition(),
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Flow::class, $created);
        self::assertSame('T23ivh', $created->id);
        self::assertSame('sdk-smoke-09049919-flow2', $created->name);
        self::assertSame('Added to List', $created->trigger_type);
        self::assertFalse($created->archived);
        // Klaviyo answers a create with the stored definition, its temporary ids resolved.
        self::assertSame('116466133', $created->definition['entry_action_id']);
        self::assertSame([['type' => 'list', 'id' => 'TNiRNG']], $created->definition['triggers']);

        $updated = $flows->update(new UpdateFlow('f1', 'manual'));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flows/f1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'flow',
                'attributes' => ['status' => 'manual'],
                'id'         => 'f1',
            ],
        ], self::body($mock));
        self::assertSame('manual', $updated->status);
        self::assertSame('sdk-smoke-09049919-flow', $updated->name);

        self::assertNull($flows->delete('f1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flows/f1', self::path($mock));

    }

    /**
     * Null leaves never reach the wire, so a terminal action's `links.next` is sent as an
     * absent key rather than an explicit null.
     */
    public function testCreateFlowDropsNullDefinitionLeaves(): void
    {

        $mock = new MockHandler([self::json(['type' => 'flow', 'id' => 'f3'], 201)]);

        self::client($mock)->flows->create(new CreateFlow('Terminal', [
            'triggers'        => [['type' => 'list', 'id' => 'L1']],
            'actions'         => [['temporary_id' => 'a1', 'type' => 'send-email', 'links' => ['next' => null], 'data' => ['status' => 'draft']]],
            'entry_action_id' => 'a1',
        ]));

        self::assertSame([
            'triggers'        => [['type' => 'list', 'id' => 'L1']],
            'actions'         => [['temporary_id' => 'a1', 'type' => 'send-email', 'data' => ['status' => 'draft']]],
            'entry_action_id' => 'a1',
        ], self::body($mock)['data']['attributes']['definition']);

    }

    public function testFlowRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_actions_for_flow.200'),
            Fixtures::response('get_action_ids_for_flow.200'),
            Fixtures::response('get_tags_for_flow.200'),
            Fixtures::response('get_tag_ids_for_flow.200'),
        ]);
        $flows = self::client($mock)->flows;

        $actions = $flows->flowActions('f1', (new Query())->fields('flow-action', 'definition')->sort('id'));
        self::assertSame('/api/flows/f1/flow-actions', self::path($mock));
        self::assertSame(['fields' => ['flow-action' => 'definition'], 'sort' => 'id'], self::query($mock));
        self::assertInstanceOf(FlowAction::class, $actions['data'][0]);
        self::assertSame('116466131', $actions['data'][0]->id);
        self::assertInstanceOf(FlowActionDefinition::class, $actions['data'][0]->definition);
        self::assertSame('time-delay', $actions['data'][0]->definition->type);
        self::assertSame(2, $actions['data'][0]->definition->data->value);
        self::assertSame('116466132', $actions['data'][0]->definition->links->next);
        // Recorded with page[size]=1 against a two-action flow, so a cursor came back.
        self::assertStringContainsString('page%5Bcursor%5D=', $actions['links']->next);

        $actionIds = $flows->flowActionIds('f1', (new Query())->pageSize(100));
        self::assertSame('/api/flows/f1/relationships/flow-actions', self::path($mock));
        self::assertSame(['page' => ['size' => '100']], self::query($mock));
        self::assertSame(['116466132'], array_map(static fn(FlowAction $a) => $a->id, $actionIds['data']));

        $tags = $flows->tags('f1', (new Query())->fields('tag', 'name'));
        self::assertSame('/api/flows/f1/tags', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name']], self::query($mock));
        self::assertInstanceOf(Tag::class, $tags['data'][0]);
        self::assertSame('da4ac434-4e42-417c-8860-19cd2ca68f4e', $tags['data'][0]->id);
        self::assertSame('sdk-smoke-09049919-tag', $tags['data'][0]->name);

        self::assertSame('da4ac434-4e42-417c-8860-19cd2ca68f4e', $flows->tagIds('f1')['data'][0]->id);
        self::assertSame('/api/flows/f1/relationships/tags', self::path($mock));

    }

    // endregion

    // region Flow actions

    public function testFlowActionGetUpdateDelete(): void
    {

        $definition = [
            'id'    => 'a1',
            'type'  => 'send-email',
            'links' => ['next' => 'a2'],
            'data'  => ['message' => ['subject_line' => 'Welcome!'], 'status' => 'live'],
        ];

        $mock = new MockHandler([
            Fixtures::response('get_flow_action.200'),
            Fixtures::response('update_flow_action.200'),
            new Response(204),
        ]);
        $actions = self::client($mock)->flowActions;

        $action = $actions->get('a1', (new Query())->fields('flow-action', 'definition')->include('flow-messages'));
        self::assertSame('/api/flow-actions/a1', self::path($mock));
        self::assertSame([
            'fields'  => ['flow-action' => 'definition'],
            'include' => 'flow-messages',
        ], self::query($mock));
        self::assertInstanceOf(FlowAction::class, $action);
        self::assertSame('116466131', $action->id);
        self::assertSame('2026-09-04T13:40:13+00:00', $action->updated);
        self::assertInstanceOf(FlowActionDefinition::class, $action->definition);
        self::assertInstanceOf(AttributeBag::class, $action->definition->links);
        self::assertSame('116466132', $action->definition->links->next);
        // A time-delay action's data is the delay, not a message: no `status` here.
        self::assertSame('time-delay', $action->definition->type);
        self::assertSame('hours', $action->definition->data->unit);
        self::assertSame(2, $action->definition->data->value);
        self::assertSame('profile', $action->definition->data->timezone);
        self::assertNull($action->definition->data->status);
        // include=flow: the identifier is replaced by the flow from `included`.
        self::assertSame('sdk-smoke-09049919-flow', $action->getRelationship('flow')->data->name);

        $updated = $actions->update(new UpdateFlowAction('a1', $definition));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flow-actions/a1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'flow-action',
                'attributes' => ['definition' => $definition],
                'id'         => 'a1',
            ],
        ], self::body($mock));
        self::assertSame('time-delay', $updated->definition->type);
        self::assertSame('days', $updated->definition->data->unit);
        self::assertSame(3, $updated->definition->data->value);
        self::assertSame('09:00:00', $updated->definition->data->delay_until_time);
        self::assertSame(['monday', 'wednesday', 'friday'], $updated->definition->data->delay_until_weekdays);

        self::assertNull($actions->delete('a1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flow-actions/a1', self::path($mock));

    }

    public function testFlowActionRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_flow_for_flow_action.200'),
            Fixtures::response('get_flow_id_for_flow_action.200'),
            Fixtures::response('get_flow_action_messages.200'),
            Fixtures::response('get_message_ids_for_flow_action.200'),
        ]);
        $actions = self::client($mock)->flowActions;

        $flow = $actions->flow('a1', (new Query())->fields('flow', 'name'));
        self::assertSame('/api/flow-actions/a1/flow', self::path($mock));
        self::assertSame(['fields' => ['flow' => 'name']], self::query($mock));
        self::assertInstanceOf(Flow::class, $flow);
        self::assertSame('T6EyQF', $flow->id);
        self::assertSame('sdk-smoke-09049919-flow', $flow->name);
        self::assertSame('Added to List', $flow->trigger_type);

        self::assertSame('T6EyQF', $actions->flowId('a1')->id);
        self::assertSame('/api/flow-actions/a1/relationships/flow', self::path($mock));

        $messages = $actions->messages('a1', (new Query())->fields('flow-message', 'channel')->pageSize(50));
        self::assertSame('/api/flow-actions/a1/flow-messages', self::path($mock));
        self::assertSame(['fields' => ['flow-message' => 'channel'], 'page' => ['size' => '50']], self::query($mock));
        self::assertInstanceOf(FlowMessage::class, $messages['data'][0]);
        self::assertSame('StDwe4', $messages['data'][0]->id);
        // Klaviyo capitalises the channel on flow messages.
        self::assertSame('Email', $messages['data'][0]->channel);
        self::assertSame('SDK smoke subject', $messages['data'][0]->definition->subject_line);
        self::assertSame('Ug5pJx', $messages['data'][0]->getRelationship('template')->data->id);

        $messageIds = $actions->messageIds('a1', (new Query())->sort('id', descending: true));
        self::assertSame('/api/flow-actions/a1/relationships/flow-messages', self::path($mock));
        self::assertSame(['sort' => '-id'], self::query($mock));
        self::assertSame(['StDwe4'], array_map(static fn(FlowMessage $m) => $m->id, $messageIds['data']));

    }

    // endregion

    // region Flow messages

    public function testFlowMessageGet(): void
    {

        // Live shape: the definition is the flat channel payload, not the create-time envelope.
        $mock = new MockHandler([Fixtures::response('get_flow_message.200')]);

        $message = self::client($mock)->flowMessages->get('fm1', (new Query())->fields('flow-message', 'definition')->include('template'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flow-messages/fm1', self::path($mock));
        self::assertSame([
            'fields'  => ['flow-message' => 'definition'],
            'include' => 'template',
        ], self::query($mock));
        self::assertInstanceOf(FlowMessage::class, $message);
        self::assertSame('StDwe4', $message->id);
        self::assertSame('Email', $message->channel);
        self::assertInstanceOf(FlowMessageDefinition::class, $message->definition);
        self::assertSame('sdk-smoke-09049919-msg', $message->definition->name);
        self::assertSame('SDK smoke subject', $message->definition->subject_line);
        self::assertSame('SDK smoke preview', $message->definition->preview_text);
        self::assertSame('customer@example.com', $message->definition->from_email);
        self::assertSame('Ug5pJx', $message->definition->template_id);
        self::assertTrue($message->definition->smart_sending_enabled);
        self::assertFalse($message->definition->transactional);
        self::assertFalse($message->definition->add_tracking_params);
        // An email message with no tracking params or filters sends explicit nulls, not objects.
        self::assertNull($message->definition->custom_tracking_params);
        self::assertNull($message->definition->additional_filters);
        self::assertNull($message->definition->content, 'the create-time envelope keys are not part of the read shape');
        // include=flow-action,template: both to-one relationships resolve from `included`.
        $action = $message->getRelationship('flow-action')->data;
        self::assertInstanceOf(FlowAction::class, $action);
        self::assertSame('send-email', $action->definition->type);
        $template = $message->getRelationship('template')->data;
        self::assertInstanceOf(Template::class, $template);
        self::assertSame('sdk-smoke-09049919-tpl', $template->name);
        // `image` came back links-only on a message that has no image.
        self::assertFalse($message->getRelationship('image')->hasData);

    }

    public function testFlowMessageRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_action_for_flow_message.200'),
            Fixtures::response('get_action_id_for_flow_message.200'),
            Fixtures::response('get_template_for_flow_message.200'),
            Fixtures::response('get_template_id_for_flow_message.200'),
        ]);
        $messages = self::client($mock)->flowMessages;

        $action = $messages->flowAction('fm1', (new Query())->fields('flow-action', 'definition'));
        self::assertSame('/api/flow-messages/fm1/flow-action', self::path($mock));
        self::assertSame(['fields' => ['flow-action' => 'definition']], self::query($mock));
        self::assertInstanceOf(FlowAction::class, $action);
        self::assertSame('116466132', $action->id);
        self::assertSame('send-email', $action->definition->type);
        self::assertSame('SDK smoke subject', $action->definition->data->message['subject_line']);

        // This relationship endpoint answers with a numeric id, which hydrates to a string.
        self::assertSame('116466132', $messages->flowActionId('fm1')->id);
        self::assertSame('/api/flow-messages/fm1/relationships/flow-action', self::path($mock));

        $template = $messages->template('fm1', (new Query())->fields('template', 'name'));
        self::assertSame('/api/flow-messages/fm1/template', self::path($mock));
        self::assertSame(['fields' => ['template' => 'name']], self::query($mock));
        self::assertInstanceOf(Template::class, $template);
        self::assertSame('Ug5pJx', $template->id);
        self::assertSame('sdk-smoke-09049919-tpl', $template->name);
        self::assertSame('CODE', $template->editor_type);
        self::assertSame('Smoke test', $template->text);

        self::assertSame('Ug5pJx', $messages->templateId('fm1')->id);
        self::assertSame('/api/flow-messages/fm1/relationships/template', self::path($mock));

    }

    // endregion

}
