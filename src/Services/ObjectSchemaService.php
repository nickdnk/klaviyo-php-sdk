<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateObjectSchema;
use nickdnk\Klaviyo\Resources\Request\ObjectSchemaRelationship;
use nickdnk\Klaviyo\Resources\Request\ProfileObjectSchemaRelationship;
use nickdnk\Klaviyo\Resources\Request\UpdateObjectSchema;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema;
use nickdnk\Klaviyo\Resources\Response\SourceMapping;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectSchema;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Object schemas — one immutable version of an object type's field definitions, plus the
 * source mapping that feeds them and the linkages to other schemas and to profiles.
 *
 * The two linkage families are the odd ones out among Klaviyo's relationship writes: each
 * entry carries a `meta` block (the linkage's own `relationship_id`, `name` and
 * `description`) rather than being a bare identifier, and PATCH takes a single entry where
 * POST and DELETE take a list. See {@see ObjectSchemaRelationship}.
 */
class ObjectSchemaService extends BaseService
{

    use HasCreate;
    use HasGet;
    use HasRelationships;
    use HasUpdate;

    private const string RELATION_OBJECT_SCHEMAS         = 'object-schemas';
    private const string RELATION_PROFILE_OBJECT_SCHEMAS = 'profile-object-schemas';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_object_schema
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): ObjectSchema|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_object_schema
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateObjectSchema $schema, ?Query $query = null, bool $returnRequest = false): ObjectSchema|RequestInterface
    {

        return $this->createTrait($schema, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_object_schema
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateObjectSchema $schema, ?Query $query = null, bool $returnRequest = false): ObjectSchema|RequestInterface
    {

        return $this->updateTrait($schema, $query, $returnRequest);

    }

    // region Source mapping

    /**
     * The mapping that projects the data source's raw payload onto this schema.
     *
     * @link https://developers.klaviyo.com/en/reference/get_source_mapping_for_object_schema
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function sourceMapping(string $schemaId, ?Query $query = null, bool $returnRequest = false): SourceMapping|RequestInterface|null
    {

        return $this->relatedOneTrait($schemaId, 'source-mapping', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_source_mapping_id_for_object_schema
     * @return SourceMapping|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function sourceMappingId(string $schemaId, bool $returnRequest = false): SourceMapping|RequestInterface|null
    {

        return $this->relatedOneIdTrait($schemaId, 'source-mapping', $returnRequest);

    }

    // endregion

    // region Object schema linkages

    /**
     * Object schemas linked to this one. Each entry's `relationship_id`, `name` and `description`
     * travel in its `meta` block: read them with `$entry->getMeta()['relationship_id']`.
     *
     * @link https://developers.klaviyo.com/en/reference/get_object_schema_relationships
     * @return array{data: ObjectSchema[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function objectSchemaRelationships(string $schemaId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($schemaId, self::RELATION_OBJECT_SCHEMAS, returnRequest: $returnRequest);

    }

    /**
     * Each entry needs `name` in its `meta`; Klaviyo mints the `relationship_id`. Answered
     * 204 with an empty body.
     *
     * @link https://developers.klaviyo.com/en/reference/create_object_schema_relationship
     * @param ObjectSchemaRelationship[] $relationships
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function createObjectSchemaRelationship(string $schemaId, array $relationships, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($schemaId, self::RELATION_OBJECT_SCHEMAS, $relationships, $returnRequest);

    }

    /**
     * Renames or re-describes one existing linkage, addressed by the `relationship_id` in its
     * `meta`. Takes a single entry, not a list. Answered 204 with an empty body.
     *
     * @link https://developers.klaviyo.com/en/reference/update_object_schema_relationship
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function updateObjectSchemaRelationship(string $schemaId, ObjectSchemaRelationship $relationship, bool $returnRequest = false): ?RequestInterface
    {

        return $this->patchRelationship($schemaId, self::RELATION_OBJECT_SCHEMAS, $relationship, $returnRequest);

    }

    /**
     * Each entry needs the `relationship_id` in its `meta`. Answered 204 with an empty body.
     *
     * @link https://developers.klaviyo.com/en/reference/delete_object_schema_relationship
     * @param ObjectSchemaRelationship[] $relationships
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function deleteObjectSchemaRelationship(string $schemaId, array $relationships, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($schemaId, self::RELATION_OBJECT_SCHEMAS, $relationships, $returnRequest);

    }

    // endregion

    // region Profile schema linkages

    /**
     * Profile object schemas linked to this one. Identifiers only, and the linkage's
     * `relationship_id` rides in a `meta` block that hydration drops, as above.
     *
     * @link https://developers.klaviyo.com/en/reference/get_profile_schema_relationships
     * @return array{data: ProfileObjectSchema[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileSchemaRelationships(string $schemaId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($schemaId, self::RELATION_PROFILE_OBJECT_SCHEMAS, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_profile_schema_relationship
     * @param ProfileObjectSchemaRelationship[] $relationships
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function createProfileSchemaRelationship(string $schemaId, array $relationships, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($schemaId, self::RELATION_PROFILE_OBJECT_SCHEMAS, $relationships, $returnRequest);

    }

    /**
     * Takes a single entry, not a list.
     *
     * @link https://developers.klaviyo.com/en/reference/update_profile_schema_relationship
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function updateProfileSchemaRelationship(string $schemaId, ProfileObjectSchemaRelationship $relationship, bool $returnRequest = false): ?RequestInterface
    {

        return $this->patchRelationship($schemaId, self::RELATION_PROFILE_OBJECT_SCHEMAS, $relationship, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/delete_profile_schema_relationship
     * @param ProfileObjectSchemaRelationship[] $relationships
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function deleteProfileSchemaRelationship(string $schemaId, array $relationships, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($schemaId, self::RELATION_PROFILE_OBJECT_SCHEMAS, $relationships, $returnRequest);

    }

    // endregion

    /**
     * PATCH on both linkage families sends one entry as `{data: {...}}`, where
     * {@see HasRelationships::replaceRelatedTrait()} would send a list.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function patchRelationship(string $schemaId, string $relation,
        ObjectSchemaRelationship|ProfileObjectSchemaRelationship $relationship, bool $returnRequest
    ): ?RequestInterface
    {

        $result = $this->request(
                           'PATCH',
                           $this->apiPath() . '/' . $schemaId . '/relationships/' . $relation,
                           $relationship,
            returnRequest: $returnRequest,
        );

        return $result instanceof RequestInterface ? $result : null;

    }

    protected function apiPath(): string
    {

        return 'object-schemas';
    }
}
