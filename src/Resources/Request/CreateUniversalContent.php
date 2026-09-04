<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TemplateUniversalContent;

/**
 * POST /api/template-universal-content: a reusable block that templates embed by id.
 *
 * `$definition` is one block, discriminated by `type`:
 *
 *   'content_type' (required) 'block'
 *   'type'         (required) button, drop_shadow, horizontal_rule, html, image, spacer or text
 *   'data'         (required) block payload. `html` and `text` blocks carry
 *                  ['content' => …, 'display_options' => ['show_on' => …, 'visible_check' => …,
 *                                                          'content_repeat' => [...]]],
 *                  and a `text` block additionally carries
 *                  ['styles' => ['font_family' => …, 'font_size' => 0, 'color' => …, …]].
 *                  The remaining block types take their own free-form payload.
 *
 * @property string $name
 * @property array  $definition
 */
class CreateUniversalContent extends TemplateUniversalContent
{

    public function __construct(string $name, array $definition)
    {

        parent::__construct();
        $this->name = $name;
        $this->definition = $definition;
    }

}
