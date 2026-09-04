<?php
/**
 * TemplateService + UniversalContentService + ImageService, end to end: create (code and
 * drag-and-drop) → read with every query knob the spec lists → render → clone → update →
 * delete. Images cannot be deleted through the API, so the ones uploaded here are hidden in
 * cleanup and recorded as a mutation.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateTemplate;
use nickdnk\Klaviyo\Resources\Request\CreateUniversalContent;
use nickdnk\Klaviyo\Resources\Request\TemplateClone;
use nickdnk\Klaviyo\Resources\Request\TemplateRender;
use nickdnk\Klaviyo\Resources\Request\UpdateImage;
use nickdnk\Klaviyo\Resources\Request\UpdateTemplate;
use nickdnk\Klaviyo\Resources\Request\UpdateUniversalContent;
use nickdnk\Klaviyo\Resources\Request\UploadImageFromUrl;
use nickdnk\Klaviyo\Resources\Response\Image;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Response\TemplateUniversalContent;
use nickdnk\Klaviyo\Resources\Response\UniversalContentDefinition;
use Smoke\Harness;

return function (Harness $h): void {

    // ───────────────────────────── Templates ─────────────────────────────

    $html = '<html><body><h1>Hello {{ first_name|default:"friend" }}</h1><p>{{ event.name }}</p></body></html>';
    $text = 'Hello {{ first_name|default:"friend" }} - {{ event.name }}';
    $amp = '<!doctype html><html amp4email><head><meta charset="utf-8"><script async src="https://cdn.ampproject.org/v0.js"></script><style amp4email-boilerplate>body{visibility:hidden}</style></head><body>Hello {{ first_name|default:"friend" }}</body></html>';

    /** @var Template|null $code */
    $code = $h->step('templates', 'create', 'create CODE template (html + text + amp)',
        fn(APIClient $c) => $c->templates->create(
            new CreateTemplate($h->name('tpl-code'), 'CODE', $html, $text, $amp),
            (new Query())->fields('template', 'name', 'editor_type', 'html', 'text', 'amp', 'created', 'updated')
        ),
        // Klaviyo normalises the markup on the way in (it injects an empty <head> here), so the
        // html is compared on its body, not byte-for-byte.
        fn($r) => $h->assert(
            $r instanceof Template && $r->id && $r->name === $h->name('tpl-code') && $r->editor_type === 'CODE'
            && str_contains((string)$r->html, 'Hello {{ first_name|default:"friend" }}') && $r->text === $text
            && $r->amp !== null && $r->created !== null && $r->updated !== null,
            'hydrated template with id, name, editor_type, html, text, amp'
        ));
    if ($code) {
        $h->cleanup('templates', 'delete', 'CODE template', fn(APIClient $c) => $c->templates->delete($code->id));
    }

    // A SYSTEM_DRAGGABLE template is the only editor_type that takes `definition`
    // (TemplateCreateHtmlOrDndQueryResourceObject: "Required for SYSTEM_DRAGGABLE; ignored for
    // CODE/USER_DRAGGABLE"). TemplateDefinition wants body{properties,styles} plus one style
    // object per style_type.
    // Klaviyo answers 400 "Template must contain at least one section" for a body with no
    // sections, so the definition carries one section → row → column → text block.
    $section = [
        'content_type' => 'section',
        'type'         => 'section',
        'data'         => [
            'properties'      => ['is_ai_generated' => false, 'is_prebuilt_content' => false],
            'display_options' => ['show_on' => 'all'],
            'styles'          => [
                'background_color' => '#ffffff',
                'border_style'     => 'none',
                'border_width'     => 0,
                'column_align'     => 'top',
            ],
        ],
        'rows'         => [
            [
                'data'    => ['styles' => ['column_layout' => '1-column-full-width']],
                'columns' => [
                    [
                        // ColumnData takes no fields at all: Klaviyo answers 400 "'styles' is
                        // not a valid field for the resource 'ColumnData'" if any are sent.
                        'blocks' => [
                            [
                                'content_type' => 'block',
                                'type'         => 'text',
                                'data'         => [
                                    'content'         => '<p>sdk-smoke drag-and-drop text block {{ first_name }}</p>',
                                    'display_options' => ['show_on' => 'all'],
                                    'styles'          => [
                                        'font_family' => 'Helvetica, Arial, sans-serif',
                                        'font_size'   => 14,
                                        'color'       => '#222222',
                                        'text_align'  => 'left',
                                        'line_height' => 1.4,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $definition = [
        'body'   => [
            'properties' => ['css_class' => 'sdk-smoke-body'],
            'styles'     => ['background_color' => '#ffffff', 'width' => 600],
            'sections'   => [$section],
        ],
        'styles' => [
            [
                'style_type' => 'base-styles',
                'properties' => [
                    'currency'                 => 'USD',
                    'currency_set_on_template' => true,
                    'disable_websafe_fonts'    => false,
                    'is_user_draggable'        => false,
                    'mobile_optimizations'     => true,
                    'tip_tap_enabled'          => false,
                ],
                'styles'     => [
                    'background_format'        => 'cover',
                    'background_position'      => 'center-center',
                    'background_repeat'        => false,
                    'border_color'             => '#cccccc',
                    'border_radius'            => 0,
                    'border_style'             => 'none',
                    'border_width'             => 0,
                    'content_background_color' => '#ffffff',
                    'inner_padding_bottom'     => 10,
                    'inner_padding_left'       => 10,
                    'inner_padding_right'      => 10,
                    'inner_padding_top'        => 10,
                    'margin_top'               => 0,
                ],
            ],
            [
                'style_type' => 'text-styles',
                'styles'     => [
                    'color'              => '#222222',
                    'font_family'        => 'Helvetica, Arial, sans-serif',
                    'font_size'          => 14,
                    'font_style'         => 'normal',
                    'font_weight'        => 'normal',
                    'letter_spacing'     => 0,
                    'line_height'        => 1.4,
                    'mobile_font_size'   => 16,
                    'mobile_line_height' => 1.5,
                    'text_align'         => 'left',
                    'text_decoration'    => 'none',
                ],
            ],
            ...array_map(fn(int $n) => [
                'style_type' => "heading-{$n}-styles",
                'styles'     => [
                    'color'              => '#111111',
                    'font_family'        => 'Helvetica, Arial, sans-serif',
                    'font_size'          => 32 - (4 * $n),
                    'font_style'         => 'normal',
                    'font_weight'        => 'bold',
                    'letter_spacing'     => 0,
                    'line_height'        => 1.2,
                    'margin_bottom'      => 10,
                    'mobile_font_size'   => 34 - (4 * $n),
                    'mobile_line_height' => 1.3,
                    'text_align'         => 'left',
                    'text_decoration'    => 'none',
                ],
            ], [1, 2, 3, 4]),
            [
                'style_type' => 'link-styles',
                'styles'     => [
                    'color'           => '#0000ee',
                    'font_style'      => 'normal',
                    'font_weight'     => 'normal',
                    'text_decoration' => 'underline',
                ],
            ],
            [
                'style_type' => 'mobile-styles',
                'properties' => ['mobile_padding_override' => false],
                'styles'     => [
                    'mobile_block_padding_left'  => 10,
                    'mobile_block_padding_right' => 10,
                    'mobile_margin'              => 0,
                    'mobile_padding_bottom'      => 10,
                    'mobile_padding_left'        => 10,
                    'mobile_padding_right'       => 10,
                    'mobile_padding_top'         => 10,
                ],
            ],
        ],
    ];

    /** @var Template|null $dnd */
    $dnd = $h->step('templates', 'create', 'create SYSTEM_DRAGGABLE template (definition: body + all 8 style types)',
        function (APIClient $c) use ($h, $definition) {
            $t = new CreateTemplate($h->name('tpl-dnd'), 'SYSTEM_DRAGGABLE');
            $t->definition = $definition;
            return $c->templates->create($t, (new Query())->additionalFields('template', 'definition'));
        },
        fn($r) => $h->assert(
            $r instanceof Template && $r->id && $r->editor_type === 'SYSTEM_DRAGGABLE' && is_array($r->definition)
            && ($r->definition['body']['styles']['width'] ?? null) === 600,
            'dnd template hydrated with definition echoed back'
        ));
    if ($dnd) {
        $h->cleanup('templates', 'delete', 'SYSTEM_DRAGGABLE template', fn(APIClient $c) => $c->templates->delete($dnd->id));
    }

    if (!$code) {
        $h->note('CODE template creation failed; template reads/renders/clones are skipped.');
    } else {
        $h->step('templates', 'get', 'get template w/ fields[template] subset',
            fn(APIClient $c) => $c->templates->get($code->id, (new Query())->fields('template', 'name', 'editor_type', 'created')),
            fn($r) => $h->assert(
                $r instanceof Template && $r->id === $code->id && $r->name === $h->name('tpl-code') && $r->html === null,
                'sparse fieldset: name present, html omitted'
            ));

        $h->step('templates', 'get', 'get template w/ additional-fields[template]=definition (CODE → null definition)',
            fn(APIClient $c) => $c->templates->get($code->id, (new Query())->additionalFields('template', 'definition')),
            fn($r) => $h->assert(
                $r instanceof Template && str_contains((string)$r->html, '{{ event.name }}') && $r->definition === null,
                'html round-trips; a CODE template has no definition'
            ));

        $h->step('templates', 'get', 'get unknown template → null',
            fn(APIClient $c) => $c->templates->get('NOPE01'),
            fn($r) => $h->assert($r === null, 'null on 404'));
    }

    if ($dnd) {
        $h->step('templates', 'get', 'get dnd template w/ additional-fields[template]=definition',
            fn(APIClient $c) => $c->templates->get($dnd->id, (new Query())->additionalFields('template', 'definition')),
            fn($r) => $h->assert(
                $r instanceof Template && is_array($r->definition) && isset($r->definition['styles'])
                && count($r->definition['styles']) >= 8,
                'definition returned with all style types'
            ));
    }

    $h->step('templates', 'list', 'list w/ filter equals(name) + fields + page[size]=10 + sort=-created',
        fn(APIClient $c) => $c->templates->list((new Query())
            ->filter(Filter::equals('name', $h->name('tpl-code')))
            ->fields('template', 'name', 'created')
            ->pageSize(10)
            ->sort('created', descending: true)),
        fn($r) => $h->assert(count($r['data']) === ($code ? 1 : 0) && (!$code || $r['data'][0]->id === $code->id), 'exactly our CODE template'));

    $h->step('templates', 'list', 'list w/ filter contains(name,"sdk-smoke-<run>") + additional-fields definition',
        fn(APIClient $c) => $c->templates->list((new Query())
            ->filter(Filter::contains('name', 'sdk-smoke-' . $h->runId))
            ->additionalFields('template', 'definition')
            ->pageSize(10)),
        fn($r) => $h->assert(count($r['data']) >= 1 && $r['data'][0] instanceof Template, 'contains(name) matches our run'));

    $h->step('templates', 'list', 'list w/ filter all(any(id,[..]), greater-than(updated,-1h))',
        fn(APIClient $c) => $c->templates->list((new Query())->filter(Filter::all(
            Filter::any('id', array_values(array_filter([$code?->id, $dnd?->id]))),
            Filter::greaterThan('updated', new DateTimeImmutable('-1 hour')),
        ))->sort('updated', descending: true)),
        fn($r) => $h->assert(count($r['data']) === count(array_filter([$code, $dnd])), 'any(id) + updated filter'));

    if ($code) {
        $h->step('templates', 'update', 'update name + html + text on CODE template', function (APIClient $c) use ($h, $code) {
            $u = new UpdateTemplate($code->id);
            $u->name = $h->name('tpl-code-renamed');
            $u->html = '<html><body><h1>Updated {{ first_name }}</h1></body></html>';
            $u->text = 'Updated {{ first_name }}';
            return $c->templates->update($u, (new Query())->fields('template', 'name', 'html', 'text', 'updated'));
        }, fn($r) => $h->assert(
            $r instanceof Template && $r->name === $h->name('tpl-code-renamed')
            && str_contains((string)$r->html, 'Updated {{ first_name }}') && str_contains((string)$r->text, 'Updated'),
            'name + html + text patched'
        ));

        $h->step('templates', 'render', 'render CODE template w/ flat + nested context',
            fn(APIClient $c) => $c->templates->render(new TemplateRender($code->id, ['first_name' => 'Ada', 'event' => ['name' => 'Opening night']])),
            fn($r) => $h->assert(
                $r instanceof Template && str_contains((string)$r->html, 'Ada') && str_contains((string)$r->text, 'Ada'),
                'rendered html + text carry the context value'
            ));

        $h->step('templates', 'render', 'render CODE template w/ empty-ish context (default filter applies)',
            fn(APIClient $c) => $c->templates->render(new TemplateRender($code->id, ['first_name' => 'Grace'])),
            fn($r) => $h->assert($r instanceof Template && str_contains((string)$r->html, 'Grace'), 'second render'));

        $clone1 = $h->step('templates', 'clone', 'clone template with a new name',
            fn(APIClient $c) => $c->templates->clone(new TemplateClone($code->id, $h->name('tpl-clone'))),
            fn($r) => $h->assert(
                $r instanceof Template && $r->id && $r->id !== $code->id && $r->name === $h->name('tpl-clone'),
                'clone has its own id and the requested name'
            ));
        if ($clone1) {
            $h->cleanup('templates', 'delete', 'named clone', fn(APIClient $c) => $c->templates->delete($clone1->id));
        }

        $clone2 = $h->step('templates', 'clone', 'clone template without a name (Klaviyo derives one)',
            fn(APIClient $c) => $c->templates->clone(new TemplateClone($code->id)),
            fn($r) => $h->assert($r instanceof Template && $r->id && $r->id !== $code->id && $r->name !== null, 'derived name: ' . 'non-null'));
        if ($clone2) {
            $h->cleanup('templates', 'delete', 'unnamed clone', fn(APIClient $c) => $c->templates->delete($clone2->id));
        }

        $h->step('templates', 'executePool', 'executePool: 3 template GETs via returnRequest (one 404)', function (APIClient $c) use ($h, $code, $dnd) {
            $reqs = [
                $c->templates->get($code->id, returnRequest: true),
                $c->templates->get('NOPE01', returnRequest: true),
                $c->templates->get($dnd?->id ?? $code->id, (new Query())->fields('template', 'name'), returnRequest: true),
            ];
            $res = $c->executePool($reqs, 3);
            $h->assert(count($res) === 3, '3 results');
            $h->assert($res[0] instanceof Template && $res[0]->id === $code->id, 'first ok');
            $h->assert($res[1] instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $res[1]->getHttpStatus() === 404, 'second is 404 exception');
            $h->assert($res[2] instanceof Template, 'third ok');
            return $res;
        });
    }

    // Runs after the clones so the account holds more than one template even when it starts empty.
    $h->step('templates', 'list', 'paginate templates via links.next (page[size]=1)', function (APIClient $c) use ($h) {
        $first = $c->templates->list((new Query())->pageSize(1)->sort('created', descending: true));
        $h->assert(count($first['data']) === 1, 'first page has 1');
        $h->assert($first['links']?->next !== null, 'has next link');
        $second = $c->templates->list(next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        $viaCursor = $c->templates->list((new Query())->pageSize(1)->sort('created', descending: true)->cursor($first['links']->next));
        $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) equals links.next');
        return $second;
    });

    if ($dnd) {
        $h->step('templates', 'update', 'replace definition on SYSTEM_DRAGGABLE template', function (APIClient $c) use ($h, $dnd, $definition) {
            $u = new UpdateTemplate($dnd->id);
            $u->name = $h->name('tpl-dnd-renamed');
            $d = $definition;
            $d['body']['styles']['width'] = 640;
            $d['body']['styles']['background_color'] = '#f5f5f5';
            $u->definition = $d;
            return $c->templates->update($u, (new Query())->additionalFields('template', 'definition'));
        }, fn($r) => $h->assert(
            $r instanceof Template && $r->name === $h->name('tpl-dnd-renamed')
            && ($r->definition['body']['styles']['width'] ?? null) === 640,
            'definition replaced (width 640)'
        ));
    }

    // ───────────────────────────── Universal content ─────────────────────────────

    $htmlBlock = [
        'content_type' => 'block',
        'type'         => 'html',
        'data'         => [
            'content'         => '<div>sdk-smoke html block {{ first_name }}</div>',
            'display_options' => ['show_on' => 'all', 'visible_check' => ''],
        ],
    ];

    /** @var TemplateUniversalContent|null $uc */
    $uc = $h->step('universalContent', 'create', 'create universal content (html block)',
        fn(APIClient $c) => $c->universalContent->create(
            new CreateUniversalContent($h->name('uc-html'), $htmlBlock),
            (new Query())->fields('template-universal-content', 'name', 'definition', 'created', 'updated')
        ),
        fn($r) => $h->assert(
            $r instanceof TemplateUniversalContent && $r->id && $r->name === $h->name('uc-html')
            && $r->definition instanceof UniversalContentDefinition && $r->definition->type === 'html'
            && $r->definition->content_type === 'block'
            && str_contains((string)($r->definition->data['content'] ?? ''), 'sdk-smoke html block'),
            'hydrated UC with nested UniversalContentDefinition + AttributeBag data'
        ));
    // DELETE /api/template-universal-content/{id} answers 204 but the resource has been observed
    // to survive the first delete (a later GET still returns it); a second delete removes it. The
    // cleanup therefore verifies and retries.
    // A patched universal content has been observed to come *back* after a 204 delete: the GET
    // right after the delete 404s, but a GET a few seconds later answers 200 again (only the
    // resources that were PATCHed first behave this way — an async post-update job re-materialises
    // the row). So the cleanup waits before it verifies, and re-deletes while it is still there.
    $deleteUc = fn(string $id) => function (APIClient $c) use ($h, $id) {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $c->universalContent->delete($id);
            sleep(4);
            if ($c->universalContent->get($id) === null) {
                return $attempt;
            }
        }
        $h->assert(false, "universal content {$id} still readable after 4 deletes");
    };

    if ($uc) {
        $h->cleanup('universalContent', 'delete', 'html universal content (verified gone)', $deleteUc($uc->id));
    }

    $textBlock = [
        'content_type' => 'block',
        'type'         => 'text',
        'data'         => [
            'content'         => '<p>sdk-smoke text block</p>',
            'display_options' => ['show_on' => 'mobile'],
            'styles'          => [
                'font_family'   => 'Helvetica, Arial, sans-serif',
                'font_size'     => 16,
                'color'         => '#333333',
                'text_align'    => 'center',
                'line_height'   => 1.5,
                'block_padding_top' => 8,
            ],
        ],
    ];

    /** @var TemplateUniversalContent|null $uc2 */
    $uc2 = $h->step('universalContent', 'create', 'create universal content (text block w/ styles)',
        fn(APIClient $c) => $c->universalContent->create(new CreateUniversalContent($h->name('uc-text'), $textBlock)),
        fn($r) => $h->assert(
            $r instanceof TemplateUniversalContent && $r->definition?->type === 'text'
            && ($r->definition->data['styles']['font_size'] ?? null) === 16,
            'text block styles echoed'
        ));
    if ($uc2) {
        $h->cleanup('universalContent', 'delete', 'text universal content (verified gone)', $deleteUc($uc2->id));
    }

    if ($uc) {
        $h->step('universalContent', 'get', 'get universal content w/ fields (name only)',
            fn(APIClient $c) => $c->universalContent->get($uc->id, (new Query())->fields('template-universal-content', 'name')),
            fn($r) => $h->assert(
                $r instanceof TemplateUniversalContent && $r->id === $uc->id && $r->name === $h->name('uc-html') && $r->definition === null,
                'sparse fieldset drops definition'
            ));

        $h->step('universalContent', 'get', 'get universal content w/ fields(definition.data.content, screenshot_status)',
            fn(APIClient $c) => $c->universalContent->get($uc->id, (new Query())->fields('template-universal-content', 'definition', 'screenshot_status')),
            fn($r) => $h->assert($r instanceof TemplateUniversalContent && $r->definition !== null, 'definition present'));
    }

    $h->step('universalContent', 'get', 'get unknown universal content → null',
        fn(APIClient $c) => $c->universalContent->get('01HWWWKAW4RHXQJCMW4R2KRYR4'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('universalContent', 'list', 'list w/ filter equals(name) + fields + page[size]=100 + sort=-created',
        fn(APIClient $c) => $c->universalContent->list((new Query())
            ->filter(Filter::equals('name', $h->name('uc-html')))
            ->fields('template-universal-content', 'name', 'created')
            ->pageSize(100)
            ->sort('created', descending: true)),
        fn($r) => $h->assert(count($r['data']) === ($uc ? 1 : 0) && (!$uc || $r['data'][0]->id === $uc->id), 'exactly our html UC'));

    $h->step('universalContent', 'list', 'list w/ filter all(equals(definition.type,"text"), greater-than(created,-1h))',
        fn(APIClient $c) => $c->universalContent->list((new Query())->filter(Filter::all(
            Filter::equals('definition.type', 'text'),
            Filter::greaterThan('created', new DateTimeImmutable('-1 hour')),
        ))->pageSize(20)),
        fn($r) => $h->assert(count($r['data']) >= ($uc2 ? 1 : 0), 'definition.type filter'));

    $h->step('universalContent', 'list', 'list w/ filter any(name,[..]) + equals(definition.content_type,"block")',
        fn(APIClient $c) => $c->universalContent->list((new Query())->filter(Filter::all(
            Filter::any('name', [$h->name('uc-html'), $h->name('uc-text')]),
            Filter::equals('definition.content_type', 'block'),
        ))),
        fn($r) => $h->assert(count($r['data']) === count(array_filter([$uc, $uc2])), 'both of ours'));

    $h->step('universalContent', 'list', 'paginate universal content via links.next (page[size]=1)', function (APIClient $c) use ($h) {
        $first = $c->universalContent->list((new Query())->pageSize(1)->sort('created', descending: true));
        $h->assert(count($first['data']) === 1, 'first page has 1');
        $h->assert($first['links']?->next !== null, 'has next link');
        $second = $c->universalContent->list(next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        return $second;
    });

    if ($uc) {
        $h->step('universalContent', 'update', 'update name + definition (html content)', function (APIClient $c) use ($h, $uc, $htmlBlock) {
            $u = new UpdateUniversalContent($uc->id);
            $u->name = $h->name('uc-html-renamed');
            $d = $htmlBlock;
            $d['data']['content'] = '<div>sdk-smoke html block updated</div>';
            $d['data']['display_options'] = ['show_on' => 'desktop'];
            $u->definition = $d;
            return $c->universalContent->update($u, (new Query())->fields('template-universal-content', 'name', 'definition'));
        }, fn($r) => $h->assert(
            $r instanceof TemplateUniversalContent && $r->name === $h->name('uc-html-renamed')
            && str_contains((string)($r->definition->data['content'] ?? ''), 'updated'),
            'name + definition patched'
        ));

        $h->step('universalContent', 'update', 'update name only (definition untouched)', function (APIClient $c) use ($h, $uc) {
            $u = new UpdateUniversalContent($uc->id);
            $u->name = $h->name('uc-html-final');
            return $c->universalContent->update($u);
        }, fn($r) => $h->assert(
            $r instanceof TemplateUniversalContent && $r->name === $h->name('uc-html-final') && $r->definition?->type === 'html',
            'name-only patch keeps definition'
        ));
    }

    // ───────────────────────────── Images ─────────────────────────────

    // 1x1 transparent PNG, hand-crafted so the suite needs no GD and no network fetch.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
    $uploadedImages = [];

    /** @var Image|null $imgUrl */
    $imgUrl = $h->step('images', 'uploadFromUrl', 'uploadFromUrl (data uri) w/ name + hidden=false + fields[image]',
        fn(APIClient $c) => $c->images->uploadFromUrl(
            new UploadImageFromUrl('data:image/png;base64,' . base64_encode($png), $h->name('img-url'), false),
            (new Query())->fields('image', 'name', 'image_url', 'format', 'size', 'hidden', 'updated_at')
        ),
        fn($r) => $h->assert(
            $r instanceof Image && $r->id && $r->name === $h->name('img-url') && $r->hidden === false
            && $r->format === 'png' && $r->size > 0 && str_starts_with((string)$r->image_url, 'http'),
            'hydrated image with hosted url, format, size, hidden=false'
        ));
    if ($imgUrl) {
        $uploadedImages[$imgUrl->id] = $h->name('img-url');
        // PATCH /api/images/{id} is a full replace of the writable attributes: a body carrying
        // only `hidden` blanks `name`, so the name is resent alongside it.
        $h->cleanup('images', 'update', 'hide img-url, keeping its name (images cannot be deleted)', function (APIClient $c) use ($h, $imgUrl) {
            $u = new UpdateImage($imgUrl->id);
            $u->name = $h->name('img-url-renamed');
            $u->hidden = true;
            $r = $c->images->update($u);
            $h->assert($r->hidden === true && $r->name === $h->name('img-url-renamed'), 'hidden + name kept');
            return $r;
        });
    }

    /** @var Image|null $imgUrl2 */
    $imgUrl2 = $h->step('images', 'uploadFromUrl', 'uploadFromUrl (public https png) w/ name + hidden=true',
        fn(APIClient $c) => $c->images->uploadFromUrl(new UploadImageFromUrl(
            'https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png',
            $h->name('img-remote'),
            true
        )),
        fn($r) => $h->assert($r instanceof Image && $r->id && $r->hidden === true && $r->size > 0, 'remote fetch stored, hidden=true'));
    if ($imgUrl2) {
        $uploadedImages[$imgUrl2->id] = $h->name('img-remote');
    }

    /** @var Image|null $imgFile */
    $imgFile = $h->step('images', 'uploadFromFile', 'uploadFromFile (multipart, 1x1 png bytes) w/ name + hidden=false',
        fn(APIClient $c) => $c->images->uploadFromFile($png, 'sdk-smoke.png', $h->name('img-file'), false,),
        fn($r) => $h->assert(
            $r instanceof Image && $r->id && $r->name === $h->name('img-file') && $r->format === 'png' && $r->hidden === false,
            'multipart upload stored'
        ));
    if ($imgFile) {
        $uploadedImages[$imgFile->id] = $h->name('img-file');
        $h->cleanup('images', 'update', 'hide img-file, keeping its name (images cannot be deleted)', function (APIClient $c) use ($h, $imgFile) {
            $u = new UpdateImage($imgFile->id);
            $u->name = $h->name('img-file-pooled');
            $u->hidden = true;
            $r = $c->images->update($u);
            $h->assert($r->hidden === true && $r->name === $h->name('img-file-pooled'), 'hidden + name kept');
            return $r;
        });
    }

    if ($imgFile) {
        $h->step('images', 'get', 'get image w/ fields[image] subset',
            fn(APIClient $c) => $c->images->get($imgFile->id, (new Query())->fields('image', 'name', 'hidden')),
            fn($r) => $h->assert(
                $r instanceof Image && $r->id === $imgFile->id && $r->name === $h->name('img-file') && $r->image_url === null,
                'sparse fieldset drops image_url'
            ));

        $h->step('images', 'get', 'get image w/ all fields',
            fn(APIClient $c) => $c->images->get($imgFile->id, (new Query())->fields('image', 'name', 'image_url', 'format', 'size', 'hidden', 'updated_at')),
            fn($r) => $h->assert($r instanceof Image && $r->image_url !== null && $r->updated_at !== null, 'full image'));
    }

    $h->step('images', 'get', 'get unknown image → null',
        fn(APIClient $c) => $c->images->get('99999999999'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('images', 'list', 'list w/ filter equals(name) + fields + page[size]=100 + sort=-updated_at',
        fn(APIClient $c) => $c->images->list((new Query())
            ->filter(Filter::equals('name', $h->name('img-file')))
            ->fields('image', 'name', 'hidden', 'updated_at')
            ->pageSize(100)
            ->sort('updated_at', descending: true)),
        fn($r) => $h->assert(count($r['data']) === ($imgFile ? 1 : 0) && (!$imgFile || $r['data'][0]->id === $imgFile->id), 'exactly our file image'));

    $h->step('images', 'list', 'list w/ filter all(contains(name,run), equals(hidden,false))',
        fn(APIClient $c) => $c->images->list((new Query())->filter(Filter::all(
            Filter::contains('name', 'sdk-smoke-' . $h->runId),
            Filter::equals('hidden', false),
        ))->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === count(array_filter([$imgUrl, $imgFile])), 'only the two non-hidden ones'));

    // greater-than(size,0) is rejected by Klaviyo with "At least one filter value is required to
    // filter on size" — the literal 0 is read as an absent value — so the bound is 1.
    $h->step('images', 'list', 'list w/ filter all(starts-with(name,..), equals(format,"png"), greater-than(size,1), greater-or-equal(updated_at,-1h))',
        fn(APIClient $c) => $c->images->list((new Query())->filter(Filter::all(
            Filter::startsWith('name', 'sdk-smoke-' . $h->runId),
            Filter::equals('format', 'png'),
            Filter::greaterThan('size', 1),
            Filter::greaterOrEqual('updated_at', new DateTimeImmutable('-1 hour')),
        ))->sort('size')),
        fn($r) => $h->assert(count($r['data']) >= 1, 'starts-with + format + size + updated_at'));

    // GET /api/images hides hidden images unless the filter asks for them, so any(id) over all
    // three uploads only answers with the two that are still visible.
    $h->step('images', 'list', 'list w/ filter any(id,[..]) over every upload (hidden ones excluded)',
        fn(APIClient $c) => $c->images->list((new Query())->filter(
            Filter::any('id', array_keys($uploadedImages) ?: ['0'])
        )),
        fn($r) => $h->assert(count($r['data']) === count(array_filter([$imgUrl, $imgFile])), 'any(id) returns the non-hidden uploads'));

    $h->step('images', 'list', 'list w/ filter all(any(id,[..]), equals(hidden,true)) finds the hidden upload',
        fn(APIClient $c) => $c->images->list((new Query())->filter(Filter::all(
            Filter::any('id', array_keys($uploadedImages) ?: ['0']),
            Filter::equals('hidden', true),
        ))->fields('image', 'name', 'hidden')),
        fn($r) => $h->assert(
            count($r['data']) === ($imgUrl2 ? 1 : 0) && (!$imgUrl2 || ($r['data'][0]->id === $imgUrl2->id && $r['data'][0]->hidden === true)),
            'equals(hidden,true) surfaces the hidden upload'
        ));

    $h->step('images', 'list', 'paginate images via links.next (page[size]=1)', function (APIClient $c) use ($h) {
        $first = $c->images->list((new Query())->pageSize(1)->sort('updated_at', descending: true));
        $h->assert(count($first['data']) === 1, 'first page has 1');
        $h->assert($first['links']?->next !== null, 'has next link');
        $second = $c->images->list(next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        $viaCursor = $c->images->list((new Query())->pageSize(1)->sort('updated_at', descending: true)->cursor($first['links']->next));
        $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) equals links.next');
        return $second;
    });

    if ($imgUrl) {
        $h->step('images', 'update', 'update image name + hidden=true', function (APIClient $c) use ($h, $imgUrl) {
            $u = new UpdateImage($imgUrl->id);
            $u->name = $h->name('img-url-renamed');
            $u->hidden = true;
            return $c->images->update($u, (new Query())->fields('image', 'name', 'hidden'));
        }, fn($r) => $h->assert(
            $r instanceof Image && $r->name === $h->name('img-url-renamed') && $r->hidden === true,
            'renamed + hidden'
        ));
        $uploadedImages[$imgUrl->id] = $h->name('img-url-renamed');

        $h->step('images', 'update', 'update image hidden back to false (toggle)', function (APIClient $c) use ($imgUrl) {
            $u = new UpdateImage($imgUrl->id);
            $u->hidden = false;
            return $c->images->update($u);
        }, fn($r) => $h->assert($r instanceof Image && $r->hidden === false, 'unhidden'));
    }

    if ($imgFile) {
        $h->step('images', 'executePool', 'executePool: image GET + PATCH via returnRequest', function (APIClient $c) use ($h, $imgFile) {
            $patch = new UpdateImage($imgFile->id);
            $patch->name = $h->name('img-file-pooled');
            $res = $c->executePool([
                $c->images->get($imgFile->id, (new Query())->fields('image', 'name'), returnRequest: true),
                $c->images->update($patch, returnRequest: true),
            ], 2);
            APIClient::assertNoExceptions($res);
            $h->assert($res[0] instanceof Image && $res[1] instanceof Image && $res[1]->name === $h->name('img-file-pooled'), 'both pooled calls hydrated');
            return $res;
        });
        $uploadedImages[$imgFile->id] = $h->name('img-file-pooled');
    }

    $h->note('ImageService has no delete(): the Klaviyo API exposes no DELETE for /api/images, so the three uploads '
        . 'are hidden (hidden=true) in cleanup instead of removed. Its HasCreate trait is reachable only through '
        . 'uploadFromUrl(), and HasDelete is not used at all, so list/get/update/uploadFromUrl/uploadFromFile is the '
        . 'complete public surface.');
    $h->note('GET /api/images omits hidden images unless the filter asks for them (equals(hidden,true) / any(hidden,[..])); '
        . 'Klaviyo also rejects greater-than(size,0) with "At least one filter value is required to filter on size", '
        . 'reading the literal 0 as an absent value.');
    $h->note('PATCH /api/images/{id} is a full replace of the writable attributes even though it is documented as a '
        . 'partial update: a body of {"data":{"type":"image","attributes":{"hidden":true},"id":"…"}} (exactly what '
        . 'UpdateImage serialises when only `hidden` is set) leaves the image with name=null. Send `name` with every '
        . 'image patch. Confirmed by probe: rename → patch hidden only → name is null.');
    $h->note('DELETE /api/template-universal-content/{id} does not always stick: it answers 204, an immediate GET '
        . '404s, and a GET seconds-to-minutes later answers 200 again — the resource is resurrected. Seen in 3 of 5 '
        . 'runs and only for the resource that had been PATCHed beforehand (the never-updated one always deleted '
        . 'cleanly), which points at an async post-update job on Klaviyo\'s side re-writing the row. The cleanup here '
        . 'deletes, sleeps, re-GETs and re-deletes up to 4 times. Template and image writes showed no such behaviour.');
    $h->note('Klaviyo normalises template html on write (an empty <head> is injected into the CODE template markup), '
        . 'so byte-for-byte html comparison after create/update does not hold.');
    $h->note('SYSTEM_DRAGGABLE definition constraints learned by trial: body.sections must hold at least one section '
        . '("Template must contain at least one section") and column entries take no `data` member at all '
        . '("\'styles\' is not a valid field for the resource \'ColumnData\'"), although the spec models ColumnData '
        . 'as an object and documents ColumnStyles.');

    if ($uploadedImages) {
        $h->mutation('Images cannot be deleted through the API. Left on the account (hidden=true where the API allowed it): '
            . implode(', ', array_map(fn($id, $name) => "{$id} ({$name})", array_keys($uploadedImages), $uploadedImages)));
    }
};
