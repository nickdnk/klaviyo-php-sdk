<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ProfileObjectSchema;

/**
 * Body for the object-schema ↔ profile linkage writes on
 * `/api/object-schemas/{id}/relationships/profile-object-schemas`. Same `meta`-carrying
 * shape as {@see ObjectSchemaRelationship}, with `profile-object-schema` as the far side.
 */
class ProfileObjectSchemaRelationship extends ProfileObjectSchema
{

    /**
     * @param string $id the profile object schema on the far side of the linkage
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
