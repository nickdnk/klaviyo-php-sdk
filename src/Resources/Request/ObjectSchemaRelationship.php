<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ObjectSchema;

/**
 * Body for the object-schema ↔ object-schema linkage writes on
 * `/api/object-schemas/{id}/relationships/object-schemas`.
 *
 * Unlike every other Klaviyo relationship body these entries carry more than an identifier:
 * the linkage's own name, description and id ride in `meta`. POST requires `name` (Klaviyo
 * mints the `relationship_id`), while PATCH and DELETE address an existing linkage by its
 * `relationship_id`. POST and DELETE take a list of these, PATCH a single one.
 */
class ObjectSchemaRelationship extends ObjectSchema
{

    /**
     * @param string $id the object schema on the far side of the linkage
     */
    public function __construct(string $id, ?string $relationshipId = null, ?string $name = null,
        ?string $description = null
    )
    {

        parent::__construct($id);

        if ($relationshipId !== null) {
            $this->addMeta('relationship_id', $relationshipId);
        }
        if ($name !== null) {
            $this->addMeta('name', $name);
        }
        if ($description !== null) {
            $this->addMeta('description', $description);
        }
    }

}
