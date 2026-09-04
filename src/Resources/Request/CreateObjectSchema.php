<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\ObjectType;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\SourceMapping;

/**
 * POST /api/object-schemas: a new version of the schema behind one object type, which the
 * `object-type` relationship names.
 *
 * The ingestion mapping rides along as the hyphenated `source-mapping` attribute — a
 * `{data: {...}}` envelope nested inside `attributes`, not a JSON:API relationship.
 *
 * @property string      $title
 * @property array       $properties
 * @property string|null $description
 * @property string|null $status       active|draft
 * @property array|null  $required
 */
class CreateObjectSchema extends ObjectSchema
{

    /**
     * @param array<int, array{id: int, name: string, type: string, description?: string|null}> $properties
     *        one entry per field; `type` is one of boolean|float|int|list_str|string|timestamp
     * @param string[]|null $required names of the properties a record must carry
     */
    public function __construct(string $title, array $properties, ?string $description = null,
        ?string $status = null, ?array $required = null, ?SourceMapping $sourceMapping = null,
        ?string $objectTypeId = null
    )
    {

        parent::__construct();
        $this->title = $title;
        $this->properties = array_values($properties);
        $this->description = $description;
        $this->status = $status;
        $this->required = $required !== null ? array_values($required) : null;
        $this->{'source-mapping'} = $sourceMapping?->wrapData();

        if ($objectTypeId !== null) {
            $this->addRelationship('object-type', new Relationship(new ObjectType($objectTypeId)));
        }
    }

}
