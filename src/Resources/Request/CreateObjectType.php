<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\ObjectType;

/**
 * POST /api/object-types. The first schema rides along as the hyphenated `object-schema`
 * attribute — a `{data: {...}}` envelope nested inside `attributes`, not a JSON:API
 * relationship — carrying only `properties`, `status`, `required` and `source-mapping`.
 *
 * @property string      $title
 * @property string|null $description
 * @property string|null $visibility   private|shared
 * @property string|null $namespace
 */
class CreateObjectType extends ObjectType
{

    public function __construct(string $title, ?string $description = null, ?string $visibility = null,
        ?string $namespace = null, ?ObjectSchema $objectSchema = null
    )
    {

        parent::__construct();
        $this->title = $title;
        $this->description = $description;
        $this->visibility = $visibility;
        $this->namespace = $namespace;
        $this->{'object-schema'} = $objectSchema?->wrapData();
    }

}
