<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateFlowAction;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
use nickdnk\Klaviyo\Resources\Response\FlowMessage;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The individual steps of a flow: sends, delays, conditional splits and profile updates.
 *
 * - An action is addressed by its own id; the actions of a flow are listed by
 *   {@see FlowService::flowActions()}.
 * - The `action_type` filter uses upper snake case (`SEND_EMAIL`, `TIME_DELAY`) while the action
 *   definitions themselves use kebab case (`send-email`).
 *
 * @link https://developers.klaviyo.com/en/reference/flows_api_overview
 */
class FlowActionService extends BaseService
{

    use HasDelete;
    use HasGet;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_action
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): FlowAction|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * Replaces the action's definition, which is also where its status lives (`definition.data.status`).
     *
     * @link https://developers.klaviyo.com/en/reference/update_flow_action
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateFlowAction $action, ?Query $query = null, bool $returnRequest = false): FlowAction|RequestInterface
    {

        return $this->updateTrait($action, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_for_flow_action
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flow(string $actionId, ?Query $query = null, bool $returnRequest = false): Flow|RequestInterface|null
    {

        return $this->relatedOneTrait($actionId, 'flow', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_id_for_flow_action
     * @return Flow|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowId(string $actionId, bool $returnRequest = false): Flow|RequestInterface|null
    {

        return $this->relatedOneIdTrait($actionId, 'flow', $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_action_messages
     * @return array{data: FlowMessage[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function messages(string $actionId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($actionId, 'flow-messages', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_message_ids_for_flow_action
     * @return array{data: FlowMessage[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function messageIds(string $actionId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($actionId, 'flow-messages', $query, $next, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {
        return 'flow-actions';
    }
}
