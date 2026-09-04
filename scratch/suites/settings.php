<?php
/**
 * Account-level settings & integrations: AccountService, FormService, FormVersionService,
 * WebFeedService, TrackingSettingService, WebhookService, WebhookTopicService, ReviewService
 * and ConversationMessageService (skipped — it delivers to a human).
 *
 * Everything created here is deleted in cleanup. The one non-undoable touch is the UTM
 * tracking setting, which is read first and restored to its original value at the end.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateForm;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\CreateWebFeed;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Request\UpdateReview;
use nickdnk\Klaviyo\Resources\Request\UpdateTrackingSetting;
use nickdnk\Klaviyo\Resources\Request\UpdateWebFeed;
use nickdnk\Klaviyo\Resources\Request\UpdateWebhook;
use nickdnk\Klaviyo\Resources\Response\Account;
use nickdnk\Klaviyo\Resources\Response\Form;
use nickdnk\Klaviyo\Resources\Response\FormVersion;
use nickdnk\Klaviyo\Resources\Response\Review;
use nickdnk\Klaviyo\Resources\Response\TrackingSetting;
use nickdnk\Klaviyo\Resources\Response\WebFeed;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Response\ContactInformation;
use nickdnk\Klaviyo\Resources\Response\StreetAddress;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use Smoke\Harness;

return function (Harness $h): void {

    // Sparse-fieldset enums straight out of scratch/openapi/stable.json.
    $accountFields = ['contact_information', 'industry', 'timezone', 'preferred_currency', 'public_api_key', 'locale', 'test_account'];
    $formFields = ['ab_test', 'created_at', 'name', 'status', 'updated_at', 'definition', 'definition.versions'];
    $formVersionFields = ['ab_test', 'ab_test.variation_name', 'created_at', 'form_type', 'status', 'updated_at', 'variation_name'];
    $webFeedFields = ['content_type', 'created', 'name', 'request_method', 'status', 'updated', 'url'];
    $trackingFields = ['auto_add_parameters', 'custom_parameters', 'utm_campaign', 'utm_id', 'utm_medium', 'utm_source', 'utm_term'];
    $webhookFields = ['created_at', 'description', 'enabled', 'endpoint_url', 'name', 'updated_at'];
    $reviewFields = ['author', 'content', 'created', 'email', 'images', 'product', 'public_reply', 'rating', 'review_type', 'smart_quote', 'status', 'title', 'updated', 'verified'];
    $eventFields = ['datetime', 'event_properties', 'timestamp', 'uuid'];

    // ═════════════════════════════ AccountService ═════════════════════════════

    $accounts = $h->step('accounts', 'list', 'list accounts w/ fields[account] (all attributes)',
        fn(APIClient $c) => $c->accounts->list((new Query())->fields('account', ...$accountFields)),
        function ($r) use ($h) {
            $h->assert(is_array($r) && array_key_exists('data', $r) && array_key_exists('links', $r), 'list envelope {data, links}');
            $h->assert(count($r['data']) === 1, 'API key is scoped to exactly one account');
            $a = $r['data'][0];
            $h->assert($a instanceof Account && $a->id !== null, 'hydrated Account with id');
            $h->assert($a->contact_information instanceof ContactInformation, 'contact_information hydrated to its own class');
            $h->assert(is_string($a->contact_information->organization_name), 'contact_information.organization_name populated');
            $h->assert($a->contact_information->street_address instanceof StreetAddress && $a->contact_information->street_address->city !== null, 'street_address hydrated two levels deep');
            $h->assert($a->timezone !== null && $a->preferred_currency !== null && $a->public_api_key !== null && $a->locale !== null, 'scalar attributes present');
            $h->assert($a->test_account === true, 'smoke account is flagged test_account');
        });

    $accountId = $accounts['data'][0]->id ?? null;
    if ($accountId === null) {
        $h->note('No account id — every id-addressed call below is skipped.');
        return;
    }
    $h->note("Account {$accountId} (public_api_key doubles as the account id and the tracking-setting id).");

    $h->step('accounts', 'list', 'list accounts w/o query (default fieldset)',
        fn(APIClient $c) => $c->accounts->list(),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $accountId && $r['data'][0]->contact_information instanceof ContactInformation, 'same account, full fieldset'));

    $h->step('accounts', 'get', 'get account w/ fields[account] (all attributes)',
        fn(APIClient $c) => $c->accounts->get($accountId, (new Query())->fields('account', ...$accountFields)),
        function ($r) use ($h, $accountId) {
            $h->assert($r instanceof Account && $r->id === $accountId, 'hydrated Account');
            $h->assert($r->public_api_key === $accountId, 'public_api_key equals the account id');
            $h->assert($r->contact_information instanceof ContactInformation && $r->contact_information->default_sender_email !== null, 'sender email present');
        });

    $h->step('accounts', 'get', 'get account w/ narrow fields[account]=timezone,locale',
        fn(APIClient $c) => $c->accounts->get($accountId, (new Query())->fields('account', 'timezone', 'locale')),
        fn($r) => $h->assert($r instanceof Account && $r->timezone !== null && $r->locale !== null && $r->public_api_key === null && $r->contact_information === null, 'sparse fieldset really omits the rest'));

    $h->step('accounts', 'get', 'get unknown account id → null',
        fn(APIClient $c) => $c->accounts->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ═════════════════════════════ FormService ═════════════════════════════
    //
    // POST /api/forms wants the whole version tree inline. The minimal shape the API actually
    // accepts (found by iterating on the error pointers, see notes at the bottom):
    //   definition.versions[].steps  — at least TWO steps
    //   step.columns[].rows[].blocks[] — blocks with {type, properties}
    //   the submit button's action needs properties.list_id, so a list fixture is required.
    $formList = null;
    try {
        $formList = $h->client->lists->create(new CreateList($h->name('form-list')));
        $h->cleanup('lists', 'delete', 'fixture list for the form submit action', fn(APIClient $c) => $c->lists->delete($formList->id));
    } catch (Throwable $e) {
        $h->note('Could not create the fixture list for form submit actions: ' . $e->getMessage());
    }

    $text = fn(string $t) => ['type' => 'html_text', 'properties' => ['content' => "<p>{$t}</p>"]];
    $emailBlock = ['type' => 'email', 'properties' => ['label' => 'Email', 'property_name' => '$email', 'required' => true, 'show_label' => true, 'placeholder' => 'you@example.com']];
    $submitBlock = fn(?string $listId) => ['type' => 'button', 'properties' => ['label' => 'Subscribe'], 'styles' => ['background_color' => '#000000', 'width' => 'fill', 'alignment' => 'center'], 'action' => ['type' => 'next_step', 'submit' => true, 'properties' => ['list_id' => $listId]]];
    $closeBlock = ['type' => 'button', 'properties' => ['label' => 'Close'], 'action' => ['type' => 'close', 'submit' => false]];

    $version = function (string $name, string $type, ?string $location, bool $abTest) use ($text, $emailBlock, $submitBlock, $closeBlock, $formList) {
        $v = [
            'steps'    => [
                ['name' => 'Signup', 'columns' => [['rows' => [['blocks' => [$text('Join our list'), $emailBlock, $submitBlock($formList?->id)]]]]]],
                ['name' => 'Success', 'columns' => [['rows' => [['blocks' => [$text('Thanks for signing up!'), $closeBlock]]]]]],
            ],
            'triggers' => [],
            'teasers'  => [],
            'name'     => $name,
            'styles'   => [
                'width'          => 'medium',
                'background_color' => '#ffffff',
                'overlay_color'  => 'rgba(0,0,0,0.4)',
                'minimum_height' => 200,
                'margin'         => ['top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0],
                'padding'        => ['top' => 20, 'bottom' => 20, 'left' => 20, 'right' => 20],
            ],
            'properties' => ['show_close_button' => true, 'record_utm_params_on_submit' => true, 'rule_based_trigger_evaluation' => 'all', 'accessible_name' => 'Smoke signup form'],
            'type'       => $type,
            'status'     => 'draft',
            'ab_test'    => $abTest,
            'specialties' => [],
            'channel'    => 'WEB',
            'message_priority' => 1,
        ];
        if ($location !== null) {
            $v['location'] = $location; // only legal on flyout
        }
        return $v;
    };

    /** @var Form|null $popup */
    $popup = $h->step('forms', 'create', 'create popup form (rich version: styles, properties, 2 steps, 5 blocks) + fields[form]',
        fn(APIClient $c) => $c->forms->create(
            new CreateForm($h->name('form-popup'), ['versions' => [$version('V1', 'popup', null, false)]]),
            (new Query())->fields('form', ...$formFields)
        ),
        function ($r) use ($h) {
            $h->assert($r instanceof Form && $r->id !== null, 'hydrated Form with id');
            $h->assert($r->name === $h->name('form-popup'), 'name echoed');
            $h->assert($r->status === 'draft', 'created as draft (only legal status on create)');
            $h->assert($r->ab_test === false, 'ab_test echoed');
            $h->assert($r->created_at !== null && $r->updated_at !== null, 'timestamps');
            $h->assert($r->definition instanceof AttributeBag && is_array($r->definition->versions) && count($r->definition->versions) === 1, 'definition hydrated to AttributeBag with 1 version');
        });
    if ($popup) {
        $h->cleanup('forms', 'delete', 'popup form', fn(APIClient $c) => $c->forms->delete($popup->id));
    }

    /** @var Form|null $flyout */
    $flyout = $h->step('forms', 'create', 'create flyout form (type=flyout + location=bottom_right)',
        fn(APIClient $c) => $c->forms->create(new CreateForm($h->name('form-flyout'), ['versions' => [$version('V1', 'flyout', 'bottom_right', false)]])),
        fn($r) => $h->assert($r instanceof Form && $r->id !== null && $r->status === 'draft', 'flyout form created'));
    if ($flyout) {
        $h->cleanup('forms', 'delete', 'flyout form', fn(APIClient $c) => $c->forms->delete($flyout->id));
    }

    /** @var Form|null $abForm */
    $abForm = $h->step('forms', 'create', 'create A/B-test form (ab_test=true, two versions)',
        fn(APIClient $c) => $c->forms->create(new CreateForm(
            $h->name('form-abtest'),
            ['versions' => [$version('V1', 'popup', null, true), $version('V2', 'popup', null, true)]],
            abTest: true
        )),
        fn($r) => $h->assert($r instanceof Form && $r->id !== null && $r->ab_test === true, 'A/B form created'));
    if ($abForm) {
        $h->cleanup('forms', 'delete', 'A/B-test form', fn(APIClient $c) => $c->forms->delete($abForm->id));
    }

    $h->step('forms', 'create', 'create form w/ one step → 400 "Versions must have at least two steps"',
        fn(APIClient $c) => (function () use ($c, $h, $version) {
            $bad = $version('V1', 'popup', null, false);
            $bad['steps'] = [$bad['steps'][0]];
            try {
                $f = $c->forms->create(new CreateForm($h->name('form-invalid'), ['versions' => [$bad]]));
                $h->cleanup('forms', 'delete', 'unexpectedly-created invalid form', fn(APIClient $cc) => $cc->forms->delete($f->id));
                return 'no-exception';
            } catch (ClientException $e) {
                return $e;
            }
        })(),
        fn($r) => $h->assert($r instanceof ClientException && $r->getHttpStatus() === 400
            && str_contains($r->getFirstError()?->detail ?? '', 'at least two steps')
            && ($r->getFirstError()?->source['pointer'] ?? null) === '/data/attributes/definition/versions/0',
            '400 with a JSON-pointer into the definition'));

    if (!$popup) {
        $h->note('Popup form creation failed — the form/form-version read steps below cannot run.');
    } else {
        $h->step('forms', 'get', 'get form w/ fields[form] (incl. definition + definition.versions)',
            fn(APIClient $c) => $c->forms->get($popup->id, (new Query())->fields('form', ...$formFields)),
            function ($r) use ($h, $popup) {
                $h->assert($r instanceof Form && $r->id === $popup->id, 'same form');
                $h->assert($r->definition instanceof AttributeBag, 'definition is an AttributeBag');
                $v = $r->definition->versions[0] ?? null;
                $h->assert(is_array($v) && isset($v['steps']) && count($v['steps']) === 2, 'both steps round-tripped');
                $h->assert(($v['type'] ?? null) === 'popup' && ($v['status'] ?? null) === 'draft', 'version type/status round-tripped');
            });

        $h->step('forms', 'get', 'get form w/ narrow fields[form]=name,status',
            fn(APIClient $c) => $c->forms->get($popup->id, (new Query())->fields('form', 'name', 'status')),
            fn($r) => $h->assert($r instanceof Form && $r->name === $h->name('form-popup') && $r->definition === null && $r->created_at === null, 'sparse fieldset honoured'));

        $h->step('forms', 'get', 'get unknown form → null',
            fn(APIClient $c) => $c->forms->get('NOPE01'),
            fn($r) => $h->assert($r === null, 'null on 404'));

        $h->step('forms', 'list', 'list forms w/ filter equals(status,draft) + fields + page[size]=100 + sort=-created_at',
            fn(APIClient $c) => $c->forms->list((new Query())
                ->filter(Filter::equals('status', 'draft'))
                ->fields('form', 'name', 'status', 'created_at')
                ->pageSize(100)
                ->sort('created_at', descending: true)),
            function ($r) use ($h, $popup) {
                $h->assert(count($r['data']) >= 1, 'at least our own draft');
                $h->assert(array_reduce($r['data'], fn($ok, $f) => $ok && $f->status === 'draft', true), 'every row is a draft');
                $h->assert(in_array($popup->id, array_map(fn($f) => $f->id, $r['data']), true), 'our popup is in there');
            });

        $h->step('forms', 'list', 'list forms w/ filter contains(name,<runId>)',
            fn(APIClient $c) => $c->forms->list((new Query())->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))->pageSize(20)),
            fn($r) => $h->assert(count($r['data']) === count(array_filter([$popup, $flyout, $abForm])), 'contains(name) matches exactly the forms this run created'));

        $h->step('forms', 'list', 'list forms w/ filter all(equals(ab_test,false), greater-than(created_at,-1h))',
            fn(APIClient $c) => $c->forms->list((new Query())->filter(Filter::all(
                Filter::equals('ab_test', false),
                Filter::greaterThan('created_at', new DateTimeImmutable('-1 hour')),
            ))->fields('form', 'name', 'ab_test')->pageSize(50)),
            function ($r) use ($h, $popup, $abForm) {
                $ids = array_map(fn($f) => $f->id, $r['data']);
                $h->assert(in_array($popup->id, $ids, true), 'non-A/B form present');
                $h->assert($abForm === null || !in_array($abForm->id, $ids, true), 'A/B form filtered out');
            });

        $h->step('forms', 'list', 'list forms w/ filter any(id,[...]) + sort=updated_at',
            fn(APIClient $c) => $c->forms->list((new Query())
                ->filter(Filter::any('id', array_values(array_map(fn($f) => $f->id, array_filter([$popup, $flyout, $abForm])))))
                ->sort('updated_at')),
            fn($r) => $h->assert(count($r['data']) === count(array_filter([$popup, $flyout, $abForm])), 'any(id) returns our forms'));

        $h->step('forms', 'list', 'paginate forms via links.next (page[size]=1) + Query::cursor',
            function (APIClient $c) use ($h) {
                $first = $c->forms->list((new Query())->pageSize(1)->sort('created_at'));
                $h->assert(count($first['data']) === 1, 'first page has 1 form');
                $h->assert($first['links']?->next !== null, 'links.next present');
                $second = $c->forms->list(next: $first['links']->next);
                $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page is a different form');
                $viaCursor = $c->forms->list((new Query())->pageSize(1)->sort('created_at')->cursor($first['links']->next));
                $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(links.next) == next: argument');
                return $second;
            });

        $versions = $h->step('forms', 'versions', 'versions for popup form w/ fields[form-version] + sort=-created_at + page[size]=100',
            fn(APIClient $c) => $c->forms->versions($popup->id, (new Query())
                ->fields('form-version', ...$formVersionFields)
                ->sort('created_at', descending: true)
                ->pageSize(100)),
            function ($r) use ($h) {
                $h->assert(count($r['data']) === 1, 'the popup form has exactly one version');
                $v = $r['data'][0];
                $h->assert($v instanceof FormVersion && $v->id !== null, 'hydrated FormVersion');
                $h->assert($v->form_type === 'popup' && $v->status === 'draft', 'form_type + status');
                $h->assert($v->variation_name === 'V1', 'variation_name comes from the version name');
                $h->assert($v->created_at !== null && $v->updated_at !== null, 'timestamps');
            });
        $versionId = $versions['data'][0]->id ?? null;

        $h->step('forms', 'versions', 'versions w/ filter equals(form_type,popup)',
            fn(APIClient $c) => $c->forms->versions($popup->id, (new Query())->filter(Filter::equals('form_type', 'popup'))),
            fn($r) => $h->assert(count($r['data']) === 1, 'form_type filter matches'));

        $h->step('forms', 'versions', 'versions w/ filter all(equals(status,draft), greater-than(created_at,-1h), less-or-equal(updated_at,+1h))',
            fn(APIClient $c) => $c->forms->versions($popup->id, (new Query())->filter(Filter::all(
                Filter::equals('status', 'draft'),
                Filter::greaterThan('created_at', new DateTimeImmutable('-1 hour')),
                Filter::lessOrEqual('updated_at', new DateTimeImmutable('+1 hour')),
            ))),
            fn($r) => $h->assert(count($r['data']) === 1, 'combined version filter'));

        // stable.json documents `any` for form_type on this endpoint, but Klaviyo answers 500 for it.
        $h->step('forms', 'versions', 'versions w/ filter any(form_type,[popup,embed]) → Klaviyo 500',
            fn(APIClient $c) => $c->forms->versions($popup->id, (new Query())->filter(Filter::any('form_type', ['popup', 'embed']))),
            fn($r) => $h->assert(count($r['data']) === 1, 'any(form_type) filter'));
        $h->reclassify('api-bug', 'HTTP 500 from GET /api/forms/{id}/form-versions?filter=any(form_type,[...]). stable.json lists `any` as an allowed operator for form_type; equals(form_type,popup) on the same form works and any(form_type,["popup"]) alone also 500s, so it is the operator, not the value.');

        $h->step('forms', 'versions', 'versions w/ filter equals(form_type,banner) → empty',
            fn(APIClient $c) => $c->forms->versions($popup->id, (new Query())->filter(Filter::equals('form_type', 'banner'))),
            fn($r) => $h->assert(is_array($r['data']) && count($r['data']) === 0, 'no banner versions'));

        $h->step('forms', 'versionIds', 'version ids for popup form (identifier-only hydration)',
            fn(APIClient $c) => $c->forms->versionIds($popup->id),
            function ($r) use ($h, $versionId) {
                $h->assert(count($r['data']) === 1, 'one identifier');
                $h->assert($r['data'][0] instanceof FormVersion && $r['data'][0]->id === $versionId, 'FormVersion with only id set');
                $h->assert($r['data'][0]->form_type === null && $r['data'][0]->status === null, 'no attributes on an identifier payload');
            });

        $h->step('forms', 'versionIds', 'version ids w/ filter equals(status,draft) + sort=-updated_at + page[size]=100',
            fn(APIClient $c) => $c->forms->versionIds($popup->id, (new Query())
                ->filter(Filter::equals('status', 'draft'))
                ->sort('updated_at', descending: true)
                ->pageSize(100)),
            fn($r) => $h->assert(count($r['data']) === 1, 'filtered identifiers'));

        if ($abForm) {
            $h->step('forms', 'versions', 'versions for the A/B form (baseline + 2 variations)',
                fn(APIClient $c) => $c->forms->versions($abForm->id, (new Query())->fields('form-version', ...$formVersionFields)),
                function ($r) use ($h) {
                    $h->assert(count($r['data']) === 3, 'A/B form gets 2 variations plus a baseline version');
                    $withAb = array_filter($r['data'], fn($v) => $v->ab_test instanceof AttributeBag);
                    $h->assert(count($withAb) === 2, 'exactly the two variations carry an ab_test object');
                    $h->assert(array_reduce($withAb, fn($ok, $v) => $ok && $v->ab_test->variation_name !== null, true), 'ab_test.variation_name hydrated');
                });

            // page[size] is accepted but ignored on both form-version endpoints (see the note below),
            // so there is never a links.next to follow; assert the behaviour actually observed.
            $h->step('forms', 'versions', 'versions w/ page[size]=1 + sort=created_at (server ignores page[size])',
                function (APIClient $c) use ($h, $abForm) {
                    $p1 = $c->forms->versions($abForm->id, (new Query())->pageSize(1)->sort('created_at'));
                    $h->assert(count($p1['data']) === 3, 'page[size]=1 still returns all 3 versions');
                    $h->assert($p1['links'] === null || $p1['links']->next === null, 'and no links.next is offered');
                    $ids = array_map(fn($v) => $v->id, $p1['data']);
                    $h->assert($ids === array_values(array_unique($ids)), 'no duplicate versions');
                    return $p1;
                });

            $h->step('forms', 'versionIds', 'version ids w/ page[size]=2 + sort=created_at (server ignores page[size])',
                function (APIClient $c) use ($h, $abForm) {
                    $p1 = $c->forms->versionIds($abForm->id, (new Query())->pageSize(2)->sort('created_at'));
                    $h->assert(count($p1['data']) === 3, 'page[size]=2 still returns all 3 identifiers');
                    $h->assert($p1['links'] === null || $p1['links']->next === null, 'no links.next');
                    return $p1;
                });

            $h->step('forms', 'versionIds', 'version ids fetched through the next: argument (links.self replayed)',
                function (APIClient $c) use ($h, $abForm) {
                    $p1 = $c->forms->versionIds($abForm->id, (new Query())->pageSize(2));
                    $h->assert($p1['links']?->self !== null, 'links.self present');
                    $again = $c->forms->versionIds($abForm->id, next: $p1['links']->self);
                    $h->assert(count($again['data']) === count($p1['data']), 'next: <url> re-fetches that URL verbatim');
                    return $again;
                });
        }

        // ═══════════════════════ FormVersionService ═══════════════════════

        if ($versionId === null) {
            $h->note('No form-version id — formVersions.* steps skipped.');
        } else {
            $h->step('formVersions', 'get', 'get form version w/ fields[form-version] + fields[form] + include(form)',
                fn(APIClient $c) => $c->formVersions->get($versionId, (new Query())
                    ->fields('form-version', ...$formVersionFields)
                    ->fields('form', 'name', 'status', 'ab_test', 'created_at', 'updated_at')
                    ->include('form')),
                function ($r) use ($h, $versionId, $popup) {
                    $h->assert($r instanceof FormVersion && $r->id === $versionId, 'hydrated FormVersion');
                    $h->assert($r->form_type === 'popup' && $r->status === 'draft' && $r->variation_name === 'V1', 'attributes');
                    $rel = $r->getRelationship('form');
                    $h->assert($rel instanceof Relationship, 'form relationship present');
                    $h->assert($rel->data instanceof Form && $rel->data->id === $popup->id, 'to-one relationship hydrated to the parent Form');
                });

            $h->step('formVersions', 'get', 'get form version w/ narrow fields[form-version]=form_type',
                fn(APIClient $c) => $c->formVersions->get($versionId, (new Query())->fields('form-version', 'form_type')),
                fn($r) => $h->assert($r instanceof FormVersion && $r->form_type === 'popup' && $r->status === null && $r->created_at === null, 'sparse fieldset honoured'));

            $h->step('formVersions', 'get', 'get unknown form version → null',
                fn(APIClient $c) => $c->formVersions->get('1'),
                fn($r) => $h->assert($r === null, 'null on 404'));

            $h->step('formVersions', 'form', 'form for form version w/ fields[form]',
                fn(APIClient $c) => $c->formVersions->form($versionId, (new Query())->fields('form', 'name', 'status', 'ab_test')),
                function ($r) use ($h, $popup) {
                    $h->assert($r instanceof Form && $r->id === $popup->id, 'parent form');
                    $h->assert($r->name === $h->name('form-popup') && $r->status === 'draft' && $r->ab_test === false, 'attributes via fields[form]');
                });

            $h->step('formVersions', 'formId', 'form id for form version (identifier only)',
                fn(APIClient $c) => $c->formVersions->formId($versionId),
                function ($r) use ($h, $popup) {
                    $h->assert($r instanceof Form && $r->id === $popup->id, 'Form identifier');
                    $h->assert($r->name === null && $r->status === null, 'no attributes on an identifier payload');
                });

            $h->step('formVersions', 'form', 'form for unknown form version → null',
                fn(APIClient $c) => $c->formVersions->form('1'),
                fn($r) => $h->assert($r === null, 'null on 404'));

            $h->step('formVersions', 'formId', 'form id for unknown form version → null',
                fn(APIClient $c) => $c->formVersions->formId('1'),
                fn($r) => $h->assert($r === null, 'null on 404'));
        }
    }

    // ═════════════════════════════ WebFeedService ═════════════════════════════
    //
    // Klaviyo validates the feed at save time: it fetches the URL with the given method and
    // parses it as the given content type, and the name must match ^[0-9_A-z]+$ (no dashes),
    // so $h->name() is transliterated to underscores here.
    $feedName = fn(string $what) => str_replace('-', '_', $h->name($what));
    $jsonUrl = 'https://help.klaviyo.com/api/v2/help_center/en-us/articles.json';
    $xmlUrl = 'https://feeds.bbci.co.uk/news/rss.xml';
    $postJsonUrl = 'https://postman-echo.com/post';

    /** @var WebFeed|null $feedJson */
    $feedJson = $h->step('webFeeds', 'create', 'create web feed (get/json) + fields[web-feed]',
        fn(APIClient $c) => $c->webFeeds->create(
            new CreateWebFeed($feedName('feed-json'), $jsonUrl, 'get', 'json'),
            (new Query())->fields('web-feed', ...$webFeedFields)
        ),
        function ($r) use ($h, $feedName, $jsonUrl) {
            $h->assert($r instanceof WebFeed && $r->id !== null, 'hydrated WebFeed with id');
            $h->assert($r->name === $feedName('feed-json') && $r->url === $jsonUrl, 'name + url echoed');
            $h->assert($r->request_method === 'get' && $r->content_type === 'json', 'method + content type echoed');
            $h->assert($r->created !== null && $r->updated !== null, 'timestamps');
        });
    if ($feedJson) {
        $h->cleanup('webFeeds', 'delete', 'json web feed', fn(APIClient $c) => $c->webFeeds->delete($feedJson->id));
    }

    /** @var WebFeed|null $feedXml */
    $feedXml = $h->step('webFeeds', 'create', 'create web feed (get/xml, RSS URL)',
        fn(APIClient $c) => $c->webFeeds->create(new CreateWebFeed($feedName('feed-xml'), $xmlUrl, 'get', 'xml')),
        fn($r) => $h->assert($r instanceof WebFeed && $r->id !== null && $r->content_type === 'xml' && $r->request_method === 'get', 'xml feed created'));
    if ($feedXml) {
        $h->cleanup('webFeeds', 'delete', 'xml web feed', fn(APIClient $c) => $c->webFeeds->delete($feedXml->id));
    }

    /** @var WebFeed|null $feedPost */
    $feedPost = $h->step('webFeeds', 'create', 'create web feed (post/json, echo endpoint)',
        fn(APIClient $c) => $c->webFeeds->create(new CreateWebFeed($feedName('feed-post'), $postJsonUrl, 'post', 'json')),
        fn($r) => $h->assert($r instanceof WebFeed && $r->id !== null && $r->request_method === 'post', 'post feed created'));
    if ($feedPost) {
        $h->cleanup('webFeeds', 'delete', 'post web feed', fn(APIClient $c) => $c->webFeeds->delete($feedPost->id));
    }

    $h->skip('webFeeds', 'create', 'create web feed (post/xml)', 'Klaviyo validates the feed by POSTing to the URL and parsing the body as XML; no public endpoint returns valid XML for a POST, so this enum combination is untestable. get/xml and post/json are covered separately.');

    $h->step('webFeeds', 'create', 'create web feed with a dashed name → 400 (name must match ^[0-9_A-z]+$)',
        fn(APIClient $c) => (function () use ($c, $h, $jsonUrl) {
            try {
                $f = $c->webFeeds->create(new CreateWebFeed($h->name('feed-dashed'), $jsonUrl));
                $h->cleanup('webFeeds', 'delete', 'unexpectedly-created dashed feed', fn(APIClient $cc) => $cc->webFeeds->delete($f->id));
                return 'no-exception';
            } catch (ClientException $e) {
                return $e;
            }
        })(),
        fn($r) => $h->assert($r instanceof ClientException && $r->getHttpStatus() === 400 && ($r->getFirstError()?->source['pointer'] ?? null) === '/data/attributes/name', '400 pointing at /data/attributes/name'));

    if (!$feedJson) {
        $h->note('Web feed creation failed — the web-feed read/update steps below cannot run.');
    } else {
        $h->step('webFeeds', 'get', 'get web feed w/ fields[web-feed] (all attributes)',
            fn(APIClient $c) => $c->webFeeds->get($feedJson->id, (new Query())->fields('web-feed', ...$webFeedFields)),
            function ($r) use ($h, $feedJson, $jsonUrl) {
                $h->assert($r instanceof WebFeed && $r->id === $feedJson->id, 'same feed');
                $h->assert($r->url === $jsonUrl && $r->content_type === 'json', 'attributes');
            });

        $h->step('webFeeds', 'get', 'get web feed w/ narrow fields[web-feed]=name',
            fn(APIClient $c) => $c->webFeeds->get($feedJson->id, (new Query())->fields('web-feed', 'name')),
            fn($r) => $h->assert($r instanceof WebFeed && $r->name !== null && $r->url === null && $r->content_type === null, 'sparse fieldset honoured'));

        $h->step('webFeeds', 'get', 'get unknown web feed → null',
            fn(APIClient $c) => $c->webFeeds->get('0'),
            fn($r) => $h->assert($r === null, 'null on 404'));

        $h->step('webFeeds', 'list', 'list web feeds w/ filter equals(name) + fields + page[size]=20',
            fn(APIClient $c) => $c->webFeeds->list((new Query())
                ->filter(Filter::equals('name', $feedName('feed-json')))
                ->fields('web-feed', ...$webFeedFields)
                ->pageSize(20)),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $feedJson->id, 'equals(name) matches exactly ours'));

        $h->step('webFeeds', 'list', 'list web feeds w/ filter contains(name,<runId>) + sort=-name',
            fn(APIClient $c) => $c->webFeeds->list((new Query())
                ->filter(Filter::contains('name', 'sdk_smoke_' . $h->runId))
                ->sort('name', descending: true)
                ->pageSize(20)),
            function ($r) use ($h, $feedJson, $feedXml, $feedPost) {
                $expected = count(array_filter([$feedJson, $feedXml, $feedPost]));
                $h->assert(count($r['data']) === $expected, "contains(name) matches the {$expected} feeds this run created");
                $names = array_map(fn($f) => $f->name, $r['data']);
                $sorted = $names;
                rsort($sorted);
                $h->assert($names === $sorted, 'sort=-name respected');
            });

        $h->step('webFeeds', 'list', 'list web feeds w/ filter any(name,[...]) + all(greater-or-equal(created), less-than(updated))',
            fn(APIClient $c) => $c->webFeeds->list((new Query())->filter(Filter::all(
                Filter::any('name', array_values(array_filter([$feedJson?->name, $feedXml?->name, $feedPost?->name]))),
                Filter::greaterOrEqual('created', new DateTimeImmutable('-1 hour')),
                Filter::lessThan('updated', new DateTimeImmutable('+1 hour')),
            ))->sort('created')->pageSize(20)),
            fn($r) => $h->assert(count($r['data']) === count(array_filter([$feedJson, $feedXml, $feedPost])), 'combined name+date filter'));

        $h->step('webFeeds', 'list', 'paginate web feeds via links.next (page[size]=1) + Query::cursor',
            function (APIClient $c) use ($h) {
                $p1 = $c->webFeeds->list((new Query())->pageSize(1)->sort('created'));
                $h->assert(count($p1['data']) === 1, 'page 1 has one feed');
                $h->assert($p1['links']?->next !== null, 'links.next present');
                $p2 = $c->webFeeds->list(next: $p1['links']->next);
                $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
                $viaCursor = $c->webFeeds->list((new Query())->pageSize(1)->sort('created')->cursor($p1['links']->next));
                $h->assert($viaCursor['data'][0]->id === $p2['data'][0]->id, 'Query::cursor == next: argument');
                return $p2;
            });

        $h->step('webFeeds', 'update', 'update web feed: name + url + request_method + content_type + fields[web-feed]',
            function (APIClient $c) use ($h, $feedName, $postJsonUrl, $feedJson, $webFeedFields) {
                $u = new UpdateWebFeed($feedJson->id);
                $u->name = $feedName('feed-json-renamed');
                $u->url = $postJsonUrl;
                $u->request_method = 'post';
                $u->content_type = 'json';
                return $c->webFeeds->update($u, (new Query())->fields('web-feed', ...$webFeedFields));
            },
            function ($r) use ($h, $feedName, $postJsonUrl, $feedJson) {
                $h->assert($r instanceof WebFeed && $r->id === $feedJson->id, 'same id');
                $h->assert($r->name === $feedName('feed-json-renamed'), 'renamed');
                $h->assert($r->url === $postJsonUrl && $r->request_method === 'post' && $r->content_type === 'json', 'url/method/content_type updated');
            });

        $h->step('webFeeds', 'update', 'update web feed: name only (partial PATCH leaves url/method alone)',
            function (APIClient $c) use ($h, $feedName, $feedJson) {
                $u = new UpdateWebFeed($feedJson->id);
                $u->name = $feedName('feed-json-final');
                return $c->webFeeds->update($u);
            },
            fn($r) => $h->assert($r instanceof WebFeed && $r->name === $feedName('feed-json-final') && $r->request_method === 'post' && $r->url === $postJsonUrl, 'only the name changed'));
    }

    $h->note('GET /api/web-feeds only allows filtering on name/created/updated (no `status` filter per stable.json), so equals(status,..) is not exercised.');

    // ═══════════════════════ TrackingSettingService ═══════════════════════

    $trackingList = $h->step('trackingSettings', 'list', 'list tracking settings w/ page[size]=1 (max) + fields[tracking-setting]',
        fn(APIClient $c) => $c->trackingSettings->list((new Query())->pageSize(1)->fields('tracking-setting', ...$trackingFields)),
        function ($r) use ($h, $accountId) {
            $h->assert(count($r['data']) === 1, 'an account has exactly one tracking setting');
            $t = $r['data'][0];
            $h->assert($t instanceof TrackingSetting && $t->id === $accountId, 'id is the account id');
            $h->assert(is_bool($t->auto_add_parameters), 'auto_add_parameters is a bool');
            $h->assert($t->utm_source instanceof AttributeBag && $t->utm_source->campaign !== null && $t->utm_source->flow !== null, 'utm_source hydrated to AttributeBag with flow+campaign');
            $h->assert($r['links'] === null || $r['links']->next === null, 'single page');
        });

    $setting = $trackingList['data'][0] ?? null;

    $h->step('trackingSettings', 'list', 'list tracking settings w/o query',
        fn(APIClient $c) => $c->trackingSettings->list(),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $accountId, 'same single setting'));

    $h->step('trackingSettings', 'get', 'get tracking setting by account id w/ fields[tracking-setting] (all attributes)',
        fn(APIClient $c) => $c->trackingSettings->get($accountId, (new Query())->fields('tracking-setting', ...$trackingFields)),
        function ($r) use ($h, $accountId) {
            $h->assert($r instanceof TrackingSetting && $r->id === $accountId, 'hydrated TrackingSetting');
            $h->assert($r->utm_source instanceof AttributeBag, 'utm_source nested');
            $h->assert($r->utm_medium === null || $r->utm_medium instanceof AttributeBag, 'utm_medium nested when set');
        });

    $h->step('trackingSettings', 'get', 'get tracking setting w/ narrow fields[tracking-setting]=auto_add_parameters',
        fn(APIClient $c) => $c->trackingSettings->get($accountId, (new Query())->fields('tracking-setting', 'auto_add_parameters')),
        fn($r) => $h->assert($r instanceof TrackingSetting && is_bool($r->auto_add_parameters) && $r->utm_source === null, 'sparse fieldset honoured'));

    $h->step('trackingSettings', 'get', 'get unknown tracking setting id → null',
        fn(APIClient $c) => $c->trackingSettings->get('NOPE01'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    if ($setting === null) {
        $h->note('Could not read the tracking setting — update skipped.');
    } else {
        $originalAuto = $setting->auto_add_parameters;
        // Round-trip the utm_* objects as plain arrays so the PATCH re-states, not clears, them.
        $plain = fn(?AttributeBag $b) => $b === null ? null : json_decode(json_encode($b), true);
        $originalSource = $plain($setting->utm_source);
        $originalMedium = $plain($setting->utm_medium);

        $updated = $h->step('trackingSettings', 'update', 'update tracking setting: toggle auto_add_parameters, re-state utm_source + utm_medium',
            function (APIClient $c) use ($accountId, $originalAuto, $originalSource, $originalMedium, $trackingFields) {
                $u = new UpdateTrackingSetting($accountId);
                $u->auto_add_parameters = !$originalAuto;
                if ($originalSource !== null) {
                    $u->utm_source = $originalSource;
                }
                if ($originalMedium !== null) {
                    $u->utm_medium = $originalMedium;
                }
                return $c->trackingSettings->update($u, (new Query())->fields('tracking-setting', ...$trackingFields));
            },
            function ($r) use ($h, $accountId, $originalAuto, $originalSource) {
                $h->assert($r instanceof TrackingSetting && $r->id === $accountId, 'echoed setting');
                $h->assert($r->auto_add_parameters === !$originalAuto, 'auto_add_parameters flipped');
                $h->assert($originalSource === null || ($r->utm_source instanceof AttributeBag && $r->utm_source->campaign['value'] === $originalSource['campaign']['value']), 'utm_source preserved');
            });

        if ($updated !== null) {
            $h->mutation(sprintf(
                'PATCH /api/tracking-settings/%s flipped auto_add_parameters %s → %s (utm_source/utm_medium re-stated unchanged). Restored to %s in cleanup.',
                $accountId,
                var_export($originalAuto, true),
                var_export(!$originalAuto, true),
                var_export($originalAuto, true)
            ));
            $h->cleanup('trackingSettings', 'update', "restore auto_add_parameters to " . var_export($originalAuto, true),
                function (APIClient $c) use ($h, $accountId, $originalAuto, $originalSource, $originalMedium) {
                    $u = new UpdateTrackingSetting($accountId);
                    $u->auto_add_parameters = $originalAuto;
                    if ($originalSource !== null) {
                        $u->utm_source = $originalSource;
                    }
                    if ($originalMedium !== null) {
                        $u->utm_medium = $originalMedium;
                    }
                    $r = $c->trackingSettings->update($u);
                    $h->assert($r instanceof TrackingSetting && $r->auto_add_parameters === $originalAuto, 'original value restored');
                    return $r;
                });
        }

        $h->note('Only auto_add_parameters is toggled: Resource::jsonSerialize() strips nulls, so a null utm_* / custom_parameters cannot be sent and any utm_* this suite added could not be cleared again.');
    }

    // ═══════════════════════ WebhookTopicService / WebhookService ═══════════════════════
    //
    // Both endpoint families answer 403 "You must have Advanced KDP enabled" on this account.
    // Every method is still called so the report records the exact status.

    $topics = $h->step('webhookTopics', 'list', 'list webhook topics w/ fields[webhook-topic]=id',
        fn(APIClient $c) => $c->webhookTopics->list((new Query())->fields('webhook-topic', 'id')),
        function ($r) use ($h) {
            $h->assert(count($r['data']) > 0, 'at least one topic');
            $h->assert($r['data'][0] instanceof WebhookTopic && $r['data'][0]->id !== null, 'hydrated WebhookTopic');
        });

    $h->step('webhookTopics', 'get', 'get webhook topic by id (' . WebhookTopic::OPENED_EMAIL . ')',
        fn(APIClient $c) => $c->webhookTopics->get(WebhookTopic::OPENED_EMAIL, (new Query())->fields('webhook-topic', 'id')),
        fn($r) => $h->assert($r instanceof WebhookTopic && $r->id === WebhookTopic::OPENED_EMAIL, 'topic identifier'));

    $h->step('webhookTopics', 'get', 'get unknown webhook topic → null',
        fn(APIClient $c) => $c->webhookTopics->get('event:klaviyo.not_a_topic'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('webhooks', 'list', 'list webhooks w/ fields[webhook] + fields[webhook-topic] + include(webhook-topics)',
        fn(APIClient $c) => $c->webhooks->list((new Query())
            ->fields('webhook', ...$webhookFields)
            ->fields('webhook-topic', 'id')
            ->include('webhook-topics')),
        fn($r) => $h->assert(is_array($r) && array_key_exists('data', $r), 'list envelope'));

    $topicIds = array_map(fn($t) => $t->id, $topics['data'] ?? []);
    if (!$topicIds) {
        $topicIds = [WebhookTopic::OPENED_EMAIL, WebhookTopic::CLICKED_EMAIL];
    }
    $topicIds = array_slice($topicIds, 0, 2);

    /** @var Webhook|null $webhook */
    $webhook = $h->step('webhooks', 'create', 'create webhook (name, endpoint_url, secret_key, description, webhook-topics relationship)',
        function (APIClient $c) use ($h, $topicIds, $webhookFields) {
            $w = new CreateWebhook($h->name('webhook'), $h->webhookUrl(), bin2hex(random_bytes(16)));
            $w->description = 'sdk-smoke webhook for run ' . $h->runId;
            $w->addRelationship('webhook-topics', new Relationship(array_map(fn(string $id) => new WebhookTopic($id), $topicIds)));
            return $c->webhooks->create($w, (new Query())->fields('webhook', ...$webhookFields));
        },
        function ($r) use ($h) {
            $h->assert($r instanceof Webhook && $r->id !== null, 'hydrated Webhook with id');
            $h->assert($r->name === $h->name('webhook') && $r->endpoint_url === $h->webhookUrl(), 'name + endpoint echoed');
            $h->assert($r->enabled !== null, 'enabled flag present');
        });
    if ($webhook) {
        $h->cleanup('webhooks', 'delete', 'webhook', fn(APIClient $c) => $c->webhooks->delete($webhook->id));
    }

    $webhookId = $webhook->id ?? 'NOPE01';

    $h->step('webhooks', 'get', 'get webhook w/ fields[webhook] + include(webhook-topics)',
        fn(APIClient $c) => $c->webhooks->get($webhookId, (new Query())
            ->fields('webhook', ...$webhookFields)
            ->fields('webhook-topic', 'id')
            ->include('webhook-topics')),
        function ($r) use ($h, $webhook) {
            if ($webhook === null) {
                $h->assert($r === null, 'null on 404 for a webhook that could not be created');
                return;
            }
            $h->assert($r instanceof Webhook && $r->id === $webhook->id, 'same webhook');
            $h->assert($r->getRelationship('webhook-topics') instanceof Relationship, 'topics relationship hydrated');
        });

    $h->step('webhooks', 'update', 'update webhook (name, description, endpoint_url, secret_key, enabled)',
        function (APIClient $c) use ($h, $webhookId, $webhookFields) {
            $u = new UpdateWebhook($webhookId);
            $u->name = $h->name('webhook-renamed');
            $u->description = 'renamed by sdk-smoke';
            $u->endpoint_url = $h->webhookUrl('klaviyo/' . $h->runId . '/updated');
            $u->secret_key = bin2hex(random_bytes(16));
            $u->enabled = false;
            return $c->webhooks->update($u, (new Query())->fields('webhook', ...$webhookFields));
        },
        function ($r) use ($h, $webhook) {
            $h->assert($webhook !== null, 'no webhook was created, so this PATCH cannot succeed');
            $h->assert($r instanceof Webhook && $r->name === $h->name('webhook-renamed') && $r->enabled === false, 'renamed + disabled');
        });

    if ($webhook === null) {
        $h->step('webhooks', 'delete', 'delete webhook (no webhook exists — records endpoint status)',
            fn(APIClient $c) => $c->webhooks->delete($webhookId),
            fn($r) => $h->assert($r === null, 'void'));
    }

    // ═════════════════════════════ ReviewService ═════════════════════════════

    $reviews = $h->step('reviews', 'list', 'list reviews w/ fields[review] + fields[event] + include(events) + sort=-created + page[size]=100',
        fn(APIClient $c) => $c->reviews->list((new Query())
            ->fields('review', ...$reviewFields)
            ->fields('event', ...$eventFields)
            ->include('events')
            ->sort('created', descending: true)
            ->pageSize(100)),
        function ($r) use ($h) {
            $h->assert(is_array($r) && array_key_exists('data', $r) && array_key_exists('links', $r), 'list envelope {data, links}');
            $h->assert(count($r['data']) === 0, 'Klaviyo Reviews is not collecting on this account — no reviews');
            $h->assert($r['links'] === null || $r['links']->next === null, 'no next page for an empty collection');
        });

    $h->step('reviews', 'list', 'list reviews w/ filter equals(status,all) + page[size]=1',
        fn(APIClient $c) => $c->reviews->list((new Query())->filter(Filter::equals('status', 'all'))->pageSize(1)),
        fn($r) => $h->assert(count($r['data']) === 0, 'still empty with status=all'));

    $h->step('reviews', 'list', 'list reviews w/ filter all(equals(review_type,review), any(rating,[4,5]), greater-or-equal(created,-30d))',
        fn(APIClient $c) => $c->reviews->list((new Query())->filter(Filter::all(
            Filter::equals('review_type', 'review'),
            Filter::any('rating', [4, 5]),
            Filter::greaterOrEqual('created', new DateTimeImmutable('-30 days')),
        ))->sort('rating', descending: true)),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty'));

    $h->step('reviews', 'list', 'list reviews w/ filter all(contains(content,..), equals(verified,true), less-or-equal(created,now)) + sort=updated',
        fn(APIClient $c) => $c->reviews->list((new Query())->filter(Filter::all(
            Filter::contains('content', 'sdk-smoke'),
            Filter::equals('verified', true),
            Filter::lessOrEqual('created', new DateTimeImmutable()),
        ))->sort('updated')),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty'));

    // item.id is a Klaviyo compound catalog id: $integration:::$catalog:::$external_id.
    // A bare string is rejected with 400 "Invalid Compound ID, Must be delimited by :::".
    $h->step('reviews', 'list', 'list reviews w/ filter any(item.id,[<compound catalog id>])',
        fn(APIClient $c) => $c->reviews->list((new Query())
            ->filter(Filter::any('item.id', ['$custom:::$default:::sdk-smoke-' . $h->runId]))
            ->fields('review', 'rating', 'status', 'product')),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty'));

    // Review ids are numeric strings; a ULID/UUID-shaped value is rejected with 400 "Invalid id".
    $h->step('reviews', 'list', 'list reviews w/ filter all(equals(id,<numeric>), equals(status,pending))',
        fn(APIClient $c) => $c->reviews->list((new Query())->filter(Filter::all(
            Filter::equals('id', '123456789'),
            Filter::equals('status', 'pending'),
        ))),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty'));

    $h->step('reviews', 'list', 'list reviews w/ filter any(id,[<numeric>,<numeric>]) + equals(review_type,rating)',
        fn(APIClient $c) => $c->reviews->list((new Query())->filter(Filter::all(
            Filter::any('id', ['123456789', '987654321']),
            Filter::equals('review_type', 'rating'),
        ))->fields('review', 'rating', 'status')),
        fn($r) => $h->assert(count($r['data']) === 0, 'empty'));

    $h->step('reviews', 'get', 'get unknown review → null',
        fn(APIClient $c) => $c->reviews->get('123456789', (new Query())
            ->fields('review', ...$reviewFields)
            ->fields('event', ...$eventFields)
            ->include('events')),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $existingReview = $reviews['data'][0] ?? null;
    if ($existingReview instanceof Review) {
        $h->step('reviews', 'get', 'get existing review w/ fields + include(events)',
            fn(APIClient $c) => $c->reviews->get($existingReview->id, (new Query())->fields('review', ...$reviewFields)->include('events')),
            fn($r) => $h->assert($r instanceof Review && $r->id === $existingReview->id, 'hydrated Review'));
        $h->step('reviews', 'update', 'moderate review: set status back to its current value',
            fn(APIClient $c) => $c->reviews->update(new UpdateReview($existingReview->id, $existingReview->status->value ?? 'pending')),
            fn($r) => $h->assert($r instanceof Review, 'echoed review'));
    } else {
        $h->skip('reviews', 'get', 'get existing review', 'Account has no reviews (Klaviyo Reviews not collecting); only the 404 → null path is exercisable.');
        $h->skip('reviews', 'update', 'moderate a review', 'PATCH /api/reviews/{id} needs a real review id and would change a real customer review; the account has none, and reviews cannot be created through this API (only POST /client/reviews, which is not in ReviewService).');
    }

    // ═══════════════════════ ConversationMessageService ═══════════════════════

    $h->skip('conversationMessages', 'create', 'send a conversation message', 'POST /api/conversation-messages delivers an SMS/chat message to a real person — never called from a smoke suite (harness rule 1). Requires account-level enablement of the conversations API as well.');

    // ═══════════════════════ returnRequest + executePool ═══════════════════════

    $h->step('accounts', 'executePool', 'executePool: accounts.get + forms.get + webFeeds.get + a 404, all via returnRequest',
        function (APIClient $c) use ($h, $accountId, $popup, $feedJson) {
            $reqs = ['account' => $c->accounts->get($accountId, (new Query())->fields('account', 'timezone'), returnRequest: true)];
            $expected = ['account'];
            if ($popup) {
                $reqs['form'] = $c->forms->get($popup->id, (new Query())->fields('form', 'name'), returnRequest: true);
                $expected[] = 'form';
            }
            if ($feedJson) {
                $reqs['feed'] = $c->webFeeds->get($feedJson->id, (new Query())->fields('web-feed', 'name'), returnRequest: true);
                $expected[] = 'feed';
            }
            $reqs['missing'] = $c->accounts->get('NOPE01', returnRequest: true);
            foreach ($reqs as $key => $r) {
                $h->assert($r instanceof \Psr\Http\Message\RequestInterface, "returnRequest gave a PSR-7 request for {$key}");
            }

            $res = $c->executePool(array_values($reqs), 4);
            $h->assert(count($res) === count($reqs), 'one result per request');
            $keys = array_keys($reqs);
            $byKey = array_combine($keys, $res);
            $h->assert($byKey['account'] instanceof Account && $byKey['account']->id === $accountId && $byKey['account']->timezone !== null, 'pooled account hydrated');
            if (isset($byKey['form'])) {
                $h->assert($byKey['form'] instanceof Form && $byKey['form']->id === $popup->id, 'pooled form hydrated');
            }
            if (isset($byKey['feed'])) {
                $h->assert($byKey['feed'] instanceof WebFeed && $byKey['feed']->id === $feedJson->id, 'pooled web feed hydrated');
            }
            $h->assert($byKey['missing'] instanceof ClientException && $byKey['missing']->getHttpStatus() === 404, 'pooled 404 surfaces as a ClientException in the result array');
            return $expected;
        });

    $h->step('webFeeds', 'executePoolLazy', 'executePoolLazy: GET every web feed this run created concurrently',
        function (APIClient $c) use ($h, $feedJson, $feedXml, $feedPost) {
            $ids = array_values(array_map(fn($f) => $f->id, array_filter([$feedJson, $feedXml, $feedPost])));
            $h->assert($ids !== [], 'at least one feed to fetch');
            $res = $c->executePoolLazy($ids, fn(string $id) => $c->webFeeds->get($id, (new Query())->fields('web-feed', 'name', 'url'), returnRequest: true), 3);
            APIClient::assertNoExceptions($res);
            $h->assert(count($res) === count($ids), 'one result per id');
            $h->assert(array_reduce($res, fn($ok, $f) => $ok && $f instanceof WebFeed && $f->name !== null, true), 'all hydrated');
            return $res;
        });

    // ═══════════════════════ notes for the report ═══════════════════════

    $h->note('FormCreateQuery minimal accepted definition (found by iterating on the 400 error pointers): '
        . 'definition.versions[] needs `steps` with AT LEAST TWO steps ("Versions must have at least two steps"); '
        . 'each step needs columns[].rows[].blocks[] of {type, properties}; a block whose action has submit=true must carry '
        . 'action.properties.list_id ("Submit actions require a list_id, and list_id is only allowed on submit actions"); '
        . 'no `id` may be given anywhere on create ("id is not allowed to be specified on create", and string ids must be ULIDs); '
        . 'column-level styles are rejected on a column that has rows ("Column background images are only supported when a column has no rows"); '
        . '`location` is only legal when the version type is flyout ("Only flyout types may have a location").');
    $h->note('Klaviyo ignores page[size] on GET /api/forms/{id}/form-versions and GET /api/forms/{id}/relationships/form-versions: '
        . 'page[size]=1 against a 3-version A/B form returns all three rows and no links.next, so those relationships cannot be paginated '
        . '(stable.json documents page[size] 1..100 and page[cursor] for both). /api/forms itself paginates correctly.');
    $h->note('POST/PATCH /api/web-feeds echo status:null for a freshly saved feed, so WebFeed::$status only becomes meaningful after Klaviyo has polled the feed.');
    $h->note('Forms have no update endpoint (Klaviyo publishes drafts from the UI), so FormService exposes no update() — create/get/list/versions/versionIds/delete is the full surface.');
    $h->note('Review ids are numeric strings: filter=equals(id,"<ulid>") / "<uuid>" is rejected with 400 "Invalid id", while equals(id,"123456789") is accepted. '
        . 'filter=any(item.id,[..]) additionally needs a compound catalog id delimited by ::: ($integration:::$catalog:::$external_id).');
    $h->note('Reviews: stable.json only lists include=events for /api/reviews (there is no include=item), and reviews cannot be created through the server-side API, so ReviewService::update() has no exercisable target on an account with no reviews.');
};
