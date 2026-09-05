<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateTag;
use nickdnk\Klaviyo\Resources\Request\UpdateTag;
use nickdnk\Klaviyo\Resources\Response\Campaign as ResponseCampaign;
use nickdnk\Klaviyo\Resources\Response\Flow as ResponseFlow;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList as ResponseKlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Segment as ResponseSegment;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Response\TagGroup;
use nickdnk\Klaviyo\Resources\Shared\Campaign;
use nickdnk\Klaviyo\Resources\Shared\Flow;
use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\Segment;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Tags label campaigns, flows, lists and segments, and each tag belongs to exactly one tag group
 * ({@see TagGroupService}), defaulting to the account's default group.
 *
 * - Tagging and untagging goes through the relationship methods here, not through the tagged
 *   resource.
 * - An account can hold at most 500 tags.
 */
class TagService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tags
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface
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
     * Every Tag matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, Tag>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function iterate(?Query $query = null): Generator
    {
        return (new Paginator(fn(?string $next) => $this->list($query, $next)))->items();
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Tag|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_tag
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateTag $tag, ?Query $query = null, bool $returnRequest = false): Tag|RequestInterface
    {

        return $this->createTrait($tag, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_tag
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateTag $tag, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->updateTrait($tag, $query, $returnRequest);

    }

    // region Campaigns

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_ids_for_tag
     * @return array{data: ResponseCampaign[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function campaignIds(string $tagId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($tagId, 'campaigns', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/tag_campaigns
     * @param Campaign[] $campaigns
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagCampaigns(string $tagId, array $campaigns, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($tagId, 'campaigns', $campaigns, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_tag_from_campaigns
     * @param Campaign[] $campaigns
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function untagCampaigns(string $tagId, array $campaigns, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($tagId, 'campaigns', $campaigns, $returnRequest);

    }

    // endregion

    // region Flows

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_ids_for_tag
     * @return array{data: ResponseFlow[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowIds(string $tagId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($tagId, 'flows', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/tag_flows
     * @param Flow[] $flows
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagFlows(string $tagId, array $flows, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($tagId, 'flows', $flows, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_tag_from_flows
     * @param Flow[] $flows
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function untagFlows(string $tagId, array $flows, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($tagId, 'flows', $flows, $returnRequest);

    }

    // endregion

    // region Lists

    /**
     * @link https://developers.klaviyo.com/en/reference/get_list_ids_for_tag
     * @return array{data: ResponseKlaviyoList[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function listIds(string $tagId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($tagId, 'lists', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/tag_lists
     * @param KlaviyoList[] $lists
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagLists(string $tagId, array $lists, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($tagId, 'lists', $lists, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_tag_from_lists
     * @param KlaviyoList[] $lists
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function untagLists(string $tagId, array $lists, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($tagId, 'lists', $lists, $returnRequest);

    }

    // endregion

    // region Segments

    /**
     * @link https://developers.klaviyo.com/en/reference/get_segment_ids_for_tag
     * @return array{data: ResponseSegment[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function segmentIds(string $tagId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($tagId, 'segments', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/tag_segments
     * @param Segment[] $segments
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagSegments(string $tagId, array $segments, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($tagId, 'segments', $segments, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_tag_from_segments
     * @param Segment[] $segments
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function untagSegments(string $tagId, array $segments, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($tagId, 'segments', $segments, $returnRequest);

    }

    // endregion

    // region Tag group

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_group_for_tag
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagGroup(string $tagId, ?Query $query = null, bool $returnRequest = false): TagGroup|RequestInterface|null
    {

        return $this->relatedOneTrait($tagId, 'tag-group', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_group_id_for_tag
     * @return TagGroup|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagGroupId(string $tagId, bool $returnRequest = false): TagGroup|RequestInterface|null
    {

        return $this->relatedOneIdTrait($tagId, 'tag-group', $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'tags';
    }
}
