<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Template;

/**
 * PATCH /api/templates/{id}. Every attribute is optional; only what is set is written.
 * `editor_type` is fixed at creation and cannot be patched. See {@see CreateTemplate} for
 * the `definition` shape.
 *
 * @property string|null $name
 * @property string|null $html
 * @property string|null $text
 * @property string|null $amp
 * @property array|null  $definition
 */
class UpdateTemplate extends Template
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
