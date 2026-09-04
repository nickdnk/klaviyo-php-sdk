<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\SourceMapping;

/**
 * PATCH /api/source-mappings/{id}: rewires how a data source's raw payload feeds one object
 * schema's properties and relationships.
 *
 * @property array|null $property_mappings
 * @property array|null $relationship_mappings
 */
class UpdateSourceMapping extends SourceMapping
{

    /**
     * @param array<int, array<string, mixed>>|null $propertyMappings     one entry per mapped
     *        property, either `{id, type: "constant", value}` or
     *        `{id, type: "simple", source: {source_id, id_path, data_path}}`
     * @param array<int, array<string, mixed>>|null $relationshipMappings one entry per mapped
     *        linkage: `{relationship_id, type: "simple", source: {source_id, type, id_path,
     *        related_id_path|related_id_paths, update_strategy?}}`
     */
    public function __construct(string $id, ?array $propertyMappings = null, ?array $relationshipMappings = null)
    {

        parent::__construct($id);
        $this->property_mappings = $propertyMappings !== null ? array_values($propertyMappings) : null;
        $this->relationship_mappings = $relationshipMappings !== null ? array_values($relationshipMappings) : null;
    }

}
