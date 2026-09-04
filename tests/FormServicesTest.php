<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateForm;
use nickdnk\Klaviyo\Resources\Response\Form;
use nickdnk\Klaviyo\Resources\Response\FormVersion;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for the signup-form pair: `forms` (the container, created as a draft with its
 * whole version tree inline and never updated in place) and `form-versions` (the rendered
 * variations, read-only, linked back to their form by a to-one relationship).
 */
class FormServicesTest extends TestCase
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

    // region Forms

    public function testFormListGetCreateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_forms.200'),
            Fixtures::response('get_form.200'),
            Fixtures::response('create_form.201'),
            new Response(204),
        ]);
        $forms = self::client($mock)->forms;

        $list = $forms->list((new Query())->fields('form', 'name', 'status')->sort('created_at', true)->pageSize(20)->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/forms', self::path($mock));
        self::assertSame([
            'fields' => ['form' => 'name,status'],
            'sort'   => '-created_at',
            'page'   => ['size' => '20', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(1, $list['data'], 'the recording used page[size]=1');
        self::assertInstanceOf(Form::class, $list['data'][0]);
        self::assertSame('UNtbZL', $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904f206-form-popup', $list['data'][0]->name);
        self::assertSame('draft', $list['data'][0]->status);
        self::assertFalse($list['data'][0]->ab_test);
        self::assertSame(['28242214'], $list['data'][0]->getRelationship('form-versions')->ids(), 'version ids are numeric strings');
        self::assertStringContainsString('page%5Bcursor%5D=', $list['links']->next, 'a cursor page has a next link');

        $form = $forms->get('f1', (new Query())->fields('form', 'definition'));
        self::assertSame('/api/forms/f1', self::path($mock));
        self::assertSame(['fields' => ['form' => 'definition']], self::query($mock));
        self::assertInstanceOf(Form::class, $form);
        self::assertSame('UNtbZL', $form->id);
        self::assertSame('sdk-smoke-0904f206-form-popup', $form->name);
        self::assertNull($form->definition, 'the recording asked for fields[form]=name, so nothing else is hydrated');
        self::assertNull($form->getRelationship('form-versions'), 'a sparse fieldset drops the relationships too');

        $definition = ['versions' => [[
            'steps'  => [['type' => 'form_step']],
            'type'   => 'popup',
            'status' => 'draft',
        ]]];
        $created = $forms->create(new CreateForm('Table booking', $definition), (new Query())->fields('form', 'name'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/forms', self::path($mock));
        self::assertSame(['fields' => ['form' => 'name']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'form',
                'attributes' => [
                    'name'       => 'Table booking',
                    'definition' => $definition,
                    'status'     => 'draft',
                    'ab_test'    => false,
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Form::class, $created);
        self::assertSame('RKZdg3', $created->id);
        self::assertSame('draft', $created->status);
        // The response echoes the whole rendered version tree back, not just what was posted.
        self::assertInstanceOf(AttributeBag::class, $created->definition);
        self::assertCount(1, $created->definition->versions);
        self::assertSame(28242216, $created->definition->versions[0]['id'], 'version ids are ints inside definition');
        self::assertSame('flyout', $created->definition->versions[0]['type']);
        self::assertSame('draft', $created->definition->versions[0]['status']);

        self::assertNull($forms->delete('f3'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/forms/f3', self::path($mock));

    }

    public function testFormCreateSendsAbTestAndStatusVerbatim(): void
    {

        $mock = new MockHandler([self::json(['type' => 'form', 'id' => 'f4', 'attributes' => ['name' => 'Split test']], 201)]);

        self::client($mock)->forms->create(new CreateForm('Split test', ['versions' => []], true));

        $body = self::body($mock);
        self::assertTrue($body['data']['attributes']['ab_test']);
        self::assertSame('draft', $body['data']['attributes']['status']);
        self::assertArrayNotHasKey(
            'definition',
            $body['data']['attributes'],
            'An empty version list collapses to null and must be omitted rather than sent as [].'
        );

    }

    public function testFormVersionRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_versions_for_form.200'),
            Fixtures::response('get_version_ids_for_form.200'),
        ]);
        $forms = self::client($mock)->forms;

        $versions = $forms->versions('f1', (new Query())->fields('form-version', 'form_type')->sort('created_at'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/forms/f1/form-versions', self::path($mock));
        self::assertSame([
            'fields' => ['form-version' => 'form_type'],
            'sort'   => 'created_at',
        ], self::query($mock));
        self::assertCount(1, $versions['data']);
        self::assertInstanceOf(FormVersion::class, $versions['data'][0]);
        self::assertSame('28242214', $versions['data'][0]->id);
        self::assertSame('popup', $versions['data'][0]->form_type);
        self::assertSame('V1', $versions['data'][0]->variation_name);
        self::assertSame('draft', $versions['data'][0]->status);
        self::assertNull($versions['data'][0]->ab_test, 'no A/B test configured on this version');
        self::assertSame('UNtbZL', $versions['data'][0]->getRelationship('form')->data->id);

        $ids = $forms->versionIds('f1', (new Query())->pageSize(5));
        self::assertSame('/api/forms/f1/relationships/form-versions', self::path($mock));
        self::assertSame(['page' => ['size' => '5']], self::query($mock));
        self::assertInstanceOf(FormVersion::class, $ids['data'][0]);
        self::assertSame(['28242214'], array_map(static fn(FormVersion $v) => $v->id, $ids['data']));
        self::assertNull($ids['data'][0]->form_type);

    }

    // endregion

    // region Form versions

    public function testFormVersionGetAndFormRelationship(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_form_version.200'),
            Fixtures::response('get_form_for_form_version.200'),
            Fixtures::response('get_form_id_for_form_version.200'),
        ]);
        $versions = self::client($mock)->formVersions;

        $version = $versions->get('v1', (new Query())->fields('form-version', 'form_type')->include('form'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/form-versions/v1', self::path($mock));
        self::assertSame([
            'fields'  => ['form-version' => 'form_type'],
            'include' => 'form',
        ], self::query($mock));
        self::assertInstanceOf(FormVersion::class, $version);
        self::assertSame('28242214', $version->id);
        self::assertSame('popup', $version->form_type);
        self::assertSame('V1', $version->variation_name);
        self::assertNull($version->ab_test, 'ab_test is null, not an empty bag, when the form has no split test');
        // include=form: the parent form arrives in `included` and is spliced into the relationship.
        $parent = $version->getRelationship('form')->data;
        self::assertInstanceOf(Form::class, $parent);
        self::assertSame('sdk-smoke-0904f206-form-popup', $parent->name);
        self::assertFalse($parent->ab_test);
        self::assertSame(['28242214'], $parent->getRelationship('form-versions')->ids(), 'the back-reference resolves to an identifier');

        $form = $versions->form('v1', (new Query())->fields('form', 'name'));
        self::assertSame('/api/form-versions/v1/form', self::path($mock));
        self::assertSame(['fields' => ['form' => 'name']], self::query($mock));
        self::assertInstanceOf(Form::class, $form);
        self::assertSame('UNtbZL', $form->id);
        self::assertSame('sdk-smoke-0904f206-form-popup', $form->name);
        self::assertSame('draft', $form->status);

        $formId = $versions->formId('v1');
        self::assertSame('/api/form-versions/v1/relationships/form', self::path($mock));
        self::assertInstanceOf(Form::class, $formId);
        self::assertSame('UNtbZL', $formId->id);
        self::assertNull($formId->name);

    }

    public function testUnknownFormVersionAndEmptyFormRelationshipReturnNull(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_form_version.404'),
            // no fixture: every recorded form-version had a form, so the empty to-one stays hand-written.
            new Response(200, [], json_encode(['data' => null])),
        ]);
        $versions = self::client($mock)->formVersions;

        self::assertNull($versions->get('missing'));
        self::assertNull($versions->form('v1'));

    }

    // endregion

}
