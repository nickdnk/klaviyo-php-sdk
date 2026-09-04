<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateSegment;
use nickdnk\Klaviyo\Resources\Request\UpdateSegment;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\Segment;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Segments are profile groups defined by a condition tree, so membership is derived rather than
 * assigned ({@see ListService} is the assigned kind).
 *
 * - There is no add or remove endpoint, only reads of the current members.
 * - A new segment reports `is_processing = false` immediately, but its members appear minutes
 *   later.
 * - `page[size]` caps at 10, and `name` accepts only `any` and `equals`.
 *
 * @link https://developers.klaviyo.com/en/reference/segments_api_overview
 */
class SegmentService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_segments
     * @return array{data: Segment[], links: ?PaginationLinks}|RequestInterface
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
     * `profile_count` is excluded by default; asking for it with
     * `additionalFields('segment', 'profile_count')` drops this endpoint's rate limit to 1/s.
     *
     * @link https://developers.klaviyo.com/en/reference/get_segment
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Segment|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_segment
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateSegment $segment, ?Query $query = null, bool $returnRequest = false): Segment|RequestInterface
    {

        return $this->createTrait($segment, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_segment
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateSegment $segment, ?Query $query = null, bool $returnRequest = false): Segment|RequestInterface
    {

        return $this->updateTrait($segment, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profiles_for_segment
     * @return array{data: Profile[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profiles(string $segmentId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($segmentId, 'profiles', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_ids_for_segment
     * @return array{data: Profile[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileIds(string $segmentId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($segmentId, 'profiles', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tags_for_segment
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tags(string $segmentId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($segmentId, 'tags', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_ids_for_segment
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagIds(string $segmentId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($segmentId, 'tags', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flows_triggered_by_segment
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggers(string $segmentId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($segmentId, 'flow-triggers', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_ids_for_flows_triggered_by_segment
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggerIds(string $segmentId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($segmentId, 'flow-triggers', returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'segments';
    }
}
