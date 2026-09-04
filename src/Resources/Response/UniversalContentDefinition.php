<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * The single block a universal-content resource holds. `content_type` is always `block` and
 * `type` names the block (button, drop_shadow, horizontal_rule, html, image, spacer, text);
 * `data` is the block's own payload — `content` plus `display_options` for html and text
 * blocks, `styles` on top of those for text blocks — kept free-form through
 * {@see AttributeBag}.
 *
 * @property string|null       $content_type
 * @property string|null       $type
 * @property AttributeBag|null $data
 */
class UniversalContentDefinition extends Resource
{

    protected static function nested(): array
    {

        return ['data' => AttributeBag::class];
    }
}
