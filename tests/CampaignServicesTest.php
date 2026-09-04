<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CampaignClone;
use nickdnk\Klaviyo\Resources\Request\CampaignMessage as RequestCampaignMessage;
use nickdnk\Klaviyo\Resources\Request\CreateCampaign;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaign;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaignMessage;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimation;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimationJob;
use nickdnk\Klaviyo\Resources\Response\CampaignSendJob;
use nickdnk\Klaviyo\Resources\Response\Image;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageContent;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageRenderOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategyOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignTrackingOptions;
use PHPUnit\Framework\TestCase;

class CampaignServicesTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

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

    private static function method(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getMethod();

    }

    /** Ids of the campaign, message, template and tag the recorded fixtures were captured against. */
    private const string CAMPAIGN_ID = '01M1PA99J4GSD6B3XGYF78793W';
    private const string MESSAGE_ID = '01M1PA99JBS13TE33GQ4YRESFN';
    private const string TEMPLATE_ID = 'RXgJ7k';
    private const string TAG_ID = 'fe22c973-0e88-4ae3-8229-6700b2c0f43f';

    // region Campaigns

    public function testListSendsChannelFilterAndHydratesNestedAttributes(): void
    {

        $mock = new MockHandler([Fixtures::response('get_campaigns.200')]);

        $result = self::client($mock)->campaigns->list(
            (new Query())->filter("equals(messages.channel,'email')")
                ->fields('campaign', 'name', 'status')
                ->include('campaign-messages')
                ->pageSize(50)
        );

        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaigns', self::path($mock));
        self::assertSame([
            'fields'  => ['campaign' => 'name,status'],
            'filter'  => "equals(messages.channel,'email')",
            'include' => 'campaign-messages',
            'page'    => ['size' => '50'],
        ], self::query($mock));

        $campaign = $result['data'][0];
        self::assertInstanceOf(Campaign::class, $campaign);
        self::assertSame(self::CAMPAIGN_ID, $campaign->id);
        self::assertSame('sdk-smoke-09048514-c', $campaign->name);
        self::assertSame('Draft', $campaign->status);
        self::assertFalse($campaign->archived);
        self::assertInstanceOf(CampaignAudiences::class, $campaign->audiences);
        self::assertSame(['VAnKig'], $campaign->audiences->included);
        self::assertSame([], $campaign->audiences->excluded);
        self::assertInstanceOf(CampaignSendOptions::class, $campaign->send_options);
        self::assertTrue($campaign->send_options->use_smart_sending);
        self::assertInstanceOf(CampaignTrackingOptions::class, $campaign->tracking_options);
        self::assertTrue($campaign->tracking_options->add_tracking_params);
        self::assertSame(
            [
                ['type' => 'static', 'value' => 'sdk-smoke', 'name' => 'utm_source'],
                ['type' => 'static', 'value' => 'email', 'name' => 'utm_medium'],
                ['type' => 'dynamic', 'value' => 'campaign_name', 'name' => 'utm_campaign'],
            ],
            $campaign->tracking_options->custom_tracking_params
        );
        self::assertInstanceOf(CampaignSendStrategy::class, $campaign->send_strategy);
        self::assertSame('static', $campaign->send_strategy->method);
        self::assertSame('2026-10-04T09:00:00+00:00', $campaign->send_strategy->datetime);
        self::assertInstanceOf(CampaignSendStrategyOptions::class, $campaign->send_strategy->options);
        self::assertFalse($campaign->send_strategy->options->is_local);
        // Klaviyo omits send_past_recipients_immediately from a static strategy's options.
        self::assertNull($campaign->send_strategy->options->send_past_recipients_immediately);
        self::assertSame([self::MESSAGE_ID], $campaign->getRelationship('campaign-messages')->ids());
        // The tags relationship came back links-only, so it is distinguishable from an empty one.
        self::assertFalse($campaign->getRelationship('tags')->hasData);
        self::assertNull($result['links']->next);

    }

    public function testGetSendsSparseFieldsetAndReturnsNullOn404(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_campaign.200'),
            Fixtures::response('get_campaign.404'),
        ]);
        $campaigns = self::client($mock)->campaigns;

        $campaign = $campaigns->get('c1', (new Query())->fields('campaign', 'name')->include('tags'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaigns/c1', self::path($mock));
        self::assertSame(['fields' => ['campaign' => 'name'], 'include' => 'tags'], self::query($mock));
        self::assertInstanceOf(Campaign::class, $campaign);
        self::assertSame('Draft', $campaign->status);
        self::assertSame('sdk-smoke-09048514-c', $campaign->name);
        // The fixture was recorded with include=campaign-messages, so the identifier in the
        // relationship is spliced out of `included` and carries its full attributes.
        $message = $campaign->getRelationship('campaign-messages')->data[0];
        self::assertInstanceOf(CampaignMessage::class, $message);
        self::assertSame(self::MESSAGE_ID, $message->id);
        self::assertSame('sdk-smoke-09048514-msg', $message->definition->label);
        self::assertSame('SDK smoke 09048514', $message->definition->content->subject);
        // `tags` came back as an empty list here, not links-only.
        self::assertTrue($campaign->getRelationship('tags')->hasData);
        self::assertSame([], $campaign->getRelationship('tags')->data);

        self::assertNull($campaigns->get('missing'));

    }

    public function testCreateNestsMessagesInsideAttributes(): void
    {

        $mock = new MockHandler([Fixtures::response('create_campaign.201')]);

        $definition = new CampaignMessageDefinition('email');
        $definition->label = 'Launch email';
        $content = new CampaignMessageContent();
        $content->subject = 'Summer is here';
        $content->preview_text = 'Doors open at 22:00';
        $content->from_email = 'hello@acme.test';
        $definition->content = $content;

        $campaign = new CreateCampaign(
            'Summer launch',
            new CampaignAudiences(['L1', 'S2'], ['S9']),
            [new RequestCampaignMessage($definition, 'img1')]
        );
        $campaign->send_strategy = CampaignSendStrategy::at(
            '2026-09-10T09:00:00',
            new CampaignSendStrategyOptions(true, false)
        );
        $campaign->send_options = new CampaignSendOptions(true);
        $tracking = new CampaignTrackingOptions();
        $tracking->add_tracking_params = true;
        $tracking->is_tracking_clicks = true;
        $campaign->tracking_options = $tracking;

        $created = self::client($mock)->campaigns->create($campaign);

        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/campaigns', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'campaign',
                'attributes' => [
                    'name'              => 'Summer launch',
                    'audiences'         => ['included' => ['L1', 'S2'], 'excluded' => ['S9']],
                    'campaign-messages' => [
                        'data' => [
                            [
                                'type'          => 'campaign-message',
                                'attributes'    => [
                                    'definition' => [
                                        'channel' => 'email',
                                        'label'   => 'Launch email',
                                        'content' => [
                                            'subject'      => 'Summer is here',
                                            'preview_text' => 'Doors open at 22:00',
                                            'from_email'   => 'hello@acme.test',
                                        ],
                                    ],
                                ],
                                'relationships' => ['image' => ['data' => ['type' => 'image', 'id' => 'img1']]],
                            ],
                        ],
                    ],
                    'send_strategy'     => [
                        'method'   => 'static',
                        'datetime' => '2026-09-10T09:00:00',
                        'options'  => ['is_local' => true, 'send_past_recipients_immediately' => false],
                    ],
                    'send_options'      => ['use_smart_sending' => true],
                    'tracking_options'  => ['add_tracking_params' => true, 'is_tracking_clicks' => true],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Campaign::class, $created);
        self::assertSame('01M1PA9AXT75VXDN6WZJYXE1EJ', $created->id);
        self::assertSame('sdk-smoke-09048514-c-sms', $created->name);
        self::assertSame('Draft', $created->status);
        self::assertSame([], $created->tracking_options->custom_tracking_params);

    }

    public function testUpdatePatchesCampaignAtItsOwnId(): void
    {

        $mock = new MockHandler([Fixtures::response('update_campaign.200')]);

        $campaign = new UpdateCampaign('c1');
        $campaign->name = 'Summer launch v2';
        $campaign->audiences = new CampaignAudiences(['L1']);
        $campaign->send_strategy = CampaignSendStrategy::throttled('2026-09-11T09:00:00', 25);

        $updated = self::client($mock)->campaigns->update($campaign);

        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/campaigns/c1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'campaign',
                'attributes' => [
                    'name'          => 'Summer launch v2',
                    'audiences'     => ['included' => ['L1']],
                    'send_strategy' => [
                        'method'              => 'throttled',
                        'datetime'            => '2026-09-11T09:00:00',
                        'throttle_percentage' => 25,
                    ],
                ],
                'id'         => 'c1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Campaign::class, $updated);
        self::assertSame(self::CAMPAIGN_ID, $updated->id);
        self::assertSame('sdk-smoke-09048514-c-renamed', $updated->name);
        self::assertSame(['VAnKig'], $updated->audiences->included);
        self::assertSame(['UXmGFD'], $updated->audiences->excluded);
        self::assertSame('2026-10-19T11:30:00+00:00', $updated->send_strategy->datetime);

    }

    public function testDeleteCampaign(): void
    {

        $mock = new MockHandler([new Response(204)]);

        self::assertNull(self::client($mock)->campaigns->delete('c1'));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/campaigns/c1', self::path($mock));

    }

    public function testCloneSendsSourceIdAndNewName(): void
    {

        $mock = new MockHandler([Fixtures::response('create_campaign_clone.201')]);

        $copy = self::client($mock)->campaigns->clone(new CampaignClone('c1', 'Summer launch (copy)'));

        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/campaign-clone', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'campaign',
                'attributes' => ['new_name' => 'Summer launch (copy)'],
                'id'         => 'c1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Campaign::class, $copy);
        self::assertSame('01M1PABGG6H1Q8VS6HT47FB10F', $copy->id);
        self::assertSame('sdk-smoke-09048514-c-clone', $copy->name);
        self::assertNotSame(self::CAMPAIGN_ID, $copy->id);
        self::assertSame(['UXmGFD'], $copy->audiences->excluded);

    }

    public function testCampaignRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_messages_for_campaign.200'),
            Fixtures::response('get_message_ids_for_campaign.200'),
            Fixtures::response('get_tags_for_campaign.200'),
            Fixtures::response('get_tag_ids_for_campaign.200'),
        ]);
        $campaigns = self::client($mock)->campaigns;

        $messages = $campaigns->messages('c1', (new Query())->fields('campaign-message', 'definition')->include('template'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaigns/c1/campaign-messages', self::path($mock));
        self::assertSame(
            ['fields' => ['campaign-message' => 'definition'], 'include' => 'template'],
            self::query($mock)
        );
        self::assertInstanceOf(CampaignMessage::class, $messages['data'][0]);
        self::assertInstanceOf(CampaignMessageDefinition::class, $messages['data'][0]->definition);
        self::assertSame('sdk-smoke-09048514-msg', $messages['data'][0]->definition->label);
        self::assertSame('email', $messages['data'][0]->definition->channel);
        // Recorded with include=template,image while the message had neither: both to-one
        // relationships are present with an explicit null rather than being absent.
        self::assertNull($messages['data'][0]->getRelationship('template')->data);
        self::assertSame(self::CAMPAIGN_ID, $messages['data'][0]->getRelationship('campaign')->data->id);

        self::assertSame(self::MESSAGE_ID, $campaigns->messageIds('c1')['data'][0]->id);
        self::assertSame('/api/campaigns/c1/relationships/campaign-messages', self::path($mock));

        $tags = $campaigns->tags('c1', (new Query())->fields('tag', 'name'));
        self::assertSame('/api/campaigns/c1/tags', self::path($mock));
        self::assertSame(['fields' => ['tag' => 'name']], self::query($mock));
        self::assertInstanceOf(Tag::class, $tags['data'][0]);
        self::assertSame(self::TAG_ID, $tags['data'][0]->id);
        self::assertSame('sdk-smoke-09048514-tag', $tags['data'][0]->name);

        self::assertSame(self::TAG_ID, $campaigns->tagIds('c1')['data'][0]->id);
        self::assertSame('/api/campaigns/c1/relationships/tags', self::path($mock));

    }

    // endregion

    // region Send jobs

    public function testSendGetSendJobAndCancelSend(): void
    {

        $mock = new MockHandler([
            self::json(['type' => 'campaign-send-job', 'id' => 'c1', 'attributes' => ['status' => 'queued']], 202),
            self::json(['type' => 'campaign-send-job', 'id' => 'c1', 'attributes' => ['status' => 'processing']]),
            new Response(204),
            new Response(204),
        ]);
        $campaigns = self::client($mock)->campaigns;

        $job = $campaigns->send('c1');
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/campaign-send-jobs', self::path($mock));
        self::assertSame(['data' => ['type' => 'campaign-send-job', 'id' => 'c1']], self::body($mock));
        self::assertInstanceOf(CampaignSendJob::class, $job);
        self::assertSame('queued', $job->status);

        $polled = $campaigns->getSendJob('c1', (new Query())->fields('campaign-send-job', 'status'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaign-send-jobs/c1', self::path($mock));
        self::assertSame(['fields' => ['campaign-send-job' => 'status']], self::query($mock));
        self::assertInstanceOf(CampaignSendJob::class, $polled);
        self::assertSame('processing', $polled->status);

        self::assertNull($campaigns->cancelSend('c1'));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/campaign-send-jobs/c1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'campaign-send-job',
                'attributes' => ['action' => 'cancel'],
                'id'         => 'c1',
            ],
        ], self::body($mock));

        self::assertNull($campaigns->revertSend('c1'));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/campaign-send-jobs/c1', self::path($mock));
        self::assertSame('revert', self::body($mock)['data']['attributes']['action']);

    }

    // endregion

    // region Recipient estimation

    public function testRecipientEstimationJobAndEstimation(): void
    {

        $mock = new MockHandler([
            Fixtures::response('refresh_campaign_recipient_estimation.202'),
            // No 2xx recorded for the estimation job poll or the estimation itself.
            self::json(['type' => 'campaign-recipient-estimation-job', 'id' => 'c1', 'attributes' => ['status' => 'complete']]),
            self::json(['type' => 'campaign-recipient-estimation', 'id' => 'c1', 'attributes' => ['estimated_recipient_count' => 4231]]),
            Fixtures::response('get_campaign_recipient_estimation.404'),
        ]);
        $campaigns = self::client($mock)->campaigns;

        $job = $campaigns->refreshRecipientEstimation('c1');
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/campaign-recipient-estimation-jobs', self::path($mock));
        self::assertSame(
            ['data' => ['type' => 'campaign-recipient-estimation-job', 'id' => 'c1']],
            self::body($mock)
        );
        self::assertInstanceOf(CampaignRecipientEstimationJob::class, $job);
        self::assertSame(self::CAMPAIGN_ID, $job->id);
        // The job is keyed by the campaign id and comes back already running, not queued.
        self::assertSame('processing', $job->status);

        $polled = $campaigns->getRecipientEstimationJob('c1');
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaign-recipient-estimation-jobs/c1', self::path($mock));
        self::assertInstanceOf(CampaignRecipientEstimationJob::class, $polled);
        self::assertSame('complete', $polled->status);

        $estimation = $campaigns->getRecipientEstimation('c1', (new Query())->fields('campaign-recipient-estimation', 'estimated_recipient_count'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaign-recipient-estimations/c1', self::path($mock));
        self::assertSame(
            ['fields' => ['campaign-recipient-estimation' => 'estimated_recipient_count']],
            self::query($mock)
        );
        self::assertInstanceOf(CampaignRecipientEstimation::class, $estimation);
        self::assertSame(4231, $estimation->estimated_recipient_count);

        self::assertNull($campaigns->getRecipientEstimation('missing'));

    }

    // endregion

    // region Campaign messages

    public function testMessageGetHydratesDefinitionAndReturnsNullOn404(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_campaign_message.200'),
            Fixtures::response('get_campaign_message.404'),
        ]);
        $messages = self::client($mock)->campaignMessages;

        $message = $messages->get('m1', (new Query())->fields('campaign-message', 'definition')->include('image', 'template'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaign-messages/m1', self::path($mock));
        self::assertSame(
            ['fields' => ['campaign-message' => 'definition'], 'include' => 'image,template'],
            self::query($mock)
        );
        self::assertInstanceOf(CampaignMessage::class, $message);
        self::assertInstanceOf(CampaignMessageDefinition::class, $message->definition);
        self::assertSame('email', $message->definition->channel);
        self::assertInstanceOf(CampaignMessageContent::class, $message->definition->content);
        self::assertSame('SDK smoke 09048514', $message->definition->content->subject);
        self::assertSame('customer@example.com', $message->definition->content->from_email);
        // An email definition carries no render_options, and an unscheduled message no send times.
        self::assertNull($message->definition->render_options);
        self::assertSame([], $message->send_times);
        // include=campaign: the identifier is replaced by the resource from `included`.
        $campaign = $message->getRelationship('campaign')->data;
        self::assertInstanceOf(Campaign::class, $campaign);
        self::assertSame('sdk-smoke-09048514-c-renamed', $campaign->name);

        self::assertNull($messages->get('missing'));

    }

    public function testMessageUpdateSendsDefinitionAndImageRelationship(): void
    {

        $mock = new MockHandler([Fixtures::response('update_campaign_message.200')]);

        $definition = new CampaignMessageDefinition('sms');
        $content = new CampaignMessageContent();
        $content->body = 'Doors open at 22:00';
        $definition->content = $content;
        $definition->render_options = new CampaignMessageRenderOptions(true, addOptOutLanguage: false);

        $updated = self::client($mock)->campaignMessages->update(new UpdateCampaignMessage('m1', $definition, 'img1'));

        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/campaign-messages/m1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'campaign-message',
                'attributes'    => [
                    'definition' => [
                        'channel'        => 'sms',
                        'content'        => ['body' => 'Doors open at 22:00'],
                        'render_options' => ['shorten_links' => true, 'add_opt_out_language' => false],
                    ],
                ],
                'relationships' => ['image' => ['data' => ['type' => 'image', 'id' => 'img1']]],
                'id'            => 'm1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(CampaignMessage::class, $updated);
        self::assertSame(self::MESSAGE_ID, $updated->id);
        self::assertSame('sdk-smoke-09048514-msg-renamed', $updated->definition->label);
        self::assertSame('SDK smoke updated 09048514', $updated->definition->content->subject);

    }

    public function testMessageToOneRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_campaign_for_campaign_message.200'),
            Fixtures::response('get_campaign_id_for_campaign_message.200'),
            Fixtures::response('get_template_for_campaign_message.200'),
            Fixtures::response('get_template_id_for_campaign_message.200'),
            Fixtures::response('get_image_for_campaign_message.200'),
            Fixtures::response('get_image_id_for_campaign_message.200'),
        ]);
        $messages = self::client($mock)->campaignMessages;

        $campaign = $messages->campaign('m1', (new Query())->fields('campaign', 'name'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/campaign-messages/m1/campaign', self::path($mock));
        self::assertSame(['fields' => ['campaign' => 'name']], self::query($mock));
        self::assertInstanceOf(Campaign::class, $campaign);
        self::assertSame(self::CAMPAIGN_ID, $campaign->id);
        self::assertSame('sdk-smoke-09048514-c-renamed', $campaign->name);
        self::assertSame('static', $campaign->send_strategy->method);

        self::assertSame(self::CAMPAIGN_ID, $messages->campaignId('m1')->id);
        self::assertSame('/api/campaign-messages/m1/relationships/campaign', self::path($mock));

        $template = $messages->template('m1');
        self::assertSame('/api/campaign-messages/m1/template', self::path($mock));
        self::assertInstanceOf(Template::class, $template);
        self::assertSame(self::TEMPLATE_ID, $template->id);
        self::assertSame('CODE', $template->editor_type);
        self::assertSame('<html><head></head><body><p>hi</p></body></html>', $template->html);

        self::assertSame(self::TEMPLATE_ID, $messages->templateId('m1')->id);
        self::assertSame('/api/campaign-messages/m1/relationships/template', self::path($mock));

        // A message with no image answers 200 with `data: null`, so the related read is null
        // even though the relationship endpoint below still resolves an id.
        self::assertNull($messages->image('m1'));
        self::assertSame('/api/campaign-messages/m1/image', self::path($mock));

        $imageId = $messages->imageId('m1');
        self::assertInstanceOf(Image::class, $imageId);
        self::assertSame('366547581', $imageId->id);
        self::assertSame('/api/campaign-messages/m1/relationships/image', self::path($mock));

    }

    public function testAssignTemplateAndUpdateImage(): void
    {

        $mock = new MockHandler([
            Fixtures::response('assign_template_to_campaign_message.200'),
            new Response(204),
        ]);
        $messages = self::client($mock)->campaignMessages;

        $assigned = $messages->assignTemplate('m1', 't1');
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/campaign-message-assign-template', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'campaign-message',
                'relationships' => ['template' => ['data' => ['type' => 'template', 'id' => 't1']]],
                'id'            => 'm1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(CampaignMessage::class, $assigned);
        // This endpoint answers with the legacy flat shape: no `definition`, the channel,
        // label and content sit directly on the message.
        self::assertNull($assigned->definition);
        self::assertSame('email', $assigned->channel);
        self::assertSame('sdk-smoke-09048514-msg-renamed', $assigned->label);
        self::assertInstanceOf(CampaignMessageContent::class, $assigned->content);
        self::assertSame('SDK smoke updated 09048514', $assigned->content->subject);
        // The snapshotted template comes back in `included` and is spliced into the relationship.
        $template = $assigned->getRelationship('template')->data;
        self::assertInstanceOf(Template::class, $template);
        self::assertSame(self::TEMPLATE_ID, $template->id);
        self::assertSame('Clone of RhSFjh', $template->name);

        self::assertNull($messages->updateImage('m1', 'img2'));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/campaign-messages/m1/relationships/image', self::path($mock));
        self::assertSame(['data' => ['type' => 'image', 'id' => 'img2']], self::body($mock));

    }

    // endregion

}
