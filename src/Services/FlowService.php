<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateFlow;
use nickdnk\Klaviyo\Resources\Request\UpdateFlow;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
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
 * Flows are automated sequences triggered by a metric, a list or a segment, whose steps are flow
 * actions ({@see FlowActionService}).
 *
 * - Date filters may not reach into the future, and `sort` must name the field being filtered.
 * - Deleting a flow leaves behind the template copies its send actions made, and those copies
 *   then refuse to be deleted.
 *
 * @link https://developers.klaviyo.com/en/reference/flows_api_overview
 */
class FlowService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flows
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface
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
     * Every Flow matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, Flow>
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
     * @link https://developers.klaviyo.com/en/reference/get_flow
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Flow|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * Every `temporary_id` in the submitted definition comes back replaced by a real id.
     *
     * @link https://developers.klaviyo.com/en/reference/create_flow
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateFlow $flow, ?Query $query = null, bool $returnRequest = false): Flow|RequestInterface
    {

        return $this->createTrait($flow, $query, $returnRequest);

    }

    /**
     * Sets the status of the flow and of every action inside it.
     *
     * @link https://developers.klaviyo.com/en/reference/update_flow
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateFlow $flow, ?Query $query = null, bool $returnRequest = false): Flow|RequestInterface
    {

        return $this->updateTrait($flow, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_actions_for_flow
     * @return array{data: FlowAction[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowActions(string $flowId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($flowId, 'flow-actions', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_action_ids_for_flow
     * @return array{data: FlowAction[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowActionIds(string $flowId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($flowId, 'flow-actions', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tags_for_flow
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tags(string $flowId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($flowId, 'tags', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_ids_for_flow
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagIds(string $flowId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($flowId, 'tags', returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {
        return 'flows';
    }
}
