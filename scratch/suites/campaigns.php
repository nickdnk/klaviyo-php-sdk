<?php
/**
 * CampaignService + CampaignMessageService, end to end: draft EMAIL campaign with every
 * create attribute the spec allows → reads with every query knob → update → relationships
 * → recipient estimation (async) → clone → message edits (definition, template, image) →
 * cleanup. Nothing is ever sent: send / cancelSend / revertSend are skipped by design.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CampaignClone;
use nickdnk\Klaviyo\Resources\Request\CampaignMessage as RequestCampaignMessage;
use nickdnk\Klaviyo\Resources\Request\CreateCampaign;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateTag;
use nickdnk\Klaviyo\Resources\Request\CreateTemplate;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaign;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaignMessage;
use nickdnk\Klaviyo\Resources\Request\UpdateImage;
use nickdnk\Klaviyo\Resources\Request\UploadImageFromUrl;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimation;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimationJob;
use nickdnk\Klaviyo\Resources\Response\Image as ResponseImage;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\Template as ResponseTemplate;
use nickdnk\Klaviyo\Resources\Shared\Campaign as SharedCampaign;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageContent;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageRenderOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategyOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignTrackingOptions;
use Smoke\Harness;

return function (Harness $h): void {

    // Account's verified default sender; anything else is rejected by Klaviyo.
    $fromEmail = $h->senderEmail();
    $sendAt = (new DateTimeImmutable('+30 days'))->setTime(9, 0)->format('Y-m-d\TH:i:sP');
    $sendAt2 = (new DateTimeImmutable('+45 days'))->setTime(11, 30)->format('Y-m-d\TH:i:sP');

    // ───────────────────────── setup: audience, template, image ─────────────────────────

    /** @var KlaviyoList|null $list */
    $list = $h->step('lists', 'create', 'create list for campaign audience',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('l'))),
        fn($r) => $h->assert($r instanceof KlaviyoList && $r->id, 'list created'));
    if (!$list) {
        $h->note('No list → cannot create a campaign; aborting suite.');
        return;
    }
    $h->cleanup('lists', 'delete', 'audience list', fn(APIClient $c) => $c->lists->delete($list->id));

    /** @var KlaviyoList|null $list2 */
    $list2 = $h->step('lists', 'create', 'create second list to exclude from the audience',
        fn(APIClient $c) => $c->lists->create(new CreateList($h->name('l2'))),
        fn($r) => $h->assert($r instanceof KlaviyoList && $r->id, 'list created'));
    if ($list2) {
        $h->cleanup('lists', 'delete', 'excluded list', fn(APIClient $c) => $c->lists->delete($list2->id));
    }

    /** @var ResponseTemplate|null $template */
    $template = $h->step('templates', 'create', 'create CODE template for assignTemplate',
        fn(APIClient $c) => $c->templates->create(new CreateTemplate($h->name('t'), 'CODE', '<p>hi</p>', 'hi')),
        fn($r) => $h->assert($r instanceof ResponseTemplate && $r->id, 'template created'));
    if ($template) {
        $h->cleanup('templates', 'delete', 'template', fn(APIClient $c) => $c->templates->delete($template->id));
    }

    $h->step('images', 'uploadFromUrl', 'upload image from https://www.klaviyo.com/favicon.ico (ico is not jpeg/png/gif → expect 400)',
        fn(APIClient $c) => $c->images->uploadFromUrl(new UploadImageFromUrl('https://www.klaviyo.com/favicon.ico', $h->name('img-ico'))));
    $h->reclassify('api-contract', 'POST /api/images only accepts jpeg/png/gif; .ico is fetched and rejected with 400 "Unable to fetch the URL you entered."');

    // 16x16 png as a data uri — self-contained, no external host to depend on.
    $pngDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAAAAAA6mKC9AAAAEElEQVR4nGNg+E8AMowoFQDA/X+BQnmBUwAAAABJRU5ErkJggg==';

    /** @var ResponseImage|null $image */
    $image = $h->step('images', 'uploadFromUrl', 'upload image from data uri for updateImage',
        fn(APIClient $c) => $c->images->uploadFromUrl(new UploadImageFromUrl($pngDataUri, $h->name('img'))),
        fn($r) => $h->assert($r instanceof ResponseImage && $r->id && $r->image_url, 'image stored w/ hosted url'));
    if ($image) {
        // Images cannot be deleted through the API; hide it instead and record the leftover.
        $h->cleanup('images', 'update', 'hide image (cannot be deleted)', function (APIClient $c) use ($h, $image) {
            $u = new UpdateImage($image->id);
            // `name` must be resent: PATCH /api/images/{id} replaces the attributes it is given
            // and nulls `name` when only `hidden` is sent (verified against the live API).
            $u->name = $h->name('img');
            $u->hidden = true;
            return $c->images->update($u);
        });
        $h->mutation("Image {$image->id} (" . $h->name('img') . ') stays on the account — the API has no delete for images; left hidden=true.');
    }

    // ───────────────────────────── create the campaign ─────────────────────────────

    $buildEmailMessage = function (string $label) use ($h, $fromEmail): RequestCampaignMessage {
        $content = new CampaignMessageContent();
        $content->subject = 'SDK smoke ' . $h->runId;
        $content->preview_text = 'Preview text for ' . $h->runId;
        $content->from_email = $fromEmail;
        $content->from_label = 'SDK Smoke';
        $content->reply_to_email = $fromEmail;
        $content->cc_email = $fromEmail;
        $content->bcc_email = $fromEmail;

        $def = new CampaignMessageDefinition('email');
        $def->label = $label;
        $def->content = $content;

        return new RequestCampaignMessage($def);
    };

    /** @var Campaign|null $campaign */
    $campaign = $h->step('campaigns', 'create', 'create EMAIL draft: audiences + static send_strategy(+30d, is_local=false) + send_options + tracking_options + 1 message w/ full email content',
        function (APIClient $c) use ($h, $list, $sendAt, $buildEmailMessage) {
            $create = new CreateCampaign(
                $h->name('c'),
                new CampaignAudiences(included: [$list->id]),
                [$buildEmailMessage($h->name('msg'))],
            );
            $create->send_strategy = CampaignSendStrategy::at($sendAt, new CampaignSendStrategyOptions(isLocal: false));
            $create->send_options = new CampaignSendOptions(useSmartSending: true);
            $tracking = new CampaignTrackingOptions();
            $tracking->add_tracking_params = true;
            // Klaviyo requires at least utm_source + utm_medium among custom_tracking_params.
            $tracking->custom_tracking_params = [
                ['type' => 'static', 'value' => 'sdk-smoke', 'name' => 'utm_source'],
                ['type' => 'static', 'value' => 'email', 'name' => 'utm_medium'],
                ['type' => 'dynamic', 'value' => 'campaign_name', 'name' => 'utm_campaign'],
            ];
            $tracking->is_tracking_clicks = true;
            $tracking->is_tracking_opens = true;
            $create->tracking_options = $tracking;

            return $c->campaigns->create($create, (new Query())->fields('campaign', 'name', 'status', 'audiences', 'send_strategy', 'send_options', 'tracking_options', 'created_at', 'scheduled_at'));
        },
        function ($r) use ($h, $list) {
            $h->assert($r instanceof Campaign && $r->id, 'campaign hydrated with id');
            $h->assert($r->status === 'Draft', 'created as Draft, got ' . var_export($r->status, true));
            $h->assert($r->audiences?->included === [$list->id], 'audiences.included hydrated to our list');
            $h->assert($r->send_strategy?->method === 'static' && $r->send_strategy->options?->is_local === false, 'static send strategy w/ nested options hydrated');
            $h->assert($r->send_options?->use_smart_sending === true, 'send_options hydrated');
            $h->assert($r->tracking_options?->is_tracking_clicks === true && $r->tracking_options->is_tracking_opens === true, 'tracking_options hydrated');
        });

    if (!$campaign) {
        $h->note('Campaign create failed — the rest of the suite cannot run.');
        return;
    }
    $h->cleanup('campaigns', 'delete', 'campaign', fn(APIClient $c) => $c->campaigns->delete($campaign->id));

    // SMS variant: the account has no SMS sending number, but a draft is still accepted.
    // Its message is the only place image relationships are legal (email messages reject them).
    /** @var Campaign|null $sms */
    $sms = $h->step('campaigns', 'create', 'create SMS draft (sms definition + render_options; account has no SMS sender)',
        function (APIClient $c) use ($h, $list, $sendAt) {
            $def = new CampaignMessageDefinition('sms');
            $content = new CampaignMessageContent();
            $content->body = 'SDK smoke sms ' . $h->runId;
            $def->content = $content;
            $def->render_options = new CampaignMessageRenderOptions(shortenLinks: true, addOrgPrefix: true, addInfoLink: true, addOptOutLanguage: true);
            $create = new CreateCampaign($h->name('c-sms'), new CampaignAudiences(included: [$list->id]), [new RequestCampaignMessage($def)]);
            $create->send_strategy = CampaignSendStrategy::at($sendAt, new CampaignSendStrategyOptions(isLocal: false));
            $create->send_options = new CampaignSendOptions(useSmartSending: false);
            $created = $c->campaigns->create($create);
            $h->cleanup('campaigns', 'delete', 'sms campaign', fn(APIClient $cc) => $cc->campaigns->delete($created->id));
            return $created;
        },
        fn($r) => $h->assert($r instanceof Campaign && $r->id && $r->status === 'Draft', 'SMS draft created (never sent)'));

    // ───────────────────────────── reads ─────────────────────────────

    $h->step('campaigns', 'get', 'get w/ fields[campaign,campaign-message,tag] + include(campaign-messages,tags)',
        fn(APIClient $c) => $c->campaigns->get($campaign->id, (new Query())
            ->fields('campaign', 'name', 'status', 'audiences', 'send_strategy', 'send_options', 'tracking_options', 'created_at', 'updated_at', 'send_time', 'archived')
            ->fields('campaign-message', 'definition', 'send_times', 'created_at')
            ->fields('tag', 'name')
            ->include('campaign-messages', 'tags')),
        function ($r) use ($h, $campaign) {
            $h->assert($r instanceof Campaign && $r->id === $campaign->id && $r->name === $h->name('c'), 'our campaign');
            $h->assert($r->archived === false, 'archived hydrated as bool');
            $rel = $r->getRelationship('campaign-messages');
            $h->assert($rel !== null && is_array($rel->data) && count($rel->data) === 1 && $rel->data[0]->id, 'campaign-messages relationship ids hydrated');
        });

    $h->step('campaigns', 'get', 'get unknown campaign → null',
        fn(APIClient $c) => $c->campaigns->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('campaigns', 'list', 'list w/ filter all(equals(messages.channel,email), contains(name), equals(status,Draft), greater-than(created_at,-1d)) + sort=-created_at + fields + page[size]',
        fn(APIClient $c) => $c->campaigns->list((new Query())
            ->filter(Filter::all(
                Filter::equals('messages.channel', 'email'),
                Filter::contains('name', $h->name('c')),
                Filter::equals('status', 'Draft'),
                Filter::greaterThan('created_at', new DateTimeImmutable('-1 day')),
            ))
            ->sort('created_at', descending: true)
            ->fields('campaign', 'name', 'status', 'created_at')
            ->pageSize(10)),
        fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 1 && $r['data'][0]->id === $campaign->id, 'exactly our campaign'));

    $h->step('campaigns', 'list', 'list w/ filter any(id,[ours]) + include(campaign-messages)',
        fn(APIClient $c) => $c->campaigns->list((new Query())
            ->filter(Filter::all(Filter::equals('messages.channel', 'email'), Filter::any('id', [$campaign->id])))
            ->include('campaign-messages')),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->getRelationship('campaign-messages') !== null, 'any(id) + include'));

    $h->step('campaigns', 'list', 'list w/o the required channel filter → 400',
        fn(APIClient $c) => $c->campaigns->list((new Query())->pageSize(1)));
    $h->reclassify('api-contract', 'GET /api/campaigns requires a messages.channel filter — 400 by design, documented in CampaignService::list()');

    $h->step('campaigns', 'list', 'list w/ filter equals(name,..) → 400 (spec: name supports `contains` only)',
        fn(APIClient $c) => $c->campaigns->list((new Query())->filter(Filter::all(
            Filter::equals('messages.channel', 'email'),
            Filter::equals('name', $h->name('c')),
        ))));
    $h->reclassify('api-contract', 'spec allows only contains(name,..) on campaigns; equals(name,..) is rejected by the API');

    $h->step('campaigns', 'list', 'list w/ filter archived + status any(...) + sort=-updated_at',
        fn(APIClient $c) => $c->campaigns->list((new Query())->filter(Filter::all(
            Filter::equals('messages.channel', 'email'),
            Filter::equals('archived', false),
            Filter::any('status', ['Draft', 'Scheduled']),
        ))->sort('updated_at', descending: true)->pageSize(5)),
        fn($r) => $h->assert(is_array($r['data']), 'array of campaigns'));

    // ───────────────────────────── message relationships ─────────────────────────────

    $messages = $h->step('campaigns', 'messages', 'messages for campaign w/ fields + include(template,image)',
        fn(APIClient $c) => $c->campaigns->messages($campaign->id, (new Query())
            ->fields('campaign-message', 'definition', 'created_at', 'updated_at', 'send_times')
            ->fields('template', 'name', 'editor_type')
            ->fields('image', 'name', 'image_url')
            ->include('template', 'image')),
        function ($r) use ($h) {
            $h->assert(count($r['data']) === 1, 'one message');
            /** @var CampaignMessage $m */
            $m = $r['data'][0];
            $h->assert($m instanceof CampaignMessage && $m->id, 'CampaignMessage hydrated');
            $h->assert($m->definition?->channel === 'email' && $m->definition->label === $h->name('msg'), 'definition.channel + label');
            $h->assert($m->definition->content?->subject === 'SDK smoke ' . $h->runId, 'nested definition.content hydrated');
        });

    $messageId = $messages['data'][0]->id ?? null;

    $h->step('campaigns', 'messageIds', 'message ids for campaign (identifiers only)',
        fn(APIClient $c) => $c->campaigns->messageIds($campaign->id),
        function ($r) use ($h, $messageId) {
            $h->assert(count($r['data']) === 1 && $r['data'][0] instanceof CampaignMessage, 'hydrates to CampaignMessage');
            $h->assert($r['data'][0]->id === $messageId, 'same id as messages()');
            $h->assert($r['data'][0]->definition === null, 'identifier only — no attributes');
        });

    // ───────────────────────────── tags ─────────────────────────────

    /** @var Tag|null $tag */
    $tag = $h->step('tags', 'create', 'create tag to attach to campaign',
        fn(APIClient $c) => $c->tags->create(new CreateTag($h->name('tag'))),
        fn($r) => $h->assert($r instanceof Tag && $r->id, 'tag created'));
    if ($tag) {
        $h->cleanup('tags', 'delete', 'tag', fn(APIClient $c) => $c->tags->delete($tag->id));
        $h->step('tags', 'tagCampaigns', 'attach tag to campaign',
            fn(APIClient $c) => $c->tags->tagCampaigns($tag->id, [new SharedCampaign($campaign->id)]),
            fn($r) => $h->assert($r === null, '204 void'));
        $h->cleanup('tags', 'untagCampaigns', 'detach tag from campaign', fn(APIClient $c) => $c->tags->untagCampaigns($tag->id, [new SharedCampaign($campaign->id)]));
    }

    $h->step('campaigns', 'tags', 'tags for campaign w/ fields[tag]',
        fn(APIClient $c) => $c->campaigns->tags($campaign->id, (new Query())->fields('tag', 'name')),
        fn($r) => $h->assert(is_array($r['data']) && ($tag === null || (count($r['data']) === 1 && $r['data'][0] instanceof Tag && $r['data'][0]->name === $h->name('tag'))), 'our tag'));

    $h->step('campaigns', 'tagIds', 'tag ids for campaign (identifiers only)',
        fn(APIClient $c) => $c->campaigns->tagIds($campaign->id),
        fn($r) => $h->assert(is_array($r['data']) && ($tag === null || (count($r['data']) === 1 && $r['data'][0]->id === $tag->id)), 'our tag id'));

    // ───────────────────────────── update ─────────────────────────────

    $h->step('campaigns', 'update', 'update name + audiences(included/excluded) + send_strategy(static +45d) + send_options + tracking_options',
        function (APIClient $c) use ($h, $campaign, $list, $list2, $sendAt2) {
            $u = new UpdateCampaign($campaign->id);
            $u->name = $h->name('c-renamed');
            $u->audiences = new CampaignAudiences(included: [$list->id], excluded: $list2 ? [$list2->id] : []);
            $u->send_strategy = CampaignSendStrategy::at($sendAt2, new CampaignSendStrategyOptions(isLocal: false));
            $u->send_options = new CampaignSendOptions(useSmartSending: false);
            $tracking = new CampaignTrackingOptions();
            $tracking->add_tracking_params = false;
            $tracking->is_tracking_clicks = false;
            $tracking->is_tracking_opens = true;
            $u->tracking_options = $tracking;
            return $c->campaigns->update($u, (new Query())->fields('campaign', 'name', 'send_strategy', 'send_options', 'tracking_options', 'audiences', 'status'));
        },
        function ($r) use ($h, $sendAt2, $list2) {
            $h->assert($r instanceof Campaign && $r->name === $h->name('c-renamed'), 'renamed');
            $h->assert($list2 === null || $r->audiences?->excluded === [$list2->id], 'audiences.excluded echoed');
            $h->assert($r->send_options?->use_smart_sending === false, 'send_options updated');
            $h->assert($r->send_strategy?->method === 'static', 'still static');
            $h->assert(str_starts_with((string)$r->send_strategy->datetime, substr($sendAt2, 0, 10)), 'new datetime echoed: ' . var_export($r->send_strategy->datetime, true));
        });

    // ───────────────────────── recipient estimation (async) ─────────────────────────

    /** @var CampaignRecipientEstimationJob|null $estJob */
    $estJob = $h->step('campaigns', 'refreshRecipientEstimation', 'submit recipient estimation job',
        fn(APIClient $c) => $c->campaigns->refreshRecipientEstimation($campaign->id),
        fn($r) => $h->assert($r instanceof CampaignRecipientEstimationJob && $r->id === $campaign->id, 'job keyed by campaign id'));

    if ($estJob) {
        $h->step('campaigns', 'getRecipientEstimationJob', 'poll estimation job until complete (w/ fields)',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $estJob) {
                $j = $c->campaigns->getRecipientEstimationJob($estJob->id, (new Query())->fields('campaign-recipient-estimation-job', 'status'));
                return $j && $j->status === 'complete' ? $j : null;
            }, 60, 5, 'estimation job complete'),
            fn($r) => $h->assert($r instanceof CampaignRecipientEstimationJob && $r->status === 'complete', 'complete'));
        $h->reclassify('account-limitation', 'estimation job never becomes readable: POST answers 202 status=processing, but GET /campaign-recipient-estimation-jobs/{id} answers 404 "No results or results were out of date. Schedule a new estimation." — verified for 5+ min out of suite, with a subscribed profile in the audience');

        $h->step('campaigns', 'getRecipientEstimationJob', 'estimation job while unreadable → SDK maps 404 to null',
            fn(APIClient $c) => $c->campaigns->getRecipientEstimationJob($estJob->id, (new Query())->fields('campaign-recipient-estimation-job', 'status')),
            fn($r) => $h->assert($r === null || ($r instanceof CampaignRecipientEstimationJob && $r->status !== null), 'null (404) or a job with status; got ' . get_debug_type($r)));
    }

    $h->step('campaigns', 'getRecipientEstimationJob', 'unknown estimation job id → null',
        fn(APIClient $c) => $c->campaigns->getRecipientEstimationJob('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('campaigns', 'getRecipientEstimation', 'recipient estimation after refresh (w/ fields)',
        fn(APIClient $c) => $c->campaigns->getRecipientEstimation($campaign->id, (new Query())->fields('campaign-recipient-estimation', 'estimated_recipient_count')),
        function ($r) use ($h, $campaign) {
            $h->assert($r === null || ($r instanceof CampaignRecipientEstimation && $r->id === $campaign->id), 'null (404, estimation never lands on this account) or hydrated estimation');
            if ($r === null) {
                $h->note('getRecipientEstimation: 404 "No results or results were out of date" even after refreshRecipientEstimation + polling; the SDK maps it to null as documented.');
            }
        });

    $h->step('campaigns', 'getRecipientEstimation', 'unknown campaign estimation → null',
        fn(APIClient $c) => $c->campaigns->getRecipientEstimation('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────────────── send jobs (never sent) ─────────────────────────────

    $h->skip('campaigns', 'send', 'POST campaign-send-jobs', 'would send email / no credits');
    $h->skip('campaigns', 'cancelSend', 'PATCH campaign-send-jobs (action cancel)', 'would send email / no credits — requires a live send job first');
    $h->skip('campaigns', 'revertSend', 'PATCH campaign-send-jobs (action revert)', 'would send email / no credits — requires a live send job first');

    $h->step('campaigns', 'getSendJob', 'unknown send job id → null (no send performed)',
        fn(APIClient $c) => $c->campaigns->getSendJob('NOPE01', (new Query())->fields('campaign-send-job', 'status')),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────────────── clone ─────────────────────────────

    /** @var Campaign|null $clone */
    $clone = $h->step('campaigns', 'clone', 'clone campaign w/ new_name',
        fn(APIClient $c) => $c->campaigns->clone(new CampaignClone($campaign->id, $h->name('c-clone'))),
        function ($r) use ($h, $campaign) {
            $h->assert($r instanceof Campaign && $r->id && $r->id !== $campaign->id, 'new campaign id');
            $h->assert($r->name === $h->name('c-clone'), 'new_name applied, got ' . var_export($r->name, true));
            $h->assert($r->status === 'Draft', 'clone is a draft');
        });
    if ($clone) {
        $h->cleanup('campaigns', 'delete', 'cloned campaign', fn(APIClient $c) => $c->campaigns->delete($clone->id));
    }

    $h->step('campaigns', 'list', 'paginate campaigns via links.next (page[size]=1) + Query::cursor()',
        function (APIClient $c) use ($h) {
            $q = fn() => (new Query())->filter(Filter::equals('messages.channel', 'email'))->sort('created_at', descending: true)->pageSize(1);
            $first = $c->campaigns->list($q());
            $h->assert(count($first['data']) === 1, 'first page has 1');
            $h->assert($first['links']?->next !== null, 'links.next present');
            $second = $c->campaigns->list(next: $first['links']->next);
            $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
            $viaCursor = $c->campaigns->list($q()->cursor($first['links']->next));
            $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) equals links.next');
            return $second;
        });

    // ───────────────────────────── campaign messages ─────────────────────────────

    if ($messageId === null) {
        $h->note('No message id resolved — campaignMessages steps skipped.');
        return;
    }

    $h->step('campaignMessages', 'get', 'get message w/ fields[campaign-message,campaign,template,image] + include(campaign,template,image)',
        fn(APIClient $c) => $c->campaignMessages->get($messageId, (new Query())
            ->fields('campaign-message', 'definition', 'send_times', 'created_at', 'updated_at')
            ->fields('campaign', 'name', 'status')
            ->fields('template', 'name', 'editor_type', 'html')
            ->fields('image', 'name', 'image_url', 'format', 'size', 'hidden')
            ->include('campaign', 'template', 'image')),
        function ($r) use ($h, $campaign, $messageId) {
            $h->assert($r instanceof CampaignMessage && $r->id === $messageId, 'our message');
            $h->assert($r->definition?->content?->from_email !== null, 'definition.content.from_email hydrated');
            $rel = $r->getRelationship('campaign');
            $h->assert($rel?->data instanceof \nickdnk\Klaviyo\Resources\Shared\Campaign && $rel->data->id === $campaign->id, 'to-one campaign relationship id hydrated');
        });

    $h->step('campaignMessages', 'get', 'get unknown message → null',
        fn(APIClient $c) => $c->campaignMessages->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('campaignMessages', 'update', 'update definition (label + content subject/preview/from/reply-to/cc/bcc)',
        function (APIClient $c) use ($h, $messageId, $fromEmail) {
            $content = new CampaignMessageContent();
            $content->subject = 'SDK smoke updated ' . $h->runId;
            $content->preview_text = 'Updated preview ' . $h->runId;
            $content->from_email = $fromEmail;
            $content->from_label = 'SDK Smoke v2';
            $content->reply_to_email = $fromEmail;
            $content->cc_email = $fromEmail;
            $content->bcc_email = $fromEmail;
            $def = new CampaignMessageDefinition('email');
            $def->label = $h->name('msg-renamed');
            $def->content = $content;
            return $c->campaignMessages->update(new UpdateCampaignMessage($messageId, $def), (new Query())->fields('campaign-message', 'definition', 'updated_at'));
        },
        function ($r) use ($h) {
            $h->assert($r instanceof CampaignMessage && $r->definition?->label === $h->name('msg-renamed'), 'label updated');
            $h->assert($r->definition->content?->subject === 'SDK smoke updated ' . $h->runId, 'subject updated');
            $h->assert($r->definition->content->from_label === 'SDK Smoke v2', 'from_label updated');
        });

    $h->step('campaignMessages', 'update', 'update email definition w/ render_options (spec: SMS-only field)',
        function (APIClient $c) use ($h, $messageId, $fromEmail) {
            $content = new CampaignMessageContent();
            $content->subject = 'SDK smoke render-opts ' . $h->runId;
            $content->from_email = $fromEmail;
            $content->from_label = 'SDK Smoke v2';
            $def = new CampaignMessageDefinition('email');
            $def->label = $h->name('msg-renamed');
            $def->content = $content;
            $def->render_options = new CampaignMessageRenderOptions(shortenLinks: true, addOrgPrefix: false, addInfoLink: false, addOptOutLanguage: true);
            return $c->campaignMessages->update(new UpdateCampaignMessage($messageId, $def));
        },
        fn($r) => $h->assert($r instanceof CampaignMessage, 'accepted (render_options ignored for email)'));
    $h->reclassify('api-contract', 'EmailMessageDefinition has no render_options in the spec; Klaviyo answers 400 "\'render_options\' is not a valid field for the resource \'EmailMessageDefinition\'" — the SDK docblock already scopes it to SMS');

    if ($template) {
        $h->step('campaignMessages', 'assignTemplate', 'assign CODE template to message (snapshots a non-reusable copy)',
            function (APIClient $c) use ($h, $messageId, $template) {
                // A parallel suite / scratch/sweep.php may have deleted our sdk-smoke-* template
                // in the meantime; recreate it rather than reporting a bogus 404.
                $id = $template->id;
                if ($c->templates->get($id) === null) {
                    $fresh = $c->templates->create(new CreateTemplate($h->name('t2'), 'CODE', '<p>hi</p>', 'hi'));
                    $h->cleanup('templates', 'delete', 'replacement template', fn(APIClient $cc) => $cc->templates->delete($fresh->id));
                    $h->note("Template {$id} disappeared before assignTemplate (parallel sweep?); used {$fresh->id} instead.");
                    $id = $fresh->id;
                }
                return $c->campaignMessages->assignTemplate($messageId, $id);
            },
            fn($r) => $h->assert($r instanceof CampaignMessage && $r->id === $messageId, 'message returned'));
    } else {
        $h->skip('campaignMessages', 'assignTemplate', 'assign template', 'template creation failed in setup');
    }

    $h->step('campaignMessages', 'template', 'template for message w/ fields[template]',
        fn(APIClient $c) => $c->campaignMessages->template($messageId, (new Query())->fields('template', 'name', 'editor_type', 'html', 'created')),
        fn($r) => $h->assert($r instanceof ResponseTemplate && $r->id && $r->editor_type !== null, 'snapshot template hydrated: editor_type=' . var_export($r?->editor_type, true)));

    $h->step('campaignMessages', 'templateId', 'template id for message (identifier only)',
        fn(APIClient $c) => $c->campaignMessages->templateId($messageId),
        fn($r) => $h->assert($r instanceof ResponseTemplate && $r->id && $r->name === null, 'identifier only'));

    // ── image relationship: only legal on sms / mobile_push messages, so use the SMS draft's message
    $smsMessageId = null;
    if ($sms) {
        $smsMessages = $h->step('campaigns', 'messages', 'messages for the SMS campaign (definition.render_options + content.body)',
            fn(APIClient $c) => $c->campaigns->messages($sms->id, (new Query())->fields('campaign-message', 'definition')),
            function ($r) use ($h) {
                $h->assert(count($r['data']) === 1 && $r['data'][0]->definition?->channel === 'sms', 'one sms message');
                $h->assert($r['data'][0]->definition->render_options?->shorten_links === true, 'render_options hydrated on the sms definition');
                $h->assert($r['data'][0]->definition->content?->body === 'SDK smoke sms ' . $h->runId, 'sms content.body hydrated');
            });
        $smsMessageId = $smsMessages['data'][0]->id ?? null;
    }

    if ($image && $smsMessageId) {
        $h->step('campaignMessages', 'updateImage', 'point the SMS message at the uploaded image (204)',
            fn(APIClient $c) => $c->campaignMessages->updateImage($smsMessageId, $image->id),
            fn($r) => $h->assert($r === null, '204 void'));

        $h->step('campaignMessages', 'image', 'image for the SMS message w/ fields[image]',
            fn(APIClient $c) => $c->campaignMessages->image($smsMessageId, (new Query())->fields('image', 'name', 'image_url', 'format', 'size', 'hidden')),
            fn($r) => $h->assert($r instanceof ResponseImage && $r->id === $image->id && $r->image_url, 'our image hydrated'));

        $h->step('campaignMessages', 'imageId', 'image id for the SMS message (identifier only)',
            fn(APIClient $c) => $c->campaignMessages->imageId($smsMessageId),
            fn($r) => $h->assert($r instanceof ResponseImage && $r->id === $image->id && $r->name === null, 'identifier only'));
    } else {
        $h->skip('campaignMessages', 'updateImage', 'update image relationship', 'no image or no sms message available');
        $h->skip('campaignMessages', 'image', 'image for message', 'no image or no sms message available');
        $h->skip('campaignMessages', 'imageId', 'image id for message', 'no image or no sms message available');
    }

    if ($image) {
        $h->step('campaignMessages', 'updateImage', 'point the EMAIL message at an image → 400 (images are sms/push only)',
            fn(APIClient $c) => $c->campaignMessages->updateImage($messageId, $image->id));
        $h->reclassify('api-contract', 'Klaviyo: "Image relationships are only supported for push and SMS messages"; GET image / relationships/image on an email message answer 400 the same way');
    }

    $h->step('campaignMessages', 'campaign', 'campaign for message w/ fields[campaign]',
        fn(APIClient $c) => $c->campaignMessages->campaign($messageId, (new Query())->fields('campaign', 'name', 'status', 'send_strategy')),
        fn($r) => $h->assert($r instanceof Campaign && $r->id === $campaign->id && $r->name === $h->name('c-renamed'), 'parent campaign'));

    $h->step('campaignMessages', 'campaignId', 'campaign id for message (identifier only)',
        fn(APIClient $c) => $c->campaignMessages->campaignId($messageId),
        fn($r) => $h->assert($r instanceof Campaign && $r->id === $campaign->id && $r->name === null, 'identifier only'));

    // ───────────────────── returnRequest + executePool ─────────────────────

    $h->step('campaigns', 'executePool', 'executePool: campaigns.get + campaignMessages.get + campaigns.get(unknown) via returnRequest',
        function (APIClient $c) use ($h, $campaign, $messageId, $clone) {
            $reqs = [
                $c->campaigns->get($campaign->id, (new Query())->fields('campaign', 'name'), returnRequest: true),
                $c->campaignMessages->get($messageId, (new Query())->fields('campaign-message', 'definition'), returnRequest: true),
                $c->campaigns->get('NOPE01', returnRequest: true),
                $clone ? $c->campaigns->messageIds($clone->id, returnRequest: true) : $c->campaigns->messageIds($campaign->id, returnRequest: true),
            ];
            $res = $c->executePool($reqs, 4);
            $h->assert(count($res) === 4, '4 results');
            $h->assert($res[0] instanceof Campaign && $res[0]->id === $campaign->id, 'campaign');
            $h->assert($res[1] instanceof CampaignMessage && $res[1]->id === $messageId, 'message');
            $h->assert($res[2] instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $res[2]->getHttpStatus() === 404, 'unknown id → 404 exception in pool');
            // executePool hands back decodeResponse()['data'] — a bare list for collection
            // endpoints, without the ['data' => …, 'links' => …] wrapper list() returns.
            $h->assert(is_array($res[3]) && count($res[3]) === 1 && $res[3][0] instanceof CampaignMessage, 'messageIds via pool → bare list of CampaignMessage');
            return $res;
        });

    $h->note('No campaign was ever sent: send / cancelSend / revertSend are skipped; the drafts were scheduled ~30-45 days out and deleted in cleanup.');
};
