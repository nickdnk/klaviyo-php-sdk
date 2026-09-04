<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\ObjectType as SharedObjectType;

/**
 * @property string|null $title
 * @property string|null $description
 * @property string|null $status       active|draft|publishing|undefined
 * @property string|null $namespace
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class ObjectType extends SharedObjectType
{

}
