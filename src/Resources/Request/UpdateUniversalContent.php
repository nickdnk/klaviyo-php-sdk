<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TemplateUniversalContent;

/**
 * PATCH /api/template-universal-content/{id}. Both attributes are optional; only what is set
 * is written. `definition` is writable for the button, drop_shadow, horizontal_rule, html,
 * image, spacer and text block types — see {@see CreateUniversalContent} for its shape.
 *
 * @property string|null $name
 * @property array|null  $definition
 */
class UpdateUniversalContent extends TemplateUniversalContent
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
