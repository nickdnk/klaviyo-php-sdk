<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\UpdateList;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Profile as ResponseProfile;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Lists hold profiles you put in them, as opposed to the derived membership of a segment
 * ({@see SegmentService}).
 *
 * - {@see self::addProfiles()} and {@see self::removeProfiles()} take up to 1000 profiles per
 *   call.
 * - Membership and consent are separate: adding a profile here does not subscribe it, which is
 *   {@see ProfileService::subscribe()}.
 * - `page[size]` caps at 10 for lists themselves, and `name` accepts only `any` and `equals`.
 *
 * @link https://developers.klaviyo.com/en/reference/lists_api_overview
 */
class ListService extends BaseService
{
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_lists
     * @return array{data: KlaviyoList[], links: ?PaginationLinks}|RequestInterface
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
     * Every KlaviyoList matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, KlaviyoList>
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
     * @link https://developers.klaviyo.com/en/reference/get_list
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): KlaviyoList|RequestInterface|null
    {
        return $this->getTrait($id, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_list
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateList $list, ?Query $query = null, bool $returnRequest = false): KlaviyoList|RequestInterface
    {
        return $this->createTrait($list, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_list
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateList $list, ?Query $query = null, bool $returnRequest = false): KlaviyoList|RequestInterface
    {
        return $this->updateTrait($list, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profiles_for_list
     * @return array{data: ResponseProfile[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profiles(string $listId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedTrait($listId, 'profiles', $query, $next, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_ids_for_list
     * @return array{data: ResponseProfile[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileIds(string $listId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedIdsTrait($listId, 'profiles', $query, $next, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tags_for_list
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tags(string $listId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedTrait($listId, 'tags', $query, returnRequest: $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_ids_for_list
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagIds(string $listId, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedIdsTrait($listId, 'tags', returnRequest: $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flows_triggered_by_list
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggers(string $listId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedTrait($listId, 'flow-triggers', $query, returnRequest: $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_ids_for_flows_triggered_by_list
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggerIds(string $listId, bool $returnRequest = false): array|RequestInterface
    {
        return $this->relatedIdsTrait($listId, 'flow-triggers', returnRequest: $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/add_profiles_to_list
     * @param Profile[] $profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function addProfiles(string $listId, array $profiles, bool $returnRequest = false): ?RequestInterface
    {
        return $this->addRelatedTrait($listId, 'profiles', $profiles, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_profiles_from_list
     * @param Profile[] $profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function removeProfiles(string $listId, array $profiles, bool $returnRequest = false): ?RequestInterface
    {
        return $this->removeRelatedTrait($listId, 'profiles', $profiles, $returnRequest);
    }

    protected function apiPath(): string
    {
        return 'lists';
    }
}
