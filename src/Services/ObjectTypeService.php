<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateObjectType;
use nickdnk\Klaviyo\Resources\Response\ObjectIngestionLog;
use nickdnk\Klaviyo\Resources\Response\ObjectRecord;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema;
use nickdnk\Klaviyo\Resources\Response\ObjectType;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectType;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * Object types — the custom entity definitions behind object records. Each type owns a
 * chain of schema versions, of which one is current and at most one is a draft.
 */
class ObjectTypeService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_object_types
     * @return array{data: ObjectType[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->listTrait($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_object_type
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): ObjectType|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_object_type
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateObjectType $objectType, ?Query $query = null, bool $returnRequest = false): ObjectType|RequestInterface
    {

        return $this->createTrait($objectType, $query, $returnRequest);

    }

    // region Schemas

    /**
     * The schema version records are validated against today.
     *
     * @link https://developers.klaviyo.com/en/reference/get_current_schema_for_object_type
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function currentSchema(string $objectTypeId, ?Query $query = null, bool $returnRequest = false): ObjectSchema|RequestInterface|null
    {

        return $this->relatedOneTrait($objectTypeId, 'current-schema', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_current_schema_id_for_object_type
     * @return ObjectSchema|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function currentSchemaId(string $objectTypeId, bool $returnRequest = false): ObjectSchema|RequestInterface|null
    {

        return $this->relatedOneIdTrait($objectTypeId, 'current-schema', $returnRequest);

    }

    /**
     * The unpublished next schema version, if one is in flight.
     *
     * @link https://developers.klaviyo.com/en/reference/get_draft_schema_for_object_type
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function draftSchema(string $objectTypeId, ?Query $query = null, bool $returnRequest = false): ObjectSchema|RequestInterface|null
    {

        return $this->relatedOneTrait($objectTypeId, 'draft-schema', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_draft_schema_id_for_object_type
     * @return ObjectSchema|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function draftSchemaId(string $objectTypeId, bool $returnRequest = false): ObjectSchema|RequestInterface|null
    {

        return $this->relatedOneIdTrait($objectTypeId, 'draft-schema', $returnRequest);

    }

    /**
     * Every schema version the type has had, current and draft included.
     *
     * @link https://developers.klaviyo.com/en/reference/get_schema_versions_for_object_type
     * @return array{data: ObjectSchema[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function schemaVersions(string $objectTypeId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($objectTypeId, 'schema-versions', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_schema_version_ids_for_object_type
     * @return array{data: ObjectSchema[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function schemaVersionIds(string $objectTypeId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($objectTypeId, 'schema-versions', returnRequest: $returnRequest);

    }

    // endregion

    // region Records and ingestion logs

    /**
     * @link https://developers.klaviyo.com/en/reference/get_records_for_object_type
     * @return array{data: ObjectRecord[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function records(string $objectTypeId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($objectTypeId, 'object-records', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_record_ids_for_object_type
     * @return array{data: ObjectRecord[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function recordIds(string $objectTypeId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($objectTypeId, 'object-records', $query, $next, $returnRequest);

    }

    /**
     * Only ingestion failures are logged, so an absent entry is not proof a record landed.
     * Logs are kept for 14 days and come back newest first, 50 per page.
     *
     * @link https://developers.klaviyo.com/en/reference/get_ingestion_logs_for_object_type
     * @return array{data: ObjectIngestionLog[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function ingestionLogs(string $objectTypeId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($objectTypeId, 'object-ingestion-logs', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_ingestion_log_ids_for_object_type
     * @return array{data: ObjectIngestionLog[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function ingestionLogIds(string $objectTypeId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($objectTypeId, 'object-ingestion-logs', $query, $next, $returnRequest);

    }

    // endregion

    // region Type linkages

    /**
     * Object types this type is linked to. Identifiers only — there is no full-resource
     * counterpart for this relationship.
     *
     * @link https://developers.klaviyo.com/en/reference/get_object_type_relationships
     * @return array{data: ObjectType[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function objectTypeRelationships(string $objectTypeId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($objectTypeId, 'object-types', returnRequest: $returnRequest);

    }

    /**
     * Profile object types this type is linked to. Identifiers only, as above.
     *
     * @link https://developers.klaviyo.com/en/reference/get_profile_type_relationships
     * @return array{data: ProfileObjectType[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileTypeRelationships(string $objectTypeId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($objectTypeId, 'profile-object-types', returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'object-types';
    }
}
