<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Response\FlowAction;
use nickdnk\Klaviyo\Resources\Response\FlowMessage;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * The messages a sending flow action delivers. Read-only: messages are written through their
 * action's definition ({@see FlowActionService::update()}). A flow action's messages are
 * listed by {@see FlowActionService::messages()}.
 */
class FlowMessageService extends BaseService
{

    use HasGet;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flow_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): FlowMessage|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    // region Relationships

    /**
     * The action that sends this message.
     *
     * @link https://developers.klaviyo.com/en/reference/get_action_for_flow_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowAction(string $messageId, ?Query $query = null, bool $returnRequest = false): FlowAction|RequestInterface|null
    {

        return $this->relatedOneTrait($messageId, 'flow-action', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_action_id_for_flow_message
     * @return FlowAction|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowActionId(string $messageId, bool $returnRequest = false): FlowAction|RequestInterface|null
    {

        return $this->relatedOneIdTrait($messageId, 'flow-action', $returnRequest);

    }

    /**
     * The template this message renders. Needs the `templates:read` scope, not `flows:read`.
     *
     * @link https://developers.klaviyo.com/en/reference/get_template_for_flow_message
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function template(string $messageId, ?Query $query = null, bool $returnRequest = false): Template|RequestInterface|null
    {

        return $this->relatedOneTrait($messageId, 'template', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_template_id_for_flow_message
     * @return Template|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function templateId(string $messageId, bool $returnRequest = false): Template|RequestInterface|null
    {

        return $this->relatedOneIdTrait($messageId, 'template', $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {
        return 'flow-messages';
    }
}
