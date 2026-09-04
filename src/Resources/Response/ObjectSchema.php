<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\ObjectSchema as SharedObjectSchema;

/**
 * @property string|null $title
 * @property string|null $description
 * @property string|null $status        active|draft|publishing|undefined
 * @property array|null  $properties
 * @property array|null  $required
 * @property string|null $published_at
 * @property string|null $visibility    private|shared
 */
class ObjectSchema extends SharedObjectSchema
{

}
