<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * One flow action's stored definition. `type` discriminates the payload: `links` points at
 * the next action (`next`, or `next_if_true` / `next_if_false` for the split types) and `data`
 * holds the per-type body, which is where an action's `status` lives. Both keep their
 * free-form shape through {@see AttributeBag}, so unknown keys read back as null rather than
 * raising.
 *
 * @property string|null       $id
 * @property string|null       $temporary_id
 * @property string|null       $type
 * @property AttributeBag|null $links
 * @property AttributeBag|null $data
 */
class FlowActionDefinition extends Resource
{

    protected static function nested(): array
    {

        return [
            'links' => AttributeBag::class,
            'data'  => AttributeBag::class,
        ];
    }
}
