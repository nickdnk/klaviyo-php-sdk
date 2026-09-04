<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ObjectSchema;

/**
 * PATCH /api/object-schemas/{id}. Every attribute is optional; set the ones to change.
 *
 * @property string|null $title
 * @property array|null  $properties   entries of {id, name, type, description}
 * @property string|null $description
 * @property string|null $status       active|draft
 * @property array|null  $required     names of the properties a record must carry
 */
class UpdateObjectSchema extends ObjectSchema
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
