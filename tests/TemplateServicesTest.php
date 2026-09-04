<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateTemplate;
use nickdnk\Klaviyo\Resources\Request\CreateUniversalContent;
use nickdnk\Klaviyo\Resources\Request\TemplateClone;
use nickdnk\Klaviyo\Resources\Request\TemplateRender;
use nickdnk\Klaviyo\Resources\Request\UpdateTemplate;
use nickdnk\Klaviyo\Resources\Request\UpdateUniversalContent;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Response\TemplateUniversalContent;
use nickdnk\Klaviyo\Resources\Response\UniversalContentDefinition;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use PHPUnit\Framework\TestCase;

class TemplateServicesTest extends TestCase
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

    private static function textBlock(): array
    {

        return [
            'content_type' => 'block',
            'type'         => 'text',
            'data'         => [
                'content'         => '<p>See you at the door.</p>',
                'display_options' => ['show_on' => 'all'],
                'styles'          => ['font_family' => 'Helvetica', 'font_size' => 14, 'text_align' => 'center'],
            ],
        ];

    }

    // region Templates

    public function testTemplateListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_templates.200'),
            Fixtures::response('get_template.200'),
            Fixtures::response('create_template.201'),
            Fixtures::response('update_template.200'),
            // A template delete answers 200 with an empty body, not 204.
            Fixtures::response('delete_template.200'),
        ]);
        $templates = self::client($mock)->templates;

        $list = $templates->list((new Query())->fields('template', 'name')->filter(Filter::equals('editor_type', 'CODE'))->sort('created'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/templates', self::path($mock));
        self::assertSame([
            'fields' => ['template' => 'name'],
            'filter' => 'equals(editor_type,"CODE")',
            'sort'   => 'created',
        ], self::query($mock));
        self::assertInstanceOf(Template::class, $list['data'][0]);
        self::assertSame('SpfvU7', $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904a5e6-tpl-code', $list['data'][0]->name);
        self::assertSame('2026-09-04T13:39:05+00:00', $list['data'][0]->created);
        // Recorded with fields[template]=name,created: the other attributes are absent.
        self::assertNull($list['data'][0]->editor_type);

        $template = $templates->get('tpl1', (new Query())->additionalFields('template', 'definition'));
        self::assertSame('/api/templates/tpl1', self::path($mock));
        self::assertSame(['additional-fields' => ['template' => 'definition']], self::query($mock));
        self::assertInstanceOf(Template::class, $template);
        self::assertSame('Vm6eis', $template->id);
        self::assertSame('sdk-smoke-0904a5e6-tpl-dnd', $template->name);
        // The recorded read used fields[template]=name only, so `definition` never came back
        // even though the request here asks for it.
        self::assertNull($template->definition);

        $created = $templates->create(new CreateTemplate('Reminder', 'CODE', '<p>Tonight</p>', 'Tonight'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/templates', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template',
                'attributes' => [
                    'name'        => 'Reminder',
                    'editor_type' => 'CODE',
                    'html'        => '<p>Tonight</p>',
                    'text'        => 'Tonight',
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Template::class, $created);
        self::assertSame('RhSFjh', $created->id);
        self::assertSame('sdk-smoke-09048514-t', $created->name);
        self::assertSame('CODE', $created->editor_type);
        self::assertSame('<html><head></head><body><p>hi</p></body></html>', $created->html);
        self::assertSame('hi', $created->text);
        self::assertNull($created->amp);

        $update = new UpdateTemplate('tpl2');
        $update->name = 'Reminder v2';
        $update->html = '<p>Tonight!</p>';
        $updated = $templates->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/templates/tpl2', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template',
                'attributes' => ['name' => 'Reminder v2', 'html' => '<p>Tonight!</p>'],
                'id'         => 'tpl2',
            ],
        ], self::body($mock));
        self::assertSame('sdk-smoke-0904a5e6-tpl-code-renamed', $updated->name);
        self::assertSame('Updated {{ first_name }}', $updated->text);

        self::assertNull($templates->delete('tpl2'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/templates/tpl2', self::path($mock));

    }

    public function testTemplateCloneAndRender(): void
    {

        // Both endpoints answer 200, not the 201 a create-shaped POST would suggest.
        $mock = new MockHandler([
            Fixtures::response('clone_template.200'),
            Fixtures::response('render_template.200'),
        ]);
        $templates = self::client($mock)->templates;

        $clone = $templates->clone(new TemplateClone('tpl1', 'Welcome copy'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-clone', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template',
                'attributes' => ['name' => 'Welcome copy'],
                'id'         => 'tpl1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Template::class, $clone);
        self::assertSame('S4Juwx', $clone->id);
        self::assertSame('sdk-smoke-0904a5e6-tpl-clone', $clone->name);
        self::assertSame('CODE', $clone->editor_type);

        $rendered = $templates->render(new TemplateRender('tpl1', ['first_name' => 'Ada', 'event' => ['name' => 'Opening night']]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-render', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template',
                'attributes' => ['context' => ['first_name' => 'Ada', 'event' => ['name' => 'Opening night']]],
                'id'         => 'tpl1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Template::class, $rendered);
        // The render answers with the context substituted into all three bodies.
        self::assertSame('SpfvU7', $rendered->id);
        self::assertSame('<html><head></head><body><h1>Updated Ada</h1></body></html>', $rendered->html);
        self::assertSame('Updated Ada', $rendered->text);
        self::assertStringContainsString('Hello Ada', $rendered->amp);

    }

    /** No name sends no attributes at all, so Klaviyo derives the copy's name from the source. */
    public function testTemplateCloneOmitsAttributesWithoutAName(): void
    {

        $mock = new MockHandler([self::json(['type' => 'template', 'id' => 'tpl9'], 201)]);

        self::client($mock)->templates->clone(new TemplateClone('tpl1'));

        self::assertSame(['data' => ['type' => 'template', 'id' => 'tpl1']], self::body($mock));

    }

    // endregion

    // region Universal content

    public function testUniversalContentListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_all_universal_content.200'),
            Fixtures::response('get_universal_content.200'),
            Fixtures::response('create_universal_content.201'),
            Fixtures::response('update_universal_content.200'),
            new Response(204),
        ]);
        $content = self::client($mock)->universalContent;

        $list = $content->list((new Query())->fields('template-universal-content', 'name')->filter(Filter::contains('name', 'Foot'))->pageSize(20));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-universal-content', self::path($mock));
        self::assertSame([
            'fields' => ['template-universal-content' => 'name'],
            'filter' => 'contains(name,"Foot")',
            'page'   => ['size' => '20'],
        ], self::query($mock));
        self::assertInstanceOf(TemplateUniversalContent::class, $list['data'][0]);
        self::assertSame('b0efdc506d1741d8b47c7f45b8c649e6', $list['data'][0]->id);
        self::assertSame('sdk-smoke-0904a5e6-uc-html', $list['data'][0]->name);

        $one = $content->get('uc1', (new Query())->fields('template-universal-content', 'definition'));
        self::assertSame('/api/template-universal-content/uc1', self::path($mock));
        self::assertSame(['fields' => ['template-universal-content' => 'definition']], self::query($mock));
        self::assertInstanceOf(TemplateUniversalContent::class, $one);
        self::assertSame('sdk-smoke-0904a5e6-uc-html', $one->name);
        // Recorded with fields[template-universal-content]=name, so neither the definition
        // nor the screenshot status came back.
        self::assertNull($one->definition);
        self::assertNull($one->screenshot_status);

        $created = $content->create(new CreateUniversalContent('Door policy', self::textBlock()));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-universal-content', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template-universal-content',
                'attributes' => [
                    'name'       => 'Door policy',
                    'definition' => self::textBlock(),
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(TemplateUniversalContent::class, $created);
        self::assertSame('b0efdc506d1741d8b47c7f45b8c649e6', $created->id);
        self::assertSame('sdk-smoke-0904a5e6-uc-html', $created->name);
        self::assertInstanceOf(UniversalContentDefinition::class, $created->definition);
        self::assertSame('block', $created->definition->content_type);
        self::assertSame('html', $created->definition->type);
        self::assertInstanceOf(AttributeBag::class, $created->definition->data);
        self::assertSame('<div>sdk-smoke html block {{ first_name }}</div>', $created->definition->data->content);
        self::assertSame(['show_on' => 'all', 'visible_check' => ''], $created->definition->data->display_options);

        $update = new UpdateUniversalContent('uc2');
        $update->name = 'Door policy v2';
        $updated = $content->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-universal-content/uc2', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'template-universal-content',
                'attributes' => ['name' => 'Door policy v2'],
                'id'         => 'uc2',
            ],
        ], self::body($mock));
        self::assertSame('sdk-smoke-0904a5e6-uc-html-renamed', $updated->name);
        self::assertSame('<div>sdk-smoke html block updated</div>', $updated->definition->data->content);
        self::assertSame(['show_on' => 'desktop'], $updated->definition->data->display_options);

        self::assertNull($content->delete('uc2'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/template-universal-content/uc2', self::path($mock));

    }

    // endregion

}
